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
            new VoiceGroup('S1', 'Sopran 1'), new VoiceGroup('A2', 'Alt 2'), new VoiceGroup('T1', 'Tenor 1'),
        ]);

        self::assertSame([], $file->problems);
        self::assertCount(3, $file->rows);
        [$alex, $sam, $robin] = $file->rows;
        self::assertSame(['Alex Example', ['alex.example@example.org'], 'S1', true, true], [$alex->details->name, $alex->details->emails, $alex->voiceGroupKey, $alex->isBoard, $alex->isAdmin]);
        self::assertSame([['sam.sample@example.org', 'sam.private@example.org'], 'A2', true, false], [$sam->details->emails, $sam->voiceGroupKey, $sam->isBoard, $sam->isAdmin]);
        self::assertSame(['T1', false, false], [$robin->voiceGroupKey, $robin->isBoard, $robin->isAdmin]);
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
            [6, MemberFileIssue::RoleInvalid, 'vielleicht'],
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
