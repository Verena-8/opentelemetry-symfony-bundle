<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Fixtures;

use OpenTelemetry\API\Globals;
use Symfony\Component\HttpFoundation\Response;

final class ArticleController
{
    public function show(): Response
    {
        Globals::tracerProvider()->getTracer('test')->spanBuilder('SELECT article')->startSpan()->end();

        return new Response('article');
    }

    public function __invoke(): Response
    {
        return $this->show();
    }
}
