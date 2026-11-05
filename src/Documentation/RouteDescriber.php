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

use Phlox\RestApi\Routing\ParamChecker;
use Phlox\RestApi\Routing\RouteCompiler;

/**
 * Turns a route into OpenAPI paths. OpenAPI has no optional path parameters, so a
 * route with one becomes a path for every combination.
 *
 * @phpstan-import-type Check from ParamChecker
 * @phpstan-type PathParameter array{name: string, token: string, expression: ?string, checks: list<Check>}
 * @phpstan-type PathVariant array{template: string, parameters: array<string, PathParameter>}
 */
final class RouteDescriber
{
    /**
     * @param array<string, string> $params Regular expressions of `regex` parameters
     * @return list<PathVariant>
     */
    public static function describe(string $path, array $params): array
    {
        $variants = [['template' => '', 'parameters' => []]];

        foreach ($path === '' ? [] : explode('/', trim($path, '/')) as $segment) {
            $parts = RouteCompiler::splitSegment($segment);
            $alone = count($parts) === 1;
            $choices = [['template' => '/', 'parameters' => []]];

            foreach ($parts as $part) {
                if (!str_starts_with($part, '{')) {
                    $choices = array_map(static fn(array $choice): array => [
                        'template' => $choice['template'] . $part,
                        'parameters' => $choice['parameters'],
                    ], $choices);
                    continue;
                }

                $parameter = self::readParameter($part, $params);
                $next = [];
                foreach ($choices as $choice) {
                    $next[] = [
                        'template' => "{$choice['template']}{{$parameter['name']}}",
                        'parameters' => $choice['parameters'] + [$parameter['name'] => $parameter],
                    ];
                    if ($part[1] === '?') {
                        $next[] = $alone ? ['template' => '', 'parameters' => $choice['parameters']] : $choice;
                    }
                }
                $choices = $next;
            }

            $combined = [];
            foreach ($variants as $variant) {
                foreach ($choices as $choice) {
                    $combined[] = [
                        'template' => $variant['template'] . $choice['template'],
                        'parameters' => $variant['parameters'] + $choice['parameters'],
                    ];
                }
            }
            $variants = $combined;
        }

        return array_values(array_map(static fn(array $variant): array => [
            'template' => $variant['template'] === '' ? '/' : $variant['template'],
            'parameters' => $variant['parameters'],
        ], $variants));
    }

    /**
     * @param array<string, string> $params
     * @return PathParameter
     */
    private static function readParameter(string $definition, array $params): array
    {
        // RouteCompiler has already refused anything malformed
        $tokens = explode(':', trim($definition, '{}'));
        $name = ltrim((string) array_shift($tokens), '?');
        $type = 'string';
        $checks = [];

        foreach ($tokens as $token) {
            $check = ParamChecker::parse($token);
            if ($check === null) {
                $type = $token;
            } else {
                $checks[] = $check;
            }
        }

        return [
            'name' => $name,
            'token' => $type,
            'expression' => $type === 'regex' ? ($params[$name] ?? null) : null,
            'checks' => $checks,
        ];
    }
}
