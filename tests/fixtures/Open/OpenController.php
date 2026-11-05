<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Open;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\HttpPost;
use Phlox\RestApi\Attributes\HttpPut;
use Phlox\RestApi\Attributes\Route;
use Phlox\RestApi\Tests\Fixtures\Docs\CreateUser;

#[Route('open')]
#[AllowAnonymous]
class OpenController extends ApiController
{
    #[HttpPost]
    #[Route('')]
    public function create(#[FromBody] CreateUser $input): CreateUser
    {
        return $input;
    }

    /**
     * @param list<CreateUser> $inputs
     * @return list<CreateUser>
     */
    #[HttpPost]
    #[Route('many')]
    public function many(#[FromBody] array $inputs): array
    {
        return $inputs;
    }

    #[HttpPut]
    #[Route('named')]
    public function named(CreateUser $input): CreateUser
    {
        return $input;
    }
}
