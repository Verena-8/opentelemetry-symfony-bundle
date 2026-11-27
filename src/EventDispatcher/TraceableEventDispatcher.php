<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventDispatcher;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Instrumentation\TracerAwareTrait;
use Traceway\OpenTelemetryBundle\Util\CallableName;
use Traceway\OpenTelemetryBundle\Util\KernelSpanError;

/**
 * Splits the request span into the steps of the HTTP kernel:
 *
 *     GET /articles/{id}
 *     ├─ kernel.request
 *     ├─ kernel.controller
 *     ├─ kernel.controller_arguments
 *     ├─ ArticleController::show
 *     │  └─ SELECT article
 *     └─ kernel.response
 *
 * Each kernel event gets an INTERNAL span, and so does the controller, from the end of
 * kernel.controller_arguments to the next kernel event of the same request.
 *
 * Decorates event_dispatcher at priority 1: the profiler's TraceableEventDispatcher stays
 * on top in dev and keeps seeing every listener.
 */
final class TraceableEventDispatcher implements EventDispatcherInterface, ResetInterface
{
    use TracerAwareTrait;

    public const EVENT_ATTRIBUTE = 'kernel.event';

    /**
     * kernel.finish_request and kernel.terminate are left out: the request span's scope is
     * detached during the former and the span itself has ended by the latter.
     */
    private const TRACED_EVENTS = [
        KernelEvents::REQUEST => true,
        KernelEvents::CONTROLLER => true,
        KernelEvents::CONTROLLER_ARGUMENTS => true,
        KernelEvents::VIEW => true,
        KernelEvents::RESPONSE => true,
        KernelEvents::EXCEPTION => true,
    ];

    /**
     * The first of these to be dispatched for a request ends its controller span. Symfony 8.1.0
     * could skip kernel.response and kernel.finish_request when an exception listener set a
     * response, but kernel.exception is always dispatched in that case.
     */
    private const CONTROLLER_END_EVENTS = [
        KernelEvents::VIEW => true,
        KernelEvents::RESPONSE => true,
        KernelEvents::EXCEPTION => true,
        KernelEvents::FINISH_REQUEST => true,
    ];

    /** @var \WeakMap<Request, ScopeInterface> */
    private \WeakMap $controllerScopes;

