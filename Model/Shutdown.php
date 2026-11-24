<?php
/**
 * Copyright © MageZero. All rights reserved.
 */
namespace MageZero\CloudflareR2\Model;

/**
 * Runs a callback when PHP shuts down: after the response has been built and sent, or after a
 * command has finished. A class of its own so tests can see what was registered.
 */
class Shutdown
{
    /**
     * @param callable $callback
     * @return void
     */
    public function register(callable $callback): void
    {
        register_shutdown_function($callback);
    }
}
