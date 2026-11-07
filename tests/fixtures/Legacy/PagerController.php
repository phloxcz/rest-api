<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Legacy;

use Phlox\ApiCore\ApiController;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\Response;
use Phlox\RestApi\Attributes\Route;

/**
 * The array notation of older versions, which is refused.
 */
#[Route('pager')]
class PagerController extends ApiController
{
    #[HttpGet]
    #[Route('')]
    #[Response(200, ['@pager', 'User'])]
    public function list(): array
    {
        return [];
    }
}
