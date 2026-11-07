<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Legacy;

use Phlox\ApiCore\ApiController;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\Response;
use Phlox\RestApi\Attributes\Route;

/**
 * A type as text, which is refused.
 */
#[Route('text')]
class TextController extends ApiController
{
    #[HttpGet]
    #[Route('')]
    #[Response(200, 'list<User>')]
    public function list(): array
    {
        return [];
    }
}
