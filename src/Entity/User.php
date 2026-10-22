<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use sgoranov\IdentityLinkShared\Security\PasswordHashGenerator;
use sgoranov\IdentityLinkShared\Validator\JsonChoice;
use sgoranov\IdentityLinkShared\Validator\PasswordStrength;
use sgoranov\IdentityLinkShared\Validator\UniqueEntry;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\Index(columns: ['reset_token'], name: 'idx_user_reset_token')]
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(
            property: 'id',
            description: 'User UUID',
            type: 'string',
            format: 'uuid',
            example: '550e8400-e29b-41d4-a716-446655440000'
        ),
        new OA\Property(
            property: 'username',
            description: 'Unique username',
            type: 'string',
            example: 'john_doe'
        ),
        new OA\Property(
            property: 'password',
            description: 'User password',
            type: 'string',
            example: 'superSecret123'
        ),
        new OA\Property(
            property: 'firstName',
            description: 'User first name',
            type: 'string',
            example: 'John'
        ),
        new OA\Property(
            property: 'lastName',
            description: 'User last name',
            type: 'string',
            example: 'Doe'
        ),
        new OA\Property(
            property: 'email',
            description: 'User email address',
            type: 'string',
            format: 'email',
            example: 'john.doe@example.com'
        ),
        new OA\Property(
            property: 'groups',
            description: 'User groups (array of UUIDs)',
            type: 'array',
            items: new OA\Items(
                type: 'string',
                format: 'uuid',
                example: '550e8400-e29b-41d4-a716-446655440000'
            )
        ),
        new OA\Property(
            property: 'grantTypes',
            description: 'Allowed OAuth2 grant types',
            type: 'array',
            items: new OA\Items(
                type: 'string',
                enum: ['client_credentials', 'password', 'authorization_code', 'refresh_token', 'implicit'],
                example: 'password'
            )
        ),
        new OA\Property(
            property: 'isSystem',
            description: 'Whether this user is protected from API update and deletion',
            type: 'boolean',
            example: false
        ),
        new OA\Property(
            property: 'twoFaEnabled',
            description: 'Indicates whether two-factor authentication is enabled for this user',
            type: 'boolean',
            example: false
        )
    ],
    type: 'object'
)]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: "CUSTOM")]
    #[ORM\Column(type: "uuid", unique: true)]
    #[ORM\CustomIdGenerator(class: "doctrine.uuid_generator")]
    private ?string $id = null;

    #[Groups(['create', 'update', 'response_without_password'])]
    #[Assert\NotBlank(groups: ['create'])]
    #[UniqueEntry(groups: ['create', 'update'])]
    #[Assert\Length(min: 1, max: 100, groups: ['create', 'update'])]
    #[Assert\Regex(pattern: '/^([\w0-9_-])+$/u', groups: ['create', 'update'])]
    #[ORM\Column(length: 100, unique: true)]
    private string $username;

    #[Ignore]
    #[ORM\Column(name: 'password', length: 100)]
    private string $hashedPassword;

    #[Groups(['create', 'update', 'profile_update'])]
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(min: 1, max: 50, groups: ['create', 'update', 'profile_update'])]
    #[PasswordStrength(groups: ['create', 'update', 'profile_update'])]
    private string $password;

    #[Groups(['create', 'update', 'profile_update', 'response_without_password'])]
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(min: 1, max: 100, groups: ['create', 'update', 'profile_update'])]
    #[ORM\Column(length: 100)]
    private string $firstName;

    #[Groups(['create', 'update', 'profile_update', 'response_without_password'])]
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(min: 1, max: 100, groups: ['create', 'update', 'profile_update'])]
    #[ORM\Column(length: 100)]
    private string $lastName;

    #[Groups(['create', 'update', 'profile_update', 'response_without_password'])]
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Email(groups: ['create', 'update', 'profile_update'])]
    #[Assert\Length(min: 1, max: 100, groups: ['create', 'update', 'profile_update'])]
    #[UniqueEntry(groups: ['create', 'update', 'profile_update'])]
    #[ORM\Column(length: 100, unique: true)]
    private string $email;

    #[Groups(['create', 'update', 'response_without_password'])]
    #[Assert\Count(
        min: 0,
        max: 50,
        maxMessage: 'You cannot specify more than {{ limit }} groups',
        groups: ['create', 'update']
    )]
    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy: "users")]
    #[ORM\JoinTable(name: "user_group")]
    private Collection $groups;

    #[Groups(['create', 'update', 'response_without_password'])]
    #[JsonChoice(
        choices: ['client_credentials', 'password', 'authorization_code', 'refresh_token', 'implicit'],
        groups: ['create', 'update']
    )]
    #[ORM\Column(type: 'json')]
    private array $grantTypes = [];

    #[Groups(['response_without_password'])]
    #[ORM\Column(name: 'is_system', type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    #[Groups(['create', 'update', 'profile_update', 'response_without_password'])]
    #[Assert\NotNull(groups: ['create'])]
    #[Assert\Type(type: 'bool', groups: ['create', 'update', 'profile_update'])]
    #[ORM\Column(name: 'two_fa_enabled', type: 'boolean')]
    private bool $twoFaEnabled;

    #[Ignore]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[Ignore]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetTokenExpiresAt = null;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function getHashedPassword(): ?string
    {
        return $this->hashedPassword;
    }

    public function setHashedPassword(?string $hashedPassword): void
    {
        $this->hashedPassword = $hashedPassword;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
        $this->setHashedPassword(PasswordHashGenerator::create($password));
    }

    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function setGroups(Collection $groups): void
    {
        $this->groups = $groups;
    }

    public function getGrantTypes(): array
    {
        return $this->grantTypes;
    }

    public function setGrantTypes(array $grantTypes): void
    {
        $this->grantTypes = $grantTypes;
    }

    public function getIsSystem(): bool
    {
        return $this->isSystem;
    }

    public function isTwoFaEnabled(): bool
    {
        return $this->twoFaEnabled;
    }

    public function setTwoFaEnabled(bool $twoFaEnabled): void
    {
        $this->twoFaEnabled = $twoFaEnabled;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): void
    {
        $this->resetToken = $resetToken;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeInterface $resetTokenExpiresAt): void
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
    }
}
