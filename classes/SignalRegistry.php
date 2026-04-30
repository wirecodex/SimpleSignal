<?php

declare(strict_types=1);

namespace SimpleWire\Signal;

class SignalRegistry
{
    protected static array $signals = [];

    public static function reset(): void
    {
        self::$signals = [];
    }

    public static function push(Signal $signal): void
    {
        self::$signals[] = $signal;
    }

    public static function collect(): array
    {
        return self::$signals;
    }
}
