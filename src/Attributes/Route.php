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

namespace Phlox\RestApi\Attributes;

use Attribute;

/**
 * Path of a controller or a method, overriding the one derived from its name.
 *
 * Example: `users/{id:int:min(1)}`, `files/{?path:all}`, `{code:regex}` with
 * `['code' => '[A-Z]{3}']`. See RouteCompiler for the syntax.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Route
{
    /**
     * @param array<string, string> $params Regular expressions of `regex` parameters
     */
    public function __construct(
        public readonly string $path,
        public readonly array $params = [],
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
