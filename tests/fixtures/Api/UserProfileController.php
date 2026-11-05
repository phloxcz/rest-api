<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Api;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\RestApi\Attributes\HttpGet;

#[AllowAnonymous]
class UserProfileController extends ApiController
{
    #[HttpGet]
    public function showDetail(): string
    {
        return 'derived from both names';
    }
}
