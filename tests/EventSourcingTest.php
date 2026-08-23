<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Tests;

use Contempt\Contracts\Messaging\DomainEvent;
use Contempt\EventSourcing\Aggregate\AggregateFactory;
use Contempt\EventSourcing\Aggregate\AggregateRepository;
use Contempt\EventSourcing\Aggregate\EventSourcedAggregate;
use Contempt\EventSourcing\Store\ConcurrencyConflict;
use Contempt\EventSourcing\Store\EventMetadata;
use Contempt\EventSourcing\Store\InMemoryEventStore;
use Contempt\EventSourcing\Store\StoredEvent;
use Contempt\EventSourcing\Store\StreamId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventSourcedAggregate::class)]
#[CoversClass(AggregateRepository::class)]
#[CoversClass(InMemoryEventStore::class)]
final class EventSourcingTest extends TestCase
{
    public function testAppendIsAtomicAndExpectedVersionPreventsLostUpdates(): void
    {
        $store = new InMemoryEventStore();
        $stream = new StreamId('bank-account', 'account-1');
        $first = $store->append($stream, 0, [new MoneyDeposited(10), new MoneyDeposited(20)], new EventMetadata(['tenant' => 'acme']));

        self::assertSame([1, 2], array_map(static fn($event): int => $event->version, $first));
        self::assertSame(30, array_sum(array_map(static function (StoredEvent $stored): int {
            if (!$stored->event instanceof MoneyDeposited) {
                self::fail('Unexpected event type in bank account stream.');
            }

            return $stored->event->amount;
        }, $store->load($stream))));

        try {
            $store->append($stream, 1, [new MoneyDeposited(999)]);
            self::fail('Stale writer must be rejected.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(1, $conflict->expectedVersion);
            self::assertSame(2, $conflict->actualVersion);
        }

        self::assertCount(2, $store->load($stream));
    }

    public function testRepositoryReplaysWithoutRecordingAndCommitsPendingEvents(): void
    {
        $store = new InMemoryEventStore();
        $repository = new AggregateRepository($store, new BankAccountFactory());
        $account = $repository->load(new StreamId('bank-account', 'account-2'));
        $account->deposit(40);
        $account->deposit(2);

        self::assertSame(42, $account->balance);
        self::assertCount(2, $account->pendingEvents());
        $repository->save($account);
        self::assertSame([], $account->pendingEvents());

        $loaded = $repository->load(new StreamId('bank-account', 'account-2'));
        self::assertSame(42, $loaded->balance);
        self::assertSame(2, $loaded->version());
        self::assertSame([], $loaded->pendingEvents());
    }

    public function testFailedSaveKeepsPendingEventsForExplicitRetry(): void
    {
        $store = new InMemoryEventStore();
        $repository = new AggregateRepository($store, new BankAccountFactory());
        $first = $repository->load(new StreamId('bank-account', 'account-3'));
        $second = $repository->load(new StreamId('bank-account', 'account-3'));
        $first->deposit(1);
        $repository->save($first);
        $second->deposit(2);

        try {
            $repository->save($second);
            self::fail('Stale aggregate save must fail.');
        } catch (ConcurrencyConflict) {
        }

        self::assertCount(1, $second->pendingEvents());
        self::assertSame(0, $second->persistedVersion());
    }

    public function testUnsafeStreamAndMetadataValuesFailBeforePersistence(): void
    {
        foreach ([
            static fn(): StreamId => new StreamId('../unsafe', 'id'),
            static fn(): EventMetadata => new EventMetadata(['secret' => new \stdClass()]),
            static fn(): EventMetadata => new EventMetadata(['bad/key' => 'value']),
        ] as $factory) {
            try {
                $factory();
                self::fail('Unsafe persistence metadata must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->addToAssertionCount(1);
    }
}

final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(public int $amount) {}
}

final class BankAccount extends EventSourcedAggregate
{
    public int $balance = 0;

    public function deposit(int $amount): void
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('Deposit must be positive.');
        }

        $this->recordThat(new MoneyDeposited($amount));
    }

    protected function apply(DomainEvent $event): void
    {
        if (!$event instanceof MoneyDeposited) {
            throw new \LogicException('Unsupported bank account event.');
        }

        $this->balance += $event->amount;
    }
}

/** @implements AggregateFactory<BankAccount> */
final readonly class BankAccountFactory implements AggregateFactory
{
    public function create(StreamId $stream): EventSourcedAggregate
    {
        return new BankAccount($stream);
    }
}
