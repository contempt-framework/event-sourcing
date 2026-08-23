<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Upcasting;

final readonly class EventUpcasterChain
{
    /** @var array<string, array{target: string, upcaster: EventUpcaster}> */
    private array $steps;

    /**
     * @param iterable<EventUpcaster> $upcasters
     */
    public function __construct(iterable $upcasters = [], private int $maximumHops = 64)
    {
        if ($maximumHops < 1 || $maximumHops > 1_024) {
            throw new \InvalidArgumentException('Event upcaster hop limit must be between 1 and 1,024.');
        }

        $steps = [];

        foreach ($upcasters as $upcaster) {
            $source = $upcaster->sourceType();
            $target = $upcaster->targetType();
            new SerializedEvent($source, []);
            new SerializedEvent($target, []);

            if (isset($steps[$source])) {
                throw new \InvalidArgumentException(\sprintf(
                    'More than one event upcaster is registered for source type %s.',
                    $source,
                ));
            }

            $steps[$source] = ['target' => $target, 'upcaster' => $upcaster];
        }

        ksort($steps, SORT_STRING);
        self::assertAcyclic($steps);
        $this->steps = $steps;
    }

    /** @param array<array-key, mixed> $payload */
    #[\NoDiscard]
    public function upcast(string $type, array $payload): SerializedEvent
    {
        $event = new SerializedEvent($type, $payload);
        $hops = 0;

        while (isset($this->steps[$event->type])) {
            if (++$hops > $this->maximumHops) {
                throw new \OverflowException(\sprintf(
                    'Event upcasting exceeded the configured %d-hop limit.',
                    $this->maximumHops,
                ));
            }

            $step = $this->steps[$event->type];
            $payload = $step['upcaster']->upcast($event->payload);

            try {
                $event = new SerializedEvent($step['target'], $payload);
            } catch (\InvalidArgumentException $failure) {
                throw new \UnexpectedValueException(\sprintf(
                    'Upcaster from %s to %s must return a valid JSON object payload.',
                    $event->type,
                    $step['target'],
                ), 0, $failure);
            }
        }

        return $event;
    }

    /** @param array<string, array{target: string, upcaster: EventUpcaster}> $steps */
    private static function assertAcyclic(array $steps): void
    {
        foreach (array_keys($steps) as $source) {
            $path = [];
            $current = $source;

            while (isset($steps[$current])) {
                if (isset($path[$current])) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Event upcaster cycle detected at type %s.',
                        $current,
                    ));
                }

                $path[$current] = true;
                $current = $steps[$current]['target'];
            }
        }
    }
}
