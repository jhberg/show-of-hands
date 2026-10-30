<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The count of a closed poll. A poll needs two thirds of all eligible voters, not of the votes
 * cast: a member who does not vote counts as an abstention, which works like a No.
 */
final readonly class Tally
{
    /**
     * @param int $eligible every member at the close, each with one voting right
     * @param int $yes the voting rights behind Yes, delegated ones included
     * @param int $no the voting rights behind No, delegated ones included
     * @param int $delegations the delegated votes among them, those whose recipient voted
     */
    public function __construct(
        public int $eligible,
        public int $yes,
        public int $no,
        public int $delegations,
    ) {}

    /**
     * Counts the ballots, each with its weight: 1 plus the votes handed to the member who cast it.
     *
     * @param iterable<array{Choice, int}> $ballots choice and weight of each ballot
     */
    public static function count(int $eligible, iterable $ballots): self
    {
        $yes = 0;
        $no = 0;
        $delegations = 0;
        foreach ($ballots as [$choice, $weight]) {
            if ($choice === Choice::Yes) {
                $yes += $weight;
            } else {
                $no += $weight;
            }
            $delegations += $weight - 1;
        }

        return new self($eligible, $yes, $no, $delegations);
    }

    /** The Yes votes the poll needs: two thirds of the eligible voters, rounded up. */
    public function needed(): int
    {
        return intdiv(2 * $this->eligible + 2, 3);
    }

    public function isAccepted(): bool
    {
        return $this->eligible > 0 && $this->yes >= $this->needed();
    }

    /** The voting rights cast, delegated ones included. */
    public function cast(): int
    {
        return $this->yes + $this->no;
    }

    /** The voting rights nobody cast. */
    public function abstained(): int
    {
        return max(0, $this->eligible - $this->cast());
    }

    /**
     * A count as a share of the eligible voters, between 0 and 1.
     */
    public function share(int $count): float
    {
        return $this->eligible === 0 ? 0.0 : $count / $this->eligible;
    }
}
