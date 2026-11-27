<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventDispatcher;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\Event;
use Traceway\OpenTelemetryBundle\EventDispatcher\TraceableEventDispatcher;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\Tests\Fixtures\ArticleController;
use Traceway\OpenTelemetryBundle\Tests\Fixtures\ResettableEventDispatcher;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * Runs a real HttpKernel with the bundle's request subscriber, as an application does.
 */
final class TraceableEventDispatcherTest extends TestCase
{
    use OTelTestTrait;

    private EventDispatcher $inner;
    private TraceableEventDispatcher $dispatcher;
    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->inner = new EventDispatcher();
        $this->dispatcher = new TraceableEventDispatcher($this->inner);
        $this->dispatcher->addSubscriber(new OpenTelemetrySubscriber(excludedPaths: ['/health']));
        $this->kernel = new HttpKernel($this->dispatcher, new ControllerResolver(), new RequestStack(), new ArgumentResolver());
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testEachKernelStepAndTheControllerGetASpanUnderTheRequestSpan(): void
    {
        $this->handle([new ArticleController(), 'show']);

        self::assertSame(
            ['kernel.request', 'kernel.controller', 'kernel.controller_arguments', 'SELECT article', 'ArticleController::show', 'kernel.response', 'GET'],
            $this->spanNames(),
        );

        $root = $this->span('GET');
        foreach (['kernel.request', 'kernel.controller', 'kernel.controller_arguments', 'ArticleController::show', 'kernel.response'] as $name) {
            self::assertSame($root->getSpanId(), $this->span($name)->getParentSpanId(), $name.' is a child of the request span');
            self::assertSame(SpanKind::KIND_INTERNAL, $this->span($name)->getKind());
        }

        self::assertSame($this->span('ArticleController::show')->getSpanId(), $this->span('SELECT article')->getParentSpanId(), 'the controller span is active while the controller runs');
        self::assertSame('kernel.controller', $this->span('kernel.controller')->getAttributes()->get(TraceableEventDispatcher::EVENT_ATTRIBUTE));
        self::assertSame(ArticleController::class.'::show', $this->span('ArticleController::show')->getAttributes()->get('code.function.name'));
        self::assertNull(Context::storage()->scope(), 'no scope outlives the request');
    }

    public function testRequestSpanCoversTheWholeEventFromItsStart(): void
    {
        $this->inner->addListener(KernelEvents::REQUEST, static fn () => usleep(2000), 512);

        $this->handle([new ArticleController(), 'show']);

        $event = $this->span('kernel.request');
        self::assertGreaterThanOrEqual(2_000_000, $event->getEndEpochNanos() - $event->getStartEpochNanos(), 'includes the listeners that ran before the request span existed');
        self::assertGreaterThanOrEqual($this->span('GET')->getStartEpochNanos(), $event->getStartEpochNanos());
    }

    public function testSpansFromRequestListenersBelongToTheRequestSpanAndOtherEventsToTheirOwn(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');
        $this->inner->addListener(KernelEvents::REQUEST, static fn () => $tracer->spanBuilder('SELECT user')->startSpan()->end());
        $this->inner->addListener(KernelEvents::RESPONSE, static fn () => $tracer->spanBuilder('UPDATE session')->startSpan()->end());

        $this->handle([new ArticleController(), 'show']);

        self::assertSame($this->span('GET')->getSpanId(), $this->span('SELECT user')->getParentSpanId(), 'kernel.request has no span of its own while it runs');
        self::assertSame($this->span('kernel.response')->getSpanId(), $this->span('UPDATE session')->getParentSpanId());
    }

    public function testViewEndsTheControllerSpanAndGetsItsOwn(): void
    {
        $this->inner->addListener(KernelEvents::VIEW, static fn (ViewEvent $event) => $event->setResponse(new Response()));

        $this->handle(static fn (): string => 'not a response');

        $controller = $this->span('closure');
        $view = $this->span('kernel.view');
        self::assertSame($this->span('GET')->getSpanId(), $view->getParentSpanId());
        self::assertLessThanOrEqual($view->getStartEpochNanos(), $controller->getEndEpochNanos());
    }

