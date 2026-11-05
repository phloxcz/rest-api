<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Docs;

use Phlox\ApiCore\ApiController;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\Route;

#[Route('')]
class ClashController extends ApiController
{
    #[HttpGet]
    #[Route('users/import.json')]
    public function clash(): void
    {
    }
}
