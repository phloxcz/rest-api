<?php

/**
 * Parameters, bodies, errors and authorization, through a real compiled container.
 */

declare(strict_types=1);

use Phlox\ApiCore\ProtocolError;
use Phlox\ApiCore\Attributes\ErrorCase;
use Nette\Security\SimpleIdentity;
use Nette\Security\User;
use Phlox\ApiCore\ApiException;
use Phlox\RestApi\Dispatcher;
use Phlox\RestApi\Result;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$container = createContainer(StandardConfig);
$dispatcher = $container->getByType(Dispatcher::class);

$get = static fn(string $path, array $query = []): Result => $dispatcher->handle('rest', 'GET', $path, $query);
$post = static fn(string $path, ?string $body, ?string $type = 'application/json', array $query = []): Result
    => $dispatcher->handle('rest', 'POST', $path, $query, $type, $body);

$status = static fn(Result $result): ?int => $result->status;
$code = static fn(Result $result): mixed => $result->data['error']['code'] ?? 'no error';

// --- Routing through controllers

Assert::same(['action' => 'list', 'page' => 1], $get('users')->data);
Assert::same(['action' => 'detail', 'id' => 5], $get('users/5')->data, 'int from the path is converted');
Assert::same(['action' => 'bySlug', 'slug' => 'john'], $get('users/john')->data);
Assert::same(['action' => 'me'], $get('users/me')->data, 'literal beats a parameter whatever the order of methods');
Assert::same(['action' => 'bySlug', 'slug' => '0'], $get('users/0')->data, 'failed min(1) moves on to the next route');
Assert::same('derived from the method name', $get('users/get-all-active')->data);
Assert::same('derived from both names', $get('user-profile/show-detail')->data);

Assert::same('a/b/c', $get('files/tree/a/b/c')->data);
Assert::same('root', $get('files/tree')->data, 'missing optional parameter gets the default of the method');
Assert::same(7, $get('files/a/7/c')->data);
Assert::same(0, $get('files/a/c')->data);
Assert::same('ABC', $get('files/code/ABC')->data);
Assert::same('report', $get('files/name-report.json')->data);
Assert::same(true, $get('files/flag/true')->data);
Assert::same(false, $get('files/flag/0')->data);
Assert::same(404, $status($get('files/flag/maybe')));

// --- Not routed

foreach (['users/hidden/x', 'users/helper/x', 'other/read', 'nothing', 'users/5/6'] as $path) {
    $result = $get($path);
    Assert::same(404, $status($result), $path);
    Assert::same(404, $code($result), "{$path}: a REST error without a code of its own has the status as its code");
}

Assert::same('bySlug', $get('users/helper')->data['action'], 'a method with no verb has no route of its own');
Assert::same('bySlug', $get('users/hidden')->data['action'], '#[NonApi] isn\'t routed, despite the verb');

// --- Method not allowed

$wrong = $dispatcher->handle('rest', 'DELETE', 'users');
Assert::same(405, $wrong->status);
Assert::same('GET, POST', $wrong->headers['Allow']);

$wrong = $post('users/5', '{}');
Assert::same(405, $wrong->status);
Assert::same('GET, PUT, PATCH, DELETE', $wrong->headers['Allow']);

// --- Parameters and their precedence

Assert::same(3, $get('users', ['page' => '3'])->data['page'], 'from the query');
Assert::same(422, $status($get('users', ['page' => 'x'])));
Assert::same(ProtocolError::InvalidParams->value, $code($get('users', ['page' => 'x'])));

$created = $post('users', '{"name":"Ann","age":30}');
Assert::same(['name' => 'Ann', 'age' => 30, 'jsonData' => ['name' => 'Ann', 'age' => 30]], $created->data);

Assert::same('Query', $post('users', '{"name":"Body"}', query: ['name' => 'Query'])->data['name'], 'query beats body');

$updated = $dispatcher->handle('rest', 'PUT', 'users/5', ['id' => '99'], 'application/json', '{"id":77,"name":"Ann"}');
Assert::same(5, $updated->data['id'], 'path beats query and body');
Assert::same('Ann', $updated->data['name']);

Assert::same('Ann', $post('users', '{"name":"Ann"}', 'application/json; charset=utf-8')->data['name'], 'charset is ignored');
Assert::same('Ann', $post('users', '{"name":"Ann"}', 'Application/JSON')->data['name'], 'media types are case-insensitive');
Assert::same('Ann', $post('users', '{"name":"Ann"}', 'application/vnd.api+json')->data['name']);
Assert::same('Ann', $post('users', '{"name":"Ann"}', null)->data['name'], 'JSON is the default');

