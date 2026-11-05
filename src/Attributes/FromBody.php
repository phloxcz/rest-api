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
 * Fills the parameter with the whole request body: decoded when it's JSON, as a
 * string otherwise. Without #[Consumes], the method accepts application/json.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class FromBody
{
}
