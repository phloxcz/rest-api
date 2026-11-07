<?php

/**
 * The HTTP side: which requests are handled and what's actually sent.
 */

declare(strict_types=1);

use Nette\Http\IResponse;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Phlox\ApiCore\ErrorReporter;
use Phlox\ApiCore\Http\CorsHandler;
use Phlox\RestApi\Dispatcher;
use Phlox\RestApi\Server;
use Phlox\RestApi\Tests\Fixtures\RecordingResponse;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// "api" serves something else than "api/v2", so that a wrong choice gives a 404
$container = createContainer(<<<'NEON'
restApi:
    endpoints:
        rest:
            namespace: Phlox\RestApi\Tests\Fixtures\Api
        api:
            namespace: Phlox\RestApi\Tests\Fixtures\ApiOther
        'api/v2':
            namespace: Phlox\RestApi\Tests\Fixtures\Api

services:
    - Phlox\RestApi\Tests\Fixtures\Api\UserController
    - Phlox\RestApi\Tests\Fixtures\Api\FileController
    - Phlox\RestApi\Tests\Fixtures\Api\SecureController
    - Phlox\RestApi\Tests\Fixtures\ApiOther\OtherController
    http.response: Phlox\RestApi\Tests\Fixtures\RecordingResponse
NEON);

$dispatcher = $container->getByType(Dispatcher::class);
$response = $container->getByType(IResponse::class);
Assert::type(RecordingResponse::class, $response);

$accessControl = [
    'allowedOrigins' => ['https://app.test'],
    'allowCredentials' => true,
    'allowMethods' => ['GET', 'POST'],
    'allowHeaders' => ['content-type'],
    'exposeHeaders' => ['location'],
    'maxAge' => 60,
];

// error_log() is the fallback of the error reporter; keep it out of the test's output
ini_set('error_log', __DIR__ . '/tmp/' . getmypid() . '/error.log');

/**
 * @return array{handled: bool, output: string}
 */
$request = static function (
    string $method,
    string $path,
    ?string $body = null,
    array $headers = [],
    array $endpoints = ['rest'],
    string $basePath = '/',
) use ($dispatcher, $response, $accessControl): array {
    $response->reset();

    $httpRequest = new Request(
        new UrlScript('http://example.test' . $path, $basePath . 'index.php'),
        headers: $headers,
        method: $method,
        rawBodyCallback: static fn(): ?string => $body,
    );

    $server = new Server(
        $dispatcher,
        new CorsHandler($httpRequest, $response),
        $httpRequest,
        $response,
        new ErrorReporter(),
        array_fill_keys($endpoints, $accessControl),
    );

    ob_start();
    $handled = $server->process();

    return ['handled' => $handled, 'output' => (string) ob_get_clean()];
};

// --- What is and isn't ours

Assert::false($request('GET', '/other/users')['handled']);
Assert::false($request('GET', '/restless/users')['handled'], 'a shared prefix isn\'t the endpoint');
Assert::false($request('GET', '/rest')['handled'], 'the endpoint root stays with the application');
Assert::false($request('POST', '/rest/')['handled']);

Assert::true($request('GET', '/rest/users')['handled']);
Assert::true($request('GET', '/rest/users/')['handled'], 'trailing slash');
Assert::true($request('GET', '/rest/nothing')['handled'], 'under the endpoint, an unknown route is ours too');

Assert::true($request('GET', '/app/rest/users/me', basePath: '/app/')['handled'], 'application in a subdirectory');

// The longest endpoint wins
$result = $request('GET', '/api/v2/users/me', endpoints: ['api', 'api/v2']);
Assert::same(200, $response->code);
Assert::same('{"action":"me"}', $result['output']);

$result = $request('GET', '/api/v2/users/me', endpoints: ['api/v2', 'api']);
Assert::same('{"action":"me"}', $result['output'], 'whatever the order in config');

// --- Successful responses

$result = $request('GET', '/rest/users/5');
Assert::same(200, $response->code);
Assert::same('application/json; charset=utf-8', $response->getHeader('content-type'));
Assert::same('{"action":"detail","id":5}', $result['output']);

$result = $request('GET', '/rest/users?page=3');
Assert::same('{"action":"list","page":3}', $result['output'], 'query string');

$result = $request('POST', '/rest/users', '{"name":"Ann"}', ['content-type' => 'application/json']);
Assert::same(201, $response->code, 'a code set by the controller is kept');
Assert::contains('"name":"Ann"', $result['output']);

$result = $request('DELETE', '/rest/users/5');
Assert::same(204, $response->code, 'no value, no content');
Assert::same('', $result['output']);

$result = $request('POST', '/rest/users/5/activate');
Assert::same(202, $response->code, 'no value, but the controller chose a code');
Assert::same('', $result['output']);

$result = $request('HEAD', '/rest/users/probe');
Assert::same(200, $response->code);
Assert::same('', $result['output'], 'HEAD has no body');

// --- Errors

$result = $request('GET', '/rest/nothing');
Assert::same(404, $response->code);
Assert::same('application/json; charset=utf-8', $response->getHeader('content-type'));
Assert::contains('{"error":{"code":404,"message":"Not found."}}', $result['output']);

$result = $request('PATCH', '/rest/users/me');
Assert::same(405, $response->code);
Assert::same('GET', $response->getHeader('allow'));

$result = $request('GET', '/rest/secure/plain');
Assert::same(401, $response->code);

$result = $request('GET', '/rest/users/crash');
Assert::same(500, $response->code);
Assert::notContains('secret', $result['output']);

$result = $request('GET', '/rest/users/unencodable');
Assert::same(500, $response->code);
Assert::contains('"code":-32603', $result['output']);

// --- A controller that sent the response itself

$result = $request('GET', '/rest/users/abort');
Assert::same(200, $response->code);
Assert::same('', $result['output']);
Assert::null($response->getHeader('content-type'));

// --- Application responses

$result = $request('GET', '/rest/files/download');
Assert::same('plain text from an application response', $result['output']);
Assert::null($response->getHeader('content-type'));

// --- CORS

$request('GET', '/rest/users/me', headers: ['origin' => 'https://app.test']);
Assert::same('https://app.test', $response->getHeader('access-control-allow-origin'));
Assert::same('true', $response->getHeader('access-control-allow-credentials'));

$request('GET', '/rest/users/me', headers: ['origin' => 'https://evil.test']);
Assert::null($response->getHeader('access-control-allow-origin'));

$result = $request('OPTIONS', '/rest/users/5', headers: ['origin' => 'https://app.test']);
Assert::same(204, $response->code);
Assert::same('', $result['output']);
Assert::same('GET, POST', $response->getHeader('access-control-allow-methods'));

$request('GET', '/rest/nothing', headers: ['origin' => 'https://app.test']);
Assert::same('https://app.test', $response->getHeader('access-control-allow-origin'), 'errors must be readable by the client too');

// --- A controller writing the response itself, as reports do with readfile()

$printed = $request('GET', '/rest/files/printed');
Assert::same('printed', $printed['output']);
Assert::same(200, $response->code, 'not 204, which would make clients drop the body');
