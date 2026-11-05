# phloxcz/rest-api

**REST server for [Nette](https://nette.org).**

API controllers are ordinary DI services. Their routes, authorization and parameters are described by PHP attributes and native types, and the route table is compiled into the DI container, so nothing is reflected or cached at runtime to find a route.

> ⚠️ This package is under active development. The API is not yet stable.

## Requirements

PHP 8.4+ and [`phloxcz/api-core`](../phlox-api-core), which documents controllers, authorization and parameter types.

Optional: `nette/application` for returning `Nette\Application\Response` objects.

## Installation

```bash
composer require phloxcz/rest-api
```

```neon
extensions:
    restApi: Phlox\RestApi\DI\Extension

restApi:
    endpoints:
        api:                            # served under /api/
            namespace: App\ApiModule    # string or list; includes sub-namespaces
            authorize: true             # default for methods without an attribute
            accessControl:
                allowedOrigins: ['https://app.example.com']
                allowCredentials: true

services:
    - App\ApiModule\UserController
```

## Usage

```php
namespace App\ApiModule;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\Authorize;
use Phlox\RestApi\Attributes\HttpDelete;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\HttpPost;
use Phlox\RestApi\Attributes\Route;

#[Route('users')]
class UserController extends ApiController
{
    #[HttpGet]
    #[Route('')]
    public function list(int $page = 1): array { /* GET /api/users?page=2 */ }

    #[HttpGet]
    #[Route('{id:int:min(1)}')]
    public function detail(int $id): array { /* GET /api/users/5 */ }

    #[HttpPost]
    #[Route('')]
    public function create(string $name, int $age = 0): array { /* POST /api/users, {"name": "Ann"} */ }

    #[HttpDelete]
    #[Route('{id:int}')]
    #[Authorize('admin')]
    public function remove(int $id): void { /* DELETE /api/users/5 */ }
}
```

A public method is an endpoint when it has an HTTP verb attribute: `#[HttpGet]`, `#[HttpPost]`, `#[HttpPut]`, `#[HttpPatch]`, `#[HttpDelete]`, `#[HttpHead]` or `#[HttpOptions]`. One method can have several. A method with `#[Route]` but no verb fails when the container compiles.

## Routes

Without `#[Route]`, the path comes from the name in kebab-case: `UserProfileController::showDetail()` is `user-profile/show-detail`. A controller's path and a method's path are joined with a slash. `#[Route('')]` leaves that part out. Paths are case-sensitive and a trailing slash is ignored. The endpoint's root (`/api` itself) is not a route; it stays with the application, or with a JSON-RPC endpoint of the same name.

| Parameter | Matches |
|---|---|
| `{name}` | anything up to the next slash |
| `{?name}` | the same, optional, including its slash: `a/{?b}/c` matches `a/c` and `a/x/c` |
| `{id:int}`, `{id:long}` | an integer |
| `{n:float}`, `{n:decimal}`, `{n:double}` | a number |
| `{flag:bool}` | `true`, `false`, `0`, `1` |
| `{a:alpha}` | letters |
| `{g:guid}`, `{g:intOrGuid}` | a GUID, or an integer or a GUID |
| `{path:all}` | the rest of the path, including slashes |
| `{code:regex}` | the expression from `#[Route('{code:regex}', ['code' => '[A-Z]{3}'])]` |

Constraints follow the type: `min(1)`, `max(9)`, `range(1,9)`, `length(3)`, `length(1,3)`, `minlength(1)`, `maxlength(3)`, for example `{id:int:min(1):max(9)}`.

A value that doesn't fit means the route doesn't match, and the next one is tried. Types and constraints only select a route; the value is converted according to the type of the method's parameter. A missing optional parameter gets that parameter's default value. Values are URL-decoded.

Text outside `{...}` is literal, and an expression has to go into a `regex` parameter: instead of `(users|people)/{id}`, write `#[Route('{kind:regex}/{id}', ['kind' => 'users|people'])]`. Expressions are case-sensitive unless they start with `(?i)`.

A mistake in a route fails when the container compiles: `{id:imt}`, two methods with the same verb and path, or any of the characters `( ) [ ] | + * ? ^ $ \` in literal text.

### Order

The order of controllers and methods doesn't matter. Literal routes are tried first. The others are compared segment by segment from the left: literal text, then a constrained parameter, a plain one and a catch-all. So for `GET users/me`, `users/{id:int}` and `users/{slug}`, the path `users/me` goes to the first one, `users/5` to the second and `users/john` to the third.

## Parameters and bodies

Parameters are filled from the path, then the query, then a JSON body of `POST`, `PUT` and `PATCH`, and the earlier source wins: a client can't override `{id}` from the path with a query parameter or a body field. Conversions are described in [`phloxcz/api-core`](../phlox-api-core#parameters).

A missing `Content-Type` counts as `application/json`, but a body which isn't JSON then simply doesn't fill any parameters, unless the method has `#[FromBody]`. `application/*+json` is JSON too. A body of any other type isn't read at all unless the method asks for it, so a controller can stream a large upload from `php://input` itself. `$this->jsonData` holds the decoded body; with `jsonForceArray: false` it's made of objects instead of arrays.

`#[FromBody]` gives one parameter the whole body. JSON is decoded (into an array when the parameter accepts one, otherwise into an object) and anything else stays a string:

```php
#[HttpPost]
public function import(#[FromBody] array $rows): void { /* ... */ }

#[HttpPut]
#[Route('{id:int}/photo')]
#[Consumes('image/*')]
#[Consumes('application/pdf')]
public function upload(int $id, #[FromBody] string $content): void { /* ... */ }
```

`#[Consumes]` lists accepted content types. A body of another type gets `415`. A method with `#[FromBody]` and no `#[Consumes]` accepts `application/json`; a method with neither accepts anything.

A DTO can be the whole body or one of the parameters. See [DTOs](../phlox-api-core#dtos).

```php
#[HttpPost]
#[Route('')]
public function create(#[FromBody] CreateUser $input): UserOutput { /* ... */ }

/** @param list<CreateUser> $users */
#[HttpPost]
#[Route('import')]
public function import(#[FromBody] array $users): void { /* ... */ }
```

Problems in a body are reported without the parameter's name: `name`, `addresses.1.city`.

## Responses

| The controller | The client gets |
|---|---|
| returns a value | JSON, `200` |
| returns nothing or `null` | no body, `204` |
| sets a code with `$this->response->setCode(201)` | that code, with or without a value |
| returns `Nette\Application\Response` | whatever it sends |
| calls `$this->terminate()` | nothing added |
| writes the response itself with `readfile()` or `print` and returns nothing | what it wrote |

Errors have the form `{"error": {"code": -32601, "message": "..."}}`, with `data` of an `ApiException` merged next to `code` and `message`.

| Situation | HTTP status | `code` |
|---|---|---|
| unknown path | `404` | `-32601` |
| wrong verb, with an `Allow` header | `405` | `-32601` |
| malformed JSON | `400` | `-32700` |
| unsupported content type | `415` | `-32600` |
| invalid parameters | `422` | `-32602` |
| not logged in | `401` | `401` |
| missing role | `403` | `403` |
| unexpected exception (logged, details hidden) | `500` | `-32603` |
| `ApiException` | its `httpCode`, by default `500` for your own codes | its code |

The content type and JSON syntax are checked before authorization, so these two errors can reach an anonymous client. Parameters are validated after it.

## OpenAPI

Every endpoint gets an OpenAPI 3.1 document, generated when the container compiles from the same routes the server runs on. In debug mode it's published at `/api/openapi.json` and `/api/openapi.yaml`. What it's made of is described in [`phloxcz/api-core`](../phlox-api-core#documentation).

```neon
restApi:
    endpoints:
        api:
            namespace: App\ApiModule
            documentation:
                expose: true        # by default only in debug mode
                path: openapi       # gives /api/openapi.json and /api/openapi.yaml
                info: {title: Orders API, version: 2.1.0}
                securitySchemes:
                    bearerAuth: {type: http, scheme: bearer}
```

On top of that, REST documents these on its own:

- **Paths:** a route with an optional parameter becomes a path for every combination, because OpenAPI has no optional path parameters.
- **Path parameters:** types and constraints of a route, so `{id:int:min(1)}` is an integer with `minimum: 1` and `{id:guid}` has `format: uuid`.
- **Other parameters:** in the query, except for `POST`, `PUT` and `PATCH` without `#[FromBody]`, where they're properties of a JSON body.
- **Responses:** the return type, `204` for nothing, and for `?Foo` both.
- **Errors:** `400`, `401`, `403`, `404`, `415` and `422` wherever they can happen.
- **Security:** with `securitySchemes`, a method requiring login lists all schemes as alternatives, and a public one has `security: []`. Roles stay in `x-roles` and the description, because a security requirement can't say "admin or editor".

`#[Response]` adds what can't be read from the signature, and a successful response declared there replaces the one read from the return type:

```php
#[HttpPost]
#[Response(201)]                                     // the return type, with a different code
public function create(#[FromBody] CreateUser $input): UserOutput { /* ... */ }

#[HttpGet]
#[Response(200, '@binary', 'application/pdf', 'The invoice')]
#[Response(404, '@error', description: 'No such invoice')]
public function download(int $id): FileResponse { /* ... */ }
```

The content of `#[Response]` is a type as in a docblock (`list<UserOutput>`), JSON Schema, `@binary` or `@error`.

While the document is published, a route of your own at the same path fails when the container compiles. Change `path` if that happens.

## CORS

Without `allowedOrigins`, no CORS headers are sent. An origin not on the list gets none either. `['*']` sends a literal `*`; combining it with `allowCredentials: true` fails when the container compiles, because browsers refuse it. A client sending an `Authorization` header needs no credentials here; for cookies, list the origins.

An `OPTIONS` request gets `204`, unless a method declares `#[HttpOptions]` for that path. If clients send a token, add `authorization` to `allowHeaders`.

## Configuration

| Key | Default | Description |
|---|---|---|
| `debug` | `%debugMode%` | Adds internal messages to error responses |
| `dateTimeFormats` | see `ParameterBinder` | Accepted formats of date parameters |
| `endpoints.<name>.namespace` | required | Controllers served by the endpoint |
| `endpoints.<name>.authorize` | `true` | Whether methods without an attribute require login |
| `endpoints.<name>.jsonForceArray` | `true` | Whether `$this->jsonData` uses arrays rather than objects |
| `endpoints.<name>.accessControl` | no CORS | `allowedOrigins`, `allowCredentials`, `allowMethods`, `allowHeaders`, `exposeHeaders`, `maxAge` |
| `endpoints.<name>.documentation` | generated, published in debug mode | see [OpenAPI](#openapi) |

An endpoint's name can contain a slash, such as `api/v2`, and the longest match wins. It can live in one application with [`phloxcz/rpc-api`](../phlox-rpc-api), even under the same name.

## Migrating from abrad450/api-server-nette

The whole way is in [`MIGRATION.md`](../phlox-api-core/MIGRATION.md). What concerns this package:

Namespaces:

| Old | New |
|---|---|
| `AbraD450\ApiServer\ApiController` | `Phlox\ApiCore\ApiController` |
| `AbraD450\ApiServer\Common\Attributes\{Authorize,AllowAnonymous,NonApi}` | `Phlox\ApiCore\Attributes\*` |
| `AbraD450\ApiServer\Common\Attributes\FromBody` | `Phlox\RestApi\Attributes\FromBody` |
| `AbraD450\ApiServer\REST\Attributes\*` | `Phlox\RestApi\Attributes\*` |
| `AbraD450\ApiServer\REST\ApiException` | `Phlox\ApiCore\ApiException` |
| `AbraD450\ApiServer\ApiConstructible` | `Phlox\ApiCore\ApiConstructible` |

Changes that need checking in an existing project:

- **Config:** `apiServer: rest: <name>` becomes `restApi: endpoints: <name>`.
- **Authorization is on by default.** Mark public methods with `#[AllowAnonymous]`, or set `authorize: false` on the endpoint.
- **`#[Authorize(['a', 'b'])]` now means a or b, so it lets in more users than before.** Where both roles are needed, write `#[Authorize(['a', 'b'], all: true)]`. Attributes on a class and a method still have to hold together.
- **A verb is required.** A method with no verb used to accept any verb. Now it's not an endpoint, and if it has `#[Route]`, the container doesn't compile.
- **Inherited methods are no longer exposed.** A method from a shared base controller has to be overridden in the concrete controller.
- **`ApiException` has a different order of arguments.** The fourth positional argument was `$httpCode`, now it's `$previous`. Use the named `httpCode:`.
- **Status codes changed.** Errors that were `500` by default are now `400`, `404`, `405`, `415` or `422`, see above. A method with no value returns `204` instead of an empty `200`.
- **Routes are stricter.** Paths and `regex` expressions are case-sensitive (start an expression with `(?i)` to change that), a controller's path must end at a segment boundary, and an unknown token such as `{id:imt}` fails instead of being ignored. A regular expression in literal text, such as `(users|people)/{id}`, fails too and has to move into a `regex` parameter.
- **`#[Consumes]` is enforced,** where it used to serve only for documentation and JSON decoding.
- **Path parameters are URL-decoded.**
- **CORS:** `allowedOrigins: ['*']` with `allowCredentials: true` must become a list of origins.
- **The bootstrap runs before the API.** Code after `createContainer()` now also runs for API requests, including anything like a redirect or a maintenance mode. `index.php` has to call `Application::run()`.
- **Dates and times:** a time zone alone, such as `x` or `utc`, is now refused instead of meaning the current time.
- **Documentation:** the `openapi` config with `content` and `outputPath` is gone. Move `info` and `securitySchemes` to `documentation`, anything else to `documentation.extra`, and write files with `OpenApi::toYaml()` if you need them. `#[Response]` moves to `Phlox\RestApi\Attributes\Response`. `#[Parameter]` moves to `Phlox\ApiCore\Attributes\Parameter` and is deprecated, because a docblock or `#[Schema]` says the same. The array notation such as `['object', ['id' => 'int']]` keeps working in both, but is deprecated as well in favour of DTOs and docblock types.
- **The query language** moved to [`phloxcz/api-query`](../phlox-api-query#migrating-from-abrad450api-server-nette), including `$this->processor`.

## License

[MIT](LICENSE)
