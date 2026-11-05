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

/**
 * Makes a controller method an API endpoint for the verb given by the class
 * name. A method can have several of them.
 */
abstract class HttpVerb
{
    public function getHttpVerb(): string
    {
        $parts = explode('\\', static::class);

        return strtoupper(substr(end($parts), 4));
    }
}
