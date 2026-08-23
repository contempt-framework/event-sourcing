<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Upcasting;

interface EventUpcaster
{
    public function sourceType(): string;

    public function targetType(): string;

    /**
     * @param array<string, mixed> $payload
     * @return array<array-key, mixed>
     */
    public function upcast(array $payload): array;
}
