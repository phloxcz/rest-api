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
 * Fills the parameter with a field of a submitted form, `multipart/form-data` or
 * `application/x-www-form-urlencoded`. A file comes as Nette\Http\FileUpload:
 *
 *     public function upload(#[FromForm] FileUpload $file, #[FromForm] string $title): array
 *
 * PHP reads forms of POST requests, and the server reads those of PUT and PATCH
 * by request_parse_body().
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class FromForm
{
    /**
     * @param string|null $name The field; by default the name of the parameter
     */
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
