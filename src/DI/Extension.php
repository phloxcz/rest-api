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

namespace Phlox\RestApi\DI;

use Nette;
use Nette\Schema\Expect;
use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Binding\ParameterBinder;
use Phlox\ApiCore\ControllerInvoker;
use Phlox\ApiCore\DI\ControllerScanner;
use Phlox\ApiCore\DI\ServerStartup;
use Phlox\ApiCore\Documentation\DocumentationConfig;
use Phlox\ApiCore\ErrorReporter;
use Phlox\ApiCore\Http\CorsHandler;
use Phlox\ApiCore\Security\Authorizer;
use Phlox\ApiCore\Types\DtoMeta;
use Phlox\RestApi\Attributes\Consumes;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\FromForm;
use Phlox\RestApi\Attributes\FromHeader;
use Phlox\RestApi\Attributes\HttpVerb;
use Phlox\RestApi\Attributes\Route;
use Phlox\RestApi\Dispatcher;
use Phlox\RestApi\Documentation\OpenApiGenerator;
use Phlox\RestApi\OpenApi;
use Phlox\RestApi\Routing\RouteTableBuilder;
use Phlox\RestApi\Server;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Registers the REST server. Controllers are ordinary services; the route table
 * is built from them when the container compiles.
 *
 * @phpstan-import-type RouteInfo from OpenApiGenerator
 * @property-read \stdClass $config
 */
final class Extension extends Nette\DI\CompilerExtension
{
    /** Swagger UI on jsDelivr, at a fixed version */
    public const SwaggerUi = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14';

    private bool $hookedToApplication = false;

