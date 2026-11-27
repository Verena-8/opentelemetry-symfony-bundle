<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Fixtures;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Service\ResetInterface;

final class ResettableEventDispatcher extends EventDispatcher implements ResetInterface
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }

    public function ownMethod(): string
    {
        return 'inner';
    }
}
