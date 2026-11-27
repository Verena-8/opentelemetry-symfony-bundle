<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Marks a kernel step span (event, controller) as failed when an exception escapes it.
 *
 * An HTTP exception below the error threshold (a 404 from the router, a 403 from the
 * firewall) is the expected outcome of the request, not a failure of the step: like the
 * server span, the step span stays unset so error counts are not inflated by client errors.
 */
final class KernelSpanError
{
    public static function record(SpanInterface $span, \Throwable $exception, int $errorStatusThreshold): void
    {
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < $errorStatusThreshold) {
            return;
        }

        $span->recordException($exception);
        $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($exception));
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
    }
}