    public function testSubRequestStepsNestUnderTheSubRequestSpan(): void
    {
        $kernel = $this->kernel;
        $this->handle(static function () use ($kernel): Response {
            $request = Request::create('/fragment');
            $request->attributes->set('_controller', [new ArticleController(), 'show']);

            return $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        });

        $spans = $this->exporter->getSpans();
        $byName = static fn (string $name): array => array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => $span->getName() === $name));

        [$subRequest, $mainRequest] = $byName('GET');
        [$mainRequestEvent, $subRequestEvent] = $byName('kernel.request'); // spans are exported as they end
        self::assertSame(SpanKind::KIND_INTERNAL, $subRequest->getKind());
        self::assertSame($this->span('closure')->getSpanId(), $subRequest->getParentSpanId(), 'the sub-request runs inside the main controller');
        self::assertSame($subRequest->getSpanId(), $subRequestEvent->getParentSpanId());
        self::assertSame($mainRequest->getSpanId(), $mainRequestEvent->getParentSpanId());
        self::assertGreaterThanOrEqual($subRequest->getStartEpochNanos(), $subRequestEvent->getStartEpochNanos(), 'never starts before its parent');
        self::assertSame($subRequest->getSpanId(), $this->span('ArticleController::show')->getParentSpanId());
        self::assertNull(Context::storage()->scope());
    }

    public function testExcludedPathGetsNoSpans(): void
    {
        $response = $this->handle([new ArticleController(), 'show'], '/health');

        self::assertSame('article', $response->getContent());
        self::assertSame(['SELECT article'], $this->spanNames(), 'only the query, as its own trace: no kernel span without a request span');
    }

    public function testDelegatesWhenTracingIsDisabled(): void
    {
        Globals::reset();

        $response = $this->handle([new ArticleController(), 'show']);

        self::assertSame('article', $response->getContent());
        self::assertSame([], $this->spanNames());
        self::assertNull(Context::storage()->scope());
    }

    public function testOtherEventsAreDispatchedUntraced(): void
    {
        $calls = [];
        $this->inner->addListener('app.event', static function () use (&$calls): void { $calls[] = 'app.event'; });
        $this->inner->addListener(KernelEvents::FINISH_REQUEST, static function () use (&$calls): void { $calls[] = 'finish'; });
        $root = Globals::tracerProvider()->getTracer('test')->spanBuilder('root')->startSpan();
        $scope = $root->activate();

        $event = new Event();
        self::assertSame($event, $this->dispatcher->dispatch($event, 'app.event'));
        $this->dispatcher->dispatch(new FinishRequestEvent($this->kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST), KernelEvents::FINISH_REQUEST);

        $scope->detach();
        $root->end();
        self::assertSame(['app.event', 'finish'], $calls);
        self::assertSame(['root'], $this->spanNames());
    }

    public function testFailingRequestListenerMarksTheRequestEventSpan(): void
    {
        $this->inner->addListener(KernelEvents::REQUEST, static fn () => throw new \RuntimeException('database down'));
        $this->inner->addListener(KernelEvents::EXCEPTION, static fn (ExceptionEvent $event) => $event->setResponse(new Response('', 500)));

        $this->handle([new ArticleController(), 'show']);

        $span = $this->span('kernel.request');
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $span->getAttributes()->get('error.type'));
        self::assertSame($this->span('GET')->getSpanId(), $span->getParentSpanId());
        self::assertSame($this->span('GET')->getSpanId(), $this->span('kernel.exception')->getParentSpanId());
        self::assertNull(Context::storage()->scope());
    }

    public function testClientErrorsDoNotMarkTheirStepAsFailed(): void
    {
        $this->inner->addListener(KernelEvents::REQUEST, static fn () => throw new NotFoundHttpException('No route found'));
        $this->inner->addListener(KernelEvents::EXCEPTION, static fn (ExceptionEvent $event) => $event->setResponse(new Response('', 404)));

        $this->handle([new ArticleController(), 'show']);

        self::assertSame(StatusCode::STATUS_UNSET, $this->span('kernel.request')->getStatus()->getCode(), 'a 404 is the expected answer, as on the request span');
    }

    public function testFailingControllerMarksItsSpanAndEndsItOnKernelException(): void
    {
        $this->inner->addListener(KernelEvents::EXCEPTION, static fn (ExceptionEvent $event) => $event->setResponse(new Response('', 500)));

        $this->handle(static fn () => throw new \LogicException('bug'));

        $controller = $this->span('closure');
        self::assertSame(StatusCode::STATUS_ERROR, $controller->getStatus()->getCode());
        self::assertSame(\LogicException::class, $controller->getAttributes()->get('error.type'));
        self::assertCount(1, $controller->getEvents(), 'the exception is recorded');
        self::assertSame($this->span('GET')->getSpanId(), $this->span('kernel.exception')->getParentSpanId(), 'the controller span is closed before the exception is handled');
        self::assertNull(Context::storage()->scope());
    }

    public function testUncaughtControllerExceptionEndsItsSpanOnFinishRequest(): void
    {
        try {
            $this->handle(static fn () => throw new \LogicException('bug'), catch: false);
            self::fail('the exception reaches the caller');
        } catch (\LogicException) {
        }

        self::assertTrue($this->span('closure')->hasEnded());
        self::assertNull(Context::storage()->scope(), 'kernel.finish_request closed the controller scope before the request scope');
    }

    public function testResetEndsAndDetachesTheControllerOfAnAbortedRequest(): void
    {
        $root = Globals::tracerProvider()->getTracer('test')->spanBuilder('root')->startSpan();
        $rootScope = $root->activate();

        try {
            $request = Request::create('/');
            $controllerEvent = new ControllerEvent($this->kernel, [new ArticleController(), 'show'], $request, HttpKernelInterface::MAIN_REQUEST);
            $this->dispatcher->dispatch(new ControllerArgumentsEvent($this->kernel, $controllerEvent, [], $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::CONTROLLER_ARGUMENTS);
            self::assertNotSame($root->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId(), 'the controller span is active');

            $this->dispatcher->reset();

            self::assertSame($root->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId(), 'the controller scope is detached');
            self::assertTrue($this->span('ArticleController::show')->hasEnded());
        } finally {
            $rootScope->detach();
            $root->end();
        }
    }

    public function testResetAlsoResetsTheDecoratedDispatcher(): void
    {
        $inner = new ResettableEventDispatcher();

        (new TraceableEventDispatcher($inner))->reset();

        self::assertSame(1, $inner->resets);
    }

    public function testListenerManagementIsDelegated(): void
    {
        $service = new ArticleController();
        // How RegisterListenersPass registers a listener in prod: not a callable.
        $lazy = [static fn (): ArticleController => $service, 'show'];

        $this->dispatcher->addListener('app.event', $lazy, 8);

        self::assertTrue($this->dispatcher->hasListeners('app.event'));
        self::assertSame(8, $this->dispatcher->getListenerPriority('app.event', $lazy));
        self::assertCount(1, $this->dispatcher->getListeners('app.event'));
        self::assertSame($this->inner->getListeners(), $this->dispatcher->getListeners());

        $this->dispatcher->removeListener('app.event', $lazy);
        self::assertFalse($this->dispatcher->hasListeners('app.event'));

        $subscriber = new OpenTelemetrySubscriber();
        $this->dispatcher->addSubscriber($subscriber);
        $this->dispatcher->removeSubscriber($subscriber);
        self::assertCount(1, $this->inner->getListeners(KernelEvents::RESPONSE), 'only the subscriber from setUp is left');
    }

    public function testUnknownMethodsReachTheDecoratedDispatcher(): void
    {
        $dispatcher = new TraceableEventDispatcher(new ResettableEventDispatcher());

        self::assertSame('inner', $dispatcher->ownMethod());
    }

    private function handle(callable $controller, string $path = '/articles/1', bool $catch = true): Response
    {
        $request = Request::create($path);
        $request->attributes->set('_controller', $controller);

        $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, $catch);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return list<string> */
    private function spanNames(): array
    {
        return array_map(static fn (SpanDataInterface $span): string => $span->getName(), $this->exporter->getSpans());
    }

    private function span(string $name): SpanDataInterface
    {
        foreach ($this->exporter->getSpans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        self::fail(\sprintf('No "%s" span among: %s', $name, implode(', ', $this->spanNames())));
    }
}
