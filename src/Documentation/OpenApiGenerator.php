<?php
/**
 * This file is part of phloxcz/rest-api.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\RestApi\Documentation;

use Nette\Application\Response as ApplicationResponse;
use Nette\Http\FileUpload;
use Phlox\ApiCore\Documentation\DocumentationConfig;
use Phlox\ApiCore\Documentation\MethodReader;
use Phlox\ApiCore\Documentation\SchemaBuilder;
use Phlox\ApiCore\Schema\Binary;
use Phlox\ApiCore\Types\DocComment;
use Phlox\ApiCore\Types\Type;
use Phlox\RestApi\Attributes\Consumes;
use Phlox\RestApi\Attributes\Response;

/**
 * Generates an OpenAPI 3.1 document from the same routes the server runs on.
 *
 * @phpstan-import-type PathParameter from RouteDescriber
 * @phpstan-import-type ParameterInfo from MethodReader
 * @phpstan-type RouteInfo array{verbs: list<string>, path: string, params: array<string, string>, class: class-string, method: string, authorize: bool, roles: list<list<string>>, consumes: list<string>, fromBody: array{name: string}|null, fromHeader: array<string, array{name: string, position: int}>, fromForm: array<string, array{name: string, position: int}>}
 */
final class OpenApiGenerator
{
    private const BodyVerbs = ['POST', 'PUT', 'PATCH'];

    private const ErrorDescriptions = [
        400 => 'Malformed request.',
        401 => 'Not logged in.',
        403 => 'Missing role.',
        404 => 'Not found.',
        415 => 'Unsupported content type.',
        422 => 'Invalid parameters.',
    ];

    private readonly SchemaBuilder $builder;

    /** @var array<string, mixed> */
    private array $paths = [];

    /** @var array<string, array<string, mixed>> */
    private array $tags = [];

    /** @var array<string, true> */
    private array $operationIds = [];

    /** @var list<string> */
    private array $securitySchemes = [];

    public function __construct()
    {
        $this->builder = new SchemaBuilder(['Error']);
    }

    /**
     * @param list<RouteInfo> $routes
     * @return array<string, mixed>
     */
    public function generate(string $endpoint, \stdClass $config, array $routes): array
    {
        $this->securitySchemes = array_map(strval(...), array_keys($config->securitySchemes));

        foreach ($routes as $route) {
            try {
                $this->addRoute($route);
            } catch (\InvalidArgumentException $e) {
                throw new \LogicException("Documentation of {$route['class']}::{$route['method']}(): {$e->getMessage()}", previous: $e);
            }
        }

        $schemas = $this->builder->getSchemas() + ['Error' => self::errorSchema()];
        ksort($schemas);
        $components = ['schemas' => $schemas];
        if ($config->securitySchemes !== []) {
            $components['securitySchemes'] = $config->securitySchemes;
        }

        ksort($this->paths);
        ksort($this->tags);

        return DocumentationConfig::merge([
            'openapi' => '3.1.0',
            'info' => DocumentationConfig::buildInfo($config->info, $endpoint),
            'servers' => $config->servers ?: [['url' => '/' . $endpoint]],
            'tags' => array_values($this->tags),
            'paths' => $this->paths ?: new \stdClass(),
            'components' => $components,
        ], $config->extra);
    }

    /**
     * @return list<class-string>
     */
    public function getClasses(): array
    {
        return $this->builder->getClasses();
    }

