# phloxcz/rest-api

[English](README.md) | **Česky**

**REST server pro [Nette](https://nette.org).**

API kontrolery jsou běžné služby v DI kontejneru. Jejich routy, autorizaci a parametry popisují atributy PHP a nativní typy. Tabulka rout se kompiluje do DI kontejneru, takže se při běhu pro hledání routy nic nereflektuje ani nekešuje.

> ⚠️ Balíček se aktivně vyvíjí. API zatím není stabilní.

## Požadavky

PHP 8.4+ a [`phloxcz/api-core`](https://github.com/phloxcz/api-core/blob/master/README.cs.md), který popisuje kontrolery, autorizaci a typy parametrů.

Volitelně: `nette/application` pro vracení objektů `Nette\Application\Response`.

## Instalace

```bash
composer require phloxcz/rest-api
```

```neon
extensions:
    restApi: Phlox\RestApi\DI\Extension

restApi:
    endpoints:
        api:                            # běží pod /api/
            namespace: App\ApiModule    # text nebo seznam; včetně vnořených jmenných prostorů
            authorize: true             # výchozí pro metody bez atributu
            accessControl:
                allowedOrigins: ['https://app.example.com']
                allowCredentials: true

services:
    - App\ApiModule\UserController
```

## Použití

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

Veřejná metoda je endpoint, když má atribut HTTP slovesa: `#[HttpGet]`, `#[HttpPost]`, `#[HttpPut]`, `#[HttpPatch]`, `#[HttpDelete]`, `#[HttpHead]` nebo `#[HttpOptions]`. Jedna metoda jich může mít víc. Metoda s `#[Route]` bez slovesa selže při kompilaci kontejneru.

## Routy

Bez `#[Route]` vznikne cesta z názvu v kebab-case: `UserProfileController::showDetail()` je `user-profile/show-detail`. Cesta kontroleru a metody se spojí lomítkem. `#[Route('')]` tu část vynechá. Cesty rozlišují velikost písmen a koncové lomítko se ignoruje. Kořen endpointu (samotné `/api`) není routa, patří aplikaci nebo JSON-RPC endpointu stejného jména.

| Parametr | Odpovídá |
|---|---|
| `{name}` | čemukoli do dalšího lomítka |
| `{?name}` | totéž, ale nepovinné, včetně lomítka: `a/{?b}/c` odpovídá `a/c` i `a/x/c` |
| `{id:int}`, `{id:long}` | celému číslu |
| `{n:float}`, `{n:decimal}`, `{n:double}` | číslu |
| `{flag:bool}` | `true`, `false`, `0`, `1` |
| `{a:alpha}` | písmenům |
| `{g:guid}`, `{g:intOrGuid}` | GUID, nebo celému číslu či GUID |
| `{path:all}` | zbytku cesty, včetně lomítek |
| `{code:regex}` | výrazu z `#[Route('{code:regex}', ['code' => '[A-Z]{3}'])]` |

Omezení následují za typem: `min(1)`, `max(9)`, `range(1,9)`, `length(3)`, `length(1,3)`, `minlength(1)`, `maxlength(3)`, například `{id:int:min(1):max(9)}`.

Hodnota, která nesedí, znamená, že routa neodpovídá a zkouší se další. Typy a omezení jen vybírají routu, hodnota se převede podle typu parametru metody. Chybějící nepovinný parametr dostane výchozí hodnotu parametru metody. Hodnoty jsou dekódované z URL.

Text mimo `{...}` se bere doslovně a výraz musí být v parametru `regex`: místo `(users|people)/{id}` napište `#[Route('{kind:regex}/{id}', ['kind' => 'users|people'])]`. Výrazy rozlišují velikost písmen, pokud nezačínají `(?i)`.

Chyba v routě selže při kompilaci kontejneru: `{id:imt}`, dvě metody se stejným slovesem a cestou nebo některý ze znaků `( ) [ ] | + * ? ^ $ \` v doslovném textu.

### Pořadí

Na pořadí kontrolerů a metod nezáleží. Nejdřív se zkoušejí doslovné routy. Ostatní se porovnávají po segmentech zleva: doslovný text, parametr s omezením, obyčejný parametr a nakonec catch-all. U `GET users/me`, `users/{id:int}` a `users/{slug}` tedy cesta `users/me` jde na první, `users/5` na druhou a `users/john` na třetí.

## Parametry a těla požadavků

Parametry se plní z cesty, pak z query a pak z JSON těla `POST`, `PUT` a `PATCH` a dřívější zdroj má přednost: klient nemůže `{id}` z cesty přepsat parametrem v query ani polem v těle. Převody popisuje [`phloxcz/api-core`](https://github.com/phloxcz/api-core/blob/master/README.cs.md#parametry).

Chybějící `Content-Type` se bere jako `application/json`, ale tělo, které není JSON, pak prostě žádné parametry nenaplní, pokud metoda nemá `#[FromBody]`. `application/*+json` je také JSON. Tělo jiného typu se vůbec nečte, pokud o to metoda nepožádá, takže si kontroler může velký upload streamovat z `php://input` sám. `$this->jsonData` obsahuje dekódované tělo; s `jsonForceArray: false` z objektů místo polí.

`#[FromBody]` předá jednomu parametru celé tělo. JSON se dekóduje (do pole, když ho parametr přijímá, jinak do objektu) a cokoli jiného zůstane textem:

```php
#[HttpPost]
public function import(#[FromBody] array $rows): void { /* ... */ }

#[HttpPut]
#[Route('{id:int}/photo')]
#[Consumes('image/*')]
#[Consumes('application/pdf')]
public function upload(int $id, #[FromBody] string $content): void { /* ... */ }
```

`#[Consumes]` vyjmenovává přijímané typy obsahu. Tělo jiného typu dostane `415`. Metoda s `#[FromBody]` bez `#[Consumes]` přijímá `application/json`, metoda bez obojího přijímá cokoli.

Bez `#[FromBody]` kontroluje `#[Consumes]` jen typ a tělo zůstane nenačtené, aby ho metoda mohla streamovat. Dokumentace pak tělo popíše jako soubor:

```php
#[HttpPost]
#[Route('{id:int}/attachment')]
#[Consumes('application/octet-stream')]
public function attach(int $id): array
{
    $bytes = stream_copy_to_stream(fopen('php://input', 'rb'), fopen($this->storage->path($id), 'wb'));
    // ...
}
```

### Hlavičky a formuláře

`#[FromHeader]` naplní parametr hlavičkou a `#[FromForm]` polem odeslaného formuláře, `multipart/form-data` nebo `application/x-www-form-urlencoded`. Soubor přijde jako `Nette\Http\FileUpload`:

```php
/** @param list<FileUpload> $attachments */
#[HttpPost]
#[Route('{id:int}/document')]
public function document(
    int $id,
    #[FromForm] FileUpload $file,
    #[FromForm] ?string $note = null,
    #[FromForm('attachment')] array $attachments = [],
    #[FromHeader('X-Request-Id')] ?string $requestId = null,
): array { /* ... */ }
```

- **Názvy:** vlastní název parametru, nebo zadaný. Hlavička bez něj se odvodí z parametru, takže `$ifMatch` čte `If-Match`, bez ohledu na velikost písmen.
- **Hodnoty** se převádějí stejně jako parametry z query. Chybějící je jako neposlaná: výchozí hodnota, null, nebo `422` s `is required.`
- **Soubory:** prázdný souborový vstup je jako neposlaný. Příliš velký soubor dostane `422` s `is too large.`, vadný upload `could not be uploaded.`
- **Jen odtud:** parametr z query ani pole JSON se stejným názvem je nikdy nenaplní.
- **Typ obsahu:** metoda s `#[FromForm]` bez `#[Consumes]` přijímá oba typy formulářů a na cokoli jiného vrací `415`. Nemůže mít zároveň `#[FromBody]`.

Formulář funguje s `POST`, `PUT` i `PATCH`. U `POST` ho čte samo PHP, ostatní se čtou přes `request_parse_body()`, a to jen u metody, která formulář přijímá.

DTO může být celé tělo nebo jeden z parametrů. Viz [DTO](https://github.com/phloxcz/api-core/blob/master/README.cs.md#dto).

```php
#[HttpPost]
#[Route('')]
public function create(#[FromBody] CreateUser $input): UserOutput { /* ... */ }

/** @param list<CreateUser> $users */
#[HttpPost]
#[Route('import')]
public function import(#[FromBody] array $users): void { /* ... */ }
```

Problémy v těle se hlásí bez názvu parametru: `name`, `addresses.1.city`.

## Ukládání dat

Dovnitř přichází DTO a ven jde to, o co si řekne dotaz. Entita se z požadavku nesestavuje, takže klient nemůže nastavit nic, co DTO nedeklaruje, třeba `id`. `#[FromBody] App\Entity\Type $type` dostane `422`. Příklady používají [`phloxcz/entity-database`](https://github.com/phloxcz/entity-database/blob/master/README.cs.md) a [`phloxcz/api-query`](https://github.com/phloxcz/api-query/blob/master/README.cs.md) a v [`phlox-api-sample`](https://github.com/phloxcz/api-sample/blob/master/README.cs.md) jsou spuštěné.

```php
#[Dto]
final readonly class SaveType
{
    public function __construct(
        #[Length(min: 1, max: 50)] public string $ident,
        #[Length(max: 100)] public ?string $name = null,
    ) {
    }
}
```

### POST: založení

```php
#[HttpPost]
#[Route('')]
#[Response(201)]
public function create(#[FromBody] SaveType $type, #[QueryOf(TypeResource::class, single: true)] ?Query $query = null): ?array
{
    try {
        $created = $this->db->table('type')->insert([
            'ident' => $type->ident,     // klíče jsou názvy sloupců
            'name' => $type->name,
        ]);
    } catch (UniqueConstraintViolationException) {
        throw new ApiException(409, "Type '{$type->ident}' already exists.");
    }

    $this->response->setCode(201);

    return $this->processor->process($created, $query);
}
```

Abyste nemuseli opakovat názvy sloupců, nechte je přeložit entitu:

```php
$new = new Type();
$new->ident = $type->ident;
$new->name = $type->name;
$created = $this->db->table('type')->insert($new->toModifiedArrayDb());
```

`insert()` jednoho řádku vrátí jeho entitu, i na Firebirdu. U tabulky bez primárního klíče vrátí počet řádků a na SQLite musí být klíč `AUTOINCREMENT`, nebo musí být zadaný.

### PUT: nahrazení

Co nepřijde, dostane výchozí hodnotu z DTO, takže vynechání `name` výše ho nastaví na `null` a vynechání `ident` skončí `422`.

```php
#[HttpPut]
#[Route('{id:int}')]
public function replace(int $id, #[FromBody] SaveType $type, #[QueryOf(TypeResource::class, single: true)] ?Query $query = null): ?array
{
    $entity = $this->findType($id);
    $entity->ident = $type->ident;
    $entity->name = $type->name;
    $entity->saveModified();         // zapíše jen změněné sloupce

    return $this->processor->process($entity, $query);
}

private function findType(int $id): Type
{
    return $this->db->table('type')->get($id)
        ?? throw new ApiException(404, 'Type not found.');
}
```

Privátní metoda není endpoint. Aby se klient dostal jen ke svým záznamům, nejdřív omezte selection: `$this->db->table('tasks')->where('project_id', $projectId)->get($id)`.

### PATCH: změna toho, co přišlo

Stejné DTO, ale přes `Patch`, který rozliší vynechané pole od pole poslaného jako `null`. Viz [Změna jen toho, co přišlo](https://github.com/phloxcz/api-core/blob/master/README.cs.md#změna-jen-toho-co-přišlo).

```php
#[HttpPatch]
#[Route('{id:int}')]
public function change(int $id, #[FromBody, PatchOf(SaveType::class)] Patch $changes, #[QueryOf(TypeResource::class, single: true)] ?Query $query = null): ?array
{
    $entity = $changes->applyTo($this->findType($id));
    $entity->saveModified();

    return $this->processor->process($entity, $query);
}
```

`applyTo()` potřebuje, aby se pole jmenovala stejně jako vlastnosti entity. Kde se liší nebo kde je potřeba opatrnost, ptejte se po jednom: `if ($changes->has('name')) { ... }`.

### Kolekce

DTO drží seznam pomocí docblocku, viz [Kolekce](https://github.com/phloxcz/api-core/blob/master/README.cs.md#kolekce). Aby se uložilo všechno, nebo nic, použijte transakci:

```php
#[HttpPost]
#[Route('batch')]
#[Response(201, new Shape(['ids' => new ListOf('int')]))]
public function batch(#[FromBody] ImportTypes $import): array
{
    $ids = $this->db->transaction(static function (Explorer $db) use ($import): array {
        $ids = [];
        foreach ($import->types as $type) {
            $ids[] = $db->table('type')->insert(['ident' => $type->ident, 'name' => $type->name])->id;
        }
        return $ids;
    });

    $this->response->setCode(201);

    return ['ids' => $ids];
}
```

### Odpověď

| Co se stane | Stav |
|---|---|
| Založeno, přes `setCode(201)` | `201` |
| Uloženo a vrací se záznam | `200` |
| Uloženo a nevrací se nic | `204` |
| Hodnota porušuje pravidla DTO, s cestou ke každé | `422` |
| Záznam nenalezen, vaší `ApiException` | `404` |
| Unikátní hodnota je už obsazená, vaším `catch` | `409` |
| Jakékoli jiné selhání databáze | `500` a zaloguje se |

`Query` v metodě s `#[FromBody]` přichází z URL pod názvem svého parametru: `?query[select]=id,name`. Samotné `?select=` není parametr metody, takže se ignoruje.

## Odpovědi

| Kontroler | Klient dostane |
|---|---|
| vrátí hodnotu | JSON, `200` |
| nevrátí nic nebo `null` | bez těla, `204` |
| nastaví kód přes `$this->response->setCode(201)` | ten kód, s hodnotou i bez ní |
| vrátí `Nette\Application\Response` | to, co odešle |
| zavolá `$this->terminate()` | nic navíc |
| zapíše odpověď sám přes `readfile()` nebo `print` a nic nevrátí | to, co zapsal |

Chyby mají tvar `{"error": {"code": 404, "message": "..."}}` a `data` z `ApiException` se sloučí vedle `code` a `message`. `code` je HTTP stav, pokud aplikace nedala vlastní `errorCode`:

| Situace | HTTP stav a `code` |
|---|---|
| neznámá cesta | `404` |
| špatné sloveso, s hlavičkou `Allow` | `405` |
| chybný JSON | `400` |
| nepodporovaný typ obsahu | `415` |
| neplatné parametry, s tím, co je špatně, ve `fields` | `422` |
| nepřihlášený | `401` |
| chybějící role | `403` |
| neočekávaná výjimka (zaloguje se, detaily se skryjí) | `500` |
| `ApiException` | její stav; `code` je její `errorCode`, nebo stav |

Kódy JSON-RPC, které oba protokoly sdílejí, se ke klientovi REST nikdy nedostanou. Viz [Chyby](https://github.com/phloxcz/api-core/blob/master/README.cs.md#chyby).

Typ obsahu a syntaxe JSON se kontrolují před autorizací, takže tyhle dvě chyby může dostat i nepřihlášený klient. Parametry se kontrolují až po ní.

## OpenAPI

Každý endpoint dostane dokument OpenAPI 3.1, vygenerovaný při kompilaci kontejneru ze stejných rout, podle kterých server běží. V debug režimu je zveřejněný na `/api/openapi.json` a `/api/openapi.yaml`. Z čeho se skládá, popisuje [`phloxcz/api-core`](https://github.com/phloxcz/api-core/blob/master/README.cs.md#dokumentace).

```neon
restApi:
    endpoints:
        api:
            namespace: App\ApiModule
            documentation:
                expose: true        # ve výchozím stavu jen v debug režimu
                path: openapi       # dá /api/openapi.json a /api/openapi.yaml
                info: {title: Orders API, version: 2.1.0}
                securitySchemes:
                    bearerAuth: {type: http, scheme: bearer}
```

Navíc REST sám dokumentuje:

- **Cesty:** routa s nepovinným parametrem se stane cestou pro každou kombinaci, protože OpenAPI nepovinné parametry cesty nezná.
- **Parametry cesty:** typy a omezení routy, takže `{id:int:min(1)}` je celé číslo s `minimum: 1` a `{id:guid}` má `format: uuid`.
- **Ostatní parametry:** v query, kromě `POST`, `PUT` a `PATCH` bez `#[FromBody]`, kde jsou to vlastnosti JSON těla.
- **Odpovědi:** nativní návratový typ nebo `#[QueryOf]`, `204` pro nic a u `?Foo` obojí.
- **Chyby:** `400`, `401`, `403`, `404`, `415` a `422` všude, kde mohou nastat.
- **Zabezpečení:** se `securitySchemes` uvede metoda vyžadující přihlášení všechna schémata jako alternativy a veřejná má `security: []`. Role zůstávají v `x-roles` a popisu, protože požadavek na zabezpečení neumí vyjádřit „admin nebo editor".

`#[Response]` přidává to, co metoda sama neřekne, a úspěšná odpověď uvedená v něm nahradí tu vyčtenou z metody:

```php
use Phlox\ApiCore\Schema\{Binary, Paged, Shape};

#[HttpGet]
#[Response(200, new Shape(['value' => new Paged(User::class)]))]
public function list(?Query $query = null): array { /* ... */ }

#[HttpPost]
#[Response(201)]                                     // návratový typ s jiným kódem
public function create(#[FromBody] CreateUser $input): UserOutput { /* ... */ }

#[HttpGet]
#[Response(200, new Binary, 'application/pdf', 'The invoice')]
#[Response(404, description: 'No such invoice')]     // kód od 400 dostane schéma chyb
public function download(int $id): FileResponse { /* ... */ }
```

Obsahem `#[Response]` je třída, skalár, nebo objekt jako `new ListOf(...)`, `new Shape([...])` či `new Paged(...)`, viz [Popis odpovědi](https://github.com/phloxcz/api-core/blob/master/README.cs.md#popis-odpovědi). Docblock `@return` se nečte.

Dokud je dokument zveřejněný, vlastní routa na stejné cestě selže při kompilaci kontejneru. V takovém případě změňte `path`.

### Test, že odpovědi odpovídají dokumentu

`OpenApiChecker` porovná odpověď s dokumentem, takže test pozná, když se dokumentace rozejde s tím, co API vrací:

```php
use Phlox\RestApi\Testing\OpenApiChecker;

$checker = OpenApiChecker::of($container->getByType(OpenApi::class), 'api/v1', '/api/v1');

$errors = $checker->check('GET', '/api/v1/users?page=2', $status, $contentType, $body);
Assert::same([], $errors);
```

Nahlásí stav, který operace nedokumentuje, JSON tam, kde žádný dokumentovaný není, a každou hodnotu, která neodpovídá schématu, i s tím, kde je, třeba `GET /api/v1/users 200: $[0].id: "3" is not integer`. Odpověď, kterou dokument nepokrývá, třeba jiného API, chybou není. Pusťte přes ni každou odpověď svých HTTP testů.

## CORS

Bez `allowedOrigins` se žádné CORS hlavičky neposílají. Origin mimo seznam je také nedostane. `['*']` pošle doslova `*`; kombinace s `allowCredentials: true` selže při kompilaci kontejneru, protože ji prohlížeče odmítají. Klient, který posílá hlavičku `Authorization`, tu credentials nepotřebuje. Pro cookies vyjmenujte originy.

Požadavek `OPTIONS` dostane `204`, pokud metoda pro tu cestu nedeklaruje `#[HttpOptions]`. Pokud klienti posílají token, přidejte `authorization` do `allowHeaders`.

## Konfigurace

| Klíč | Výchozí | Popis |
|---|---|---|
| `debug` | `%debugMode%` | Přidává interní zprávy do chybových odpovědí |
| `dateTimeFormats` | viz `ParameterBinder` | Přijímané formáty parametrů s datem |
| `endpoints.<name>.namespace` | povinné | Kontrolery obsluhované endpointem |
| `endpoints.<name>.authorize` | `true` | Zda metody bez atributu vyžadují přihlášení |
| `endpoints.<name>.jsonForceArray` | `true` | Zda `$this->jsonData` používá pole místo objektů |
| `endpoints.<name>.accessControl` | bez CORS | `allowedOrigins`, `allowCredentials`, `allowMethods`, `allowHeaders`, `exposeHeaders`, `maxAge` |
| `endpoints.<name>.documentation` | generuje se, zveřejněná v debug režimu | viz [OpenAPI](#openapi) |

Název endpointu může obsahovat lomítko, například `api/v2`, a vyhrává nejdelší shoda. V jedné aplikaci může žít s [`phloxcz/rpc-api`](https://github.com/phloxcz/rpc-api/blob/master/README.cs.md), i pod stejným názvem.

## Přechod z abrad450/api-server-nette

Celý postup je v [`MIGRATION.cs.md`](https://github.com/phloxcz/api-core/blob/master/MIGRATION.cs.md). Tento balíček se týká:

Jmenné prostory:

| Staré | Nové |
|---|---|
| `AbraD450\ApiServer\ApiController` | `Phlox\ApiCore\ApiController` |
| `AbraD450\ApiServer\Common\Attributes\{Authorize,AllowAnonymous,NonApi}` | `Phlox\ApiCore\Attributes\*` |
| `AbraD450\ApiServer\Common\Attributes\FromBody` | `Phlox\RestApi\Attributes\FromBody` |
| `AbraD450\ApiServer\REST\Attributes\*` | `Phlox\RestApi\Attributes\*` |
| `AbraD450\ApiServer\REST\ApiException` | `Phlox\ApiCore\ApiException` |
| `AbraD450\ApiServer\ApiConstructible` | `Phlox\ApiCore\ApiConstructible` |

Změny, které je potřeba v existujícím projektu zkontrolovat:

- **Konfigurace:** z `apiServer: rest: <name>` je `restApi: endpoints: <name>`.
- **Autorizace je ve výchozím stavu zapnutá.** Veřejné metody označte `#[AllowAnonymous]`, nebo u endpointu nastavte `authorize: false`.
- **`#[Authorize(['a', 'b'])]` teď znamená a nebo b, takže pustí víc uživatelů než dřív.** Kde jsou potřeba obě role, napište `#[Authorize(['a', 'b'], all: true)]`. Atributy na třídě a metodě musí dál platit společně.
- **Sloveso je povinné.** Metoda bez slovesa dřív přijímala jakékoli. Teď není endpoint, a pokud má `#[Route]`, kontejner se nezkompiluje.
- **Zděděné metody už nejsou vystavené.** Metodu ze společného základního kontroleru je potřeba přepsat v konkrétním kontroleru.
- **`ApiException` bere jako první HTTP stav:** `new ApiException(404, 'Not found.')`, s vlastním kódem v `errorCode:`. Kód na místě stavu, jako v `new ApiException(1001, 'x')`, skončí chybou se správným zápisem ve zprávě. Totéž platí pro `#[Throws]`.
- **`code` chyby je HTTP stav,** pokud aplikace nedala vlastní: neznámá cesta `404`, špatné sloveso `405`, chybný JSON `400`, nepodporovaný typ obsahu `415`, neplatné parametry `422` a neočekávaná výjimka `500`. Dřív to byly kódy JSON-RPC, třeba `-32601` a `-32602`. Zkontrolujte klienty, kteří `code` čtou.
- **Změnily se stavové kódy.** Chyby, které byly ve výchozím stavu `500`, jsou teď `400`, `404`, `405`, `415` nebo `422`, viz výše. Metoda bez hodnoty vrací `204` místo prázdného `200`.
- **Routy jsou přísnější.** Cesty a výrazy `regex` rozlišují velikost písmen (změníte to tím, že výraz začnete `(?i)`), cesta kontroleru musí končit na hranici segmentu a neznámý token jako `{id:imt}` selže, místo aby se ignoroval. Regulární výraz v doslovném textu, jako `(users|people)/{id}`, také selže a musí se přesunout do parametru `regex`.
- **`#[Consumes]` se vynucuje,** dřív sloužil jen pro dokumentaci a dekódování JSON. Bez `#[FromBody]` už tělo nenačítá.
- **Parametry cesty jsou dekódované z URL.**
- **CORS:** `allowedOrigins: ['*']` s `allowCredentials: true` musí být nahrazené seznamem originů.
- **Bootstrap běží před API.** Kód za `createContainer()` se teď spouští i pro požadavky na API, včetně věcí jako přesměrování nebo režim údržby. `index.php` musí volat `Application::run()`.
- **Data a časy:** samotné časové pásmo, jako `x` nebo `utc`, se teď odmítne, místo aby znamenalo aktuální čas.
- **Dokumentace:** konfigurace `openapi` s `content` a `outputPath` je pryč. `info` a `securitySchemes` přesuňte do `documentation`, cokoli dalšího do `documentation.extra` a soubory, pokud je potřebujete, zapisujte přes `OpenApi::toYaml()`. `#[Response]` se přesouvá do `Phlox\RestApi\Attributes\Response` a jeho obsahem je místo zápisu polem, `@pager`, `@binary` a `@error` objekt jako `new Shape([...])` nebo `new Paged(Foo::class)`. `#[Parameter]` je pryč, protože totéž řekne typ parametru, docblock nebo `#[Schema]`. Co je pryč, skončí při kompilaci kontejneru chybou, která řekne, co napsat, viz [Dokumentace](https://github.com/phloxcz/api-core/blob/master/MIGRATION.cs.md#7-dokumentace) v návodu k migraci.
- **Dotazovací jazyk** se přesunul do [`phloxcz/api-query`](https://github.com/phloxcz/api-query/blob/master/README.cs.md#přechod-z-abrad450api-server-nette), včetně `$this->processor`.

## Licence

[MIT](LICENSE)
