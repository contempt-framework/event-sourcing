<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Snapshot;

use Contempt\EventSourcing\Store\StreamId;

final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, AggregateSnapshot> */
    private array $snapshots = [];

    public function load(StreamId $stream): ?AggregateSnapshot
    {
        return $this->snapshots[self::key($stream)] ?? null;
    }

    public function save(AggregateSnapshot $snapshot): void
    {
        $key = self::key($snapshot->stream);
        $current = $this->snapshots[$key] ?? null;

        if ($current !== null && $snapshot->version <= $current->version) {
            throw new \InvalidArgumentException('A snapshot store accepts only a newer aggregate version.');
        }

        $this->snapshots[$key] = $snapshot;
    }

    private static function key(StreamId $stream): string
    {
        return $stream->aggregateType . "\0" . $stream->aggregateId;
    }
}
