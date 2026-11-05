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
 * A content type the method accepts; repeat it for more. `image/*` covers a
 * whole group. A request with a body of any other type gets HTTP 415.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Consumes
{
    public readonly BodyType $bodyType;

    public function __construct(
        public readonly string $contentType,
        ?BodyType $bodyType = null,
    ) {
        $this->bodyType = $bodyType ?? (self::isText($contentType) ? BodyType::Text : BodyType::Binary);
    }

    private static function isText(string $contentType): bool
    {
        return str_starts_with($contentType, 'text/')
            || str_ends_with($contentType, '+json')
            || str_ends_with($contentType, '+xml')
            || in_array($contentType, [
                'application/json',
                'application/xml',
                'application/x-www-form-urlencoded',
                'multipart/form-data',
            ], true);
    }
}