    /**
     * Held strongly, in attach order: a worker can free an aborted request before it resets
     * services, and the WeakMap entry holding its scope goes with it.
     *
     * @var array<int, array{ScopeInterface, SpanInterface}>
     */
    private array $openScopes = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $tracerName = 'opentelemetry-symfony',
        private readonly int $errorStatusThreshold = 500,
    ) {
        $this->controllerScopes = new \WeakMap();
    }

    /**
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     */
    public function dispatch(object $event, ?string $eventName = null): object
    {
        $eventName ??= $event::class;

        if (!$event instanceof KernelEvent) {
            return $this->dispatcher->dispatch($event, $eventName);
        }

        if (isset(self::CONTROLLER_END_EVENTS[$eventName])) {
            $this->endController($event);
        }

        if (!isset(self::TRACED_EVENTS[$eventName]) || !$this->isEnabled()) {
            return $this->dispatcher->dispatch($event, $eventName);
        }

        if (KernelEvents::REQUEST === $eventName) {
            return $this->dispatchRequest($event);
        }

        $parent = Context::getCurrent();
        if (!Span::fromContext($parent)->getContext()->isValid()) {
            return $this->dispatcher->dispatch($event, $eventName);
        }

        $span = $this->getTracer()->spanBuilder($eventName)
            ->setParent($parent)
            ->setAttribute(self::EVENT_ATTRIBUTE, $eventName)
            ->startSpan();
        $scope = $span->storeInContext($parent)->activate();

        try {
            $this->callListeners($event, $eventName);
        } catch (\Throwable $e) {
            KernelSpanError::record($span, $e, $this->errorStatusThreshold);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }

        if ($event instanceof ControllerArgumentsEvent) {
            $this->startController($event);
        }

        return $event;
    }

    /**
     * The request span is started by a kernel.request listener (OpenTelemetrySubscriber), so
     * there is no parent before this event runs. Its span is created afterwards, under the
     * request span, from the time the event started. Spans opened by its listeners (a database
     * query from the firewall, say) are therefore children of the request span, not of this one.
     *
     * @template T of KernelEvent
     *
     * @param T $event
     *
     * @return T
     */
    private function dispatchRequest(KernelEvent $event): KernelEvent
    {
        $start = Clock::getDefault()->now();
        $exception = null;

        try {
            $this->callListeners($event, KernelEvents::REQUEST);
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            $this->recordRequestSpan($event, $start, $exception);
        }

        return $event;
    }

    private function recordRequestSpan(KernelEvent $event, int $start, ?\Throwable $exception): void
    {
        $parent = Context::getCurrent();
        $parentSpan = Span::fromContext($parent);
        if (!$parentSpan->getContext()->isValid()) {
            return;
        }

        // A sub-request's span is started during the event, slightly after it began.
        if (!$event->isMainRequest() && $parentSpan instanceof ReadableSpanInterface) {
            $start = max($start, $parentSpan->toSpanData()->getStartEpochNanos());
        }

        $span = $this->getTracer()->spanBuilder(KernelEvents::REQUEST)
            ->setParent($parent)
            ->setStartTimestamp($start)
            ->setAttribute(self::EVENT_ATTRIBUTE, KernelEvents::REQUEST)
            ->startSpan();

        if (null !== $exception) {
            KernelSpanError::record($span, $exception, $this->errorStatusThreshold);
        }

        $span->end();
    }

    private function callListeners(object $event, string $eventName): void
    {
        $this->dispatcher->dispatch($event, $eventName);
    }

    /**
     * Opens the controller span once its arguments are resolved: HttpKernel calls the
     * controller right after this event. The span stays active while it runs, so the queries
     * and calls it makes are its children.
     */
    private function startController(ControllerArgumentsEvent $event): void
    {
        $controller = $event->getController();
        $parent = Context::getCurrent();

        $span = $this->getTracer()->spanBuilder(CallableName::short($controller))
            ->setParent($parent)
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, CallableName::full($controller))
            ->startSpan();
        $scope = $span->storeInContext($parent)->activate();

        $this->controllerScopes[$event->getRequest()] = $scope;
        $this->openScopes[spl_object_id($scope)] = [$scope, $span];
    }

    private function endController(KernelEvent $event): void
    {
        $scope = $this->controllerScopes[$event->getRequest()] ?? null;
        if (null === $scope) {
            return;
        }
        unset($this->controllerScopes[$event->getRequest()]);

        // A sub-request aborted inside the controller may have left its own span open above it: scopes nest.
        $id = spl_object_id($scope);
        foreach (array_reverse($this->openScopes, true) as $openId => [$openScope, $span]) {
            unset($this->openScopes[$openId]);
            $openScope->detach();

            if ($openId === $id && $event instanceof ExceptionEvent) {
                KernelSpanError::record($span, $event->getThrowable(), $this->errorStatusThreshold);
            }

            $span->end();

            if ($openId === $id) {
                return;
            }
        }
    }

    public function reset(): void
    {
        $this->resetTracer();

        // Drain controllers of aborted requests so their scopes can't parent the next request. Newest first, as scopes nest.
        foreach (array_reverse($this->openScopes) as [$scope, $span]) {
            $scope->detach();
            $span->end();
        }
        $this->openScopes = [];
        $this->controllerScopes = new \WeakMap();

        // Decoration moves the inner service's kernel.reset tag to this service, which then answers for both.
        if ($this->dispatcher instanceof ResetInterface) {
            $this->dispatcher->reset();
        }
    }

    /**
     * In prod, RegisterListenersPass registers lazy `[Closure, 'method']` listeners on the
     * outermost dispatcher, which this service is; they are not callables, hence the array type,
     * as on Symfony's own dispatchers.
     *
     * @param callable|array{0: \Closure|object|string, 1?: string} $listener
     */
    public function addListener(string $eventName, callable|array $listener, int $priority = 0): void
    {
        $this->dispatcher->addListener($eventName, $listener, $priority); // @phpstan-ignore argument.type
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->dispatcher->addSubscriber($subscriber);
    }

    /** @param callable|array{0: \Closure|object|string, 1?: string} $listener */
    public function removeListener(string $eventName, callable|array $listener): void
    {
        $this->dispatcher->removeListener($eventName, $listener); // @phpstan-ignore argument.type
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->dispatcher->removeSubscriber($subscriber);
    }

    public function getListeners(?string $eventName = null): array
    {
        return $this->dispatcher->getListeners($eventName);
    }

    /** @param callable|array{0: \Closure|object|string, 1?: string} $listener */
    public function getListenerPriority(string $eventName, callable|array $listener): ?int
    {
        return $this->dispatcher->getListenerPriority($eventName, $listener); // @phpstan-ignore argument.type
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    /**
     * Forwards the decorated dispatcher's own methods, as Symfony's TraceableEventDispatcher does.
     *
     * @param array<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->dispatcher->{$method}(...$arguments);
    }
}
