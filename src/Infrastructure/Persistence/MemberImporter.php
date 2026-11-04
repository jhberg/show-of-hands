<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\MemberFile;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Reads the member file into the member list. It adds members and changes no one, with one
 * exception: a member already on the list who has no display name gets the one of their row. A
 * member removed since the last import comes back if the file still lists them, which is why the
 * admin area shows the import before it runs.
 *
 * A new installation reads the file by itself on its first request. After that, only an admin
 * starts an import.
 */
final readonly class MemberImporter
{
    public function __construct(
        private \PDO $pdo,
        private MemberRepository $members,
        private VoiceGroupRepository $voiceGroups,
        private SettingsRepository $settings,
        private string $file,
    ) {}

    public function fileExists(): bool
    {
        return is_file($this->file);
    }

    /** The file's name as admins see it in the admin area. */
    public function fileName(): string
    {
        return basename($this->file);
    }

    /** What an import would add now, or null without a file. */
    public function preview(): ?MemberImport
    {
        $contents = $this->read();

        return $contents === null ? null : $this->plan($contents);
    }

    /** Adds the file's new members and fills in display names, or returns null without a file. */
    public function import(\DateTimeImmutable $now): ?MemberImport
    {
        return $this->run($now, true);
    }

    /**
     * The import of a new installation. An import that adds nobody, say from a file with a broken
     * header, counts as none, so the next request tries the corrected file again while the list
     * is still empty. Of two parallel first requests, the second finds every address taken.
     */
    public function importOnce(\DateTimeImmutable $now): void
    {
        if ($this->fileExists()) {
            $this->run($now, false);
        }
    }

    private function run(\DateTimeImmutable $now, bool $markEmpty): ?MemberImport
    {
        $contents = $this->read();
        if ($contents === null) {
            return null;
        }

        return Transaction::immediate($this->pdo, function () use ($contents, $now, $markEmpty): MemberImport {
            $import = $this->plan($contents);
            foreach ($import->added as $row) {
                $this->members->insert($row->details->name, $row->details->emails, $row->voiceGroupKey, $row->isBoard, $row->isAdmin, $row->isActive, $row->details->displayName);
            }
            foreach ($import->named as $entry) {
                $this->members->setDisplayName($entry['member']->id, $entry['display_name']);
            }
            if ($markEmpty || $import->added !== [] || $import->named !== []) {
                $this->settings->setMemberImportAt($now);
            }

            return $import;
        });
    }

    private function plan(string $contents): MemberImport
    {
        $file = MemberFile::parse($contents, $this->voiceGroups->all());
        $added = [];
        $named = [];
        $existing = 0;
        foreach ($file->rows as $row) {
            $matches = [];
            foreach ($row->details->emails as $email) {
                $member = $this->members->findByEmail($email)[0] ?? null;
                if ($member !== null) {
                    $matches[$member->id] = $member;
                }
            }
            // A row whose addresses belong to two members describes neither for sure.
            $member = count($matches) === 1 ? reset($matches) : null;
            if ($matches === []) {
                $added[] = $row;
            } elseif ($member !== null && $member->displayName === null && $row->details->displayName !== null && !isset($named[$member->id])) {
                $named[$member->id] = ['member' => $member, 'display_name' => $row->details->displayName];
            } else {
                ++$existing;
            }
        }

        return new MemberImport($added, array_values($named), $existing, $file->problems);
    }

    private function read(): ?string
    {
        if (!$this->fileExists()) {
            return null;
        }
        $contents = @file_get_contents($this->file);

        return $contents === false ? null : $contents;
    }
}
