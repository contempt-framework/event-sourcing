<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Store;

interface GlobalEventStore extends EventStore
{
    /**
     * Returns events strictly after the cursor, ordered by global position.
     *
     * @return list<StoredEvent>
     */
    public function loadFromGlobalPosition(int $afterPosition, int $limit): array;
}
