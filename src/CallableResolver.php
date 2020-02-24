<?php

/**
 * Modified Slim's callable resolver.
 * Adds dependency injection.
 */

namespace Ufw1;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Slim\Interfaces\CallableResolverInterface;

/**
 * This class resolves a string of the format 'class:method' into a closure
 * that can be dispatched.
 */
class CallableResolver implements CallableResolverInterface
{
    const CALLABLE_PATTERN = '!^([^\:]+)\:([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$!';

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Resolve toResolve into a closure so that the router can dispatch.
     *
     * If toResolve is of the format 'class:method', then try to extract 'class'
     * from the container otherwise instantiate it and then dispatch 'method'.
     *
     * @param callable|string $toResolve
     *
     * @return callable
     *
     * @throws RuntimeException If the callable does not exist
     * @throws RuntimeException If the callable is not resolvable
     */
    public function resolve($toResolve)
    {
        if (is_callable($toResolve)) {
            return $toResolve;
        }

        $resolved = $toResolve;

        if (is_string($toResolve)) {
            list($class, $method) = $this->parseCallable($toResolve);
            $resolved = $this->resolveCallable($class, $method);
        }

        $this->assertCallable($resolved);
        return $resolved;
    }

    /**
     * Extract class and method from toResolve
     *
     * @param string $toResolve
     *
     * @return array
     */
    protected function parseCallable($toResolve)
    {
        if (preg_match(self::CALLABLE_PATTERN, $toResolve, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [$toResolve, '__invoke'];
    }

    /**
     * Check if string is something in the DIC
     * that's callable or is a class name which has an __invoke() method.
     *
     * @param string $class
     * @param string $method
     *
     * @return callable
     *
     * @throws RuntimeException if the callable does not exist
     */
    protected function resolveCallable($class, $method)
    {
        if ($this->container->has($class)) {
            return [$this->container->get($class), $method];
        }

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('Callable %s does not exist', $class));
        }

        $instance = $this->getClassInstance($class);

        return [$instance, $method];
    }

    /**
     * @param Callable $callable
     *
     * @throws RuntimeException if the callable is not resolvable
     */
    protected function assertCallable($callable)
    {
        if (!is_callable($callable)) {
            throw new RuntimeException(sprintf(
                '%s is not resolvable',
                is_array($callable) || is_object($callable) ? json_encode($callable) : $callable
            ));
        }
    }

    /**
     * Returns a class instance.
     *
     * @param string $className Class to construct.
     *
     * @return array Constructor arguments.
     **/
    public function getClassInstance(string $className): object
    {
        $ref = new \ReflectionClass($className);
        return $this->getClassInstanceByRef($ref);
    }

    protected function getClassInstanceByRef(\ReflectionClass $class): object
    {
        $constructor = $class->getConstructor();
        if (null === $constructor) {
            $className = $class->getName();
            return new $className();
        }

        $args = [];

        $params = $constructor->getParameters();
        foreach ($params as $param) {
            $name = $param->getName();

            if ($name == 'container') {
                $value = $this->container;
            } elseif ($this->container->has($name)) {
                $value = $this->container->get($name);
            } elseif (null !== ($paramClass = $param->getClass())) {
                $value = $this->getClassInstanceByRef($paramClass);
            } else {
                $value = null;
            }

            $args[] = $value;
        }

        $obj = $class->newInstanceArgs($args);

        return $obj;
    }
}
