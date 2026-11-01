<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The settings admins change in the admin area. The majority is not among them: it is fixed at
 * two thirds of all eligible voters.
 */
final readonly class Settings
{
    /** How many extra votes one member may hold at most. */
    public const MAX_RECEIVED_CHOICES = [1, 2, 3];

    /** How many days a closed poll is kept, with its result and turnout. */
    public const RETENTION_DAYS_CHOICES = [7, 14, 30];

    /** The values a new installation starts with, as the first migration writes them. */
    public static function defaults(): self
    {
        return new self(true, 2, true, 14);
    }

    /**
     * @param bool $delegationEnabled whether members may hand their vote to another member
     * @param int $maxReceived one of MAX_RECEIVED_CHOICES
     * @param bool $showOpenNames whether ordinary members see who has not voted yet
     * @param int $retentionDays one of RETENTION_DAYS_CHOICES
     *
     * @throws \InvalidArgumentException for a value outside its choices
     */
    public function __construct(
        public bool $delegationEnabled,
        public int $maxReceived,
        public bool $showOpenNames,
        public int $retentionDays,
    ) {
        if (!in_array($maxReceived, self::MAX_RECEIVED_CHOICES, true)) {
            throw new \InvalidArgumentException("max_received {$maxReceived} is not allowed.");
        }
        if (!in_array($retentionDays, self::RETENTION_DAYS_CHOICES, true)) {
            throw new \InvalidArgumentException("retention_days {$retentionDays} is not allowed.");
        }
    }
}
