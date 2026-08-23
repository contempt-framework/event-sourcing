<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Aggregate;

use Contempt\Contracts\Messaging\DomainEvent;
use Contempt\EventSourcing\Snapshot\AggregateSnapshot;
use Contempt\EventSourcing\Snapshot\AggregateSnapshotState;
use Contempt\EventSourcing\Store\StoredEvent;
use Contempt\EventSourcing\Store\StreamId;

abstract class EventSourcedAggregate
{
    /** @var list<DomainEvent> */
    private array $pending = [];
    private int $version = 0;
    private int $persistedVersion = 0;

    final public function __construct(private readonly StreamId $stream) {}

    final public function stream(): StreamId
    {
        return $this->stream;
    }

    final public function version(): int
    {
        return $this->version;
    }

    final public function persistedVersion(): int
    {
        return $this->persistedVersion;
    }

    /** @return list<DomainEvent> */
    final public function pendingEvents(): array
    {
        return $this->pending;
    }

    final public function replay(StoredEvent $stored): void
    {
        if ((string) $stored->stream !== (string) $this->stream || $stored->version !== $this->version + 1 || $this->pending !== []) {
            throw new \LogicException('Stored event does not continue this clean aggregate stream.');
        }

        $this->apply($stored->event);
        $this->version = $stored->version;
        $this->persistedVersion = $stored->version;
    }

    final public function restoreSnapshot(AggregateSnapshot $snapshot): void
    {
        if (
            (string) $snapshot->stream !== (string) $this->stream
            || $this->version !== 0
            || $this->persistedVersion !== 0
            || $this->pending !== []
        ) {
            throw new \LogicException('A snapshot can restore only its matching, new and clean aggregate.');
        }

        $this->restoreSnapshotState($snapshot->state);
        $this->version = $snapshot->version;
        $this->persistedVersion = $snapshot->version;
    }

    final public function snapshot(\DateTimeImmutable $recordedAt): AggregateSnapshot
    {
        if ($this->version < 1 || $this->pending !== [] || $this->version !== $this->persistedVersion) {
            throw new \LogicException('Only a persisted, clean and non-empty aggregate can be snapshotted.');
        }

        return new AggregateSnapshot(
            $this->stream,
            $this->version,
            $this->captureSnapshotState(),
            $recordedAt->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    final public function markCommitted(int $version): void
    {
        if ($version !== $this->version || $version !== $this->persistedVersion + \count($this->pending)) {
            throw new \LogicException('Committed event version does not match aggregate pending events.');
        }

        $this->persistedVersion = $version;
        $this->pending = [];
    }

    final protected function recordThat(DomainEvent $event): void
    {
        if ($this->version === PHP_INT_MAX) {
            throw new \OverflowException('Aggregate event version overflow.');
        }

        $this->apply($event);
        ++$this->version;
        $this->pending[] = $event;
    }

    abstract protected function apply(DomainEvent $event): void;

    protected function captureSnapshotState(): AggregateSnapshotState
    {
        throw new \LogicException(\sprintf('Aggregate %s does not support snapshots.', static::class));
    }

    protected function restoreSnapshotState(AggregateSnapshotState $state): void
    {
        throw new \LogicException(\sprintf('Aggregate %s does not support snapshots.', static::class));
    }
}
