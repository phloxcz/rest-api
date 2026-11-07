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
 * Fills the parameter with a request header, converted to its type as a query
 * parameter is. Only the header fills it, never a parameter of the same name.
 *
 *     #[FromHeader('X-Request-Id')] ?string $requestId = null
 *     #[FromHeader] string $ifMatch     // the header If-Match
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class FromHeader
{
    /**
     * @param string|null $name The header; by default the name of the parameter, so `$ifMatch` is `If-Match`
     */
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
