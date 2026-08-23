<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Aggregate;

use Contempt\EventSourcing\Store\StreamId;

/** @template TAggregate of EventSourcedAggregate */
interface AggregateFactory
{
    /** @return TAggregate */
    public function create(StreamId $stream): EventSourcedAggregate;
}
