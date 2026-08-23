<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Store;

final readonly class EventMetadata
{
    /** @var array<string, string|int|bool> */
    private array $values;

    /** @param array<mixed, mixed> $values */
    public function __construct(array $values = [])
    {
        if (\count($values) > 32) {
            throw new \InvalidArgumentException('Event metadata may contain at most 32 controlled fields.');
        }

        $validated = [];

        foreach ($values as $name => $value) {
            if (!\is_string($name) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1
                || preg_match('/(?:password|secret|token|authorization)/i', $name) === 1
                || (!\is_string($value) && !\is_int($value) && !\is_bool($value))
                || (\is_string($value) && (\strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1))) {
                throw new \InvalidArgumentException('Event metadata contains an unsafe name or value.');
            }

            $validated[$name] = $value;
        }

        ksort($validated, SORT_STRING);
        $this->values = $validated;
    }

    /** @return array<string, string|int|bool> */
    public function all(): array
    {
        return $this->values;
    }
}
