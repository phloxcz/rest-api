<?php

/**
 * The OpenAPI document, generated from the routes the server runs on, and DTOs
 * going through a real dispatcher.
 */

declare(strict_types=1);

use Nette\InvalidStateException;
use Phlox\RestApi\Dispatcher;
use Phlox\RestApi\OpenApi;
use Phlox\RestApi\Tests\Fixtures\Docs\DocsController;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/Docs/Dtos.php';

const DocsConfig = <<<'NEON'
restApi:
    endpoints:
        api:
            namespace: Phlox\RestApi\Tests\Fixtures\Docs
            authorize: true
            documentation:
                expose: true
                info:
                    title: Users
                    version: '2.1.0'
                securitySchemes:
                    bearer:
                        type: http
                        scheme: bearer
                extra:
                    x-custom: yes
                    info:
                        contact:
                            name: Team

services:
    - Phlox\RestApi\Tests\Fixtures\Docs\DocsController
NEON;

$container = createContainer(DocsConfig);
$openApi = $container->getByType(OpenApi::class);
$dispatcher = $container->getByType(Dispatcher::class);

Assert::same(['api'], $openApi->getEndpoints());

$document = json_decode($openApi->toJson('api'), true);

// --- The frame

Assert::same('3.1.0', $document['openapi']);
Assert::same(['title' => 'Users', 'version' => '2.1.0', 'contact' => ['name' => 'Team']], $document['info']);
Assert::same([['url' => '/api']], $document['servers']);
Assert::true($document['x-custom']);
Assert::same([['name' => 'Docs', 'description' => 'Everything about users.']], $document['tags']);
Assert::same(['type' => 'http', 'scheme' => 'bearer'], $document['components']['securitySchemes']['bearer']);

$paths = $document['paths'];

Assert::false(isset($paths['/users/not-an-endpoint']), 'a method without a verb is not an endpoint');
Assert::false(isset($paths['/openapi.json']), 'the document does not describe itself');

// --- A query, with a summary, constraints and a public method

$list = $paths['/users']['get'];
Assert::same('Lists users.', $list['summary']);
Assert::same('With paging.', $list['description']);
Assert::same(['Docs'], $list['tags']);
Assert::same('Docs_list', $list['operationId']);
Assert::same([], $list['security'], 'public');
Assert::false(isset($list['x-roles']));
Assert::same(
    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['default' => 1, 'minimum' => 1, 'type' => 'integer'], 'description' => 'Which page'],
    $list['parameters'][0],
);
Assert::same('role', $list['parameters'][1]['name']);
Assert::same(['anyOf' => [['$ref' => '#/components/schemas/Role'], ['type' => 'null']]], $list['parameters'][1]['schema']);
Assert::false(isset($list['parameters'][1]['style']), 'an enum is a plain value');
Assert::same('deepObject', $list['parameters'][2]['style'], 'filter[a]=1');
Assert::same(['200', '422'], array_map(strval(...), array_keys($list['responses'])), 'public, so no 401');
Assert::same('The users', $list['responses'][200]['description']);
Assert::same(
    ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UserDto']],
    $list['responses'][200]['content']['application/json']['schema'],
);

// --- A path parameter, roles and declared errors

$detail = $paths['/users/{id}']['get'];
Assert::same('Detail', $detail['summary'], 'from the name, when the docblock has none');
Assert::same('Error named', $paths['/users/error-named']['get']['summary']);
Assert::same(
    [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['minimum' => 1, 'type' => 'integer'], 'description' => 'The user']],
    $detail['parameters'],
);
Assert::same([['bearer' => []]], $detail['security']);
Assert::same([['admin', 'editor']], $detail['x-roles']);
Assert::contains('Requires login and the role admin or editor.', $detail['description']);
Assert::same(['200', '401', '403', '404', '422', '423'], array_map(strval(...), array_keys($detail['responses'])));
Assert::same(['$ref' => '#/components/schemas/UserDto'], $detail['responses'][200]['content']['application/json']['schema']);
Assert::same('`1001`: The user is locked.', $detail['responses'][423]['description']);
Assert::same("Not found.\n\n`1002`: The user is gone.", $detail['responses'][404]['description'], 'added to what is known');
Assert::same(['$ref' => '#/components/schemas/Error'], $detail['responses'][423]['content']['application/json']['schema']);

