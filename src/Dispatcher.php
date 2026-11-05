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

namespace Phlox\RestApi;

use Phlox\ApiCore\AbortException;
use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\ControllerInvoker;
use Phlox\ApiCore\ErrorReporter;
use Phlox\ApiCore\Handler;
use Phlox\RestApi\Routing\Router;

/**
 * REST protocol: turns a request into a Result.
 *
 * Works with plain values and never exits, see Server for the HTTP side. No
 * exception leaves it.
 *
 * The content type and JSON syntax are checked before authorization, because the
 * parameters of a call depend on them. Neither says anything about the method.
 *
 * @phpstan-import-type Route from Router
 * @phpstan-import-type RouteTable from Router
 * @phpstan-type Endpoint array{jsonForceArray: bool, routes: RouteTable}
 */
final class Dispatcher
{
    private const BodyParameterVerbs = ['POST', 'PUT', 'PATCH'];

    /**
     * @param array<string, Endpoint> $endpoints
     */
    public function __construct(
        private readonly ControllerInvoker $invoker,
        private readonly ErrorReporter $errorReporter,
        private readonly OpenApi $documents,
        private readonly array $endpoints,
        private readonly bool $debugMode = false,
    ) {
    }

    /**
     * @param string $verb HTTP method in uppercase
     * @param string $path Path within the endpoint, without surrounding slashes
     * @param array<int|string, mixed> $query
     * @param string|(\Closure(): ?string)|null $body A closure is called only when the body is needed,
     *        so a controller can stream a large upload from php://input itself
     */
    public function handle(
        string $endpoint,
        string $verb,
        string $path,
        array $query = [],
        ?string $contentType = null,
        string|\Closure|null $body = null,
    ): Result {
        $config = $this->endpoints[$endpoint]
            ?? throw new \InvalidArgumentException("Unknown endpoint '{$endpoint}'.");

        try {
            $match = Router::match($config['routes'], $verb, $path);

            if ($match->route === null) {
                return $this->handleNoRoute($verb, $path, $match->allowedVerbs);
            }

            $route = $match->route;

            if (isset($route['documentation'])) {
                return $route['documentation'] === 'yaml'
                    ? new Result($this->documents->toYaml($endpoint), rawContentType: 'application/yaml')
                    : new Result($this->documents->toJson($endpoint), rawContentType: 'application/json');
            }

            $type = self::normalizeContentType($contentType);
            $typeGiven = trim((string) $contentType) !== '';
            $bodyVerb = in_array($verb, self::BodyParameterVerbs, true);

            // Anything else, such as a binary upload, stays unread for the controller to stream
            if ($route['consumes'] !== [] || $route['fromBody'] !== null || ($bodyVerb && self::isJson($type))) {
                $body = $body instanceof \Closure ? $body() : $body;
            } elseif ($body instanceof \Closure) {
                $body = null;
            }

            $hasBody = $body !== null && $body !== '';

            if ($hasBody && $route['consumes'] !== [] && !self::isAccepted($type, $route['consumes'])) {
                throw new ApiException(
                    ApiException::E_INVALID_REQUEST,
                    'Unsupported media type.',
                    internal: 'Accepted: ' . implode(', ', $route['consumes']) . '.',
                    httpCode: 415,
                );
            }

            $isJson = $hasBody && self::isJson($type);

            // Values from the path can't be overridden by the query, nor those by the body
            $parameters = $match->parameters + $query;
            $jsonData = null;
            if ($isJson && $bodyVerb) {
                // A body with no type may well be something else than JSON, so it's no error here
                $decoded = $typeGiven || $route['fromBody'] !== null ? self::decodeJson($body) : json_decode($body, true);
                if (is_array($decoded)) {
                    // Only by name: items of a list must not fill parameters by their position
                    $parameters += array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY);
                    $jsonData = $config['jsonForceArray'] ? $decoded : self::decodeJson($body, false);
                }
            }

            $prebound = [];
            if ($route['fromBody'] !== null) {
                $fromBody = $route['fromBody'];
                // It's only ever filled from the body, not by a query parameter of the same name
                unset($parameters[$fromBody['name']], $parameters[$fromBody['position']]);
                if ($hasBody) {
                    $prebound[$fromBody['name']] = $isJson ? self::decodeJson($body, $fromBody['assoc']) : $body;
                }
            }

            return new Result($this->invoker->invoke(
                Handler::fromArray($route['handler']),
                $parameters,
                $jsonData,
                $prebound,
            ));
        } catch (AbortException) {
            return new Result(aborted: true);
        } catch (ApiException $e) {
            return $this->buildError($e);
        } catch (\Throwable $e) {
            $this->errorReporter->report($e);
            return $this->buildError(new ApiException(ApiException::E_INTERNAL_ERROR, internal: $e->getMessage()));
        }
    }

    /**
     * @param list<string> $allowedVerbs
     * @throws ApiException
     */
    private function handleNoRoute(string $verb, string $path, array $allowedVerbs): Result
    {
        if ($verb === 'OPTIONS') {
            return new Result(); // a CORS preflight, unless the method declares #[HttpOptions]
        }

        if ($allowedVerbs === []) {
            throw new ApiException(ApiException::E_METHOD_NOT_FOUND, internal: "No route for '{$path}'.");
        }

        $error = $this->buildError(new ApiException(
            ApiException::E_METHOD_NOT_FOUND,
            'Method not allowed.',
            httpCode: 405,
        ));

        return new Result($error->data, $error->status, ['Allow' => implode(', ', $allowedVerbs)]);
    }

    private function buildError(ApiException $e): Result
    {
        $message = $e->getMessage();
        if ($this->debugMode && $e->getInternalMessage() !== null) {
            $message .= ' ' . $e->getInternalMessage();
        }

        return new Result(
            ['error' => [...$e->getData(), 'code' => $e->getCode(), 'message' => $message]],
            $e->getHttpCode(),
        );
    }

    /**
     * @throws ApiException
     */
    private static function decodeJson(string $body, bool $assoc = true): mixed
    {
        try {
            return json_decode($body, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(ApiException::E_PARSE_ERROR, internal: $e->getMessage(), previous: $e);
        }
    }

    /**
     * Lowercase media type without parameters such as "; charset=utf-8".
     */
    private static function normalizeContentType(?string $contentType): string
    {
        $type = strtolower(trim(explode(';', $contentType ?? '')[0]));

        return $type === '' ? 'application/json' : $type;
    }

    private static function isJson(string $type): bool
    {
        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    /**
     * @param list<string> $accepted
     */
    private static function isAccepted(string $type, array $accepted): bool
    {
        foreach ($accepted as $candidate) {
            $candidate = strtolower($candidate);
            if ($candidate === $type || (str_ends_with($candidate, '/*') && str_starts_with($type, substr($candidate, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
