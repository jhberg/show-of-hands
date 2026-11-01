<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;

final class SettingsTest extends HttpTestCase
{
    private const CHECKBOXES_OFF = ['max_received' => '3', 'retention_days' => '30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['google' => ['client_id' => 'test-client', 'client_secret' => 'secret', 'workspace_domain' => 'example.org']]);
        $this->addMember('Kim Example', ['kim@example.org'], admin: true, group: 'S1');
        $this->addMember('Lou Example', ['lou@example.org'], group: 'T1');
        $this->signIn('kim@example.org');
    }

    private function page(): string
    {
        return $this->body($this->request('GET', '/admin/settings'));
    }

    private function settings(): SettingsRepository
    {
        return $this->service(SettingsRepository::class);
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        return array_map(static fn(VoiceGroup $group): string => $group->key, $this->service(VoiceGroupRepository::class)->all());
    }

    /**
     * @param array<string, string> $body
     */
    private function group(string $path, array $body = [], bool $htmx = false): ResponseInterface
    {
        return $this->post('/admin/voice-groups' . $path, $body, $htmx);
    }

    public function testMembersWithoutTheAdminRoleAreSentHome(): void
    {
        $this->signIn('lou@example.org');

        self::assertSame('/', $this->request('GET', '/admin/settings')->getHeaderLine('Location'));
        self::assertSame('/', $this->post('/admin/settings', self::CHECKBOXES_OFF)->getHeaderLine('Location'));
        self::assertSame('/', $this->group('/T1/delete')->getHeaderLine('Location'));
        self::assertTrue($this->settings()->delegationEnabled());
    }

    public function testShowsTheSettingsTheGroupsAndTheSignInValues(): void
    {
        $html = $this->page();

        self::assertStringContainsString('<a href="/admin/settings" class="on" aria-current="page">Einstellungen</a>', $html);
        self::assertStringContainsString('Bei 2 Mitgliedern sind 2 Ja-Stimmen nötig.', $html);
        self::assertMatchesRegularExpression('/name="delegation" value="1" checked/', $html);
        self::assertStringContainsString('<option value="2" selected>2</option>', $html);
        self::assertStringContainsString('also bis zu 3 Stimmrechte.', $html);
        self::assertStringContainsString('<option value="14" selected>14</option>', $html);
        self::assertStringContainsString('value="Sopran 1"', $html);
        self::assertStringContainsString('value="example.org" readonly', $html);
        self::assertStringContainsString('value="test-client" readonly', $html);
    }

    public function testTheMajorityExampleAgreesInNumber(): void
    {
        $this->service(\PDO::class)->exec("DELETE FROM members WHERE name = 'Lou Example'");

        self::assertStringContainsString('Bei 1 Mitglied ist 1 Ja-Stimme nötig.', $this->page());
    }

    public function testSavesTheSettingsWithoutJavaScript(): void
    {
        $response = $this->post('/admin/settings', self::CHECKBOXES_OFF);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/settings#settings', $response->getHeaderLine('Location'));
        $settings = $this->settings()->all();
        self::assertFalse($settings->delegationEnabled);
        self::assertSame(3, $settings->maxReceived);
        self::assertFalse($settings->showOpenNames);
        self::assertSame(30, $settings->retentionDays);
        $html = $this->page();
        self::assertStringContainsString('Einstellungen gespeichert.', $html);
        self::assertStringContainsString('also bis zu 4 Stimmrechte.', $html);
    }