// --- A DTO as a body, and a code the signature can't tell

$create = $paths['/users']['post'];
Assert::same(['$ref' => '#/components/schemas/CreateUser'], $create['requestBody']['content']['application/json']['schema']);
Assert::true($create['requestBody']['required']);
Assert::false(isset($create['parameters']));
Assert::same(['201', '400', '401', '403', '415', '422'], array_map(strval(...), array_keys($create['responses'])));
Assert::same(['$ref' => '#/components/schemas/UserDto'], $create['responses'][201]['content']['application/json']['schema'], '#[Response(201)] takes the return type');

// --- Parameters from a JSON body, and two verbs on one method

foreach (['put', 'patch'] as $verb) {
    $update = $paths['/users/{id}'][$verb];
    Assert::same("Docs_update_{$verb}", $update['operationId']);
    Assert::same(['id'], array_column($update['parameters'], 'name'), 'the rest goes to the body');
    Assert::same(
        ['type' => 'object', 'properties' => [
            'name' => ['description' => 'The new name', 'type' => 'string'],
            'age' => ['default' => 0, 'type' => 'integer'],
        ], 'required' => ['name']],
        $update['requestBody']['content']['application/json']['schema'],
    );
}

// --- No content, and narrowed roles

$remove = $paths['/users/{id}']['delete'];
Assert::same([['owner'], ['admin', 'editor']], $remove['x-roles'], 'roles of a class and of a method hold together');
Assert::contains('204', array_map(strval(...), array_keys($remove['responses'])));
Assert::false(isset($remove['responses'][204]['content']));

// --- #[FromBody] leaves the other parameters in the query

