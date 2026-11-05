<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\ApiOther;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\RestApi\Attributes\HttpGet;

/**
 * Its namespace only starts with the same text as the endpoint's one.
 */
#[AllowAnonymous]
class OtherController extends ApiController
{
    #[HttpGet]
    public function read(): string
    {
        return 'other';
    }
}
