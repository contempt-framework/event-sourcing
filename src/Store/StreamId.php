<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Store;

final readonly class StreamId implements \Stringable
{
    public function __construct(public string $aggregateType, public string $aggregateId)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $aggregateType) !== 1 || str_contains($aggregateType, '..')) {
            throw new \InvalidArgumentException('Aggregate type must be a canonical safe identifier.');
        }

        if ($aggregateId === '' || \strlen($aggregateId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $aggregateId) === 1) {
            throw new \InvalidArgumentException('Aggregate id must contain 1-255 printable bytes.');
        }
    }

    public function __toString(): string
    {
        return $this->aggregateType . ':' . $this->aggregateId;
    }
}
