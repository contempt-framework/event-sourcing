<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Tests;

use Contempt\Contracts\Messaging\DomainEvent;
use Contempt\EventSourcing\Aggregate\AggregateFactory;
use Contempt\EventSourcing\Aggregate\AggregateRepository;
use Contempt\EventSourcing\Aggregate\EventSourcedAggregate;
use Contempt\EventSourcing\Snapshot\AggregateSnapshot;
use Contempt\EventSourcing\Snapshot\AggregateSnapshotState;
use Contempt\EventSourcing\Snapshot\InMemorySnapshotStore;
use Contempt\EventSourcing\Snapshot\SnapshotStore;
use Contempt\EventSourcing\Store\InMemoryEventStore;
use Contempt\EventSourcing\Store\StreamId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(AggregateSnapshot::class)]
#[CoversClass(InMemorySnapshotStore::class)]
#[CoversClass(AggregateRepository::class)]
final class SnapshotTest extends TestCase
{
    public function testRepositoryRestoresSnapshotThenReplaysOnlyItsTail(): void
    {
        $events = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $repository = new AggregateRepository($events, new SnapshotAccountFactory(), $snapshots, 2);
        $stream = new StreamId('snapshot-account', 'one');
        $account = $repository->load($stream);
        $account->deposit(10);
        $account->deposit(20);
        $repository->save($account);

        $snapshot = $snapshots->load($stream);
        self::assertNotNull($snapshot);
        self::assertSame(2, $snapshot->version);
        $events->append($stream, 2, [new SnapshotDeposit(7)]);

        SnapshotAccount::$replayed = 0;
        $restored = $repository->load($stream);
        self::assertSame(37, $restored->balance);
        self::assertSame(3, $restored->version());
        self::assertSame(1, SnapshotAccount::$replayed, 'Only the event after the snapshot is replayed.');
    }

    public function testSnapshotVersionsAreMonotonicAndCannotMoveBackward(): void
    {
        $store = new InMemorySnapshotStore();
        $stream = new StreamId('snapshot-account', 'one');
        $store->save(new AggregateSnapshot($stream, 2, new SnapshotAccountState(20), new \DateTimeImmutable('2026-08-23T10:00:00Z')));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('newer');
        $store->save(new AggregateSnapshot($stream, 1, new SnapshotAccountState(10), new \DateTimeImmutable('2026-08-23T09:00:00Z')));
    }

    public function testSnapshotFailureIsReportedButDoesNotTurnCommittedEventsIntoARetriableSave(): void
    {
        $events = new InMemoryEventStore();
        $logger = new SnapshotRecordingLogger();
        $repository = new AggregateRepository(
            $events,
            new SnapshotAccountFactory(),
            new FailingSnapshotStore(),
            1,
            logger: $logger,
        );
        $account = $repository->load(new StreamId('snapshot-account', 'one'));
        $account->deposit(5);

        $repository->save($account);

        self::assertSame([], $account->pendingEvents());
        self::assertSame(1, $account->persistedVersion());
        self::assertCount(1, $events->load($account->stream()));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('snapshot-account:one', $logger->records[0]['context']['stream'] ?? null);
    }

    public function testSnapshotConfigurationRejectsHalfConfiguredModes(): void
    {
        foreach ([
            static fn(): AggregateRepository => new AggregateRepository(new InMemoryEventStore(), new SnapshotAccountFactory(), new InMemorySnapshotStore(), 0),
            static fn(): AggregateRepository => new AggregateRepository(new InMemoryEventStore(), new SnapshotAccountFactory(), null, 10),
        ] as $factory) {
            try {
                $factory();
                self::fail('Half-configured snapshots were accepted.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->addToAssertionCount(1);
    }
}

final readonly class SnapshotDeposit implements DomainEvent
{
    public function __construct(public int $amount) {}
}

final readonly class SnapshotAccountState implements AggregateSnapshotState
{
    public function __construct(public int $balance) {}
}

final class SnapshotAccount extends EventSourcedAggregate
{
    public static int $replayed = 0;
    public int $balance = 0;

    public function deposit(int $amount): void
    {
        $this->recordThat(new SnapshotDeposit($amount));
    }

    protected function apply(DomainEvent $event): void
    {
        if (!$event instanceof SnapshotDeposit) {
            throw new \LogicException('Unexpected event.');
        }

        ++self::$replayed;
        $this->balance += $event->amount;
    }

    protected function captureSnapshotState(): AggregateSnapshotState
    {
        return new SnapshotAccountState($this->balance);
    }

    protected function restoreSnapshotState(AggregateSnapshotState $state): void
    {
        if (!$state instanceof SnapshotAccountState) {
            throw new \InvalidArgumentException('Unexpected snapshot state.');
        }

        $this->balance = $state->balance;
    }
}

/** @implements AggregateFactory<SnapshotAccount> */
final readonly class SnapshotAccountFactory implements AggregateFactory
{
    public function create(StreamId $stream): EventSourcedAggregate
    {
        return new SnapshotAccount($stream);
    }
}

final readonly class FailingSnapshotStore implements SnapshotStore
{
    public function load(StreamId $stream): ?AggregateSnapshot
    {
        return null;
    }

    public function save(AggregateSnapshot $snapshot): void
    {
        throw new \RuntimeException('snapshot backend unavailable');
    }
}

final class SnapshotRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $normalized = [];

        foreach ($context as $name => $value) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('Log context names must be strings.');
            }

            $normalized[$name] = $value;
        }

        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $normalized];
    }
}
