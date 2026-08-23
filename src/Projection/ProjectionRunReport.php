<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Projection;

final readonly class ProjectionRunReport
{
    public function __construct(
        public string $projection,
        public int $processed,
        public int $checkpoint,
        public bool $batchExhausted,
    ) {
        if ($processed < 0 || $checkpoint < 0) {
            throw new \InvalidArgumentException('Projection report counts must not be negative.');
        }
    }
}