Assert::same(422, $status($post('users', '{"age":1}')), 'missing required parameter');
Assert::same(ProtocolError::InvalidParams->value, $code($post('users', 'name=Ann', 'application/x-www-form-urlencoded')), 'other bodies don\'t become parameters');

// A body isn't parsed where nothing would read it
Assert::same(['action' => 'list', 'page' => 1], $dispatcher->handle('rest', 'GET', 'users', [], null, '{broken')->data);

// --- Malformed JSON

$broken = $post('users', '{"name":');
Assert::same(400, $status($broken));
Assert::same(ProtocolError::ParseError->value, $code($broken));

Assert::same(ProtocolError::InvalidParams->value, $code($post('users', '5')), 'scalar body isn\'t spread into parameters');

// --- FromBody

Assert::same(['type' => 'array', 'data' => ['a' => 1]], $post('files/json-array', '{"a":1}')->data);
Assert::same(['type' => 'stdClass', 'name' => 'x'], $post('files/json-object', '{"name":"x"}')->data);
Assert::same(['type' => 'array', 'data' => ['a' => 1]], $post('files/json-array', '{"a":1}', 'application/json; charset=utf-8')->data, 'the old version failed on this');

Assert::same(415, $status($post('files/json-array', 'a=1', 'text/plain')), 'FromBody means JSON unless said otherwise');
Assert::same(ProtocolError::ParseError->value, $code($post('files/json-array', '{broken')));
Assert::same(ProtocolError::InvalidParams->value, $code($post('files/json-array', null)), 'required body is missing');
Assert::same(ProtocolError::InvalidParams->value, $code($post('files/json-array', '')));

Assert::same(['data' => null, 'mode' => 'default'], $post('files/optional', null)->data);
Assert::same(
    ['data' => null, 'mode' => 'q'],
    $post('files/optional', null, query: ['data' => ['from' => 'query'], 'mode' => 'q'])->data,
    'a FromBody parameter can\'t be filled from the query',
);
Assert::same(['data' => ['a' => 1], 'mode' => 'default'], $post('files/optional', '{"a":1}')->data);

// --- Consumes

Assert::same(['bytes' => 3, 'content' => "\x00\xFFx"], $post('files/upload', "\x00\xFFx", 'application/pdf')->data, 'binary stays as it is');
Assert::same(3, $post('files/upload', 'png', 'image/png')->data['bytes'], 'wildcard');
Assert::same(415, $status($post('files/upload', 'x', 'video/mp4')));
Assert::same(415, $status($post('files/upload', 'x', 'imagefake/png')));
Assert::same(415, $status($post('files/upload', 'x', null)), 'a missing type counts as JSON, which isn\'t accepted here');
Assert::same(ProtocolError::InvalidParams->value, $code($post('files/upload', null, 'video/mp4')), 'an empty body has no type to check');

Assert::same('xml accepted', $post('files/only-xml', '<a/>', 'application/xml')->data);
Assert::same(415, $status($post('files/only-xml', '{}', 'application/json')));

Assert::same(['jsonData' => null], $post('files/no-consumes', 'free text', 'text/plain')->data, 'no #[Consumes], no restriction');

// --- Controller-defined errors

$failure = $get('users/fail');
Assert::same(409, $failure->status);
Assert::same(1234, $failure->data['error']['code'], 'data can\'t overwrite the code');
Assert::same('Public failure', $failure->data['error']['message'], 'internal message is hidden');
Assert::same('name', $failure->data['error']['field']);

$crash = $get('users/crash');
Assert::same(500, $crash->status);
Assert::same(ProtocolError::InternalError->value, $crash->data['error']['code']);
Assert::same(ErrorCase::of(ProtocolError::InternalError)->message, $crash->data['error']['message'], 'no details leak');

Assert::true($get('users/abort')->aborted);

// --- OPTIONS

$preflight = $dispatcher->handle('rest', 'OPTIONS', 'users/5');
Assert::null($preflight->data);
Assert::null($preflight->status);
Assert::same(['explicit' => 'options'], $dispatcher->handle('rest', 'OPTIONS', 'users/capabilities')->data);
Assert::null($dispatcher->handle('rest', 'OPTIONS', 'no/such/path')->status, 'a preflight says nothing about routes');

