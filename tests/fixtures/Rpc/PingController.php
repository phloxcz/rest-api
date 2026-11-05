<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Rpc;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;

#[AllowAnonymous]
class PingController extends ApiController
{
    public function pong(): string
    {
        return 'from rpc';
    }
}
