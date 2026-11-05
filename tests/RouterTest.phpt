<?php

/**
 * Route syntax and the order routes are tried in.
 */

declare(strict_types=1);

use Nette\InvalidStateException;
use Phlox\RestApi\Routing\ParamChecker;
use Phlox\RestApi\Routing\RouteCompiler;
use Phlox\RestApi\Routing\Router;
use Phlox\RestApi\Routing\RouteTableBuilder;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

/**
 * @param array<string, array<string, string>> $routes Path => regex parameters
 */
$table = static function (array $routes, string $verb = 'GET'): array {
    $builder = new RouteTableBuilder();
    foreach ($routes as $path => $params) {
        $builder->add([$verb], $path, $params, ['handler' => ['method' => $path], 'consumes' => [], 'fromBody' => null], $path);
    }
    return $builder->build();
};

$matches = static function (array $table, string $path): ?string {
    return Router::match($table, 'GET', $path)->route['handler']['method'] ?? null;
};

$parameters = static fn(string $route, string $path, array $params = []): ?array
    => ($match = Router::match($table([$route => $params]), 'GET', $path))->route === null ? null : $match->parameters;

// --- Types

Assert::same(['id' => '5'], $parameters('{id:int}', '5'));
Assert::same(['id' => '-5'], $parameters('{id:long}', '-5'));
Assert::null($parameters('{id:int}', '5a'));
Assert::null($parameters('{id:int}', '1-2'), 'the old pattern accepted this');
Assert::same(['n' => '1.5'], $parameters('{n:float}', '1.5'));
Assert::null($parameters('{n:decimal}', '1.2.3'), 'the old pattern accepted this');
Assert::same(['f' => 'true'], $parameters('{f:bool}', 'true'));
Assert::null($parameters('{f:bool}', 'yes'));
Assert::same(['a' => 'abc'], $parameters('{a:alpha}', 'abc'));
Assert::null($parameters('{a:alpha}', 'ab1'));
Assert::same(['g' => '01234567-89ab-cdef-0123-456789abcdef'], $parameters('{g:guid}', '01234567-89ab-cdef-0123-456789abcdef'));
Assert::null($parameters('{g:guid}', '123'));
Assert::same(['i' => '12'], $parameters('{i:intOrGuid}', '12'));
Assert::same(['s' => 'any thing'], $parameters('{s}', 'any%20thing'), 'values are URL-decoded');
Assert::null($parameters('{s}', 'a/b'), 'a plain parameter stops at a slash');
Assert::same(['p' => 'a/b/c'], $parameters('{p:all}', 'a/b/c'));
Assert::same(['c' => 'ABC'], $parameters('{c:regex}', 'ABC', ['c' => '[A-Z]{3}']));
Assert::null($parameters('{c:regex}', 'ABCD', ['c' => '[A-Z]{3}']), 'the expression must cover the whole value');
Assert::null($parameters('{s}', '%FF'), 'malformed UTF-8 never reaches a controller');

// --- Optional parameters take their slash along

Assert::same([], $parameters('a/{?b}', 'a'));
Assert::same(['b' => 'x'], $parameters('a/{?b}', 'a/x'));
Assert::same([], $parameters('a/{?b}/c', 'a/c'));
Assert::same(['b' => 'x'], $parameters('a/{?b}/c', 'a/x/c'));
Assert::same([], $parameters('{?a}/c', 'c'));
Assert::same(['a' => 'x', 'b' => 'y'], $parameters('{?a}/{?b}', 'x/y'));
Assert::same([], $parameters('{?a}', ''));
Assert::same(['name' => 'x'], $parameters('file{?name}', 'filex'));
Assert::same([], $parameters('file{?name}', 'file'));

// --- Literal text is literal

Assert::null($parameters('a.b/{x}', 'aXb/1'));
Assert::same(['x' => '1'], $parameters('a.b/{x}', 'a.b/1'));
Assert::same(['x' => '1'], $parameters('a#b/{x}', 'a#b/1'), 'the regex delimiter is safe in a route');
Assert::null($parameters('Users/{x}', 'users/1'), 'paths are case-sensitive');

