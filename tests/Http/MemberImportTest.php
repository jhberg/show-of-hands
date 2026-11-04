<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Tests\FixedClock;

final class MemberImportTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(
            ['bootstrap_admin' => ['name' => 'Kim Example', 'email' => 'kim@example.org', 'group' => 'S1']],
            overrides: [Clock::class => FixedClock::at('2026-11-03T18:42:00Z')],
        );
    }

    private function writeFile(string ...$rows): void
    {
        file_put_contents($this->dataDir . '/members.csv', implode("\n", ['name;email;email2;group;board;admin', ...$rows]) . "\n");
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(static fn($member) => $member->name, $this->service(MemberRepository::class)->all('de'));
    }

    public function testANewInstallationReadsTheFileOnceNextToTheBootstrapAdmin(): void
    {
        $this->writeFile('Kim Other;KIM@example.org;;T1;;', 'Lou Example;lou@example.org;lou@example.net;Tenor 1;x;', 'Max Example;max@example.org;;;;ja');

        $this->request('GET', '/login');

        self::assertSame(['Kim Example', 'Lou Example', 'Max Example'], $this->names());
        $kim = $this->service(MemberRepository::class)->findByEmail('kim@example.org');
        self::assertNotNull($kim);
        self::assertSame(['Kim Example', 'S1', true], [$kim[0]->name, $kim[0]->voiceGroupKey, $kim[0]->isAdmin], 'The file never changes a member who exists.');
        $lou = $this->service(MemberRepository::class)->findByEmail('lou@example.net');
        self::assertNotNull($lou);
        self::assertSame(['T1', true, false], [$lou[0]->voiceGroupKey, $lou[0]->isBoard, $lou[0]->isAdmin]);
        self::assertSame('2026-11-03T18:42:00Z', $this->service(SettingsRepository::class)->memberImportAt());

        $this->writeFile('Pia Example;pia@example.org;;;;');
        $this->request('GET', '/login');

        self::assertNotContains('Pia Example', $this->names(), 'After the first import, only an admin starts one.');
    }

    public function testAFileThatComesLaterWaitsForAnAdmin(): void
    {
        $this->request('GET', '/login');
        $this->writeFile('Lou Example;lou@example.org;;;;');
        $this->request('GET', '/login');

        self::assertSame(['Kim Example'], $this->names());
        $this->signIn('kim@example.org');
        self::assertStringContainsString('members.csv liegt bereit und wurde noch nicht eingelesen.', $this->body($this->request('GET', '/admin/members')));
    }

    public function testANewInstallationRetriesAFileThatAddedNobody(): void
    {
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at('2026-11-03T18:42:00Z')]);
        file_put_contents($this->dataDir . '/members.csv', "Name;E-Mail\nKim Example;kim@example.org\n");
        $this->request('GET', '/login');
        self::assertSame([], $this->names());
        self::assertNull($this->service(SettingsRepository::class)->memberImportAt());

        $this->writeFile('Kim Example;kim@example.org;;;;x');
        $this->request('GET', '/login');

        self::assertSame(['Kim Example'], $this->names());
    }

    public function testWithoutAFileTheMembersPageExplainsIt(): void
    {
        $this->signIn('kim@example.org');

        $page = $this->body($this->request('GET', '/admin/members'));

        self::assertStringContainsString('Lege dazu members.csv in den Ordner config', $page);
        self::assertStringNotContainsString('Datei erneut einlesen', $page);
    }

    public function testTheAdminSeesWhatAnImportAddsBeforeItRuns(): void
    {
        $this->writeFile('Lou Example;lou@example.org;;T1;x;');
        $this->signIn('kim@example.org');
        $this->service(\PDO::class)->exec("DELETE FROM members WHERE name = 'Lou Example'");
        $this->writeFile('Lou Example;lou@example.org;;T1;x;', 'Kim Other;kim@example.org;;;;', 'Bad Row;nope;;;;', 'Pia Example;pia@example.org;;S3;;');

        $page = $this->body($this->request('GET', '/admin/members'));
        self::assertStringContainsString('members.csv wurde zuletzt am 3. November 2026 um 19:42 Uhr eingelesen.', $page);
        self::assertStringContainsString('Datei erneut einlesen', $page);

        $preview = $this->body($this->request('GET', '/admin/members?import=1'));
        self::assertStringContainsString('Ein neues Mitglied einlesen?', $preview);
        self::assertStringContainsString('<b>Lou Example</b> · Tenor 1 · Vorstand', $preview);
        self::assertStringContainsString('kommt wieder dazu', $preview);
        self::assertStringContainsString('Ein Eintrag steht schon in der Liste und bleibt unverändert.', $preview);
        self::assertStringContainsString('Diese 2 Zeilen werden übersprungen:', $preview);
        self::assertStringContainsString('Zeile 4: Die E-Mail-Adresse ist ungültig.', $preview);
        self::assertStringContainsString('Zeile 5: Die Stimmgruppe „S3“ gibt es nicht.', $preview);
        self::assertSame(['Kim Example'], $this->names(), 'The preview writes nothing.');

        $response = $this->post('/admin/members/import');
        self::assertSame(303, $response->getStatusCode());
        $page = $this->body($this->request('GET', '/admin/members'));
        self::assertStringContainsString('Ein Mitglied eingelesen, 2 Zeilen übersprungen.', $page);
        self::assertSame(['Kim Example', 'Lou Example'], $this->names());
        $kim = $this->service(MemberRepository::class)->findByEmail('kim@example.org');
        self::assertNotNull($kim);
        self::assertSame('Kim Example', $kim[0]->name);
    }

    public function testAnImportAddsMembersMarkedInactiveAsInactive(): void
    {
        $this->signIn('kim@example.org');
        file_put_contents($this->dataDir . '/members.csv', "name;email;inactive\nKim Example;kim@example.org;x\nLou Example;lou@example.org;x\nPia Example;pia@example.org;\n");

        $preview = $this->body($this->request('GET', '/admin/members?import=1'));
        self::assertStringContainsString('<b>Lou Example</b> · inaktiv', $preview);
        self::assertStringNotContainsString('<b>Pia Example</b> · inaktiv', $preview);

        $this->post('/admin/members/import');

        $members = $this->service(MemberRepository::class);
        $active = static fn(string $email): ?bool => $members->findByEmail($email)[0]->isActive ?? null;
        self::assertSame([true, false, true], [$active('kim@example.org'), $active('lou@example.org'), $active('pia@example.org')], 'The file never sets a member who exists inactive.');
    }

    public function testAnImportReadsTheDisplayNameColumn(): void
    {
        $this->signIn('kim@example.org');
        file_put_contents($this->dataDir . '/members.csv', "name;display_name;email\nLouisa Example;Lou E.;lou@example.org\nPia Example;;pia@example.org\n");

        $preview = $this->body($this->request('GET', '/admin/members?import=1'));
        self::assertStringContainsString('<b>Louisa Example</b> · Anzeigename: Lou E.', $preview);
        self::assertStringNotContainsString('<b>Pia Example</b> · Anzeigename', $preview);

        $this->post('/admin/members/import');

        $members = $this->service(MemberRepository::class);
        $displayName = static fn(string $email): ?string => ($members->findByEmail($email) ?? throw new \LogicException($email))[0]->displayName;
        self::assertSame(['Lou E.', null], [$displayName('lou@example.org'), $displayName('pia@example.org')]);
    }

    public function testAnImportFillsInMissingDisplayNamesOfMembersOnTheList(): void
    {
        $members = $this->service(MemberRepository::class);
        $this->signIn('kim@example.org');
        $lou = $members->add('Louisa Example', ['lou@example.org'], 'T1', false, false);
        $pia = $members->add('Pia Example', ['pia@example.org'], 'S1', false, false, 'Pia E.');
        $ned = $members->add('Ned Example', ['ned@example.org'], 'B1', false, false);
        $max = $members->add('Max Example', ['max@example.org'], 'B1', false, false);
        file_put_contents($this->dataDir . '/members.csv', "name;display_name;email;email2;group\nLouisa Renamed;Lou E.;lou@example.org;;B1\nPia Example;Pia X.;pia@example.org;;\nKim Example;;kim@example.org;;\nNed Example;Ned E.;ned@example.org;max@example.org;\n");

        $preview = $this->body($this->request('GET', '/admin/members?import=1'));
        self::assertStringNotContainsString('Die Datei bringt keine neuen Mitglieder', $preview);
        self::assertStringNotContainsString('neue Mitglieder einlesen?', $preview);
        self::assertStringContainsString('<b>Ein Mitglied aus der Liste bekommt den Anzeigenamen aus der Datei:</b>', $preview);
        self::assertStringContainsString('<li><b>Louisa Example</b> · Anzeigename: Lou E.</li>', $preview);
        self::assertStringNotContainsString('Pia X.', $preview, 'A display name already set stays.');
        self::assertStringContainsString('3 Einträge stehen schon in der Liste und bleiben unverändert.', $preview);
        self::assertStringContainsString('Ja, einlesen', $preview);
        self::assertNull($members->find($lou)?->displayName, 'The preview writes nothing.');

        $this->post('/admin/members/import');

        self::assertStringContainsString('Keine neuen Mitglieder eingelesen, ein Anzeigename nachgetragen.', $this->body($this->request('GET', '/admin/members')));
        $louAfter = $members->find($lou);
        self::assertSame(['Lou E.', 'Louisa Example', 'T1'], [$louAfter?->displayName, $louAfter?->name, $louAfter?->voiceGroupKey], 'Only the display name changes.');
        self::assertSame('Pia E.', $members->find($pia)?->displayName);
        self::assertSame([null, null], [$members->find($ned)?->displayName, $members->find($max)?->displayName], 'A row with the addresses of two members names neither.');
    }

    public function testAPreviewWithNothingNewOffersNoConfirmation(): void
    {
        $this->writeFile('Kim Example;kim@example.org;;;;');
        $this->signIn('kim@example.org');

        $preview = $this->body($this->request('GET', '/admin/members?import=1'));

        self::assertStringContainsString('Die Datei bringt keine neuen Mitglieder und keine Anzeigenamen zum Nachtragen.', $preview);
        self::assertStringNotContainsString('Ja, einlesen', $preview);
    }

    public function testAnImportWithoutTheFileSaysSo(): void
    {
        $this->signIn('kim@example.org');

        $this->post('/admin/members/import');

        self::assertStringContainsString('members.csv liegt nicht mehr bereit.', $this->body($this->request('GET', '/admin/members')));
        self::assertNull($this->service(SettingsRepository::class)->memberImportAt());
    }

    public function testOnlyAdminsImport(): void
    {
        $this->writeFile('Lou Example;lou@example.org;;;;');
        $this->signIn('lou@example.org');
        $this->writeFile('Lou Example;lou@example.org;;;;', 'Pia Example;pia@example.org;;;;');

        self::assertSame('/', $this->post('/admin/members/import')->getHeaderLine('Location'));
        self::assertNotContains('Pia Example', $this->names());
    }
}
