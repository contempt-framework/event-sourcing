<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Aggregate;

use Contempt\Core\Time\SystemClock;
use Contempt\EventSourcing\Snapshot\SnapshotStore;
use Contempt\EventSourcing\Store\EventMetadata;
use Contempt\EventSourcing\Store\EventStore;
use Contempt\EventSourcing\Store\StreamId;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** @template TAggregate of EventSourcedAggregate */
final readonly class AggregateRepository
{
    /** @param AggregateFactory<TAggregate> $factory */
    public function __construct(
        private EventStore $store,
        private AggregateFactory $factory,
        private ?SnapshotStore $snapshots = null,
        private int $snapshotInterval = 0,
        private ClockInterface $clock = new SystemClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if (($snapshots === null && $snapshotInterval !== 0) || ($snapshots !== null && ($snapshotInterval < 1 || $snapshotInterval > 1_000_000))) {
            throw new \InvalidArgumentException('Snapshot store and an interval between 1 and 1,000,000 must be configured together.');
        }
    }

    /** @return TAggregate */
    public function load(StreamId $stream): EventSourcedAggregate
    {
        $aggregate = $this->factory->create($stream);
        $snapshot = $this->snapshots?->load($stream);

        if ($snapshot !== null) {
            $aggregate->restoreSnapshot($snapshot);
        }

        foreach ($this->store->load($stream, $aggregate->persistedVersion()) as $event) {
            $aggregate->replay($event);
        }

        return $aggregate;
    }

    public function save(EventSourcedAggregate $aggregate, EventMetadata $metadata = new EventMetadata()): void
    {
        $pending = $aggregate->pendingEvents();

        if ($pending === []) {
            return;
        }

        $stored = $this->store->append($aggregate->stream(), $aggregate->persistedVersion(), $pending, $metadata);
        $last = end($stored);

        if (!$last instanceof \Contempt\EventSourcing\Store\StoredEvent) {
            throw new \LogicException('Event store returned no records for a non-empty append.');
        }

        $aggregate->markCommitted($last->version);

        if ($this->snapshots === null || $aggregate->version() % $this->snapshotInterval !== 0) {
            return;
        }

        try {
            $this->snapshots->save($aggregate->snapshot($this->clock->now()));
        } catch (\Throwable $failure) {
            $this->logger->warning('Aggregate snapshot persistence failed after events were committed.', [
                'exception' => $failure,
                'stream' => (string) $aggregate->stream(),
                'version' => $aggregate->version(),
            ]);
        }
    }
}
