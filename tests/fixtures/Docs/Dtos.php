<?php

declare(strict_types=1);

/**
 * Data of the documented API. The file is loaded explicitly by DocumentationTest.
 */

namespace Phlox\RestApi\Tests\Fixtures\Docs;

use Phlox\ApiCore\Attributes\Constraints\Format;
use Phlox\ApiCore\Attributes\Constraints\Length;
use Phlox\ApiCore\Attributes\Dto;

enum Role: string
{
    case Admin = 'admin';
    case User = 'user';
}

/** An address. */
#[Dto]
final readonly class AddressDto
{
    public function __construct(#[Length(min: 1)] public string $city)
    {
    }
}

#[Dto]
final readonly class UserDto
{
    /**
     * @param list<AddressDto> $addresses Where the user lives
     */
    public function __construct(
        public int $id,
        #[Length(min: 2)] public string $name,
        public Role $role = Role::User,
        public array $addresses = [],
        public ?\DateTimeImmutable $created = null,
    ) {
    }
}

#[Dto]
final readonly class CreateUser
{
    public function __construct(
        #[Length(min: 2, max: 20)] public string $name,
        #[Format('email')] public string $email,
    ) {
    }
}

/** Same name as the schema of errors. */
#[Dto]
final readonly class Error
{
    public function __construct(public string $mine)
    {
    }
}