// --- Constraints

foreach ([
    ['min(5)', '5', true], ['min(5)', '4', false], ['min(5)', 'abc', false],
    ['max(5)', '5', true], ['max(5)', '6', false], ['max(5)', 'abc', false],
    ['min(1.5)', '1.5', true], ['min(1.5)', '1.4', false],
    ['min(-5)', '-5', true], ['min(-5)', '-6', false],
    ['range(1,3)', '1', true], ['range(1,3)', '3', true], ['range(1,3)', '4', false], ['range(1,3)', '0', false],
    ['length(2)', 'ab', true], ['length(2)', 'abc', false], ['length(2)', 'žš', true],
    ['length(1,2)', 'a', true], ['length(1,2)', 'abc', false],
    ['minlength(2)', 'ab', true], ['minlength(2)', 'a', false],
    ['maxlength(2)', 'ab', true], ['maxlength(2)', 'abc', false],
] as [$definition, $value, $expected]) {
    $check = ParamChecker::parse($definition);
    Assert::type('array', $check, $definition);
    Assert::same($expected, ParamChecker::check($value, $check), "$definition on '$value'");
}

Assert::null(ParamChecker::parse('nonsense'));
Assert::null(ParamChecker::parse('min(a)'));

Assert::same(['id' => '5'], $parameters('{id:int:min(1):max(9)}', '5'));
Assert::null($parameters('{id:int:min(1):max(9)}', '10'));
Assert::same([], $parameters('a/{?id:min(1)}', 'a'), 'a missing optional parameter is not checked');

// --- Mistakes fail the compilation

foreach ([
    'bad/{id:imt}' => "'imt'",
    'bad/{id:regex}' => 'no expression',
    'bad/{id' => 'must be',
    'bad/id}' => 'unbalanced',
    'bad/{a}}' => 'unbalanced',
    'bad/{}' => 'must be',
    'bad/{a-b}' => 'must be',
    'bad/{*all}' => 'must be',
    'bad/{a}/{a}' => 'regular expression',
    '(users|people)/{id}' => 'looks like a regular expression',
    '(users|people)' => 'looks like a regular expression',
    'v[0-9]+/{id}' => 'looks like a regular expression',
    'a+/{id}' => 'looks like a regular expression',
    'a*' => 'looks like a regular expression',
    'a?' => 'looks like a regular expression',
    '^a' => 'looks like a regular expression',
    'a$' => 'looks like a regular expression',
    'a\d' => 'looks like a regular expression',
    'x-(a)-{id}' => 'looks like a regular expression',
] as $route => $expected) {
    $e = Assert::exception(static fn() => RouteCompiler::compile($route), InvalidStateException::class);
    Assert::contains($expected, $e->getMessage(), $route);
}

// Ordinary punctuation stays literal, and expressions are still welcome inside a parameter
Assert::same(['n' => 'a'], $parameters('file.json/{n}', 'file.json/a'));
Assert::null($parameters('file.json/{n}', 'fileXjson/a'), 'a dot is a dot');
Assert::same(['n' => '5'], $parameters('my-file_v1~/{n:int}', 'my-file_v1~/5'));
Assert::same(['c' => 'AB-12'], $parameters('{c:regex}', 'AB-12', ['c' => '(AB|CD)-[0-9]+']));

Assert::exception(
    static fn() => RouteCompiler::compile('{a:regex}', ['a' => '(unclosed']),
    InvalidStateException::class,
);

// --- Order doesn't depend on who registered first

$ordered = $table([
    'users/{p:all}' => [],
    'users/{slug}' => [],
    'users/{id:int}' => [],
    'users/me' => [],
    'users/{id:int}/posts' => [],
    'users/{slug}/posts' => [],
    'users/me/{x}' => [],
]);

