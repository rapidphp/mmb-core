<?php

namespace Mmb\Action\Middle;

use Mmb\Action\Action;
use Mmb\Action\Inline\InlineAction;
use Mmb\Action\Inline\InlineStepHandler;
use Mmb\Action\Inline\Register\InlineRegister;
use Mmb\Action\Memory\Step;
use Mmb\Action\Section\Section;
use Mmb\Action\Update\StopHandlingException;
use Mmb\Action\Update\UpdateHandling;
use Mmb\Core\Updates\Update;

class MiddleAction extends Section implements UpdateHandling
{

    protected function onInitializeInlineRegister(InlineRegister $register)
    {
        parent::onInitializeInlineRegister($register);

        $register->before(
            function () use ($register)
            {
                $register->inlineAction->with('redirectTo');

                if ($register->inlineAction->isCreating() && !$this->redirectWith)
                {
                    return;
                }

                $register->inlineAction->with('redirectWith');
            }
        );
    }

    /**
     * If set to true, when redirect() is calling, required() method doesn't work.
     *
     * @var bool
     */
    protected $ignoreLoop = false;

    /**
     * Middle action category
     *
     * @var string
     */
    protected $category = 'global';

    // # Abstract methods:
    // public abstract function main();
    // public abstract function isRequired();

    /**
     * Target redirect location
     *
     * @var mixed
     */
    public $redirectTo;

    /**
     * Redirect arguments
     *
     * @var array
     */
    public $redirectWith = [];

    /**
     * Set redirect path
     *
     * @param string $class
     * @param string $method
     * @param        ...$args
     * @return $this
     */
    public function at(string $class, string $method, ...$args)
    {
        $this->redirectTo = [$class, $method];
        $this->redirectWith = $args;
        return $this;
    }

    public $params;

    /**
     * Set parameters for step handler
     *
     * @param ...$args
     * @return $this
     */
    public function params(...$args)
    {
        $this->params = $args;
        return $this;
    }

    /**
     * Get category
     *
     * @return string
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * Redirect back
     *
     * @param ...$args
     * @return bool
     */
    public function redirect(...$args)
    {
        $this->update->put(static::class . '::ran', true);

        if(is_array($this->redirectTo) && count($this->redirectTo) == 2)
        {
            [$class, $method] = $this->redirectTo;
            if(class_exists($class) && is_a($class, Action::class, true))
            {
                $class::invokes($method, ...$this->redirectWith, ...$args);
                $this->update->forget(static::class . '::ran');
                return true;
            }
        }
        elseif($this->redirectTo === '@step')
        {
            Step::set(null);
            $this->update->put('middle-action-handled', $this);
            $this->update->repeatHandling();
        }

        $this->update->forget(static::class . '::ran');

        return false;
    }

    /**
     * Get arguments and with value from an array
     *
     * @param string $method
     * @param        ...$args
     * @return array
     */
    private function getArgumentsAndWiths(string $method, ...$args)
    {
        $pass = [];
        foreach($args as $i => $arg)
        {
            if(is_int($i))
            {
                $pass[] = $arg;
                unset($args[$i]);
            }
        }
        foreach((new \ReflectionMethod($this, $method))->getParameters() as $parameter)
        {
            if(array_key_exists($name = $parameter->getName(), $args))
            {
                $pass[$name] = $args[$name];
                unset($args[$name]);
            }
        }

        return [$pass, $args];
    }

    /**
     * @param ...$args
     * @return bool
     */
    private function checksIsRequired(...$args)
    {
        if($this->ignoreLoop && $this->update->get(static::class . '::ran'))
        {
            return false;
        }

        [$pass, $args] = $this->getArgumentsAndWiths('isRequired', ...$args);
        return (bool) $this->invoke('isRequired', ...$pass);
    }

    /**
     * Request middle action
     *
     * @param array $redirectTo
     * @param       ...$args
     * @return void
     */
    public static function request(array $redirectTo, ...$args)
    {
        if(count($redirectTo) != 2)
        {
            throw new \ValueError("\$redirectTo size should equals to 2");
        }

        $instance = static::make();
        [$pass, $args] = $instance->getArgumentsAndWiths('main', ...$args);

        $instance->redirectTo = $redirectTo;
        $instance->redirectWith = $args;

        $instance->invoke('main', ...$pass);
    }

    /**
     * Request middle action
     *
     * @param string $class
     * @param string $method
     * @param        ...$args
     * @return void
     */
    public static function requestTo(string $class, string $method, ...$args)
    {
        static::request([$class, $method], ...$args);
    }

    /**
     * Check requiring
     *
     * @param ...$args
     * @return bool
     */
    public static function check(...$args)
    {
        return static::make()->checksIsRequired(...$args);
    }

    /**
     * Request the action, if is required.
     *
     * @param array $backTo
     * @param       ...$args
     * @return void
     * @throws StopHandlingException
     */
    public static function required(array $backTo, ...$args)
    {
        if(count($backTo) != 2)
        {
            throw new \ValueError("\$backTo size should equals to 2");
        }

        $instance = static::make();
        if($instance->checksIsRequired(...$args))
        {
            [$pass, $args] = $instance->getArgumentsAndWiths('main', ...$args);

            $instance->redirectTo = $backTo;
            $instance->redirectWith = $args;

            $instance->invoke('main', ...$pass);
            $instance->update->stopHandling();
        }
    }

    /**
     * Request the action, if is required.
     *
     * @param string $class
     * @param string $method
     * @param        ...$args
     * @return void
     * @throws StopHandlingException
     */
    public static function requiredAt(string $class, string $method, ...$args)
    {
        static::required([$class, $method], ...$args);
    }

    /**
     * Required the action, if is required.
     *
     * This method fills class and method automatically by backtrace.
     *
     * @param ...$args
     * @return void
     * @throws StopHandlingException
     */
    public static function requiredHere(...$args)
    {
        $at = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        static::required([$at['class'], $at['function']], ...$args);
    }


    /**
     * Handle update for step handler
     *
     * @param Update $update
     * @return void
     */
    public function handleUpdate(Update $update)
    {
        if($this->isMiddleActionStep())
        {
            $update->skipHandler();
            return;
        }

        if($this->checksIsRequired(...$this->params ?? []))
        {
            if(is_null($this->redirectTo))
            {
                $this->redirectTo = '@step';
                $this->redirectWith = [];
            }

            $this->invoke('main', ...$this->params ?? []);
            return;
        }

        $update->skipHandler();
    }

    private function isMiddleActionStep()
    {
        $step = Step::get();
        if($step instanceof InlineStepHandler)
        {
            return $step->initalizeClass &&
                class_exists($step->initalizeClass) &&
                is_a($step->initalizeClass, MiddleAction::class, true);
        }

        return false;
    }

}
