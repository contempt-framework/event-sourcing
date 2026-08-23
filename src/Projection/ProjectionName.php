<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Projection;

final readonly class ProjectionName
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $value) !== 1 || str_contains($value, '..')) {
            throw new \InvalidArgumentException('Projection name must be a canonical safe identifier.');
        }
    }
}
