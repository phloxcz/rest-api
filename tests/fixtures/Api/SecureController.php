<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Api;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiCore\Attributes\Authorize;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\HttpPost;

/**
 * No attribute on the class and a default route: protected by the endpoint default.
 */
class SecureController extends ApiController
{
    #[HttpGet]
    public function plain(): string
    {
        return 'plain';
    }

    #[HttpPost]
    public function typed(int $required): int
    {
        return $required;
    }

    #[HttpGet]
    #[Authorize(['editor', 'manager'])]
    public function anyRole(): string
    {
        return 'any';
    }

    #[HttpGet]
    #[Authorize(['editor', 'manager'], all: true)]
    public function allRoles(): string
    {
        return 'all';
    }

    #[HttpGet]
    #[AllowAnonymous]
    public function open(): string
    {
        return 'open';
    }
}
