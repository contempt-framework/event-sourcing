<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Snapshot;

final readonly class SnapshotStateTypeRegistry
{
    /** @var array<class-string<AggregateSnapshotState>, non-empty-string> */
    private array $types;

    /** @var array<non-empty-string, class-string<AggregateSnapshotState>> */
    private array $classes;

    /** @param array<class-string<AggregateSnapshotState>, non-empty-string> $types */
    public function __construct(array $types)
    {
        $classes = [];

        foreach ($types as $class => $type) {
            if (!is_a($class, AggregateSnapshotState::class, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Snapshot state class %s must implement %s.',
                    $class,
                    AggregateSnapshotState::class,
                ));
            }

            if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\.v[1-9][0-9]*$/D', $type) !== 1) {
                throw new \InvalidArgumentException(\sprintf('Invalid snapshot state type "%s".', $type));
            }

            if (isset($classes[$type])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Snapshot state type %s is assigned to both %s and %s.',
                    $type,
                    $classes[$type],
                    $class,
                ));
            }

            $classes[$type] = $class;
        }

        ksort($types, SORT_STRING);
        ksort($classes, SORT_STRING);
        $this->types = $types;
        $this->classes = $classes;
    }

    /** @param class-string<AggregateSnapshotState> $class */
    public function typeFor(string $class): string
    {
        return $this->types[$class] ?? throw new \OutOfBoundsException(\sprintf(
            'Snapshot state %s has no logical wire type.',
            $class,
        ));
    }

    /** @return class-string<AggregateSnapshotState> */
    public function classFor(string $type): string
    {
        return $this->classes[$type] ?? throw new \OutOfBoundsException(\sprintf(
            'Unknown snapshot state type "%s".',
            $type,
        ));
    }
}
