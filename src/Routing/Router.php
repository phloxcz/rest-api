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

namespace Phlox\RestApi\Routing;

/**
 * Finds the route of a request in a table compiled into the DI container.
 *
 * @phpstan-import-type Check from ParamChecker
 * @phpstan-type HandlerData array{service: string, class: class-string<\Phlox\ApiCore\ApiController>, method: string, authorize: bool, roles: list<list<string>>}
 * @phpstan-type ControllerRoute array{handler: HandlerData, consumes: list<string>, fromBody: array{name: string, position: int, assoc: bool}|null}
 * @phpstan-type DocumentationRoute array{documentation: 'json'|'yaml'}
 * @phpstan-type Route ControllerRoute|DocumentationRoute
 * @phpstan-type RouteTable array{literal: array<string, array<string, Route>>, dynamic: list<array{regex: string, checks: array<string, list<Check>>, verbs: array<string, Route>}>}
 */
final class Router
{
    /**
     * @param RouteTable $table
     * @param string $path Path within the endpoint, without surrounding slashes
     */
    public static function match(array $table, string $verb, string $path): RouteMatch
    {
        $allowed = [];

        if (isset($table['literal'][$path])) {
            $verbs = $table['literal'][$path];
            if (isset($verbs[$verb])) {
                return new RouteMatch($verbs[$verb]);
            }
            $allowed = array_keys($verbs);
        }

        $subject = $path === '' ? '' : '/' . $path;

        foreach ($table['dynamic'] as $route) {
            if (preg_match($route['regex'], $subject, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
                continue;
            }

            $parameters = self::readParameters($matches, $route['checks']);
            if ($parameters === null) {
                continue;
            }

            if (isset($route['verbs'][$verb])) {
                return new RouteMatch($route['verbs'][$verb], $parameters);
            }
            array_push($allowed, ...array_keys($route['verbs']));
        }

        return new RouteMatch(allowedVerbs: array_values(array_unique($allowed)));
    }

    /**
     * @param array<int|string, string|null> $matches
     * @param array<string, list<Check>> $checks
     * @return array<string, string>|null Null when a value doesn't fit the route
     */
    private static function readParameters(array $matches, array $checks): ?array
    {
        $parameters = [];

        foreach ($matches as $name => $value) {
            // An optional parameter that's missing gets the default of the method's parameter
            if (!is_string($name) || $value === null) {
                continue;
            }

            $decoded = rawurldecode($value);
            if (!preg_match('//u', $decoded)) {
                return null;
            }

            foreach ($checks[$name] ?? [] as $check) {
                if (!ParamChecker::check($decoded, $check)) {
                    return null;
                }
            }

            $parameters[$name] = $decoded;
        }

        return $parameters;
    }
}