// --- Authorization

Assert::same(401, $status($get('secure/plain')), 'default is on: no attribute still requires login');
Assert::same('open', $get('secure/open')->data);
Assert::same(401, $code($post('secure/typed', '{"required":"x"}')), 'anonymous learns nothing about parameters');
Assert::same(401, $status($get('secure/any-role')));

$user = $container->getByType(User::class);

$user->login(new SimpleIdentity(1, ['reader']));
Assert::same('plain', $get('secure/plain')->data);
Assert::same(ProtocolError::InvalidParams->value, $code($post('secure/typed', '{"required":"x"}')));
Assert::same(403, $status($get('secure/any-role')));
Assert::notContains('editor', $get('secure/any-role')->data['error']['message'], 'roles are internal');

$user->login(new SimpleIdentity(1, ['manager']));
Assert::same('any', $get('secure/any-role')->data, 'one role of the attribute is enough');
Assert::same(403, $status($get('secure/all-roles')), 'all: true needs every role');

$user->login(new SimpleIdentity(1, ['editor', 'manager']));
Assert::same('all', $get('secure/all-roles')->data);

// --- Unknown endpoint is a programmer error

Assert::exception(static fn() => $dispatcher->handle('nope', 'GET', 'x'), InvalidArgumentException::class);

// --- Debug mode adds internal messages

$debugContainer = createContainer(StandardConfig, debugMode: true);
$debug = $debugContainer->getByType(Dispatcher::class);
Assert::contains('secret detail', $debug->handle('rest', 'GET', 'users/fail')->data['error']['message']);
Assert::contains('secret crash detail', $debug->handle('rest', 'GET', 'users/crash')->data['error']['message']);

// --- A body nobody asked for stays unread, so a controller can stream a large upload itself

$never = static function (): string {
    throw new LogicException('The body was read.');
};
Assert::same(['name' => 'default'], $dispatcher->handle('rest', 'POST', 'files/stream', [], 'application/octet-stream', $never)->data);
Assert::same('list', $dispatcher->handle('rest', 'GET', 'users', [], 'application/json', $never)->data['action'], 'and a GET has no parameters in its body');

$read = static fn(): string => '{"name":"Ann"}';
Assert::same(['name' => 'Ann'], $dispatcher->handle('rest', 'POST', 'files/stream', [], 'application/json', $read)->data, 'read when it is JSON');
Assert::same(['type' => 'array', 'data' => ['a' => 1]], $dispatcher->handle('rest', 'POST', 'files/json-array', [], 'application/json', static fn(): string => '{"a":1}')->data);

// A body with no type may be anything, so it only fails where it was asked for
Assert::same(['name' => 'default'], $dispatcher->handle('rest', 'POST', 'files/stream', [], null, 'binary data')->data);
Assert::same(['name' => 'Ann'], $dispatcher->handle('rest', 'POST', 'files/stream', [], null, '{"name":"Ann"}')->data, 'JSON with no type still works');
Assert::same(400, $dispatcher->handle('rest', 'POST', 'files/stream', [], 'application/json', 'binary data')->status, 'with the type stated, it is a mistake');
Assert::same(400, $dispatcher->handle('rest', 'POST', 'files/json-array', [], null, 'binary data')->status, 'as it is for #[FromBody]');

// #[Consumes] without #[FromBody] checks the type and leaves the body unread
Assert::same(['name' => 'default'], $dispatcher->handle('rest', 'POST', 'files/streamed', [], 'application/octet-stream', $never)->data);
Assert::same(415, $dispatcher->handle('rest', 'POST', 'files/streamed', [], 'text/plain', $never)->status, 'known by the type alone');
Assert::same(['name' => 'default'], $dispatcher->handle('rest', 'POST', 'files/streamed', [], null, $never)->data, 'no type, no body to judge');

// --- Headers

