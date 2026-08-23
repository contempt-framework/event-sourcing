<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Snapshot;

use Contempt\EventSourcing\Store\StreamId;

interface SnapshotStore
{
    public function load(StreamId $stream): ?AggregateSnapshot;

    public function save(AggregateSnapshot $snapshot): void;
}
