<?php

/**
 * Configuration, mistakes refused when the container compiles, and living side
 * by side with the RPC library.
 */

declare(strict_types=1);

use Nette\InvalidStateException;
use Phlox\RestApi\Dispatcher;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/Invalid/InvalidControllers.php';

$invalid = static fn(string $namespace, string ...$classes): string => <<<NEON
restApi:
    endpoints:
        rest:
            namespace: Phlox\\RestApi\\Tests\\Fixtures\\Invalid\\$namespace

services:

NEON . implode("\n", array_map(
    static fn(string $class): string => "    - Phlox\\RestApi\\Tests\\Fixtures\\Invalid\\$namespace\\$class",
    $classes,
));

$e = Assert::exception(
    static fn() => createContainer($invalid('DuplicateRoute', 'AController', 'BController')),
    InvalidStateException::class,
);
Assert::contains('AController::one()', $e->getMessage());
Assert::contains('BController::two()', $e->getMessage());

$e = Assert::exception(
    static fn() => createContainer($invalid('NoVerb', 'AController')),
    InvalidStateException::class,
);
Assert::contains('no HTTP verb', $e->getMessage());

$e = Assert::exception(
    static fn() => createContainer($invalid('TwoBodies', 'AController')),
    InvalidStateException::class,
);
Assert::contains('only one parameter', $e->getMessage());

$e = Assert::exception(
    static fn() => createContainer($invalid('BadToken', 'AController')),
    InvalidStateException::class,
);
Assert::contains("'imt'", $e->getMessage());

// --- A wildcard with credentials is refused

Assert::exception(static fn() => createContainer(<<<'NEON'
restApi:
    endpoints:
        rest:
            namespace: Phlox\RestApi\Tests\Fixtures\Api
            accessControl:
                allowedOrigins: ['*']
                allowCredentials: true
NEON), InvalidArgumentException::class);

// --- Endpoint options

$container = createContainer(<<<'NEON'
restApi:
    endpoints:
        rest:
            namespace: [Phlox\RestApi\Tests\Fixtures\Api, Phlox\RestApi\Tests\Fixtures\ApiOther]
            authorize: false
            jsonForceArray: false

services:
    - Phlox\RestApi\Tests\Fixtures\Api\UserController
    - Phlox\RestApi\Tests\Fixtures\Api\SecureController
    - Phlox\RestApi\Tests\Fixtures\ApiOther\OtherController
NEON);
$dispatcher = $container->getByType(Dispatcher::class);

Assert::same('plain', $dispatcher->handle('rest', 'GET', 'secure/plain')->data, 'authorize: false');
Assert::same('other', $dispatcher->handle('rest', 'GET', 'other/read')->data, 'several namespaces');

$created = $dispatcher->handle('rest', 'POST', 'users', [], 'application/json', '{"name":"Ann","nested":{"a":1}}');
Assert::same('Ann', $created->data['name'], 'parameters don\'t depend on jsonForceArray');
Assert::type(stdClass::class, $created->data['jsonData']);
Assert::type(stdClass::class, $created->data['jsonData']->nested);

// --- Taking over a request: after the application has started when there is one

$source = static fn(Nette\DI\Container $container): string => (string) file_get_contents((new ReflectionClass($container))->getFileName());

$withApplication = createContainer(<<<'NEON'
restApi:
    endpoints:
        rest:
            namespace: Phlox\RestApi\Tests\Fixtures\Api

services:
    - Phlox\RestApi\Tests\Fixtures\Api\UserController
NEON);
$application = $withApplication->getByType(Nette\Application\Application::class);
Assert::count(1, $application->onStartup);
Assert::notContains('restApi.server\')->process()', (string) preg_replace('~function createServiceApplication.*?\n\t}~s', '', $source($withApplication)));
$application->onStartup[0](); // nothing happens in CLI, and no exit

$withoutApplication = createContainer(<<<'NEON'
restApi:
    endpoints:
        rest:
            namespace: Phlox\RestApi\Tests\Fixtures\Api

services:
    - Phlox\RestApi\Tests\Fixtures\Api\UserController
NEON, withApplication: false);
Assert::false($withoutApplication->hasService('application.application'));
Assert::contains("\$this->getService('restApi.server')->process()", $source($withoutApplication));
Assert::same('me', $withoutApplication->getByType(Dispatcher::class)->handle('rest', 'GET', 'users/me')->data['action']);

// --- REST and RPC in one container, sharing the "api" name

$container = createContainer(<<<'NEON'
restApi:
    endpoints:
        api:
            namespace: Phlox\RestApi\Tests\Fixtures\Api

rpcApi:
    endpoints:
        api:
            namespace: Phlox\RestApi\Tests\Fixtures\Rpc

services:
    - Phlox\RestApi\Tests\Fixtures\Api\UserController
    - Phlox\RestApi\Tests\Fixtures\Rpc\PingController
NEON, extraExtension: 'rpcApi: Phlox\RpcApi\DI\Extension');

Assert::same('me', $container->getByType(Dispatcher::class)->handle('api', 'GET', 'users/me')->data['action']);
Assert::same(
    'from rpc',
    $container->getByType(Phlox\RpcApi\Dispatcher::class)->handle(
        'api',
        '{"jsonrpc":"2.0","method":"ping.pong","id":1}',
    )['result'],
);
Assert::same(
    Phlox\ApiCore\ApiException::E_METHOD_NOT_FOUND,
    $container->getByType(Phlox\RpcApi\Dispatcher::class)->handle(
        'api',
        '{"jsonrpc":"2.0","method":"user.me","id":1}',
    )['error']['code'],
    'REST controllers stay out of RPC',
);