    /**
     * @param RouteInfo $route
     */
    private function addRoute(array $route): void
    {
        $class = new \ReflectionClass($route['class']);
        $method = new MethodReader($class, new \ReflectionMethod($route['class'], $route['method']), $this->builder);
        $tag = $this->addTag($class);
        $variants = RouteDescriber::describe($route['path'], $route['params']);
        $parameters = $method->getParameters();

        // Whatever the route takes from the path isn't anywhere else, even when a variant leaves it out
        $pathNames = [];
        foreach ($variants as $variant) {
            $pathNames += $variant['parameters'];
        }

        foreach ($variants as $variantIndex => $variant) {
            foreach ($route['verbs'] as $verb) {
                $operation = [
                    'tags' => [$tag],
                    'summary' => $method->doc->summary !== '' ? $method->doc->summary : self::humanize($route['method']),
                ];
                $description = self::buildDescription($method->doc, $route);
                if ($description !== '') {
                    $operation['description'] = $description;
                }

                $suffix = (count($route['verbs']) > 1 ? '_' . strtolower($verb) : '') . (count($variants) > 1 ? "_{$variantIndex}" : '');
                $operation['operationId'] = $this->createOperationId($tag . '_' . $route['method'] . $suffix);

                if ($method->deprecated) {
                    $operation['deprecated'] = true;
                }

                $operation += $this->buildSecurity($route);
                if ($route['authorize'] && $route['roles'] !== []) {
                    $operation['x-roles'] = $route['roles'];
                }

                $inputs = $this->buildInputs($verb, $route, $variant['parameters'], $parameters, $pathNames);
                $operation += $inputs;
                $operation['responses'] = $this->buildResponses($method, $route, $variant['parameters'], $parameters, isset($inputs['requestBody']));

                $this->paths[$variant['template']][strtolower($verb)] = $operation;
            }
        }
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function addTag(\ReflectionClass $class): string
    {
        $name = preg_replace('/Controller$/', '', $class->getShortName()) ?: $class->getShortName();

        if (!isset($this->tags[$name])) {
            $doc = new DocComment($class->getDocComment());
            $this->tags[$name] = ['name' => $name] + ($doc->summary === '' ? [] : ['description' => $doc->summary]);
        }

        return $name;
    }

    /**
     * Tools list operations by their summary, and showDetail says something: "Show detail".
     */
    private static function humanize(string $method): string
    {
        return ucfirst(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $method) ?? $method));
    }

    private function createOperationId(string $id): string
    {
        $unique = $id;
        for ($i = 2; isset($this->operationIds[$unique]); $i++) {
            $unique = "{$id}_{$i}";
        }
        $this->operationIds[$unique] = true;

        return $unique;
    }

    /**
     * @param RouteInfo $route
     */
    private static function buildDescription(DocComment $doc, array $route): string
    {
        $parts = [];
        if ($doc->description !== '') {
            $parts[] = $doc->description;
        }
        if ($route['authorize'] && $route['roles'] !== []) {
            $parts[] = DocumentationConfig::describeRoles($route['roles']);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param RouteInfo $route
     * @return array<string, mixed>
     */
    private function buildSecurity(array $route): array
    {
        if ($this->securitySchemes === []) {
            return [];
        }

        if (!$route['authorize']) {
            return ['security' => []]; // public, whatever the document says globally
        }

        // Each scheme is an alternative. Roles stay in x-roles: a requirement lists what must
        // all hold, which can't say "admin or editor" without listing every combination.
        return ['security' => array_map(
            static fn(string $scheme): array => [$scheme => []],
            $this->securitySchemes,
        )];
    }

    /**
     * @param RouteInfo $route
     * @param array<string, PathParameter> $pathParameters Those of this variant
     * @param array<string, ParameterInfo> $parameters
     * @param array<string, PathParameter> $allPathParameters
     * @return array<string, mixed>
     */
    private function buildInputs(string $verb, array $route, array $pathParameters, array $parameters, array $allPathParameters): array
    {
        $result = [];
        $list = [];

        foreach ($pathParameters as $name => $pathParameter) {
            $info = $parameters[$name] ?? null;
            $list[] = self::withDescription([
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => self::buildPathSchema($pathParameter, $info),
            ], $pathParameter['token'] === 'all'
                ? trim(($info['description'] ?? '') . ' May contain slashes.')
                : ($info['description'] ?? ''));
        }

        $fromBody = $route['fromBody']['name'] ?? null;
        $others = array_filter(
            $parameters,
            static fn(array $info): bool => !isset($allPathParameters[$info['name']])
                && $info['name'] !== $fromBody
                && !isset($route['fromHeader'][$info['name']])
                && !isset($route['fromForm'][$info['name']]),
        );

        foreach ($route['fromHeader'] as $name => $header) {
            if (isset($parameters[$name])) {
                $info = $parameters[$name];
                $list[] = self::withDescription(['name' => $header['name'], 'in' => 'header', 'required' => $info['required'], 'schema' => $info['schema']], $info['description']);
            }
        }

        $takesJson = $route['consumes'] === [] || array_any($route['consumes'], self::isJsonType(...));

        if ($fromBody !== null && isset($parameters[$fromBody])) {
            $result['requestBody'] = self::buildFromBody($parameters[$fromBody], $route['consumes']);
        } elseif ($route['fromForm'] !== []) {
            $result['requestBody'] = self::buildForm($route['fromForm'], $parameters, $route['consumes']);
        } elseif (!$takesJson) {
            $result['requestBody'] = self::buildRawBody($route['consumes']);
        }

        // Without #[FromBody], these verbs take parameters from a JSON body
        if (!isset($result['requestBody']) && $takesJson && in_array($verb, self::BodyVerbs, true) && $others !== []) {
            $result['requestBody'] = self::buildBodyFromParameters($others);
        } else {
            foreach ($others as $name => $info) {
                $list[] = self::buildQueryParameter($name, $info);
            }
        }

        return ($list === [] ? [] : ['parameters' => $list]) + $result;
    }

    /**
     * @param array<string, mixed> $parameter
     * @return array<string, mixed>
     */
    private static function withDescription(array $parameter, string $description): array
    {
        return $description === '' ? $parameter : $parameter + ['description' => $description];
    }

    /**
     * @param ParameterInfo $info
     * @return array<string, mixed>
     */
    private static function buildQueryParameter(string $name, array $info): array
    {
        $parameter = self::withDescription(['name' => $name, 'in' => 'query', 'required' => $info['required'], 'schema' => $info['schema']], $info['description']);

        // Structured values travel as filter[field]=value
        if (in_array($info['type']->kind, [Type::ClassName, Type::Array, Type::Object, Type::Map, Type::Shape], true) && !self::isScalarClass($info['type'])) {
            $parameter += ['style' => 'deepObject', 'explode' => true];
        }

        return $parameter;
    }

    private static function isScalarClass(Type $type): bool
    {
        return $type->kind === Type::ClassName && (
            is_a((string) $type->class, \DateTimeInterface::class, true)
            || is_a((string) $type->class, \BackedEnum::class, true)
        );
    }

    /**
     * @param ParameterInfo $info
     * @param list<string> $consumes
     * @return array<string, mixed>
     */
    private static function buildFromBody(array $info, array $consumes): array
    {
        $content = [];
        foreach ($consumes ?: ['application/json'] as $type) {
            // Anything other than JSON reaches the parameter as it came
            $content[$type] = self::isJsonType($type) ? ['schema' => $info['schema']] : new \stdClass();
        }

        return self::withDescription(['required' => $info['required'], 'content' => $content], $info['description']);
    }

    private static function isJsonType(string $type): bool
    {
        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    /**
     * A body the method reads itself, such as a streamed file.
     *
     * @param list<string> $consumes
     * @return array<string, mixed>
     */
    private static function buildRawBody(array $consumes): array
    {
        $content = [];
        foreach ($consumes as $type) {
            $content[$type] = ['schema' => Consumes::isText($type) ? ['type' => 'string'] : ['type' => 'string', 'format' => 'binary']];
        }

        return ['required' => true, 'content' => $content];
    }

    /**
     * @param array<string, array{name: string, position: int}> $fields Parameter => field
     * @param array<string, ParameterInfo> $parameters
     * @param list<string> $consumes
     * @return array<string, mixed>
     */
    private static function buildForm(array $fields, array $parameters, array $consumes): array
    {
        $properties = [];
        $required = [];
        $hasFile = false;

        foreach ($fields as $name => $field) {
            $info = $parameters[$name] ?? null;
            if ($info === null) {
                continue;
            }
            $properties[$field['name']] = SchemaBuilder::extend($info['schema'], $info['description'] === '' ? [] : ['description' => $info['description']]);
            if ($info['required']) {
                $required[] = $field['name'];
            }
            $item = $info['type']->kind === Type::List ? $info['type']->items : $info['type'];
            $hasFile = $hasFile || ($item !== null && $item->kind === Type::ClassName && is_a((string) $item->class, FileUpload::class, true));
        }

        $schema = ['type' => 'object', 'properties' => $properties ?: new \stdClass()] + ($required === [] ? [] : ['required' => $required]);

        $content = [];
        foreach ($consumes as $type) {
            // Files can't travel in a URL-encoded form
            if (!$hasFile || $type !== 'application/x-www-form-urlencoded') {
                $content[$type] = ['schema' => $schema];
            }
        }

        return ['required' => $required !== [], 'content' => $content];
    }

    /**
     * @param array<string, ParameterInfo> $parameters
     * @return array<string, mixed>
     */
    private static function buildBodyFromParameters(array $parameters): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $name => $info) {
            $properties[$name] = SchemaBuilder::extend($info['schema'], $info['description'] === '' ? [] : ['description' => $info['description']]);
            if ($info['required']) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties] + ($required === [] ? [] : ['required' => $required]);

        return ['required' => $required !== [], 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * Types and constraints of a route decide what the path can hold; a plain
     * parameter is described by the method.
     *
     * @param PathParameter $parameter
     * @param ParameterInfo|null $info
     * @return array<string, mixed>|\stdClass
     */
    private static function buildPathSchema(array $parameter, ?array $info): array|\stdClass
    {
        $schema = match ($parameter['token']) {
            'int', 'long' => ['type' => 'integer'],
            'float', 'decimal', 'double' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'alpha' => ['type' => 'string', 'pattern' => '^[a-zA-Z]+$'],
            'guid' => ['type' => 'string', 'format' => 'uuid'],
            'intOrGuid' => ['anyOf' => [['type' => 'integer'], ['type' => 'string', 'format' => 'uuid']]],
            'regex' => ['type' => 'string', 'pattern' => '^(?:' . $parameter['expression'] . ')$'],
            default => $info['schema'] ?? ['type' => 'string'],
        };

        $extra = [];
        foreach ($parameter['checks'] as $check) {
            $values = $check['value'];
            $extra += match ($check['name']) {
                'min' => ['minimum' => $values[0]],
                'max' => ['maximum' => $values[0]],
                'range' => ['minimum' => $values[0], 'maximum' => $values[1]],
                'length' => ['minLength' => $values[0], 'maxLength' => $values[1] ?? $values[0]],
                'minlength' => ['minLength' => $values[0]],
                'maxlength' => ['maxLength' => $values[0]],
                default => [],
            };
        }

        return SchemaBuilder::extend($schema, $extra);
    }

    /**
     * @param RouteInfo $route
     * @param array<string, PathParameter> $pathParameters
     * @param array<string, ParameterInfo> $parameters
     * @return array<int|string, mixed>
     */
    private function buildResponses(
        MethodReader $method,
        array $route,
        array $pathParameters,
        array $parameters,
        bool $hasBody,
    ): array {
        $declared = [];
        foreach ($method->getAttributes(Response::class) as $response) {
            $declared[$response->code] = $this->buildDeclaredResponse($response, $method);
        }

        $hasSuccess = array_any(array_keys($declared), static fn(int $code): bool => $code >= 200 && $code < 300);
        $responses = $hasSuccess ? [] : $this->buildInferredResponses($method);

        $errors = [];
        if ($hasBody) {
            $errors[400] = true;
        }
        if ($route['authorize']) {
            $errors[401] = true;
        }
        if ($route['authorize'] && $route['roles'] !== []) {
            $errors[403] = true;
        }
        if ($pathParameters !== []) {
            $errors[404] = true;
        }
        if ($route['consumes'] !== []) {
            $errors[415] = true;
        }
        if ($parameters !== []) {
            $errors[422] = true;
        }

        foreach (array_keys($errors) as $code) {
            $responses[$code] = self::errorResponse(self::ErrorDescriptions[$code]);
        }

        foreach ($method->getThrows() as $throws) {
            $line = $throws->errorCode === null ? $throws->message : "`{$throws->errorCode}`: {$throws->message}";
            $responses[$throws->httpCode] = isset($responses[$throws->httpCode]['description']) && !isset($declared[$throws->httpCode])
                ? ['description' => $responses[$throws->httpCode]['description'] . "\n\n" . $line] + $responses[$throws->httpCode]
                : self::errorResponse($line);
        }

        $responses = $declared + $responses;
        ksort($responses);

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDeclaredResponse(Response $response, MethodReader $method): array
    {
        $result = ['description' => $response->description ?? (string) $response->code];
        $isSuccess = $response->code >= 200 && $response->code < 300;

        if ($response->content === null) {
            if ($response->code >= 400) {
                return self::errorResponse($result['description']);
            }
            $return = $method->getReturnType();
            if (!$isSuccess || $response->code === 204 || $return->kind === Type::Void || self::isApplicationResponse($return)) {
                return $result;
            }
            $schema = $method->getDescribedResponse() ?? $this->builder->build($return->withNullable(false));
        } else {
            $schema = $this->builder->buildDefinition($response->content);
        }

        $type = $response->contentType ?? ($response->content instanceof Binary ? 'application/octet-stream' : 'application/json');
        $result['content'] = [$type => ['schema' => $schema]];

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInferredResponses(MethodReader $method): array
    {
        $return = $method->getReturnType();

        $described = $method->getDescribedResponse();
        if ($described !== null) {
            return [200 => [
                'description' => 'Success.',
                'content' => ['application/json' => ['schema' => $described]],
            ]];
        }

        if ($return->kind === Type::Void) {
            return [204 => ['description' => 'No content.']];
        }

        if (self::isApplicationResponse($return)) {
            return [200 => ['description' => 'A response sent by the application.']];
        }

        $responses = [200 => [
            'description' => 'Success.',
            'content' => ['application/json' => ['schema' => $this->builder->build($return->withNullable(false))]],
        ]];

        if ($return->nullable && $return->kind !== Type::Mixed) {
            $responses[204] = ['description' => 'No content.'];
        }

        return $responses;
    }

    private static function isApplicationResponse(Type $type): bool
    {
        return $type->kind === Type::ClassName && is_a((string) $type->class, ApplicationResponse::class, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'required' => ['code', 'message'],
                    'properties' => [
                        'code' => ['type' => 'integer'],
                        'message' => ['type' => 'string'],
                        'fields' => [
                            'type' => 'object',
                            'description' => 'What is wrong with which value, for invalid parameters.',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
            ],
        ];
    }
}
