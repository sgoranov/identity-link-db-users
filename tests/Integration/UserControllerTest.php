<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Entity\GroupScope;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use sgoranov\IdentityLinkShared\Security\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

class UserControllerTest extends WebTestCase
{
    #[DataProvider('validAudienceProvider')]
    public function testGetScopesForHttpAndHttpsAudiences(string $audience): void
    {
        $client = static::createClient();
        $client->loginUser(new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']));
        $container = $client->getContainer();
        $router = $container->get(RouterInterface::class);
        $group = $container->get(GroupRepository::class)
            ->findOneBy(['name' => AppFixtures::GROUP_NAME]);
        $user = $container->get(UserRepository::class)
            ->findOneBy(['username' => AppFixtures::USER_USERNAME]);

        $groupScope = new GroupScope();
        $groupScope->setGroup($group);
        $groupScope->setAudience($audience);
        $groupScope->setScope('orders:read');
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($groupScope);
        $entityManager->flush();

        $client->request('GET', $router->generate('api_v1_get_user_scopes', [
            'id' => $user->getId(),
            'audience' => $audience,
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['orders:read'],
            json_decode($client->getResponse()->getContent(), true)['response']['scopes']
        );
    }

    public static function validAudienceProvider(): iterable
    {
        yield 'http audience' => ['http://example.com/orders'];
        yield 'https audience' => ['https://example.com/orders'];
    }

    public function testGetScopesRejectsMissingAudience(): void
    {
        $client = static::createClient();
        $client->loginUser(new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']));
        $container = $client->getContainer();
        $router = $container->get(RouterInterface::class);
        $user = $container->get(UserRepository::class)
            ->findOneBy(['username' => AppFixtures::USER_USERNAME]);

        $client->request('GET', $router->generate('api_v1_get_user_scopes', [
            'id' => $user->getId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateUserWithMissingBody(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $client->request('POST', $router->generate('api_v1_create_user'));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateUserWithEmptyUsername(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => '',
            'password' => 'test',
            'twoFaEnabled' => false,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid username. This value should not be blank.',
            json_decode($response->getContent(), true)['error']);
    }

    public function testCreateUserWithInvalidUsername(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test$',
            'password' => 'test',
            'twoFaEnabled' => false,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid username. This value is not valid.',
            json_decode($response->getContent(), true)['error']);
    }

    public function testCreateUserWithExistingUsername(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test_user',
            'password' => 'test',
            'twoFaEnabled' => false,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid username. The value "test_user" already exists.',
            json_decode($response->getContent(), true)['error']);
    }

    public function testCreateUserWithExistingEmail(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test_email1@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => false,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid email. The value "test_email1@phpidentitylink.com" already exists.',
            json_decode($response->getContent(), true)['error']);
    }

    public function testCreateUserWithWeakPassword(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => 'weak',
            'twoFaEnabled' => false,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid password. The password is too weak. Add another word or two. Uncommon words are better.',
            json_decode($response->getContent(), true)['error']);
    }

    public function testCreateUserWithMissingTwoFaEnabled(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid twoFaEnabled. This value should not be null.',
            json_decode($response->getContent(), true)['error']);
    }

    public function testCreateUserWithInvalidTwoFaEnabled(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => 'invalid',
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('The twoFaEnabled property must be of type bool, but string was provided.',
            json_decode($response->getContent(), true)['error']);
    }

    public function testCreateUserWithTwoFaEnabledTrue(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => true,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testCreateUserWithNullTwoFaEnabled(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => null,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'The twoFaEnabled property must be of type bool, but null was provided.',
            json_decode($response->getContent(), true)['error']
        );
    }

    public function testCreateUserWithEmptyStringTwoFaEnabled(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => '',
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'The twoFaEnabled property must be of type bool, but string was provided.',
            json_decode($response->getContent(), true)['error']
        );
    }

    public function testCreateUserWithNumericTwoFaEnabled(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => 1,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'The twoFaEnabled property must be of type bool, but int was provided.',
            json_decode($response->getContent(), true)['error']
        );
    }

    public function testCreateUserWithArrayTwoFaEnabled(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => [],
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'The twoFaEnabled property must be of type bool, but array was provided.',
            json_decode($response->getContent(), true)['error']
        );
    }

