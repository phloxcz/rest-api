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

use Phlox\ApiCore\ProtocolError;
use Phlox\ApiCore\Attributes\ErrorCase;
use Nette\Application\Response as ApplicationResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\ErrorReporter;
use Phlox\ApiCore\Http\CorsHandler;

/**
 * HTTP side of the REST API: recognizes requests to an endpoint and sends the
 * response.
 *
 * @phpstan-type AccessControl array{allowedOrigins: list<string>, allowCredentials: bool, allowMethods: list<string>, allowHeaders: list<string>, exposeHeaders: list<string>, maxAge: int}
 */
final class Server
{
    /**
     * @param array<string, AccessControl> $accessControl Endpoint name => CORS config
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CorsHandler $cors,
        private readonly IRequest $request,
        private readonly IResponse $response,
        private readonly ErrorReporter $errorReporter,
        private readonly array $accessControl,
    ) {
    }

    /**
     * @return bool Whether the request was handled, i.e. was aimed at an endpoint
     */
    public function process(): bool
    {
        $url = $this->request->getUrl();
        $link = trim(substr($url->getPath(), strlen($url->getBasePath())), '/');

        $endpoint = $this->findEndpoint($link);
        if ($endpoint === null) {
            return false;
        }

        $this->cors->apply($this->accessControl[$endpoint]);

        $verb = strtoupper($this->request->getMethod());
        $result = $this->dispatcher->handle(
            $endpoint,
            $verb,
            substr($link, strlen($endpoint) + 1),
            $this->request->getQuery(),
            $this->request->getHeader('content-type'),
            $this->request->getRawBody(...),
            $this->request->getHeaders(),
            array_replace_recursive($this->request->getPost(), $this->request->getFiles()),
        );

        if (!$result->aborted) {
            $this->send($result, $verb);
        }

        return true;
    }

    /**
     * The longest match, so that "api" and "api/v2" can live side by side.
     */
    private function findEndpoint(string $link): ?string
    {
        $found = null;

        foreach ($this->accessControl as $endpoint => $_) {
            $endpoint = (string) $endpoint;
            if (str_starts_with($link, $endpoint . '/') && strlen($endpoint) > strlen((string) $found)) {
                $found = $endpoint;
            }
        }

        return $found;
    }

    private function send(Result $result, string $verb): void
    {
        foreach ($result->headers as $name => $value) {
            $this->response->setHeader($name, $value);
        }

        if ($result->data instanceof ApplicationResponse) {
            $this->sendApplicationResponse($result->data);
            return;
        }

        if ($result->rawContentType !== null && is_string($result->data)) {
            $this->response->setContentType($result->rawContentType, 'utf-8');
            if ($verb !== 'HEAD') {
                echo $result->data;
            }
            return;
        }

        if ($result->data === null) {
            // A controller which wrote the response itself, with readfile() or print, has nothing to add
            if (headers_sent() || (ob_get_level() > 0 && ob_get_length() > 0)) {
                return;
            }

            // A controller may have set e.g. 201 itself
            $this->response->setCode($result->status ?? (
                $this->response->getCode() === IResponse::S200_OK ? IResponse::S204_NoContent : $this->response->getCode()
            ));
            return;
        }

        $output = $this->encode($result);

        $this->response->setContentType('application/json', 'utf-8');
        if ($verb !== 'HEAD') {
            echo $output;
        }
    }

    private function sendApplicationResponse(ApplicationResponse $response): void
    {
        try {
            $response->send($this->request, $this->response);
        } catch (\Throwable $e) {
            $this->errorReporter->report($e);
            if (!$this->response->isSent()) {
                $this->response->setCode(IResponse::S500_InternalServerError);
            }
        }
    }

    /**
     * Sets the status too, because a value that can't be encoded means a failure.
     */
    private function encode(Result $result): string
    {
        try {
            $output = Json::encode($result->data);
        } catch (JsonException $e) {
            // A controller returned something unencodable, e.g. malformed UTF-8
            $this->errorReporter->report($e);
            $this->response->setCode(IResponse::S500_InternalServerError);

            return Json::encode([
                'error' => ['code' => ProtocolError::InternalError->value, 'message' => ErrorCase::of(ProtocolError::InternalError)->message],
            ]);
        }

        if ($result->status !== null) {
            $this->response->setCode($result->status);
        }

        return $output;
    }
}