    public function testSavingWithHtmxSwapsTheSettingsPanel(): void
    {
        $response = $this->post('/admin/settings', ['delegation' => '1', 'show_open_names' => '1', 'max_received' => '1', 'retention_days' => '7'], htmx: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<form class="panel form narrow mt-18" id="settings"', trim($this->body($response)));
        self::assertSame('{"toast":"Einstellungen gespeichert."}', $response->getHeaderLine('HX-Trigger'));
        self::assertSame(1, $this->settings()->maxReceived());
        self::assertSame(7, $this->settings()->retentionDays());
    }

    public function testRefusesValuesTheFormDoesNotOffer(): void
    {
        foreach ([['max_received' => '4', 'retention_days' => '14'], ['max_received' => '2', 'retention_days' => '1'], ['max_received' => 'x', 'retention_days' => '14']] as $body) {
            self::assertSame(400, $this->post('/admin/settings', $body)->getStatusCode());
        }
        self::assertSame(2, $this->settings()->maxReceived());
        self::assertSame(14, $this->settings()->retentionDays());
    }

    public function testAddsAGroupAtTheEndWithItsKeyUpperCased(): void
    {
        $response = $this->group('', ['key' => ' s3 ', 'label' => ' Sopran 3 ']);

        self::assertSame('/admin/settings#voice-groups', $response->getHeaderLine('Location'));
        self::assertSame('S3', $this->keys()[9]);
        self::assertSame('Sopran 3', $this->service(VoiceGroupRepository::class)->find('S3')?->label);
        self::assertStringContainsString('Gruppe Sopran 3 hinzugefügt.', $this->page());
    }

    public function testARefusedAddKeepsTheValuesAndNamesTheField(): void
    {
        foreach ([
            [['key' => '', 'label' => 'Sopran 3'], 'key', 'Kürzel und Name sind nötig.'],
            [['key' => 'S3', 'label' => ' '], 'label', 'Kürzel und Name sind nötig.'],
            [['key' => 'S1', 'label' => 'Sopran 3'], 'key', 'Dieses Kürzel gibt es schon.'],
            [['key' => 'SOPR3', 'label' => 'Sopran 3'], 'key', 'Das Kürzel besteht aus höchstens 4 Buchstaben von A bis Z oder Ziffern.'],
            [['key' => 'Ä1', 'label' => 'Sopran 3'], 'key', 'Das Kürzel besteht aus höchstens 4 Buchstaben von A bis Z oder Ziffern.'],
        ] as [$body, $field, $message]) {
            $response = $this->group('', $body, htmx: true);
            $html = $this->body($response);

            self::assertSame(422, $response->getStatusCode());
            self::assertStringStartsWith('<section class="panel narrow" id="voice-groups"', trim($html));
            self::assertSame(json_encode(['toast' => $message]), $response->getHeaderLine('HX-Trigger'));
            self::assertStringContainsString('<p class="field-error" id="g-error">' . $message . '</p>', $html);
            self::assertMatchesRegularExpression('/id="g-' . $field . '"[^>]*autofocus aria-invalid="true"/', $html);
            self::assertStringContainsString('value="' . htmlspecialchars($body['label']) . '"', $html);
        }
        self::assertCount(9, $this->keys());
    }

    public function testRenamesAGroupAndIgnoresAnEmptyOrUnchangedName(): void
    {
        $response = $this->group('/S1', ['label' => ' Sopran I '], htmx: true);
        self::assertSame('{"toast":"Gruppe umbenannt: Sopran I."}', $response->getHeaderLine('HX-Trigger'));
        self::assertStringContainsString('value="Sopran I"', $this->body($response));

        // Enter and leaving the field both send the name; the second one changes nothing.
        self::assertSame('', $this->group('/S1', ['label' => 'Sopran I'], htmx: true)->getHeaderLine('HX-Trigger'));
        self::assertSame('', $this->group('/S1', ['label' => ''], htmx: true)->getHeaderLine('HX-Trigger'));
        self::assertSame('Sopran I', $this->service(VoiceGroupRepository::class)->find('S1')?->label);
        self::assertSame(404, $this->group('/X9', ['label' => 'Nobody'])->getStatusCode());
    }

    public function testMovesAGroupUpAndDownButNotPastEitherEnd(): void
    {
        $this->group('/S2/move', ['direction' => 'up']);
        self::assertSame(['L', 'S2', 'S1'], array_slice($this->keys(), 0, 3));

        $this->group('/L/move', ['direction' => 'up']);
        $this->group('/B2/move', ['direction' => 'down']);
        self::assertSame('L', $this->keys()[0]);
        self::assertSame('B2', $this->keys()[8]);

        $this->group('/L/move', ['direction' => 'down']);
        self::assertSame(['S2', 'L', 'S1'], array_slice($this->keys(), 0, 3));
        self::assertSame(400, $this->group('/L/move', ['direction' => 'sideways'])->getStatusCode());
    }

    public function testTheMemberListFollowsTheGroupOrder(): void
    {
        $list = fn(): string => strstr($this->body($this->request('GET', '/admin/members')), 'id="member-list"') ?: '';
        self::assertLessThan(strpos($list(), 'Lou Example'), strpos($list(), 'Kim Example'));

        for ($i = 0; $i < 5; ++$i) {
            $this->group('/T1/move', ['direction' => 'up']);
        }

        self::assertSame('T1', $this->keys()[0]);
        self::assertLessThan(strpos($list(), 'Kim Example'), strpos($list(), 'Lou Example'));
    }

    public function testRemovesOnlyGroupsNobodyUses(): void
    {
        $this->addPoll(group: 'A1', status: PollStatus::Draft);
        $html = $this->page();
        self::assertMatchesRegularExpression('/id="g-remove-S1" disabled title="Wird noch verwendet"/', $html);
        self::assertMatchesRegularExpression('/id="g-remove-A1" disabled/', $html);
        self::assertMatchesRegularExpression('/id="g-remove-B2">/', $html);

        foreach (['S1', 'A1'] as $key) {
            $response = $this->group('/' . $key . '/delete', htmx: true);
            self::assertSame('{"toast":"Diese Gruppe wird noch verwendet."}', $response->getHeaderLine('HX-Trigger'));
        }
        $response = $this->group('/B2/delete', htmx: true);
        self::assertSame('{"toast":"Gruppe entfernt."}', $response->getHeaderLine('HX-Trigger'));
        self::assertSame(['L', 'S1', 'S2', 'A1', 'A2', 'T1', 'T2', 'B1'], $this->keys());
    }

    public function testAClosedPollsTurnoutKeepsItsGroups(): void
    {
        $poll = $this->addPoll(group: 'S1', status: PollStatus::Closed);
        $this->service(\PDO::class)->prepare("INSERT INTO poll_roll (poll_id, member_id, name, voice_group_key, status) VALUES (?, 99, 'Gone Example', 'B2', 'missing')")->execute([$poll]);

        $this->group('/B2/delete');

        self::assertContains('B2', $this->keys());
    }
}
