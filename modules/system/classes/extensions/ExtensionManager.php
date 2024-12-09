<?php

namespace System\Classes\Extensions;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Component;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class ExtensionManager
{
    protected OutputStyle $output;

    public function __construct(?OutputStyle $output = null)
    {
        $this->output = $output ?? new OutputStyle(new ArrayInput([]), new BufferedOutput());

        $this->init();
    }

    public function setOutput(OutputStyle $output): static
    {
        $this->output = $output;

        return $this;
    }

    public function getOutput(): OutputStyle
    {
        return $this->output;
    }

    public function termwind(string $component, ...$args): void
    {
        (new $component($this->output))->render(...$args);
    }

    /**
     * Create a new instance of this singleton.
     */
    final public static function instance(?Container $container = null): static
    {
        if (!$container) {
            $container = app();
        }

        if (!$container->bound(static::class)) {
            $container->singleton(static::class, function () {
                return new static;
            });
        }

        return $container->make(static::class);
    }

    /**
     * Forget this singleton's instance if it exists
     */
    final public static function forgetInstance(?Container $container = null): void
    {
        if (!$container) {
            $container = app();
        }

        if ($container->bound(static::class)) {
            $container->forgetInstance(static::class);
        }
    }

    /**
     * Initialize the singleton free from constructor parameters.
     */
    protected function init()
    {
    }

    public function __clone()
    {
        trigger_error('Cloning ' . __CLASS__ . ' is not allowed.', E_USER_ERROR);
    }

    public function __wakeup()
    {
        trigger_error('Unserializing ' . __CLASS__ . ' is not allowed.', E_USER_ERROR);
    }
}
