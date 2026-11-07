<?php

declare(strict_types=1);

/**
 * Controllers the container must refuse to compile. Each lives in its own
 * namespace, so that a test can point an endpoint at exactly one mistake.
 * The file is loaded explicitly by ExtensionTest.
 */

namespace Phlox\RestApi\Tests\Fixtures\Invalid\DuplicateRoute {

    use Phlox\ApiCore\ApiController;
    use Phlox\RestApi\Attributes\HttpGet;
    use Phlox\RestApi\Attributes\Route;

    #[Route('')]
    class AController extends ApiController
    {
        #[HttpGet]
        #[Route('same/{id:int}')]
        public function one(int $id): void
        {
        }
    }

    #[Route('')]
    class BController extends ApiController
    {
        #[HttpGet]
        #[Route('same/{other:int}')]
        public function two(int $other): void
        {
        }
    }
}

namespace Phlox\RestApi\Tests\Fixtures\Invalid\NoVerb {

    use Phlox\ApiCore\ApiController;
    use Phlox\RestApi\Attributes\Route;

    class AController extends ApiController
    {
        #[Route('forgotten')]
        public function forgotten(): void
        {
        }
    }
}

namespace Phlox\RestApi\Tests\Fixtures\Invalid\TwoBodies {

    use Phlox\ApiCore\ApiController;
    use Phlox\RestApi\Attributes\FromBody;
    use Phlox\RestApi\Attributes\HttpPost;

    class AController extends ApiController
    {
        #[HttpPost]
        public function twice(#[FromBody] array $a, #[FromBody] array $b): void
        {
        }
    }
}

namespace Phlox\RestApi\Tests\Fixtures\Invalid\BodyAndForm {

    use Phlox\ApiCore\ApiController;
    use Phlox\RestApi\Attributes\FromBody;
    use Phlox\RestApi\Attributes\FromForm;
    use Phlox\RestApi\Attributes\HttpPost;

    class AController extends ApiController
    {
        #[HttpPost]
        public function both(#[FromBody] array $a, #[FromForm] string $b): void
        {
        }
    }
}

namespace Phlox\RestApi\Tests\Fixtures\Invalid\HeaderAndForm {

    use Phlox\ApiCore\ApiController;
    use Phlox\RestApi\Attributes\FromForm;
    use Phlox\RestApi\Attributes\FromHeader;
    use Phlox\RestApi\Attributes\HttpPost;

    class AController extends ApiController
    {
        #[HttpPost]
        public function both(#[FromHeader, FromForm] string $a): void
        {
        }
    }
}

namespace Phlox\RestApi\Tests\Fixtures\Invalid\BadToken {

    use Phlox\ApiCore\ApiController;
    use Phlox\RestApi\Attributes\HttpGet;
    use Phlox\RestApi\Attributes\Route;

    class AController extends ApiController
    {
        #[HttpGet]
        #[Route('{id:imt}')]
        public function typo(int $id): void
        {
        }
    }
}
