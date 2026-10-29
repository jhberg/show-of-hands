<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;

final class FirstStartTest extends HttpTestCase
{
    public function testCreatesTheDefaultGroupsAndTheBootstrapAdmin(): void
    {
        $this->app = $this->createApp(['bootstrap_admin' => ['name' => 'Alex Example', 'email' => 'Alex@Example.org', 'group' => 'S1']]);

        $this->request('GET', '/login');

        $groups = $this->service(VoiceGroupRepository::class)->all();
        self::assertSame(['L', 'S1', 'S2', 'A1', 'A2', 'T1', 'T2', 'B1', 'B2'], array_map(static fn($g) => $g->key, $groups));
        self::assertSame('Chorleitung', $groups[0]->label);
        $match = $this->service(MemberRepository::class)->findByEmail('alex@example.org');
        self::assertNotNull($match);
        [$admin] = $match;
        self::assertTrue($admin->isAdmin);
        self::assertTrue($admin->isBoard);
        self::assertSame('S1', $admin->voiceGroupKey);
    }

    public function testLabelsTheGroupsInTheDefaultLanguage(): void
    {
        $this->app = $this->createApp(['locale' => ['default' => 'en'], 'bootstrap_admin' => ['name' => 'Alex Example', 'email' => 'alex@example.org', 'group' => 'L']]);

        $this->request('GET', '/login');

        $groups = $this->service(VoiceGroupRepository::class)->all();
        self::assertSame(['D', 'Director'], [$groups[0]->key, $groups[0]->label]);
        $match = $this->service(MemberRepository::class)->findByEmail('alex@example.org');
        self::assertNotNull($match);
        self::assertNull($match[0]->voiceGroupKey, 'A group key that does not exist leaves the admin without a group.');
    }

    public function testRunsOnlyWhileTheMemberListIsEmpty(): void
    {
        $this->app = $this->createApp(['bootstrap_admin' => ['name' => 'Alex Example', 'email' => 'alex@example.org']]);
        $this->request('GET', '/login');
        $this->service(\PDO::class)->exec("UPDATE member_emails SET email = 'renamed@example.org'");
        $this->service(\PDO::class)->exec("DELETE FROM voice_groups WHERE key = 'B2'");

        $this->request('GET', '/login');

        self::assertNull($this->service(MemberRepository::class)->findByEmail('alex@example.org'));
        self::assertCount(8, $this->service(VoiceGroupRepository::class)->all());
    }
}
