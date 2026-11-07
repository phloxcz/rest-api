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

namespace Phlox\RestApi\Testing;

use Phlox\ApiCore\Testing\SchemaValidator;
use Phlox\RestApi\OpenApi;

/**
 * Checks responses of an endpoint against its OpenAPI document, so tests notice
 * the documentation drifting from what the API returns:
 *
 *     $checker = OpenApiChecker::of($container->getByType(OpenApi::class), 'api/v1', '/api/v1');
 *     $errors = $checker->check('GET', '/api/v1/users?page=2', $status, $contentType, $body);
 *     Assert::same([], $errors);
 *
 * A status the operation doesn't document is an error, and so is JSON where none is documented.
 */
final class OpenApiChecker
{
    /** How many responses had a schema to be checked against */
    public int $checked = 0;

    /**
     * @param array<string, mixed> $document
     * @param string $prefix What comes before the paths of the document in a URL, such as `/api/v1`
     */
    public function __construct(
        private readonly array $document,
        private readonly string $prefix = '',
    ) {
    }

    public static function of(OpenApi $openApi, string $endpoint, string $prefix = ''): self
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($openApi->toJson($endpoint), true, flags: JSON_THROW_ON_ERROR);

        return new self($document, $prefix);
    }

    /**
     * @return list<string> What doesn't match; empty for a response the document doesn't cover, such as of another API
     */
    public function check(string $method, string $url, int $status, ?string $contentType, string $body): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!str_starts_with($path, $this->prefix)) {
            return [];
        }

        $where = "{$method} {$url} {$status}";
        $template = $this->findPath(substr($path, strlen($this->prefix)));
        if ($template === null) {
            return [];
        }

        $operation = $this->document['paths'][$template][strtolower($method)] ?? null;
        if ($operation === null) {
            return ["{$where}: {$template} has no {$method}"];
        }

        $response = $operation['responses'][(string) $status] ?? null;
        if ($response === null) {
            return ["{$where}: the status is not documented, only " . implode(', ', array_keys($operation['responses']))];
        }

        if (!str_contains((string) $contentType, 'json')) {
            return [];
        }

        $schema = $response['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return ["{$where}: JSON came, none is documented"];
        }

        $this->checked++;

        return SchemaValidator::validate(json_decode($body, true), $schema, $this->document, "{$where}: \$");
    }

    /**
     * The template of a path, preferring the one with the fewest parameters, so
     * `/tasks/summary` is not taken for `/tasks/{id}`.
     */
    private function findPath(string $path): ?string
    {
        $found = null;
        foreach (array_keys($this->document['paths'] ?? []) as $template) {
            $pattern = '~^' . preg_replace('~\\\\\{[^/]+?\\\\\}~', '[^/]+', preg_quote((string) $template, '~')) . '$~';
            if (preg_match($pattern, $path) === 1 && ($found === null || substr_count((string) $template, '{') < substr_count($found, '{'))) {
                $found = (string) $template;
            }
        }

        return $found;
    }
}
