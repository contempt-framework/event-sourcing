<?php

declare(strict_types=1);

namespace Contempt\EventSourcing\Upcasting;

final readonly class SerializedEvent
{
    /** @var array<string, mixed> */
    public array $payload;

    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public string $type,
        array $payload,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\.v[1-9][0-9]*$/D', $type) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid serialized event type "%s".', $type));
        }

        if ($payload !== [] && array_is_list($payload)) {
            throw new \InvalidArgumentException('A serialized event payload must be a JSON object, not a list.');
        }

        $object = [];

        foreach ($payload as $name => $value) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('Serialized event property names must be strings.');
            }

            $object[$name] = $value;
        }

        $this->payload = $object;
    }
}