Assert::same('users/me', $matches($ordered, 'users/me'), 'literal first');
Assert::same('users/{id:int}', $matches($ordered, 'users/5'), 'constrained before plain');
Assert::same('users/{slug}', $matches($ordered, 'users/john'));
Assert::same('users/{id:int}/posts', $matches($ordered, 'users/5/posts'));
Assert::same('users/{slug}/posts', $matches($ordered, 'users/john/posts'));
Assert::same('users/me/{x}', $matches($ordered, 'users/me/posts'), 'literal segments are compared from the left');
Assert::same('users/{p:all}', $matches($ordered, 'users/a/b/c'), 'catch-all last');
Assert::null($matches($ordered, 'other'));

// A constraint that fails moves on to the next route
Assert::same('{slug}', $matches($table(['{id:int:min(10)}' => [], '{slug}' => []]), '5'));

// Routes differing only in a constraint keep their own constraints
$builder = new RouteTableBuilder();
$item = static fn(string $name): array => ['handler' => ['method' => $name], 'consumes' => [], 'fromBody' => null];
$builder->add(['GET'], '{id:int:min(1)}', [], $item('read'), 'read');
$builder->add(['PUT'], '{id:int}', [], $item('write'), 'write');
$separate = $builder->build();
Assert::null(Router::match($separate, 'GET', '0')->route);
Assert::same(['PUT'], Router::match($separate, 'GET', '0')->allowedVerbs);
Assert::same('read', Router::match($separate, 'GET', '1')->route['handler']['method']);
Assert::same('write', Router::match($separate, 'PUT', '0')->route['handler']['method']);

// --- Verbs

$builder = new RouteTableBuilder();
$route = static fn(string $name): array => ['handler' => ['method' => $name], 'consumes' => [], 'fromBody' => null];
$builder->add(['GET'], 'items', [], $route('list'), 'list');
$builder->add(['POST'], 'items', [], $route('create'), 'create');
$builder->add(['GET'], 'items/{id:int}', [], $route('detail'), 'detail');
$builder->add(['PUT', 'PATCH'], 'items/{id:int}', [], $route('update'), 'update');
$builder->add(['DELETE'], 'items/{slug}', [], $route('removeBySlug'), 'removeBySlug');
$verbs = $builder->build();

Assert::same('create', Router::match($verbs, 'POST', 'items')->route['handler']['method']);
Assert::same('update', Router::match($verbs, 'PATCH', 'items/5')->route['handler']['method']);
Assert::same('removeBySlug', Router::match($verbs, 'DELETE', 'items/5')->route['handler']['method'], 'a verb of a later route still matches');

$noVerb = Router::match($verbs, 'DELETE', 'items');
Assert::null($noVerb->route);
Assert::same(['GET', 'POST'], $noVerb->allowedVerbs);

$noVerb = Router::match($verbs, 'POST', 'items/5');
Assert::same(['GET', 'PUT', 'PATCH', 'DELETE'], $noVerb->allowedVerbs);

Assert::same([], Router::match($verbs, 'GET', 'nothing')->allowedVerbs);

// --- Duplicates

Assert::exception(static function () use ($route): void {
    $builder = new RouteTableBuilder();
    $builder->add(['GET'], 'x/{a:int}', [], $route('one'), 'one');
    $builder->add(['GET'], 'x/{b:int}', [], $route('two'), 'two');
}, InvalidStateException::class, "%a%already defined by one%a%");

Assert::exception(static function () use ($route): void {
    $builder = new RouteTableBuilder();
    $builder->add(['GET'], 'x', [], $route('one'), 'one');
    $builder->add(['GET'], '/x/', [], $route('two'), 'two');
}, InvalidStateException::class);

// Same shape, different verb or constraint: both fine
$builder = new RouteTableBuilder();
$builder->add(['GET'], 'x/{a:int}', [], $route('one'), 'one');
$builder->add(['POST'], 'x/{b:int}', [], $route('two'), 'two');
$builder->add(['GET'], 'x/{a:int:min(5)}', [], $route('three'), 'three');
Assert::type('array', $builder->build());
