<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Tests;

use Contempt\EventSourcing\Upcasting\EventUpcaster;
use Contempt\EventSourcing\Upcasting\EventUpcasterChain;
use Contempt\EventSourcing\Upcasting\SerializedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventUpcasterChain::class)]
#[CoversClass(SerializedEvent::class)]
final class EventUpcasterChainTest extends TestCase
{
    public function testMultiStepEvolutionIsDeterministic(): void
    {
        $payload = ['legacy_number' => '7'];
        $chain = new EventUpcasterChain([
            new TestUpcaster('orders.stored.v2', 'orders.stored.v3', static fn(array $value): array => [
                'number' => $value['number'] ?? null,
                'currency' => 'PLN',
            ]),
            new TestUpcaster('orders.stored.v1', 'orders.stored.v2', static function (array $value): array {
                $legacy = $value['legacy_number'] ?? null;

                if (!\is_string($legacy) || preg_match('/^[0-9]+$/D', $legacy) !== 1) {
                    throw new \UnexpectedValueException('Legacy number is invalid.');
                }

                return ['number' => (int) $legacy];
            }),
        ]);

        $result = $chain->upcast('orders.stored.v1', $payload);

        self::assertSame('orders.stored.v3', $result->type);
        self::assertSame(['number' => 7, 'currency' => 'PLN'], $result->payload);
    }

    public function testDuplicateSourceWouldMakeEvolutionOrderDependentAndIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('orders.stored.v1');

        new EventUpcasterChain([
            new TestUpcaster('orders.stored.v1', 'orders.stored.v2', static fn(array $value): array => $value),
            new TestUpcaster('orders.stored.v1', 'orders.stored.v3', static fn(array $value): array => $value),
        ]);
    }

    public function testEvolutionCycleIsRejectedBeforeAnyStoredEventIsRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');

        new EventUpcasterChain([
            new TestUpcaster('orders.stored.v1', 'orders.stored.v2', static fn(array $value): array => $value),
            new TestUpcaster('orders.stored.v2', 'orders.stored.v1', static fn(array $value): array => $value),
        ]);
    }

    public function testUpcasterCannotReturnAJsonListAsAnEventObject(): void
    {
        $chain = new EventUpcasterChain([
            new TestUpcaster('orders.stored.v1', 'orders.stored.v2', static fn(array $value): array => [1, 2]),
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('JSON object');

        (void) $chain->upcast('orders.stored.v1', ['number' => 1]);
    }

    public function testInvalidWireTypeAndNonObjectInputAreRefusedAtBoundary(): void
    {
        foreach ([
            static fn(): SerializedEvent => new SerializedEvent('../event', []),
            static fn(): SerializedEvent => new SerializedEvent('orders.stored.v1', [1]),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid serialized event was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->addToAssertionCount(1);
    }
}

final readonly class TestUpcaster implements EventUpcaster
{
    /** @param \Closure(array<string, mixed>): array<array-key, mixed> $transform */
    public function __construct(
        private string $source,
        private string $target,
        private \Closure $transform,
    ) {}

    public function sourceType(): string
    {
        return $this->source;
    }

    public function targetType(): string
    {
        return $this->target;
    }

    public function upcast(array $payload): array
    {
        return ($this->transform)($payload);
    }
}