$headers = static fn(string $path, array $headers): Result => $dispatcher->handle('rest', 'GET', $path, [], null, null, $headers);
Assert::same(['requestId' => 'abc', 'maxItems' => 5], $headers('files/headers', ['x-request-id' => 'abc', 'Max-Items' => '5'])->data, 'any case, converted to the type');
Assert::same(['requestId' => null, 'maxItems' => 10], $headers('files/headers', [])->data, 'defaults when missing');
Assert::same(['requestId' => null, 'maxItems' => 10], $dispatcher->handle('rest', 'GET', 'files/headers', ['requestId' => 'x', 'maxItems' => '3'])->data, 'never from the query');
Assert::same(['maxItems' => "must be 'int' but 'string' given."], $headers('files/headers', ['max-items' => 'many'])->data['error']['fields']);
Assert::same('"v1"', $headers('files/if-match', ['if-match' => '"v1"'])->data, '$ifMatch is If-Match');
Assert::same(['ifMatch' => 'is required.'], $headers('files/if-match', [])->data['error']['fields']);

// --- Forms, with files

$upload = static fn(string $name, int $error = UPLOAD_ERR_OK): Nette\Http\FileUpload => new Nette\Http\FileUpload([
    'name' => $name, 'full_path' => $name, 'size' => 3, 'tmp_name' => __FILE__, 'error' => $error, 'type' => 'text/plain',
]);
$form = static fn(array $form, string $type = 'multipart/form-data; boundary=x', string $path = 'files/form'): Result
    => $dispatcher->handle('rest', 'POST', $path, [], $type, $never, [], $form);

Assert::same(
    ['file' => 'a.pdf', 'title' => 'Report', 'pages' => 3, 'preview' => null, 'attachments' => ['b.txt', 'c.txt']],
    $form(['file' => $upload('a.pdf'), 'title' => 'Report', 'pages' => '3', 'preview' => $upload('', UPLOAD_ERR_NO_FILE), 'attachment' => [$upload('b.txt'), $upload('c.txt')]])->data,
    'an empty file input is as if it was not there',
);
Assert::same(['file' => 'is required.'], $form(['title' => 'Report'])->data['error']['fields']);
Assert::same(['file' => 'is too large.'], $form(['file' => $upload('a.pdf', UPLOAD_ERR_INI_SIZE), 'title' => 'Report'])->data['error']['fields']);
Assert::same(['file' => 'could not be uploaded.'], $form(['file' => $upload('a.pdf', UPLOAD_ERR_PARTIAL), 'title' => 'Report'])->data['error']['fields']);
Assert::same(415, $form(['title' => 'Report'], 'application/json')->status, 'a form, or nothing');
Assert::same('Ann', $form(['title' => 'Ann'], 'application/x-www-form-urlencoded', 'files/fields')->data);
Assert::same(['title' => 'is required.'], $dispatcher->handle('rest', 'POST', 'files/fields', ['title' => 'x'], 'application/x-www-form-urlencoded', '', [], [])->data['error']['fields'], 'never from the query');

// --- How they're documented

$paths = json_decode($container->getByType(Phlox\RestApi\OpenApi::class)->toJson('rest'), true)['paths'];

Assert::same(
    ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
    $paths['/files/streamed']['post']['requestBody']['content'],
);
Assert::same(['name'], array_column($paths['/files/streamed']['post']['parameters'], 'name'), 'the rest goes to the query');

Assert::same(
    [
        ['name' => 'X-Request-Id', 'in' => 'header', 'required' => false, 'schema' => ['type' => ['string', 'null']]],
        ['name' => 'Max-Items', 'in' => 'header', 'required' => false, 'schema' => ['default' => 10, 'type' => 'integer']],
    ],
    $paths['/files/headers']['get']['parameters'],
);
Assert::true($paths['/files/if-match']['get']['parameters'][0]['required']);

$formBody = $paths['/files/form']['post']['requestBody'];
Assert::same(['multipart/form-data'], array_keys($formBody['content']), 'files need multipart');
$formSchema = $formBody['content']['multipart/form-data']['schema'];
Assert::same(['file', 'title'], $formSchema['required']);
Assert::same(['type' => 'string', 'format' => 'binary'], $formSchema['properties']['file']);
Assert::same(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']], array_diff_key($formSchema['properties']['attachment'], ['default' => 1]));
Assert::false(isset($paths['/files/form']['post']['parameters']), 'nothing of the form in the query');
Assert::same(['multipart/form-data', 'application/x-www-form-urlencoded'], array_keys($paths['/files/fields']['post']['requestBody']['content']));

// --- Items of a list in the body don't fill parameters by their position

Assert::same(['rows' => ['a', 'b', 'c'], 'mode' => 'default'], $dispatcher->handle('rest', 'POST', 'files/rows', [], 'application/json', '["a","b","c"]')->data);
