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

use Nette\InvalidStateException;

/**
 * Collects routes into the table used by Router, when the DI container compiles.
 *
 * Literal routes win over routes with parameters, regardless of the order of
 * controllers. The others are ordered segment by segment: literal text, then a
 * constrained parameter, a plain parameter and a catch-all.
 *
 * @phpstan-import-type Route from Router
 * @phpstan-import-type RouteTable from Router
 * @phpstan-import-type CompiledPath from RouteCompiler
 */
final class RouteTableBuilder
{
    /** @var array<string, array<string, Route>> */
    private array $literal = [];

    /** @var array<string, array{path: CompiledPath, verbs: array<string, Route>}> */
    private array $dynamic = [];

    /** @var array<string, string> "VERB shape" => description of the route's method */
    private array $taken = [];

    /**
     * @param list<string> $verbs
     * @param array<string, string> $params
     * @param Route $route
     */
    public function add(array $verbs, string $path, array $params, array $route, string $description): void
    {
        $compiled = RouteCompiler::compile($path, $params);

        foreach ($verbs as $verb) {
            $key = "{$verb} {$compiled['shape']}";
            if (isset($this->taken[$key])) {
                throw new InvalidStateException(
                    "Route {$verb} '{$path}' of {$description} is already defined by {$this->taken[$key]}.",
                );
            }
            $this->taken[$key] = $description;

            if ($compiled['literal'] !== null) {
                $this->literal[$compiled['literal']][$verb] = $route;
            } else {
                // Routes differing only in constraints stay apart: PUT {id:int} must not
                // take the min(1) away from GET {id:int:min(1)}
                $group = $compiled['regex'] . json_encode($compiled['checks']);
                $this->dynamic[$group]['path'] = $compiled;
                $this->dynamic[$group]['verbs'][$verb] = $route;
            }
        }
    }

    /**
     * @return RouteTable
     */
    public function build(): array
    {
        $dynamic = array_values($this->dynamic);
        usort(
            $dynamic,
            static fn(array $a, array $b): int => strcmp($a['path']['rank'], $b['path']['rank']),
        );

        return [
            'literal' => $this->literal,
            'dynamic' => array_map(static fn(array $entry): array => [
                'regex' => (string) $entry['path']['regex'],
                'checks' => $entry['path']['checks'],
                'verbs' => $entry['verbs'],
            ], $dynamic),
        ];
    }
}
