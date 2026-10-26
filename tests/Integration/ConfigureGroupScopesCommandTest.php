<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Entity\Group;
use App\Entity\GroupScope;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigureGroupScopesCommandTest extends KernelTestCase
{
    private const AUDIENCE = 'https://example.com/api';

    public function testCreatesGroupWithScopes(): void
    {
        $tester = $this->commandTester();

        $status = $tester->execute([
            '--group' => 'administrators',
            '--audience' => self::AUDIENCE,
            '--scope' => ['users.read', 'users.write'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Group "administrators" was created', $tester->getDisplay());
        self::assertSame(
            ['users.read', 'users.write'],
            $this->scopeNames('administrators', self::AUDIENCE),
        );
    }

    public function testAddsScopesForNewAudienceToExistingGroup(): void
    {
        $this->persistScope(AppFixtures::GROUP_NAME, 'https://example.com/other', 'other.read');
        $tester = $this->commandTester(false);

        $status = $tester->execute([
            '--group' => AppFixtures::GROUP_NAME,
            '--audience' => self::AUDIENCE,
            '--scope' => ['users.read', 'users.write'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            ['users.read', 'users.write'],
            $this->scopeNames(AppFixtures::GROUP_NAME, self::AUDIENCE),
        );
        self::assertSame(
            ['other.read'],
            $this->scopeNames(AppFixtures::GROUP_NAME, 'https://example.com/other'),
        );
    }

    public function testReplacesScopesForExistingAudienceAndPreservesOtherAudiences(): void
    {
        $this->persistScope(AppFixtures::GROUP_NAME, self::AUDIENCE, 'users.old');
        $this->persistScope(AppFixtures::GROUP_NAME, self::AUDIENCE, 'users.keep');
        $this->persistScope(AppFixtures::GROUP_NAME, 'https://example.com/other', 'other.read');
        $tester = $this->commandTester(false);

        $status = $tester->execute([
            '--group' => AppFixtures::GROUP_NAME,
            '--audience' => self::AUDIENCE,
            '--scope' => ['users.keep', 'users.write'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            ['users.keep', 'users.write'],
            $this->scopeNames(AppFixtures::GROUP_NAME, self::AUDIENCE),
        );
        self::assertSame(
            ['other.read'],
            $this->scopeNames(AppFixtures::GROUP_NAME, 'https://example.com/other'),
        );
    }

    public function testDuplicateScopeArgumentsAreStoredOnce(): void
    {
        $tester = $this->commandTester();

        $status = $tester->execute([
            '--group' => 'administrators',
            '--audience' => self::AUDIENCE,
            '--scope' => ['users.read', 'users.read'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['users.read'], $this->scopeNames('administrators', self::AUDIENCE));
    }

    public function testRejectsMissingScopesWithoutCreatingGroup(): void
    {
        $tester = $this->commandTester();

        $status = $tester->execute([
            '--group' => 'administrators',
            '--audience' => self::AUDIENCE,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('At least one --scope option is required.', $tester->getDisplay());
        self::assertNull(static::getContainer()->get(GroupRepository::class)
            ->findOneBy(['name' => 'administrators']));
    }

    public function testRejectsInvalidAudienceWithoutChangingExistingScopes(): void
    {
        $this->persistScope(AppFixtures::GROUP_NAME, self::AUDIENCE, 'users.read');
        $tester = $this->commandTester(false);

        $status = $tester->execute([
            '--group' => AppFixtures::GROUP_NAME,
            '--audience' => 'ftp://example.com/api',
            '--scope' => ['users.write'],
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame(
            ['users.read'],
            $this->scopeNames(AppFixtures::GROUP_NAME, self::AUDIENCE),
        );
    }

    private function commandTester(bool $bootKernel = true): CommandTester
    {
        if ($bootKernel) {
            self::bootKernel();
        }

        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:configure-group-scopes'));
    }

    private function persistScope(string $groupName, string $audience, string $scope): void
    {
        if (!self::$booted) {
            self::bootKernel();
        }

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $group = static::getContainer()->get(GroupRepository::class)->findOneBy(['name' => $groupName]);
        self::assertNotNull($group);

        $groupScope = new GroupScope();
        $groupScope->setGroup($group);
        $groupScope->setAudience($audience);
        $groupScope->setScope($scope);
        $entityManager->persist($groupScope);
        $entityManager->flush();
    }

    /** @return list<string> */
    private function scopeNames(string $groupName, string $audience): array
    {
        $group = static::getContainer()->get(GroupRepository::class)->findOneBy(['name' => $groupName]);
        self::assertInstanceOf(Group::class, $group);

        $scopes = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(GroupScope::class)
            ->findBy(['group' => $group, 'audience' => $audience], ['scope' => 'ASC']);

        return array_map(static fn (GroupScope $groupScope): string => $groupScope->getScope(), $scopes);
    }
}
