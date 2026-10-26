<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Entity\Group;
use App\Entity\GroupScope;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use sgoranov\IdentityLinkShared\Security\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

final class GroupScopeControllerTest extends WebTestCase
{
    #[DataProvider('validAudienceProvider')]
    public function testCreateScopeWithHttpAndHttpsAudiences(string $audience): void
    {
        $client = $this->adminClient();
        $router = $client->getContainer()->get(RouterInterface::class);
        $group = $client->getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);

        $content = [
            'audience' => $audience,
            'scope' => 'orders:read',
        ];

        $client->request(
            'POST',
            $router->generate('api_v1_group_scope_create', ['groupId' => $group->getId()]),
            [],
            [],
            [],
            json_encode($content)
        );

        $this->assertResponseStatusCodeSame(201);
        $created = json_decode($client->getResponse()->getContent(), true)['response']['scope'];
        $this->assertSame($content['audience'], $created['audience']);
        $this->assertSame($content['scope'], $created['scope']);
    }

    public function testListScopes(): void
    {
        $client = $this->adminClient();
        $router = $client->getContainer()->get(RouterInterface::class);
        $group = $client->getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);
        $this->persistScope($client->getContainer()->get(EntityManagerInterface::class), $group);

        $client->request(
            'GET',
            $router->generate('api_v1_group_scope_list', ['groupId' => $group->getId()])
        );

        $this->assertResponseIsSuccessful();
        $scopes = json_decode($client->getResponse()->getContent(), true)['response']['scopes'];
        $this->assertCount(1, $scopes);
        $this->assertSame('https://example.com/orders', $scopes[0]['audience']);
    }

    public function testScopeMustNotExceedOneHundredCharacters(): void
    {
        $client = $this->adminClient();
        $router = $client->getContainer()->get(RouterInterface::class);
        $group = $client->getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);

        $client->request(
            'POST',
            $router->generate('api_v1_group_scope_create', ['groupId' => $group->getId()]),
            [],
            [],
            [],
            json_encode([
                'audience' => 'https://example.com/orders',
                'scope' => str_repeat('a', 101),
            ])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public static function validAudienceProvider(): iterable
    {
        yield 'http audience' => ['http://example.com/orders'];
        yield 'https audience' => ['https://example.com/orders'];
    }

    #[DataProvider('invalidCreateDataProvider')]
    public function testCreateRejectsInvalidData(string $content): void
    {
        $client = $this->adminClient();
        $router = $client->getContainer()->get(RouterInterface::class);
        $group = $client->getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);

        $client->request(
            'POST',
            $router->generate('api_v1_group_scope_create', ['groupId' => $group->getId()]),
            [],
            [],
            [],
            $content
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public static function invalidCreateDataProvider(): iterable
    {
        yield 'missing request body' => [''];
        yield 'malformed JSON' => ['{"audience":'];
        yield 'missing audience' => [json_encode(['scope' => 'orders:read'])];
        yield 'missing scope' => [json_encode(['audience' => 'https://example.com/orders'])];
        yield 'blank values' => [json_encode(['audience' => ' ', 'scope' => ' '])];
        yield 'malformed audience' => [json_encode(['audience' => 'not-a-url', 'scope' => 'orders:read'])];
        yield 'unsupported audience protocol' => [json_encode([
            'audience' => 'ftp://example.com/orders',
            'scope' => 'orders:read',
        ])];
        yield 'audience longer than 3000 characters' => [json_encode([
            'audience' => 'https://example.com/' . str_repeat('a', 2981),
            'scope' => 'orders:read',
        ])];
        yield 'unexpected property' => [json_encode([
            'audience' => 'https://example.com/orders',
            'scope' => 'orders:read',
            'unsupported' => true,
        ])];
    }

    public function testMultipleScopesCanBeCreatedForTheSameAudience(): void
    {
        $client = $this->adminClient();
        $router = $client->getContainer()->get(RouterInterface::class);
        $group = $client->getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);
        $this->persistScope($client->getContainer()->get(EntityManagerInterface::class), $group);

        $client->request(
            'POST',
            $router->generate('api_v1_group_scope_create', ['groupId' => $group->getId()]),
            [],
            [],
            [],
            json_encode(['audience' => 'https://example.com/orders', 'scope' => 'orders:write'])
        );

        $this->assertResponseStatusCodeSame(201);

        $scopes = $client->getContainer()->get(EntityManagerInterface::class)
            ->getRepository(GroupScope::class)
            ->findBy(['group' => $group, 'audience' => 'https://example.com/orders']);

        $this->assertCount(2, $scopes);
        $this->assertSame(
            ['orders:read', 'orders:write'],
            array_map(static fn (GroupScope $groupScope): string => $groupScope->getScope(), $scopes)
        );
    }

    public function testDuplicateAudienceScopePairIsRejectedWithinGroup(): void
    {
        $client = $this->adminClient();
        $router = $client->getContainer()->get(RouterInterface::class);
        $group = $client->getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);
        $this->persistScope($client->getContainer()->get(EntityManagerInterface::class), $group);

        $client->request(
            'POST',
            $router->generate('api_v1_group_scope_create', ['groupId' => $group->getId()]),
            [],
            [],
            [],
            json_encode(['audience' => 'https://example.com/orders', 'scope' => 'orders:read'])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testDeleteScope(): void
    {
        $client = $this->adminClient();
        $router = $client->getContainer()->get(RouterInterface::class);
        $group = $client->getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);
        $groupScope = $this->persistScope(
            $client->getContainer()->get(EntityManagerInterface::class),
            $group,
            'orders',
            'read'
        );

        $client->request('DELETE', $router->generate('api_v1_group_scope_delete', [
            'groupId' => $group->getId(),
            'scopeId' => $groupScope->getId(),
        ]));
        $this->assertResponseStatusCodeSame(204);
    }

    private function adminClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        return $client;
    }

    private function loginAdmin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $client->loginUser(new User('test', ['users.groups.read', 'users.groups.write', 'users.groups.delete']));
    }

    private function persistScope(
        EntityManagerInterface $entityManager,
        Group $group,
        string $audience = 'https://example.com/orders',
        string $scope = 'orders:read',
    ): GroupScope {
        $groupScope = new GroupScope();
        $groupScope->setGroup($group);
        $groupScope->setAudience($audience);
        $groupScope->setScope($scope);
        $entityManager->persist($groupScope);
        $entityManager->flush();

        return $groupScope;
    }
}
