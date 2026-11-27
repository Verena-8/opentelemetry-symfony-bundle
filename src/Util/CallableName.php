<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

/** Names a PHP callable for span names and the `code.function.name` attribute. */
final class CallableName
{
    /**
     * Fully qualified name: `App\Controller\ArticleController::show`, `strlen`, or `closure` for an anonymous function.
     *
     * @return non-empty-string
     */
    public static function full(callable $callable): string
    {
        if (\is_array($callable)) {
            $class = \is_object($callable[0]) ? $callable[0]::class : $callable[0];

            return $class.'::'.$callable[1];
        }

        if ($callable instanceof \Closure) {
            $function = new \ReflectionFunction($callable);
            $name = $function->name;
            // Anonymous closures are named "{closure}", prefixed by their namespace before PHP 8.2, or "{closure:file:line}" since PHP 8.4.
            if ('' === $name || str_contains($name, '{closure')) {
                return 'closure';
            }

            // First-class callables and Closure::fromCallable() keep the function or method name.
            $object = $function->getClosureThis();
            $class = null !== $object ? $object::class : $function->getClosureScopeClass()?->name;

            return null !== $class ? $class.'::'.$name : $name;
        }

        if (\is_string($callable)) {
            return $callable;
        }

        return \is_object($callable) ? $callable::class.'::__invoke' : 'unknown';
    }

    /**
     * Name without the namespace: `ArticleController::show`.
     *
     * @return non-empty-string
     */
    public static function short(callable $callable): string
    {
        $name = self::full($callable);
        $separator = strpos($name, '::');

        if (false === $separator) {
            return $name;
        }

        return ClassName::short(substr($name, 0, $separator)).'::'.substr($name, $separator + 2);
    }
}
