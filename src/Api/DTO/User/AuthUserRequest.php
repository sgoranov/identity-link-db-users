<?php
declare(strict_types=1);

namespace App\Api\DTO\User;

use Symfony\Component\Validator\Constraints as Assert;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuthUserRequest',
    required: ['username', 'password'],
    properties: [
        new OA\Property(
            property: 'username',
            description: 'Username for authentication',
            type: 'string',
            maxLength: 200,
            example: 'john_doe'
        ),
        new OA\Property(
            property: 'password',
            description: 'Password for authentication',
            type: 'string',
            maxLength: 200,
            example: 'myS3cretPass'
        ),
        new OA\Property(
            property: 'grantType',
            description: 'OAuth2 grant type',
            type: 'string',
            enum: ['client_credentials', 'password', 'authorization_code', 'refresh_token', 'implicit'],
            example: 'password',
            nullable: true
        )
    ],
    type: 'object'
)]
class AuthUserRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 200)]
    public string $username;

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 200)]
    public string $password;

    #[Assert\Choice(choices: ['client_credentials', 'password', 'authorization_code', 'refresh_token', 'implicit'])]
    public ?string $grantType = null;

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getGrantType(): ?string
    {
        return $this->grantType;
    }

    public function setGrantType(?string $grantType): void
    {
        $this->grantType = $grantType;
    }
}
