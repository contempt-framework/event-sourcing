<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Store;

use Contempt\Contracts\Messaging\DomainEvent;
use Contempt\Core\Identity\Uuid;
use Contempt\Core\Time\SystemClock;
use Psr\Clock\ClockInterface;

final class InMemoryEventStore implements GlobalEventStore
{
    /** @var array<string, list<StoredEvent>> */
    private array $streams = [];
    /** @var list<StoredEvent> */
    private array $global = [];
    private int $globalPosition = 0;

    public function __construct(private readonly ClockInterface $clock = new SystemClock()) {}

    public function load(StreamId $stream, int $afterVersion = 0): array
    {
        if ($afterVersion < 0) {
            throw new \InvalidArgumentException('Event stream cursor must not be negative.');
        }

        return array_values(array_filter(
            $this->streams[self::key($stream)] ?? [],
            static fn(StoredEvent $event): bool => $event->version > $afterVersion,
        ));
    }

    public function append(
        StreamId $stream,
        int $expectedVersion,
        array $events,
        EventMetadata $metadata = new EventMetadata(),
    ): array {
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException('Expected stream version must not be negative.');
        }

        foreach ($events as $event) {
            if (!$event instanceof DomainEvent) {
                throw new \InvalidArgumentException('Event store accepts only domain events.');
            }
        }

        $key = self::key($stream);
        $current = $this->streams[$key] ?? [];
        $actualVersion = \count($current);

        if ($actualVersion !== $expectedVersion) {
            throw new ConcurrencyConflict($expectedVersion, $actualVersion);
        }

        if ($events === []) {
            return [];
        }

        if (\count($events) > PHP_INT_MAX - $actualVersion || \count($events) > PHP_INT_MAX - $this->globalPosition) {
            throw new \OverflowException('Event stream position overflow.');
        }

        $recordedAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $appended = [];

        foreach ($events as $offset => $event) {
            $appended[] = new StoredEvent(
                Uuid::v7($recordedAt),
                $stream,
                $actualVersion + $offset + 1,
                $this->globalPosition + $offset + 1,
                $event,
                $metadata,
                $recordedAt,
            );
        }

        $this->streams[$key] = [...$current, ...$appended];
        array_push($this->global, ...$appended);
        $this->globalPosition += \count($appended);

        return $appended;
    }

    public function loadFromGlobalPosition(int $afterPosition, int $limit): array
    {
        if ($afterPosition < 0) {
            throw new \InvalidArgumentException('Global event cursor must not be negative.');
        }

        if ($limit < 1 || $limit > 10_000) {
            throw new \InvalidArgumentException('Global event batch size must be between 1 and 10,000.');
        }

        return \array_slice($this->global, $afterPosition, $limit);
    }

    private static function key(StreamId $stream): string
    {
        return $stream->aggregateType . "\0" . $stream->aggregateId;
    }
}
