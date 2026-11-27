<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Tests\Fixtures\ArticleController;
use Traceway\OpenTelemetryBundle\Util\CallableName;

final class CallableNameTest extends TestCase
{
    /**
     * @param callable(): mixed $callable
     */
    #[DataProvider('callableProvider')]
    public function testNamesTheCallable(callable $callable, string $full, string $short): void
    {
        self::assertSame($full, CallableName::full($callable));
        self::assertSame($short, CallableName::short($callable));
    }

    /**
     * @return \Generator<string, array{callable, string, string}>
     */
    public static function callableProvider(): \Generator
    {
        $controller = new ArticleController();

        yield 'object and method' => [[$controller, 'show'], ArticleController::class.'::show', 'ArticleController::show'];
        yield 'class and static method' => [[self::class, 'staticMethod'], self::class.'::staticMethod', 'CallableNameTest::staticMethod'];
        yield 'invokable object' => [$controller, ArticleController::class.'::__invoke', 'ArticleController::__invoke'];
        yield 'first-class callable of a method' => [$controller->show(...), ArticleController::class.'::show', 'ArticleController::show'];
        yield 'first-class callable of a function' => [strlen(...), 'strlen', 'strlen'];
        yield 'function name' => ['strlen', 'strlen', 'strlen'];
        yield 'anonymous closure' => [static fn () => null, 'closure', 'closure'];
    }

    public static function staticMethod(): void
    {
    }
}
