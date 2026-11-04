<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\MemberDetailsError;
use ShowOfHands\Domain\MemberFile;
use ShowOfHands\Domain\MemberFileIssue;
use ShowOfHands\Domain\MemberFileProblem;
use ShowOfHands\Domain\VoiceGroup;

final class MemberFileTest extends TestCase
{
    /**
     * @return list<VoiceGroup>
     */
    private static function voiceGroups(): array
    {
        return [new VoiceGroup('S1', 'Sopran 1'), new VoiceGroup('T1', 'Tenor 1')];
    }

    /**
     * @return list<array{int, MemberFileIssue|MemberDetailsError, string}>
     */
    private static function problems(MemberFile $file): array
    {
        return array_map(static fn(MemberFileProblem $p): array => [$p->line, $p->issue, $p->value], $file->problems);
    }

    public function testReadsTheTemplate(): void
    {
        $file = MemberFile::parse((string) file_get_contents(dirname(__DIR__, 3) . '/config/members.example.csv'), [
            new VoiceGroup('S1', 'Sopran 1'), new VoiceGroup('A2', 'Alt 2'), new VoiceGroup('T1', 'Tenor 1'), new VoiceGroup('B1', 'Bass 1'),
        ]);

        self::assertSame([], $file->problems);
        self::assertCount(4, $file->rows);
        [$alex, $sam, $robin, $kim] = $file->rows;
        self::assertSame(['Alex Example', ['alex.example@example.org'], 'S1', true, true], [$alex->details->name, $alex->details->emails, $alex->voiceGroupKey, $alex->isBoard, $alex->isAdmin]);
        self::assertSame([['sam.sample@example.org', 'sam.private@example.org'], 'A2', true, false], [$sam->details->emails, $sam->voiceGroupKey, $sam->isBoard, $sam->isAdmin]);
        self::assertSame(['T1', false, false], [$robin->voiceGroupKey, $robin->isBoard, $robin->isAdmin]);
        self::assertSame([null, 'Sam S.', null, 'Kim B.'], [$alex->details->displayName, $sam->details->displayName, $robin->details->displayName, $kim->details->displayName]);
        self::assertSame([true, true, true, false], [$alex->isActive, $sam->isActive, $robin->isActive, $kim->isActive]);
    }

    public function testReadsTheInactiveColumn(): void
    {
        $file = MemberFile::parse("name;email;inactive\nKim Example;kim@example.org;x\nLou Example;lou@example.org;\nPia Example;pia@example.org;maybe\n", self::voiceGroups());

        self::assertSame([false, true], [$file->rows[0]->isActive, $file->rows[1]->isActive]);
        self::assertSame([[4, MemberFileIssue::FlagInvalid, 'maybe']], self::problems($file));
    }

    public function testReadsCommasQuotesAnyColumnOrderAndExtraColumns(): void
    {
        $file = MemberFile::parse("Note,EMAIL,Name,Board\r\n\"first, row\",kim@example.org,\"Kim \"\"K\"\" Example\",Ja\r\n", self::voiceGroups());

        self::assertSame([], $file->problems);
        self::assertSame('Kim "K" Example', $file->rows[0]->details->name);
        self::assertTrue($file->rows[0]->isBoard);
        self::assertFalse($file->rows[0]->isAdmin);
        self::assertNull($file->rows[0]->voiceGroupKey);
    }

    public function testReadsAByteOrderMarkAndWindows1252(): void
    {
        $utf8 = MemberFile::parse("\u{FEFF}name;email\nJörg Example;jorg@example.org\n", self::voiceGroups());
        $latin = MemberFile::parse("name;email\n" . mb_convert_encoding('Jörg Example', 'Windows-1252', 'UTF-8') . ";jorg@example.org\n", self::voiceGroups());

        self::assertSame('Jörg Example', $utf8->rows[0]->details->name);
        self::assertSame('Jörg Example', $latin->rows[0]->details->name);
    }

