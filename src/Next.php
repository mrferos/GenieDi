<?php
namespace GenieDi;

use Interop\Container\ContainerInterface;

class Next
{
    /**
     * @var ContainerInterface
     */
    protected $container;
    /**
     * @var \SplQueue
     */
    protected $middlewares;

    public function __construct(ContainerInterface $container, \SplQueue $middlewares)
    {
        $this->container   = $container;
        $this->middlewares = $middlewares;
    }

    public function __invoke($id, $service)
    {
        if ($this->middlewares->isEmpty()) {
            return $service;
        }

        $method = $this->middlewares->dequeue();
        return call_user_func(
            $method,
            $this->container,
            new Next($this->container, $this->middlewares),
            $id,
            $service
        );
    }
}