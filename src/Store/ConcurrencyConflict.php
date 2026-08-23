<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Store;

use Contempt\Core\Exception\RuntimeException;

final class ConcurrencyConflict extends RuntimeException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $actualVersion)
    {
        parent::__construct('Event stream changed concurrently.');
    }
}