$import = $paths['/users/import']['post'];
Assert::true($import['deprecated']);
Assert::same(['dryRun'], array_column($import['parameters'], 'name'));
Assert::same(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CreateUser']], $import['requestBody']['content']['application/json']['schema']);

// --- Content types

$photo = $paths['/users/{id}/photo']['post'];
Assert::same(['image/*', 'application/json'], array_keys($photo['requestBody']['content']));
Assert::same([], $photo['requestBody']['content']['image/*'], 'anything but JSON comes as it is');
Assert::same(['type' => 'string'], $photo['requestBody']['content']['application/json']['schema']);
Assert::true(isset($photo['responses'][415]));

// --- A file

$file = $paths['/users/{id}/file']['get'];
Assert::same(['type' => 'string', 'format' => 'uuid'], $file['parameters'][0]['schema']);
Assert::same(['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]], $file['responses'][200]['content']);
Assert::same('The file', $file['responses'][200]['description']);

// --- OpenAPI has no optional path parameters, so each combination is a path

Assert::true(isset($paths['/users/tree']['get']));
Assert::true(isset($paths['/users/tree/{path}']['get']));
Assert::contains('May contain slashes.', $paths['/users/tree/{path}']['get']['parameters'][0]['description']);
Assert::false(isset($paths['/users/tree']['get']['parameters']), 'it does not fall into the query');
Assert::same(['200', '204', '401', '403', '422'], array_map(strval(...), array_keys($paths['/users/tree']['get']['responses'])), 'null means no content');

foreach (['/users/a/c', '/users/a/{b}/c', '/users/a/c/{d}', '/users/a/{b}/c/{d}'] as $path) {
    Assert::true(isset($paths[$path]['get']), $path);
}
$ids = [];
foreach (['/users/a/c', '/users/a/{b}/c', '/users/a/c/{d}', '/users/a/{b}/c/{d}'] as $path) {
    $ids[] = $paths[$path]['get']['operationId'];
}
Assert::count(4, array_unique($ids), 'operation ids are unique');

// --- What route tokens say

$schemas = array_column($paths['/users/code/{code}/{flag}/{n}/{s}/{u}']['get']['parameters'], 'schema', 'name');
Assert::same(['type' => 'string', 'pattern' => '^(?:[A-Z]{3})$'], $schemas['code']);
Assert::same(['type' => 'boolean'], $schemas['flag']);
Assert::same(['minimum' => 1, 'maximum' => 5, 'type' => 'number'], $schemas['n']);
Assert::same(['minLength' => 2, 'maxLength' => 3, 'type' => 'string', 'pattern' => '^[a-zA-Z]+$'], $schemas['s']);
Assert::same(['anyOf' => [['type' => 'integer'], ['type' => 'string', 'format' => 'uuid']]], $schemas['u']);

Assert::same(['$ref' => '#/components/schemas/Role'], $paths['/users/enum/{role}']['get']['parameters'][0]['schema'], 'a plain parameter is described by the method');

// --- The older notation

$older = $paths['/users/older']['post'];
$body = $older['requestBody']['content']['application/json']['schema'];
Assert::same(['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]], $body['properties']['filter']);
Assert::same(['example' => 5, 'default' => 5, 'type' => 'integer'], $body['properties']['limit']);
Assert::same(['$ref' => '#/components/schemas/UserDto'], $older['responses'][200]['content']['application/json']['schema']['properties']['data']['items']);
Assert::same('Nothing here', $older['responses'][404]['description']);
Assert::same(['$ref' => '#/components/schemas/Error'], $older['responses'][404]['content']['application/json']['schema']);

// --- Schemas

$components = $document['components']['schemas'];
Assert::same(['type' => 'string', 'enum' => ['admin', 'user']], $components['Role']);
Assert::same(['id', 'name'], $components['UserDto']['required']);
Assert::same('Where the user lives', $components['UserDto']['properties']['addresses']['description']);
Assert::same(['type' => ['string', 'null'], 'format' => 'date-time'], array_diff_key($components['UserDto']['properties']['created'], ['default' => 1]));
Assert::same(['email' => ['format' => 'email', 'type' => 'string']], array_intersect_key($components['CreateUser']['properties'], ['email' => 1]));
Assert::true(isset($components['Error']['properties']['error']), 'the schema of errors is the one of the library');
$named = $paths['/users/error-named']['get']['responses'][200]['content']['application/json']['schema']['$ref'];
Assert::same('#/components/schemas/Phlox.RestApi.Tests.Fixtures.Docs.Error', $named, 'a DTO of the same name steps aside');

// --- Everything the document refers to exists

preg_match_all('~"#/components/schemas/([^"]+)"~', str_replace('\/', '/', $openApi->toJson('api')), $references);
foreach (array_unique($references[1]) as $reference) {
    Assert::true(isset($components[$reference]), "#/components/schemas/{$reference}");
}

// --- Served by the API itself

$json = $dispatcher->handle('api', 'GET', 'openapi.json');
Assert::same($openApi->toJson('api'), $json->data);
Assert::same('application/json', $json->rawContentType);

$yaml = $dispatcher->handle('api', 'GET', 'openapi.yaml');
Assert::same($openApi->toYaml('api'), $yaml->data);
Assert::same('application/yaml', $yaml->rawContentType);
Assert::same(405, $dispatcher->handle('api', 'POST', 'openapi.json')->status);

// --- Whether it's served

$config = static fn(string $documentation): string => <<<NEON
restApi:
    endpoints:
        api:
            namespace: Phlox\\RestApi\\Tests\\Fixtures\\Docs
            documentation: $documentation

services:
    - Phlox\\RestApi\\Tests\\Fixtures\\Docs\\DocsController
NEON;

$hidden = createContainer($config('{}'), debugMode: false);
Assert::same(404, $hidden->getByType(Dispatcher::class)->handle('api', 'GET', 'openapi.json')->status, 'not published in production');
Assert::contains('"openapi":"3.1.0"', $hidden->getByType(OpenApi::class)->toJson('api'), 'but available to the application');
Assert::same('api API', json_decode($hidden->getByType(OpenApi::class)->toJson('api'))->info->title);
Assert::false(isset(json_decode($hidden->getByType(OpenApi::class)->toJson('api'), true)['paths']['/users']['get']['security']), 'no schemes, no security');
Assert::same([['admin', 'editor']], json_decode($hidden->getByType(OpenApi::class)->toJson('api'), true)['paths']['/users/{id}']['get']['x-roles'], 'roles are still there');

$shown = createContainer($config('{}') . "\n", debugMode: true);
Assert::same('application/json', $shown->getByType(Dispatcher::class)->handle('api', 'GET', 'openapi.json')->rawContentType, 'published in debug mode');

$moved = createContainer($config('{expose: true, path: docs/spec, servers: [{url: "https://api.example.com"}]}'));
Assert::same('application/yaml', $moved->getByType(Dispatcher::class)->handle('api', 'GET', 'docs/spec.yaml')->rawContentType);
Assert::same('https://api.example.com', json_decode($moved->getByType(OpenApi::class)->toJson('api'))->servers[0]->url);

$off = createContainer($config('{enabled: false, expose: true}'));
Assert::false($off->getByType(OpenApi::class)->has('api'));
Assert::same(404, $off->getByType(Dispatcher::class)->handle('api', 'GET', 'openapi.json')->status);

// A route of a controller can't get lost behind the document
$e = Assert::exception(static fn() => createContainer($config("{expose: true, path: 'users/import'}") . "\n    - Phlox\\RestApi\\Tests\\Fixtures\\Docs\\ClashController"), InvalidStateException::class);
Assert::contains('the OpenAPI document', $e->getMessage());

// --- DTOs through the dispatcher

$result = $dispatcher->handle('api', 'GET', 'users', ['page' => '3', 'role' => 'admin']);
Assert::same(
    '[{"id":3,"name":"Ann","role":"admin","addresses":[],"created":"2024-01-02T03:04:05+00:00"}]',
    json_encode($result->data),
);

$anonymous = $dispatcher->handle('api', 'POST', 'users', [], 'application/json', '{"name":"Ann","email":"a@b.cz"}');
Assert::same(401, $anonymous->status, 'authorization comes first');

$invalid = $dispatcher->handle('api', 'GET', 'users', ['page' => '0']);
Assert::same(422, $invalid->status);
Assert::same(['page' => 'must be at least 1.'], $invalid->data['error']['fields']);
Assert::same("Parameter 'page' must be at least 1.", $invalid->data['error']['message']);

$open = createContainer(<<<'NEON'
restApi:
    endpoints:
        api:
            namespace: Phlox\RestApi\Tests\Fixtures\Open

services:
    - Phlox\RestApi\Tests\Fixtures\Open\OpenController
NEON);
$openDispatcher = $open->getByType(Dispatcher::class);

$created = $openDispatcher->handle('api', 'POST', 'open', [], 'application/json', '{"name":"Ann","email":"a@b.cz","admin":true}');
Assert::same(['name' => 'Ann', 'email' => 'a@b.cz'], $created->data, 'what the DTO does not declare can not get in');

$bad = $openDispatcher->handle('api', 'POST', 'open', [], 'application/json', '{"name":"A","email":"nope"}');
Assert::same(422, $bad->status);
Assert::same(
    ['name' => 'must have at least 2 characters.', 'email' => 'must be a valid email.'],
    $bad->data['error']['fields'],
    'no parameter name in the paths of a body',
);
Assert::same(-32602, $bad->data['error']['code']);

$notObject = $openDispatcher->handle('api', 'POST', 'open', [], 'application/json', '[1,2]');
Assert::same(['input' => 'must be an object.'], $notObject->data['error']['fields']);

$list = $openDispatcher->handle('api', 'POST', 'open/many', [], 'application/json', '[{"name":"Ann","email":"a@b.cz"},{"name":"B","email":"b@b.cz"}]');
Assert::same(['1.name' => 'must have at least 2 characters.'], $list->data['error']['fields']);

$named = $openDispatcher->handle('api', 'PUT', 'open/named', [], 'application/json', '{"input":{"name":"Ann","email":"a@b.cz"}}');
Assert::same(['name' => 'Ann', 'email' => 'a@b.cz'], $named->data, 'a DTO can be one of the parameters');
