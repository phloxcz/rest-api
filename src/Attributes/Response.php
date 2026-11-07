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
use Phlox\ApiCore\Schema\SchemaDefinition;

/**
 * Documents a response the native return type can't tell:
 *
 *     #[Response(200, new Shape(['value' => new Paged(User::class)]))]
 *     #[Response(201)]                                // the return type, with another code
 *     #[Response(200, new Binary, contentType: 'application/pdf')]
 *     #[Response(404, description: 'No such user')]   // the schema of errors
 *
 * A successful response declared here replaces the one read from the method.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Response
{
    /**
     * @param SchemaDefinition|string|null $content An object from Phlox\ApiCore\Schema, a class as `User::class`, or a scalar
     *        as `'int'`; without it, a success takes the return type or #[QueryOf], and an error from 400 the schema of errors
     */
    public function __construct(
        public readonly int $code,
        public readonly SchemaDefinition|string|null $content = null,
        public readonly ?string $contentType = null,
        public readonly ?string $description = null,
    ) {
    }
}
