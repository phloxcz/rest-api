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
 * Result of routing: a route, or the verbs the path would accept. With neither,
 * the path doesn't exist.
 *
 * @phpstan-import-type Route from Router
 */
final readonly class RouteMatch
{
    /**
     * @param Route|null $route
     * @param array<string, string> $parameters Decoded parameters from the path
     * @param list<string> $allowedVerbs
     */
    public function __construct(
        public ?array $route = null,
        public array $parameters = [],
        public array $allowedVerbs = [],
    ) {
    }
}
