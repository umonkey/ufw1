<?php

namespace Ufw1;

use Psr\Container\ContainerInterface;

abstract class Service
{
    /**
     * Dependency container.
     *
     * @var ContainerInterface
     **/
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Get service by code.
     **/
    public function __get(string $key)
    {
        if (!$this->container->has($key)) {
            throw new \OutOfBoundsException("service {$key} not found");
        }

        return $this->container->get($key);
    }

    /**
     * Check if service exists.
     **/
    public function __isset(string $key)
    {
        return $this->container->has($key);
    }
}
