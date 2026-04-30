<?php

declare(strict_types=1);

namespace ProcessWire;

if (!function_exists('ProcessWire\signal')) {
    /**
     * Declare a new signal.
     *
     * Creates a Signal builder, registers it with SignalRegistry for
     * collection during compilation, and returns it for method chaining.
     *
     * Usage (inside /site/signals/*.signal.php):
     *
     *   signal('product.published')
     *       ->on('page.published')
     *       ->where('template', 'product')
     *       ->after(function($signal) { ... });
     *
     * @param  string $name  Unique signal identifier (used in log messages)
     * @return \SimpleWire\Signal\Signal
     */
    function signal(string $name): \SimpleWire\Signal\Signal
    {
        $s = new \SimpleWire\Signal\Signal($name);
        \SimpleWire\Signal\SignalRegistry::push($s);
        return $s;
    }
}
