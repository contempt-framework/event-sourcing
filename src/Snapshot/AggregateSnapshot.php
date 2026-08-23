<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Snapshot;

use Contempt\EventSourcing\Store\StreamId;

final readonly class AggregateSnapshot
{
    public function __construct(
        public StreamId $stream,
        public int $version,
        public AggregateSnapshotState $state,
        public \DateTimeImmutable $recordedAt,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('Aggregate snapshot version must be positive.');
        }

        if ($recordedAt->getTimezone()->getName() !== 'UTC' && $recordedAt->getOffset() !== 0) {
            throw new \InvalidArgumentException('Aggregate snapshot timestamp must be expressed in UTC.');
        }
    }
}
