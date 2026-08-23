<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Store;

use Contempt\Contracts\Messaging\DomainEvent;

interface EventStore
{
    /** @return list<StoredEvent> */
    public function load(StreamId $stream, int $afterVersion = 0): array;

    /**
     * Implementations must append the entire batch atomically.
     *
     * @param list<DomainEvent> $events
     * @return list<StoredEvent>
     */
    public function append(
        StreamId $stream,
        int $expectedVersion,
        array $events,
        EventMetadata $metadata = new EventMetadata(),
    ): array;
}
