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

namespace Phlox\RestApi;

/**
 * What a request resulted in, for the Server to send.
 */
final readonly class Result
{
    /**
     * @param mixed $data Value returned by a controller, or an error structure
     * @param int|null $status Null leaves the status up to the controller
     * @param array<string, string> $headers
     * @param bool $aborted The controller has sent the response itself
     * @param string|null $rawContentType Type of $data, which is a finished string rather than a value for JSON
     */
    public function __construct(
        public mixed $data = null,
        public ?int $status = null,
        public array $headers = [],
        public bool $aborted = false,
        public ?string $rawContentType = null,
    ) {
    }
}
