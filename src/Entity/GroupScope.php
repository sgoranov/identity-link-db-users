<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'group_scope')]
#[ORM\UniqueConstraint(name: 'uniq_group_scope_audience_scope', columns: ['group_id', 'audience_hash', 'scope'])]
#[UniqueEntity(
    fields: ['group', 'audience', 'scope'],
    message: 'This group already has the specified scope for this audience.',
    errorPath: 'scope',
    groups: ['create']
)]
#[OA\Schema(
    schema: 'GroupScope',
    required: ['audience', 'scope'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(
            property: 'audience',
            description: 'Protected-resource audience',
            type: 'string',
            maxLength: 3000,
            example: 'https://example.com/orders'
        ),
        new OA\Property(
            property: 'scope',
            description: 'Scope issued to this audience for members of the group',
            type: 'string',
            maxLength: 100,
            example: 'orders:read'
        )
    ],
    type: 'object'
)]
class GroupScope
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?string $id = null;

    #[Groups(['create'])]
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Url(protocols: ['http', 'https'], groups: ['create'])]
    #[Assert\Length(min: 1, max: 3000, groups: ['create'])]
    #[ORM\Column(length: 3000)]
    private string $audience;

    #[Ignore]
    #[ORM\Column(name: 'audience_hash', length: 64)]
    private string $audienceHash;

    #[Groups(['create'])]
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(max: 100, groups: ['create'])]
    #[ORM\Column(length: 100)]
    private string $scope;

    #[Ignore]
    #[ORM\ManyToOne(targetEntity: Group::class, inversedBy: 'scopes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Group $group;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAudience(): string
    {
        return $this->audience;
    }

    public function setAudience(string $audience): void
    {
        $this->audience = $audience;
        $this->audienceHash = hash('sha256', $audience);
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function setGroup(Group $group): void
    {
        $this->group = $group;
    }
}
