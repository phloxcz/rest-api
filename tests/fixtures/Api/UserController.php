<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Api;

use Nette\Http\IResponse;
use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiCore\Attributes\NonApi;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\HttpDelete;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\HttpHead;
use Phlox\RestApi\Attributes\HttpOptions;
use Phlox\RestApi\Attributes\HttpPatch;
use Phlox\RestApi\Attributes\HttpPost;
use Phlox\RestApi\Attributes\HttpPut;
use Phlox\RestApi\Attributes\Route;

#[AllowAnonymous]
#[Route('users')]
class UserController extends ApiController
{
    #[HttpGet]
    #[Route('')]
    public function list(int $page = 1): array
    {
        return ['action' => 'list', 'page' => $page];
    }

    #[HttpGet]
    #[Route('{id:int:min(1)}')]
    public function detail(int $id): array
    {
        return ['action' => 'detail', 'id' => $id];
    }

    #[HttpGet]
    #[Route('{slug}')]
    public function bySlug(string $slug): array
    {
        return ['action' => 'bySlug', 'slug' => $slug];
    }

    #[HttpGet]
    #[Route('me')]
    public function me(): array
    {
        return ['action' => 'me'];
    }

    #[HttpPost]
    #[Route('')]
    public function create(string $name, int $age = 0): ?array
    {
        $this->response->setCode(IResponse::S201_Created);

        return ['name' => $name, 'age' => $age, 'jsonData' => $this->jsonData];
    }

    #[HttpPut]
    #[HttpPatch]
    #[Route('{id:int}')]
    public function update(int $id, ?string $name = null): array
    {
        return ['id' => $id, 'name' => $name, 'verb' => $this->request->getMethod()];
    }

    #[HttpDelete]
    #[Route('{id:int}')]
    public function remove(int $id): void
    {
    }

    #[HttpPost]
    #[Route('{id:int}/activate')]
    public function activate(int $id): void
    {
        $this->response->setCode(IResponse::S202_Accepted);
    }

    #[HttpGet]
    public function getAllActive(): string
    {
        return 'derived from the method name';
    }

    #[HttpGet]
    #[Route('fail')]
    public function fail(): void
    {
        throw new ApiException(1234, 'Public failure', 'secret detail', data: ['field' => 'name', 'code' => 'overwritten'], httpCode: 409);
    }

    #[HttpGet]
    #[Route('crash')]
    public function crash(): void
    {
        throw new \RuntimeException('secret crash detail');
    }

    #[HttpGet]
    #[Route('abort')]
    public function abort(): void
    {
        $this->terminate();
    }

    #[HttpGet]
    #[Route('unencodable')]
    public function unencodable(): string
    {
        return "\xFF";
    }

    #[HttpHead]
    #[Route('probe')]
    public function probeHead(): array
    {
        return ['must' => 'not be sent'];
    }

    #[HttpOptions]
    #[Route('capabilities')]
    public function capabilities(): array
    {
        return ['explicit' => 'options'];
    }

    #[NonApi]
    #[HttpGet]
    #[Route('hidden')]
    public function hidden(): string
    {
        return 'must not be routed';
    }

    public function helper(): string
    {
        return 'no verb, not an endpoint';
    }
}
