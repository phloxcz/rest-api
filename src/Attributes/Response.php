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
 * Documents a response that can't be read from the signature, such as `201`, or
 * a file sent by a Nette\Application\Response. A successful response declared
 * here replaces the one read from the return type.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Response
{
    /**
     * @param string|array<int|string, mixed>|null $content A type such as `list<UserDto>`, JSON Schema or `@binary`;
     *        without it, a successful response takes the return type (see ContentDefinition)
     */
    public function __construct(
        public readonly int $code,
        public readonly string|array|null $content = null,
        public readonly ?string $contentType = null,
        public readonly ?string $description = null,
    ) {
    }
}
