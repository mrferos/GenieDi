<?php
namespace GenieDi;

use GenieDi\Exception\GenieException;
use GenieDi\Exception\GenieNotFoundException;
use Interop\Container\ContainerInterface;

/**
 * Class Container
 * @package GenieDi
 */
class Container implements ContainerInterface
{
    /**
     * Container of shared services
     *
     * @var array
     */
    protected $serviceMap       = [];

    /**
     * Container for service factories
     *
     * @var array
     */
    protected $serviceFactories = [];

    /**
     * Do we attempt to auto-resolve class depedencies?
     *
     * @var bool
     */
    protected $autoWire         = false;

    /**
     * List of shared services
     *
     * @var array
     */
    protected $shared           = [];

    /**
     * Container for middlewares to run
     *
     * @var \SplQueue
     */
    protected $middlewares;

    /**
     * Container constructor.
     * @param bool $autoWire
     */
    public function __construct($autoWire = false)
    {
        $this->autoWire    = $autoWire;
        $this->middlewares = new \SplQueue();
    }

    /**
     * generic register method to make things simpler
     *
     * @param array ...$args
     * @throws GenieException
     */
    public function register(...$args)
    {
        $argCount = count($args);
        // Are we dealing with a situation where a simple class is being register
        // which requires no arguments?
        if ($argCount == 1) {
            $this->registerService($args[0]);

        // registering a service with an alias
        } elseif (
            $argCount == 2 &&
            (is_string($args[1])) || (is_object($args[1]) && !$args[1] instanceof \Closure)
        ) {
            $this->registerService($args[0], $args[1]);

        // registering a factory
        } elseif ($argCount == 2 && is_callable($args[1])) {
            $this->registerFactory($args[0], $args[1]);
        }
    }

    /**
     * @param string $id
     * @param callable $factory
     */
    public function registerFactory($id, $factory)
    {
        $this->serviceFactories[$id] = $factory;
    }

    /**
     * @param string $id
     * @param null|string|object $serviceClass
     * @throws GenieException
     */
    public function registerService($id, $serviceClass = null)
    {
        if (is_null($serviceClass)) {
            $this->serviceMap[$id] = $id;
        } elseif (is_string($serviceClass) || is_object($serviceClass)) {
            $this->serviceMap[$id] = $serviceClass;
        } else {
            throw new GenieException("\$serviceClass must be a string");
        }
    }

    /**
     * Resolve a service and run it through the middlewares
     *
     * @param string $id
     * @return object
     * @throws GenieException
     */
    public function get($id)
    {
        // Let's try and resolve the service to an object
        try {
            $service = $this->resolve($id);

        // catch any exception for now...
        } catch(GenieException $e) {
            $service = null;
        } finally {
            // if we come back with an object, override the service
            $postProcessService = $this->processMiddlewares($id, $service);
            if (is_object($postProcessService)) {
                $service = $postProcessService;
            }

            // if we have an exception, emit it unless we have a service object
            // meaning the middlewares have resolved to an object
            if (isset($e) && empty($service)) {
                throw $e;
            }

            return $service;
        }
    }

    /**
     * See if we have a service
     *
     * @param string $id
     * @return bool
     */
    public function has($id)
    {
        return array_key_exists($id, $this->serviceMap) || array_key_exists($id, $this->serviceFactories);
    }

    /**
     * @param string $id
     * @param bool $shared
     * @return  void
     */
    public function markShared($id, $shared = true)
    {
        $this->shared[$id] = (bool)$shared;
    }

    /**
     * Add middleware to stack
     *
     * @param callable $middleware
     */
    public function middleware(callable $middleware)
    {
        $this->middlewares->enqueue($middleware);
    }

    /**
     * Autoresolve the dependenies for an object if possible
     *
     * @param \ReflectionClass $reflectionClass
     * @return object
     * @throws GenieException
     */
    protected function autowire(\ReflectionClass $reflectionClass)
    {
        // get constructor if one available, if not just return
        // an instance since there are no args
        $constructor = $reflectionClass->getConstructor();
        if (is_null($constructor)) {
            return $reflectionClass->newInstance();
        }

        // Let's go over our args and see if we have the
        // parameter class registered as a service
        // or perhaps the name of the variable as one
        $constructorArgs = $constructor->getParameters();
        $argList = [];
        foreach ($constructorArgs as $arg) {
            $classType = $arg->getClass()->getName();
            $argName   = $arg->getName();
            $serviceName = empty($classType) ? $argName : $classType;
            if (!$this->has($serviceName)) {
                throw new GenieException(sprintf(
                    'Could not resolve parameter %s%s as position %d in class %s',
                    $classType . ' ',
                    $argName,
                    $arg->getPosition(),
                    $reflectionClass->getName()
                ));
            }

            $argList[] = $this->get($serviceName);
        }

        return $reflectionClass->newInstanceArgs($argList);
    }

    /**
     * Check if a service is shared
     *
     * @param string $id
     * @return bool
     */
    protected function isShared($id)
    {
        if (!array_key_exists($id, $this->shared)) {
            return true;
        }

        return $this->shared[$id];
    }

    /**
     * Resolve an id to a service
     *
     * @param string $id
     * @return object
     * @throws GenieException
     */
    protected function resolve($id)
    {
        $service = null;

        // let's go check if we have a factory
        if (array_key_exists($id, $this->serviceFactories)) {
            // catch any exception during the service generation
            // and emit a GenieException instead
            try {
                // If we got a class string as a factory, instantiate it
                $factory = $this->serviceFactories[$id];
                if (is_string($factory) && class_exists($factory)) {
                    $reflFactory = new \ReflectionClass($factory);
                    $factory = $reflFactory->newInstance();
                }

                // Did the factory not return an object? error out!
                $service = call_user_func($factory, $this);
                if (!is_object($service)) {
                    throw new GenieException("$id factory does not resolve to an object");
                }
            } catch(\Exception $e) {
                throw new GenieException("$id object generation failed", 0, $e);
            }

            // Only convert this to a mapped service if it's shared
            if ($this->isShared($id)) {
                $this->serviceMap[$id] = $service;
                unset($this->serviceFactories[$id]);
            }

        // let's see if we have it in our service map
        } elseif (array_key_exists($id, $this->serviceMap)) {
            // Do we have to instantiate the class ourselves?
            if (is_string($this->serviceMap[$id])) {
                $reflClass = new \ReflectionClass($this->serviceMap[$id]);
                // Go through the autowiring procedure if that's been enabled
                $service = $this->autoWire ? $this->autowire($reflClass) : $reflClass->newInstance();
                // only overwrite the service map entry if the service is marked as shareable
                if ($this->isShared($id)) {
                    $this->serviceMap[$id] = $service;
                }
            } else {
                $service = $this->serviceMap[$id];
            }
        }

        // nada? sadface.
        if (is_null($service)) {
            throw new GenieNotFoundException("Could not resolve service '$id'");
        }

        return $service;
    }

    /**
     * Flow through the stack of middlewares
     *
     * @param $id
     * @param $service
     * @return null|object
     */
    protected function processMiddlewares($id, $service)
    {
        $next = new Next($this, clone $this->middlewares);
        $service = $next($id, $service);
        $this->middlewares->rewind();
        return $service;
    }
}