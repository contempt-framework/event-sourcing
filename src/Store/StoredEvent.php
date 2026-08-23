<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Store;

use Contempt\Contracts\Messaging\DomainEvent;
use Contempt\Core\Identity\Uuid;

final readonly class StoredEvent
{
    public function __construct(
        public Uuid $id,
        public StreamId $stream,
        public int $version,
        public int $globalPosition,
        public DomainEvent $event,
        public EventMetadata $metadata,
        public \DateTimeImmutable $recordedAt,
    ) {
        if ($version < 1 || $globalPosition < 1) {
            throw new \InvalidArgumentException('Stored event versions and positions start at one.');
        }
    }
}