    public function testReadsMacRomanAsExcelForMacSavesIt(): void
    {
        // Mac Roman puts ä, é, and ö on bytes that Windows-1252 reads as Š, Ž, and š.
        $file = MemberFile::parse("name;email\r\nJo B\x8Acker;jo@example.org\r\nRen\x8E D\x9Arr;rene@example.org\r\n", self::voiceGroups());
        // Windows-1252 puts ä and ß on bytes that Mac Roman reads as ‰ and ﬂ.
        $windows = MemberFile::parse("name;email\nJo B\xE4cker Stra\xDFe;jo@example.org\n", self::voiceGroups());

        self::assertSame(['Jo Bäcker', 'René Dörr'], [$file->rows[0]->details->name, $file->rows[1]->details->name]);
        self::assertSame('Jo Bäcker Straße', $windows->rows[0]->details->name);

        // Windows-1252 reads Mac Roman's ’ (0xD5) as the letter Õ.
        $quoted = MemberFile::parse("name;email\r\nJo M\x9Fller;jo@example.org\r\nKim O\xD5Connor;kim@example.org\r\n", self::voiceGroups());
        self::assertSame(['Jo Müller', 'Kim O’Connor'], [$quoted->rows[0]->details->name, $quoted->rows[1]->details->name]);

        // Windows-1252 reads Mac Roman's Ö (0x85) as …, which no name holds.
        $ellipsis = MemberFile::parse("name;email\n\x85zlem Example;ozlem@example.org\n", self::voiceGroups());
        self::assertSame('Özlem Example', $ellipsis->rows[0]->details->name);

        // Windows-1252 leaves 0x8D (ç) and 0x8F (è) undefined, and reads 0x91 (ë) as ‘.
        $undefined = MemberFile::parse("name;email\nFran\x8Dois Example;f@example.org\nMich\x8Fle No\x91l;m@example.org\n", self::voiceGroups());
        self::assertSame(['François Example', 'Michèle Noël'], [$undefined->rows[0]->details->name, $undefined->rows[1]->details->name]);
    }

    public function testKeepsWindows1252WhenMacRomanReadsItAsLettersToo(): void
    {
        // Mac Roman reads é (0xE9) as È and € (0x80) as Ä, both letters.
        $file = MemberFile::parse("name;email;note\nRen\xE9 Example;rene@example.org;5 \x80\nEd \x91Ted\x92 Example;ted@example.org;\n", self::voiceGroups());

        self::assertSame(['René Example', 'Ed ‘Ted’ Example'], [$file->rows[0]->details->name, $file->rows[1]->details->name]);
    }

    public function testReadsBareCarriageReturnsAndWindowsQuotes(): void
    {
        // Old Mac files end lines with a bare \r and are Mac Roman, here É (0x83).
        $mac = MemberFile::parse("name;email\r\x83va Example;eva@example.org\rJo Example;jo@example.org\r", self::voiceGroups());
        // Mac Roman would read the curly apostrophe (0x92) as í.
        $windows = MemberFile::parse("name;email\nKim O\x92Connor;kim@example.org\n", self::voiceGroups());

        self::assertSame([], $mac->problems);
        self::assertSame(['Éva Example', 'Jo Example'], [$mac->rows[0]->details->name, $mac->rows[1]->details->name]);
        self::assertSame('Kim O’Connor', $windows->rows[0]->details->name);
    }

    public function testMatchesGroupsByKeyOrLabelInAnyCase(): void
    {
        $file = MemberFile::parse("name;email;group\nA;a@example.org;s1\nB;b@example.org;tenor 1\nC;c@example.org;S3\n", self::voiceGroups());

        self::assertSame(['S1', 'T1'], array_map(static fn($row) => $row->voiceGroupKey, $file->rows));
        self::assertSame([[4, MemberFileIssue::GroupUnknown, 'S3']], self::problems($file));
    }

    public function testReportsEachBrokenRowWithItsLineAndSkipsBlankOnes(): void
    {
        $file = MemberFile::parse(implode("\n", [
            'name;email;email2;board;admin',
            ';a@example.org;;;',
            'B;not-an-address;;;',
            ';;;;',
            'C;c@example.org;c@example.org;;',
            'D;d@example.org;;vielleicht;',
            'E;e@example.org;;;',
            'F;f@example.org;E@example.org;;',
            'G;;g@example.org;;',
        ]), self::voiceGroups());

        self::assertSame([
            [2, MemberDetailsError::NameMissing, ''],
            [3, MemberDetailsError::FirstAddressInvalid, ''],
            [5, MemberDetailsError::SameAddresses, ''],
            [6, MemberFileIssue::FlagInvalid, 'vielleicht'],
            [8, MemberFileIssue::AddressRepeated, 'e@example.org'],
        ], self::problems($file));
        self::assertSame(['E', 'G'], array_map(static fn($row) => $row->details->name, $file->rows));
        self::assertSame(['g@example.org'], $file->rows[1]->details->emails);
    }

    public function testSkipsBlankRowsAboveTheHeader(): void
    {
        $file = MemberFile::parse("\n;;;\nname;email\nKim Example;kim@example.org\nBroken;nope\n", self::voiceGroups());

        self::assertSame('Kim Example', $file->rows[0]->details->name);
        self::assertSame([[5, MemberDetailsError::FirstAddressInvalid, '']], self::problems($file));
    }

    public function testRefusesAFileWithoutNameAndEmailColumns(): void
    {
        $file = MemberFile::parse("Kim Example;kim@example.org\n", self::voiceGroups());

        self::assertSame([], $file->rows);
        self::assertSame([[1, MemberFileIssue::HeaderInvalid, '']], self::problems($file));
        self::assertSame([[1, MemberFileIssue::HeaderInvalid, '']], self::problems(MemberFile::parse('', self::voiceGroups())));
    }
}
