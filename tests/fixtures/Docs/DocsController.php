<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Docs;

use Nette\Application\Response as ApplicationResponse;
use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiCore\Attributes\Authorize;
use Phlox\ApiCore\Attributes\Constraints\Range;
use Phlox\ApiCore\Attributes\Schema;
use Phlox\ApiCore\Attributes\Throws;
use Phlox\ApiCore\Schema\Binary;
use Phlox\ApiCore\Schema\ListOf;
use Phlox\ApiCore\Schema\Paged;
use Phlox\ApiCore\Schema\Shape;
use Phlox\RestApi\Attributes\Consumes;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\HttpDelete;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\HttpPatch;
use Phlox\RestApi\Attributes\HttpPost;
use Phlox\RestApi\Attributes\HttpPut;
use Phlox\RestApi\Attributes\Response;
use Phlox\RestApi\Attributes\Route;

/**
 * Everything about users.
 */
#[Route('users')]
#[Authorize(['admin', 'editor'])]
class DocsController extends ApiController
{
    /**
     * Lists users.
     *
     * With paging.
     *
     * @param int $page Which page
     * @return list<UserDto> Read by PHPStan, not by the documentation
     */
    #[HttpGet]
    #[Route('')]
    #[AllowAnonymous]
    #[Response(200, new ListOf(UserDto::class), description: 'The users')]
    public function list(#[Range(min: 1)] int $page = 1, ?Role $role = null, array $filter = []): array
    {
        return [new UserDto($page, 'Ann', $role ?? Role::User, created: new \DateTimeImmutable('2024-01-02T03:04:05+00:00'))];
    }

    /**
     * @param int $id The user
     */
    #[HttpGet]
    #[Route('{id:int:min(1)}')]
    #[Throws(423, 'The user is locked.', errorCode: 1001)]
    #[Throws(404, 'The user is gone.', errorCode: 1002)]
    public function detail(int $id): UserDto
    {
        return new UserDto($id, 'Ann');
    }

    #[HttpPost]
    #[Route('')]
    #[Response(201)]
    public function create(#[FromBody] CreateUser $input): UserDto
    {
        $this->response->setCode(201);
        return new UserDto(1, $input->name);
    }

    /**
     * @param string $name The new name
     */
    #[HttpPut]
    #[HttpPatch]
    #[Route('{id:int}')]
    public function update(int $id, string $name, int $age = 0): UserDto
    {
        return new UserDto($id, $name);
    }

    #[HttpDelete]
    #[Route('{id:int}')]
    #[Authorize('owner')]
    public function remove(int $id): void
    {
    }

    /**
     * @param list<CreateUser> $rows
     * @deprecated Use something else.
     */
    #[HttpPost]
    #[Route('import')]
    public function import(#[FromBody] array $rows, bool $dryRun = false): void
    {
    }

    #[HttpPost]
    #[Route('{id:int}/photo')]
    #[Consumes('image/*')]
    #[Consumes('application/json')]
    public function photo(int $id, #[FromBody] string $content): void
    {
    }

    #[HttpGet]
    #[Route('{id:guid}/file')]
    #[Response(200, new Binary, 'application/pdf', 'The file')]
    public function file(string $id): ApplicationResponse
    {
        throw new \LogicException();
    }

    #[HttpGet]
    #[Route('tree/{?path:all}')]
    public function tree(?string $path = null): ?string
    {
        return $path;
    }

    #[HttpGet]
    #[Route('a/{?b:int}/c/{?d}')]
    public function optional(?int $b = null, ?string $d = null): mixed
    {
        return null;
    }

    #[HttpGet]
    #[Route('code/{code:regex}/{flag:bool}/{n:float:range(1,5)}/{s:alpha:length(2,3)}/{u:intOrGuid}', ['code' => '[A-Z]{3}'])]
    public function tokens(string $code, bool $flag, float $n, string $s, string $u): ApplicationResponse
    {
        throw new \LogicException();
    }

    #[HttpGet]
    #[Route('enum/{role}')]
    public function enum(Role $role): string
    {
        return $role->value;
    }

    /**
     * Described by objects.
     */
    #[HttpPost]
    #[Route('described')]
    #[Response(200, new Shape(['value' => new Paged(UserDto::class), 'note?' => '?string']))]
    #[Response(404, description: 'Nothing here')]
    public function described(array $filter, #[Schema(['example' => 5])] int $limit = 5): array
    {
        return [];
    }

    #[HttpGet]
    #[Route('summary')]
    #[Response(200, 'int', description: 'A count')]
    #[Response(302, description: 'Moved')]
    public function summary(): array
    {
        return [];
    }

    #[HttpGet]
    #[Route('error-named')]
    public function errorNamed(): Error
    {
        return new Error('x');
    }

    #[HttpGet]
    #[Route('nonsense')]
    public function nonsense(): array
    {
        return ['date' => new \DateTimeImmutable('2024-01-02')];
    }

    public function notAnEndpoint(): void
    {
    }
}
