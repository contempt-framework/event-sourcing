<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Projection;

final class InMemoryProjectionCheckpointStore implements ProjectionCheckpointStore
{
    /** @var array<string, int> */
    private array $positions = [];

    public function position(string $projection): int
    {
        $projection = new ProjectionName($projection)->value;

        return $this->positions[$projection] ?? 0;
    }

    public function advance(string $projection, int $expectedPosition, int $newPosition): bool
    {
        $projection = new ProjectionName($projection)->value;

        if ($expectedPosition < 0 || $newPosition <= $expectedPosition) {
            throw new \InvalidArgumentException('Projection checkpoint advancement must be positive and monotonic.');
        }

        if (($this->positions[$projection] ?? 0) !== $expectedPosition) {
            return false;
        }

        $this->positions[$projection] = $newPosition;

        return true;
    }

}
