<?php

declare(strict_types=1);

namespace Contempt\EventSourcing;

use Contempt\Compiler\Extension\PackageExtension;

final readonly class EventSourcingExtension extends PackageExtension
{
    protected function package(): string
    {
        return 'contempt/event-sourcing';
    }
}