    public function testCreateUserWithObjectTwoFaEnabled(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => ['foo' => 'bar'],
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'The twoFaEnabled property must be of type bool, but array was provided.',
            json_decode($response->getContent(), true)['error']
        );
    }


    public function testCreateUserSuccessfully(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'First',
            'lastName' => 'Last',
            'email' => 'test@phpidentitylink.com',
            'username' => 'test',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'twoFaEnabled' => false,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('test',
            json_decode($response->getContent(), true)['response']['user']['username']);
        $this->assertFalse(json_decode($response->getContent(), true)['response']['user']['isSystem']);
    }

    public function testCreateUserRejectsIsSystemThroughApi(): void
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'System',
            'lastName' => 'User',
            'email' => 'system_user@phpidentitylink.com',
            'username' => 'system_user',
            'password' => '41816d28-b579-45db-9267-887ad39781d3',
            'isSystem' => true,
            'twoFaEnabled' => false,
        ];

        $client->request('POST', $router->generate('api_v1_create_user'), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateUserWithInvalidUuid()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'FirstNew',
        ];

        $client->request('PUT', $router->generate('api_v1_update_user', [
            'id' => 'uuid'
        ]), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateUserSuccessfully()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'FirstNew',
        ];

        $repository = $client->getContainer()->get(UserRepository::class);
        list($user) = $repository->findBy(['username' => AppFixtures::USER_USERNAME]);

        $client->request('PUT', $router->generate('api_v1_update_user', [
            'id' => $user->getId()
        ]), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('FirstNew',
            json_decode($response->getContent(), true)['response']['user']['firstName']);
        $this->assertFalse(json_decode($response->getContent(), true)['response']['user']['isSystem']);
    }

    public function testUpdateUserRejectsIsSystemThroughApi()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $content = [
            'firstName' => 'FirstNew',
            'isSystem' => true,
        ];

        $repository = $client->getContainer()->get(UserRepository::class);
        list($user) = $repository->findBy(['username' => AppFixtures::USER_USERNAME]);

        $client->request('PUT', $router->generate('api_v1_update_user', [
            'id' => $user->getId()
        ]), [], [], [], json_encode($content));
        $response = $client->getResponse();

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateSystemUserIsForbidden()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $repository = $client->getContainer()->get(UserRepository::class);
        list($user) = $repository->findBy(['username' => AppFixtures::USER_USERNAME]);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE "user" SET is_system = true WHERE id = :id',
            ['id' => $user->getId()]
        );
        $entityManager->clear();

        $client->request('PUT', $router->generate('api_v1_update_user', [
            'id' => $user->getId()
        ]), [], [], [], json_encode(['firstName' => 'FirstNew']));
        $response = $client->getResponse();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('System users cannot be updated.', json_decode($response->getContent(), true)['error']);
    }

    public function testDeleteUserSuccessfully()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $repository = $client->getContainer()->get(UserRepository::class);
        list($user) = $repository->findBy(['username' => AppFixtures::USER_USERNAME]);

        $client->request('DELETE', $router->generate('api_v1_delete_user', [
            'id' => $user->getId()
        ]));
        $response = $client->getResponse();

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDeleteSystemUserIsForbidden()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $repository = $client->getContainer()->get(UserRepository::class);
        list($user) = $repository->findBy(['username' => AppFixtures::USER_USERNAME]);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE "user" SET is_system = true WHERE id = :id',
            ['id' => $user->getId()]
        );
        $entityManager->clear();

        $client->request('DELETE', $router->generate('api_v1_delete_user', [
            'id' => $user->getId()
        ]));
        $response = $client->getResponse();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('System users cannot be deleted.', json_decode($response->getContent(), true)['error']);
    }

    public function testFetchUserSuccessfully()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $repository = $client->getContainer()->get(UserRepository::class);
        list($user) = $repository->findBy(['username' => AppFixtures::USER_USERNAME]);

        $client->request('GET', $router->generate('api_v1_fetch_user', [
            'id' => $user->getId()
        ]));
        $response = $client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(AppFixtures::USER_USERNAME,
            json_decode($response->getContent(), true)['response']['user']['username']);
        $this->assertFalse(json_decode($response->getContent(), true)['response']['user']['isSystem']);
    }

    public function testFetchSystemUserExposesIsSystem()
    {
        $client = static::createClient();
        $testUser = new User('test', ['users.read', 'users.write', 'users.delete', 'users.auth']);
        $client->loginUser($testUser);
        $router = $client->getContainer()->get(RouterInterface::class);

        $repository = $client->getContainer()->get(UserRepository::class);
        list($user) = $repository->findBy(['username' => AppFixtures::USER_USERNAME]);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE "user" SET is_system = true WHERE id = :id',
            ['id' => $user->getId()]
        );
        $entityManager->clear();

        $client->request('GET', $router->generate('api_v1_fetch_user', [
            'id' => $user->getId()
        ]));
        $response = $client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['response']['user']['isSystem']);
    }
}
