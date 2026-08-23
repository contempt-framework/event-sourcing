<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Projection;

final class ProjectionConcurrencyConflict extends \RuntimeException
{
    public function __construct(public readonly string $projection, public readonly int $expectedPosition)
    {
        parent::__construct(\sprintf(
            'Projection "%s" checkpoint changed concurrently from position %d.',
            $projection,
            $expectedPosition,
        ));
    }
}
