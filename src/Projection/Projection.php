<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Projection;

use Contempt\EventSourcing\Store\StoredEvent;

/** Projection handlers must be idempotent because delivery is at-least-once. */
interface Projection
{
    public function name(): string;

    public function apply(StoredEvent $event): void;
}
