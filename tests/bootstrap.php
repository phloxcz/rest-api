<?php

declare(strict_types=1);

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

/**
 * Compiles a real container from the given NEON config.
 */
function createContainer(string $neon, bool $debugMode = false, string $extraExtension = '', bool $withApplication = true): Container
{
    @mkdir(__DIR__ . '/tmp');
    $tempDir = __DIR__ . '/tmp/' . getmypid();
    Tester\Helpers::purge($tempDir);

    $configurator = new Configurator();
    $configurator->setDebugMode($debugMode);
    $configurator->setTempDirectory($tempDir);
    $configurator->addStaticParameters(['appDir' => __DIR__]);
    if (!$withApplication) {
        unset($configurator->defaultExtensions['application']);
    }

    // The name of the compiled class depends on the path, not on the content
    $configFile = $tempDir . '/' . md5($neon . $extraExtension . (int) $debugMode . (int) $withApplication) . '.neon';
    file_put_contents($configFile, <<<NEON
extensions:
    restApi: Phlox\\RestApi\\DI\\Extension
    $extraExtension

security:
    debugger: false

http:
    frames: null

$neon
NEON);
    $configurator->addConfig($configFile);

    return $configurator->createContainer();
}

const StandardConfig = <<<'NEON'
restApi:
    endpoints:
        rest:
            namespace: Phlox\RestApi\Tests\Fixtures\Api

services:
    - Phlox\RestApi\Tests\Fixtures\Api\UserController
    - Phlox\RestApi\Tests\Fixtures\Api\FileController
    - Phlox\RestApi\Tests\Fixtures\Api\SecureController
    - Phlox\RestApi\Tests\Fixtures\Api\UserProfileController
    - Phlox\RestApi\Tests\Fixtures\ApiOther\OtherController
NEON;
