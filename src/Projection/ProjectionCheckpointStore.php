<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Projection;

interface ProjectionCheckpointStore
{
    public function position(string $projection): int;

    /** Atomically advances the checkpoint only when it still equals the expected position. */
    public function advance(string $projection, int $expectedPosition, int $newPosition): bool;
}
