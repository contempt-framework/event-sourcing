<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Tests;

use Contempt\Contracts\Messaging\DomainEvent;
use Contempt\EventSourcing\Projection\InMemoryProjectionCheckpointStore;
use Contempt\EventSourcing\Projection\Projection;
use Contempt\EventSourcing\Projection\ProjectionCheckpointStore;
use Contempt\EventSourcing\Projection\ProjectionConcurrencyConflict;
use Contempt\EventSourcing\Projection\ProjectionRunner;
use Contempt\EventSourcing\Store\EventMetadata;
use Contempt\EventSourcing\Store\InMemoryEventStore;
use Contempt\EventSourcing\Store\StoredEvent;
use Contempt\EventSourcing\Store\StreamId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryEventStore::class)]
#[CoversClass(InMemoryProjectionCheckpointStore::class)]
#[CoversClass(ProjectionRunner::class)]
final class ProjectionTest extends TestCase
{
    public function testGlobalFeedIsStrictlyOrderedAndBoundedAcrossStreams(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new StreamId('order', 'a'), 0, [new ProjectionEvent('a1'), new ProjectionEvent('a2')]);
        $store->append(new StreamId('order', 'b'), 0, [new ProjectionEvent('b1')]);

        $page = $store->loadFromGlobalPosition(1, 2);

        self::assertSame([2, 3], array_map(static fn(StoredEvent $event): int => $event->globalPosition, $page));
        self::assertSame(['a2', 'b1'], array_map(
            static fn(StoredEvent $event): string => $event->event instanceof ProjectionEvent ? $event->event->value : '',
            $page,
        ));
    }

    public function testProjectionResumesAfterItsLastSuccessfulEventWithoutSkippingTheFailure(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new StreamId('order', 'a'), 0, [
            new ProjectionEvent('first'),
            new ProjectionEvent('fail-once'),
            new ProjectionEvent('last'),
        ], new EventMetadata(['source' => 'test']));
        $checkpoints = new InMemoryProjectionCheckpointStore();
        $projection = new RecordingProjection('orders', 'fail-once');
        $runner = new ProjectionRunner($store, $checkpoints);

        try {
            $runner->run($projection, 10);
            self::fail('The projection failure was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('projection failed once', $failure->getMessage());
        }

        self::assertSame(1, $checkpoints->position('orders'));
        self::assertSame(['first', 'fail-once'], $projection->seen);

        $report = $runner->run($projection, 10);
        self::assertSame(2, $report->processed);
        self::assertSame(3, $report->checkpoint);
        self::assertSame(['first', 'fail-once', 'fail-once', 'last'], $projection->seen);

        $empty = $runner->run($projection, 10);
        self::assertSame(0, $empty->processed);
        self::assertSame(3, $empty->checkpoint);
    }

    public function testCheckpointRaceIsNeverSilentlyOverwritten(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new StreamId('order', 'a'), 0, [new ProjectionEvent('first')]);
        $projection = new RecordingProjection('orders');

        $this->expectException(ProjectionConcurrencyConflict::class);
        $this->expectExceptionMessage('orders');
        new ProjectionRunner($store, new RejectingCheckpointStore())->run($projection, 1);
    }

    public function testBatchSizeAndProjectionNameAreValidatedAtTheBoundary(): void
    {
        $runner = new ProjectionRunner(new InMemoryEventStore(), new InMemoryProjectionCheckpointStore());

        foreach ([0, 10_001] as $invalid) {
            try {
                $runner->run(new RecordingProjection('orders'), $invalid);
                self::fail('Invalid projection batch size was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $runner->run(new RecordingProjection('../orders'), 1);
    }
}

final readonly class ProjectionEvent implements DomainEvent
{
    public function __construct(public string $value) {}
}

final class RecordingProjection implements Projection
{
    /** @var list<string> */
    public array $seen = [];

    private bool $failed = false;

    public function __construct(private readonly string $projectionName, private readonly ?string $failOnce = null) {}

    public function name(): string
    {
        return $this->projectionName;
    }

    public function apply(StoredEvent $event): void
    {
        if (!$event->event instanceof ProjectionEvent) {
            return;
        }

        $this->seen[] = $event->event->value;

        if (!$this->failed && $event->event->value === $this->failOnce) {
            $this->failed = true;

            throw new \RuntimeException('projection failed once');
        }
    }
}

final readonly class RejectingCheckpointStore implements ProjectionCheckpointStore
{
    public function position(string $projection): int
    {
        return 0;
    }

    public function advance(string $projection, int $expectedPosition, int $newPosition): bool
    {
        return false;
    }
}
