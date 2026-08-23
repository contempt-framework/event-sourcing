<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Projection;

use Contempt\EventSourcing\Store\GlobalEventStore;

final readonly class ProjectionRunner
{
    public function __construct(
        private GlobalEventStore $events,
        private ProjectionCheckpointStore $checkpoints,
    ) {}

    public function run(Projection $projection, int $batchSize = 100): ProjectionRunReport
    {
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new \InvalidArgumentException('Projection batch size must be between 1 and 10,000.');
        }

        $name = $projection->name();

        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $name) !== 1 || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Projection name must be a canonical safe identifier.');
        }

        $checkpoint = $this->checkpoints->position($name);
        $events = $this->events->loadFromGlobalPosition($checkpoint, $batchSize);
        $processed = 0;

        foreach ($events as $event) {
            if ($event->globalPosition !== $checkpoint + 1) {
                throw new \UnexpectedValueException(\sprintf(
                    'Global event feed is discontinuous: expected position %d, got %d.',
                    $checkpoint + 1,
                    $event->globalPosition,
                ));
            }

            $projection->apply($event);

            if (!$this->checkpoints->advance($name, $checkpoint, $event->globalPosition)) {
                throw new ProjectionConcurrencyConflict($name, $checkpoint);
            }

            $checkpoint = $event->globalPosition;
            ++$processed;
        }

        return new ProjectionRunReport($name, $processed, $checkpoint, \count($events) === $batchSize);
    }
}