    public function getConfigSchema(): Nette\Schema\Schema
    {
        return Expect::structure([
            'debug' => Expect::bool()->nullable(),
            'dateTimeFormats' => Expect::listOf('string')->default(ParameterBinder::DefaultDateTimeFormats),
            'endpoints' => Expect::arrayOf(
                Expect::structure([
                    'namespace' => Expect::anyOf(Expect::string(), Expect::listOf('string'))
                        ->required()
                        ->before(static fn(mixed $value): array => (array) $value),
                    'authorize' => Expect::bool(true),
                    'jsonForceArray' => Expect::bool(true),
                    'accessControl' => CorsHandler::getConfigSchema(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']),
                    'documentation' => DocumentationConfig::getSchema([
                        'path' => Expect::string('openapi'),
                        'securitySchemes' => Expect::array(),
                        'ui' => Expect::bool(true),
                        'uiAssets' => Expect::string(self::SwaggerUi),
                    ]),
                ]),
                'string',
            )->required(),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        foreach ($this->config->endpoints as $name => $endpoint) {
            CorsHandler::assertValidConfig($endpoint->accessControl, $name);
        }

        // Not autowired, so the RPC library can live in the same container
        $binder = $builder->addDefinition($this->prefix('binder'))
            ->setFactory(ParameterBinder::class, [$this->config->dateTimeFormats])
            ->setAutowired(false);

        $authorizer = $builder->addDefinition($this->prefix('authorizer'))
            ->setFactory(Authorizer::class)
            ->setAutowired(false);

        $invoker = $builder->addDefinition($this->prefix('invoker'))
            ->setFactory(ControllerInvoker::class, ['binder' => $binder, 'authorizer' => $authorizer])
            ->setAutowired(false);

        $cors = $builder->addDefinition($this->prefix('cors'))
            ->setFactory(CorsHandler::class)
            ->setAutowired(false);

        $errorReporter = $builder->addDefinition($this->prefix('errorReporter'))
            ->setFactory(ErrorReporter::class)
            ->setAutowired(false);

        // Documents and the route table are filled in beforeCompile(), once controllers are registered
        $documents = $builder->addDefinition($this->prefix('openApi'))
            ->setFactory(OpenApi::class);

        $dispatcher = $builder->addDefinition($this->prefix('dispatcher'))
            ->setFactory(Dispatcher::class, [
                'invoker' => $invoker,
                'errorReporter' => $errorReporter,
                'documents' => $documents,
                'debugMode' => $this->config->debug ?? $builder->parameters['debugMode'] ?? false,
            ]);

        $builder->addDefinition($this->prefix('server'))
            ->setFactory(Server::class, [
                'dispatcher' => $dispatcher,
                'cors' => $cors,
                'errorReporter' => $errorReporter,
                'accessControl' => array_map(
                    static fn(\stdClass $endpoint): array => $endpoint->accessControl,
                    $this->config->endpoints,
                ),
            ]);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();

        $endpoints = [];
        $documents = [];
        foreach ($this->config->endpoints as $name => $endpoint) {
            $table = new RouteTableBuilder();
            $routes = $this->collectRoutes($builder, $endpoint, $table);

            if ($endpoint->documentation->enabled) {
                $documents[$name] = $this->generateDocument($builder, (string) $name, $endpoint->documentation, $routes);
                $this->exposeDocument($builder, $table, $endpoint->documentation, (string) $name);
            }

            $endpoints[$name] = [
                'jsonForceArray' => $endpoint->jsonForceArray,
                'routes' => $table->build(),
            ];
        }

        /** @var Nette\DI\Definitions\ServiceDefinition $dispatcher */
        $dispatcher = $builder->getDefinition($this->prefix('dispatcher'));
        $dispatcher->setArgument('endpoints', $endpoints);

        /** @var Nette\DI\Definitions\ServiceDefinition $openApi */
        $openApi = $builder->getDefinition($this->prefix('openApi'));
        $openApi->setArgument('documents', $documents);

        $this->hookedToApplication = ServerStartup::hookApplication($builder, $this->prefix('server'));
    }

    public function afterCompile(Nette\PhpGenerator\ClassType $class): void
    {
        if (!$this->hookedToApplication) {
            ServerStartup::hookContainer($class, $this->prefix('server'));
        }
    }

    /**
     * @param list<RouteInfo> $routes
     */
    private function generateDocument(Nette\DI\ContainerBuilder $builder, string $name, \stdClass $config, array $routes): string
    {
        $generator = new OpenApiGenerator();
        $document = $generator->generate($name, $config, $routes);

        // A changed DTO recompiles the container in debug mode, as a changed controller does
        foreach ($generator->getClasses() as $class) {
            $builder->addDependency(new ReflectionClass($class));
        }

        return Nette\Utils\Json::encode($document);
    }

    /**
     * Served as ordinary routes, so a clash with a controller's route is refused
     * like any other. Published only in debug mode unless configured otherwise.
     */
    private function exposeDocument(Nette\DI\ContainerBuilder $builder, RouteTableBuilder $table, \stdClass $config, string $endpoint): void
    {
        if (!($config->expose ?? $builder->parameters['debugMode'] ?? false)) {
            return;
        }

        $path = trim($config->path, '/');
        $table->add(['GET'], "{$path}.json", [], ['documentation' => 'json'], 'the OpenAPI document');
        $table->add(['GET'], "{$path}.yaml", [], ['documentation' => 'yaml'], 'the OpenAPI document');

        if ($config->ui) {
            $page = self::buildSwaggerUi(basename($path) . '.json', DocumentationConfig::buildInfo($config->info, $endpoint)['title'], $config->uiAssets);
            $table->add(['GET'], $path, [], ['documentation' => 'ui', 'page' => $page], 'Swagger UI of the OpenAPI document');
        }
    }

    /**
     * Swagger UI reading the document next to it. Its scripts come from a CDN, or from
     * `uiAssets` for a network with no way out.
     */
    private static function buildSwaggerUi(string $document, string $title, string $assets): string
    {
        $assets = rtrim($assets, '/');
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
        $documentJs = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$e($title)}</title>
                <link rel="stylesheet" href="{$e($assets)}/swagger-ui.css">
            </head>
            <body>
                <div id="swagger-ui"></div>
                <script src="{$e($assets)}/swagger-ui-bundle.js"></script>
                <script>
                    window.ui = SwaggerUIBundle({url: {$documentJs}, dom_id: '#swagger-ui', deepLinking: true, persistAuthorization: true});
                </script>
            </body>
            </html>
            HTML;
    }

    /**
     * Fills the route table and returns the routes for documentation.
     *
     * @return list<RouteInfo>
     */
    private function collectRoutes(Nette\DI\ContainerBuilder $builder, \stdClass $endpoint, RouteTableBuilder $table): array
    {
        $routes = [];

        foreach (ControllerScanner::findControllers($builder, $endpoint->namespace) as $service => $class) {
            foreach (ControllerScanner::getApiMethods($class) as $method) {
                $description = "{$class->getName()}::{$method->getName()}()";

                $verbs = array_map(
                    static fn(ReflectionAttribute $attribute): string => $attribute->newInstance()->getHttpVerb(),
                    $method->getAttributes(HttpVerb::class, ReflectionAttribute::IS_INSTANCEOF),
                );

                if ($verbs === []) {
                    if ($method->getAttributes(Route::class) !== []) {
                        throw new Nette\InvalidStateException(
                            "{$description} has #[Route] but no HTTP verb. Add #[HttpGet], #[HttpPost] or another one.",
                        );
                    }
                    continue;
                }

                [$path, $params] = self::buildPath($class, $method);
                $fromBody = self::findFromBody($method, $description);
                $fromHeader = self::findNamed($method, FromHeader::class, $description);
                $fromForm = self::findNamed($method, FromForm::class, $description);
                if ($fromBody !== null && $fromForm !== []) {
                    throw new Nette\InvalidStateException("{$description}: a body is either #[FromBody] or #[FromForm], not both.");
                }
                $handler = ControllerScanner::createHandler($service, $class, $method, $endpoint->authorize);
                $consumes = self::buildConsumes($method, $fromBody, $fromForm !== []);

                $table->add($verbs, $path, $params, [
                    'handler' => $handler->toArray(),
                    'consumes' => $consumes,
                    'fromBody' => $fromBody,
                    'fromHeader' => $fromHeader,
                    'fromForm' => $fromForm,
                ], $description);

                $routes[] = [
                    'verbs' => $verbs,
                    'path' => $path,
                    'params' => $params,
                    'class' => $class->getName(),
                    'method' => $method->getName(),
                    'authorize' => $handler->authorize,
                    'roles' => $handler->roles,
                    'consumes' => $consumes,
                    'fromBody' => $fromBody,
                    'fromHeader' => $fromHeader,
                    'fromForm' => $fromForm,
                ];
            }
        }

        return $routes;
    }

    /**
     * @param ReflectionClass<ApiController> $class
     * @return array{string, array<string, string>} Path and regular expressions of its parameters
     */
    private static function buildPath(ReflectionClass $class, ReflectionMethod $method): array
    {
        $classRoute = self::getRoute($class);
        $methodRoute = self::getRoute($method);

        $classPath = $classRoute === null
            ? self::toKebabCase(preg_replace('/Controller$/', '', $class->getShortName()) ?? '')
            : trim($classRoute->path, '/');
        $methodPath = $methodRoute === null
            ? self::toKebabCase($method->getName())
            : trim($methodRoute->path, '/');

        return [
            implode('/', array_filter([$classPath, $methodPath], static fn(string $part): bool => $part !== '')),
            ($methodRoute === null ? [] : $methodRoute->params) + ($classRoute === null ? [] : $classRoute->params),
        ];
    }

    /**
     * @param ReflectionClass<ApiController>|ReflectionMethod $reflection
     */
    private static function getRoute(ReflectionClass|ReflectionMethod $reflection): ?Route
    {
        $attributes = $reflection->getAttributes(Route::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private static function toKebabCase(string $name): string
    {
        return preg_replace_callback(
            '/[A-Z]/',
            static fn(array $match): string => '-' . strtolower($match[0]),
            lcfirst($name),
        ) ?? $name;
    }

    /**
     * @return array{name: string, position: int, assoc: bool}|null
     */
    private static function findFromBody(ReflectionMethod $method, string $description): ?array
    {
        $found = null;

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getAttributes(FromBody::class) === []) {
                continue;
            }

            if ($found !== null) {
                throw new Nette\InvalidStateException("{$description}: only one parameter can have #[FromBody].");
            }

            $found = [
                'name' => $parameter->getName(),
                'position' => $parameter->getPosition(),
                'assoc' => self::acceptsArray($parameter->getType()),
            ];
        }

        return $found;
    }

    /**
     * Parameters filled from a header or a form field, by the attribute's name or their own.
     *
     * @param class-string<FromHeader|FromForm> $attribute
     * @return array<string, array{name: string, position: int}> Parameter => header or field
     */
    private static function findNamed(ReflectionMethod $method, string $attribute, string $description): array
    {
        $found = [];

        foreach ($method->getParameters() as $parameter) {
            $attributes = $parameter->getAttributes($attribute);
            if ($attributes === []) {
                continue;
            }

            $short = substr($attribute, strrpos($attribute, '\\') + 1);
            foreach ([FromBody::class, FromHeader::class, FromForm::class] as $other) {
                if ($other !== $attribute && $parameter->getAttributes($other) !== []) {
                    $otherShort = substr($other, strrpos($other, '\\') + 1);
                    throw new Nette\InvalidStateException("{$description}: \${$parameter->getName()} has both #[{$short}] and #[{$otherShort}].");
                }
            }

            $name = $attributes[0]->newInstance()->name;
            if ($name === null) {
                $name = $parameter->getName();
                if ($attribute === FromHeader::class) {
                    // $ifMatch is If-Match
                    $name = implode('-', array_map(ucfirst(...), explode('-', self::toKebabCase($name))));
                }
            }

            $found[$parameter->getName()] = ['name' => $name, 'position' => $parameter->getPosition()];
        }

        return $found;
    }

    /**
     * Whether a JSON body should be decoded into an array rather than an object.
     */
    private static function acceptsArray(?\ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $single) {
            if ($single instanceof ReflectionNamedType && ($single->getName() === 'array' || DtoMeta::isDto($single->getName()))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{name: string, position: int, assoc: bool}|null $fromBody
     * @return list<string>
     */
    private static function buildConsumes(ReflectionMethod $method, ?array $fromBody, bool $fromForm): array
    {
        $consumes = array_map(
            static fn(ReflectionAttribute $attribute): string => $attribute->newInstance()->contentType,
            $method->getAttributes(Consumes::class),
        );

        return match (true) {
            $consumes !== [] => $consumes,
            $fromForm => ['multipart/form-data', 'application/x-www-form-urlencoded'],
            $fromBody !== null => ['application/json'],
            default => [],
        };
    }
}
