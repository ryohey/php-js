<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Host\Display;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

final class ConsoleBuiltins
{
    public static function entries(): array
    {
        return [
            'console.log' => [self::class, 'log'],
            'console.error' => [self::class, 'log'],
            'console.warn' => [self::class, 'log'],
            'console.info' => [self::class, 'log'],
            'console.debug' => [self::class, 'log'],
        ];
    }

    public static function makeObject(Realm $r): JSObject
    {
        $console = new JSObject($r->objectPrototype());
        $console->nativeId = 'console';
        foreach (['log', 'error', 'warn', 'info', 'debug'] as $name) {
            $r->defineMethod($console, $name, "console.$name", 0);
        }
        return $console;
    }

    public static function log(Vm $vm, mixed $t, array $args): mixed
    {
        $parts = [];
        foreach ($args as $a) {
            $parts[] = Display::stringify($vm, $a);
        }
        $line = implode(' ', $parts) . "\n";
        $writer = $vm->realm->hostWriter;
        if ($writer !== null) {
            $writer($line);
        } else {
            fwrite(STDOUT, $line);
        }
        return JSUndefined::$undefined;
    }
}
