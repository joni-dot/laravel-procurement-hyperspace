<?php

namespace App\Companies;

use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Seeded choices for demo data, so every demo company gets the same made-up data and a failing test can be
 * repeated. Plain PHP: demo companies are made in production, where the development-only test data tools are missing.
 */
final class DemoRandom
{
    private readonly Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar($seed));
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->randomizer->getInt(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  array<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new InvalidArgumentException('There is nothing to pick from.');
        }

        return $items[$this->randomizer->pickArrayKeys($items, 1)[0]];
    }

    /**
     * Some of the items, in a random order.
     *
     * @template T
     *
     * @param  array<T>  $items
     * @return list<T>
     */
    public function some(array $items, int $count): array
    {
        return array_slice($this->shuffle($items), 0, max(0, $count));
    }

    /**
     * Each value as many times as its share, in a random order: ['done' => 2, 'late' => 1] gives
     * 'done' twice and 'late' once.
     *
     * @param  array<string, int>  $shares
     * @return list<string>
     */
    public function shuffledShares(array $shares): array
    {
        $values = [];

        foreach ($shares as $value => $count) {
            array_push($values, ...array_fill(0, $count, (string) $value));
        }

        return $this->shuffle($values);
    }

    /**
     * @template T
     *
     * @param  array<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return $this->randomizer->shuffleArray(array_values($items));
    }
}
