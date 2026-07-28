<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * The `process` global. Bundles branch on `process.env.NODE_ENV` and on the
 * presence of `process.versions.node`, so getting this shape right is what
 * makes a production React build take its production path.
 */
final class ProcessBuiltins
{
    public static function entries(): array
    {
        return [
            'node.process.cwd' => [self::class, 'cwd'],
            'node.process.nextTick' => [self::class, 'nextTick'],
            'node.process.stdoutWrite' => [self::class, 'stdoutWrite'],
            'node.process.hrtime' => [self::class, 'hrtime'],
            'node.process.exit' => [self::class, 'exit'],
            'node.process.on' => [self::class, 'noop'],
            'node.process.emitWarning' => [self::class, 'noop'],
        ];
    }

    public static function makeObject(Realm $realm): JSObject
    {
        $host = $realm->hostContext;
        $process = $realm->newObject();
        $flags = JSObject::W | JSObject::C;

        $env = $realm->newObject();
        if ($host instanceof NodeHost) {
            foreach ($host->env() as $key => $value) {
                $env->defineOwnData($key, $value);
            }
        }
        $process->defineOwnData('env', $env, $flags);
        $process->defineOwnData(
            'argv',
            $realm->newArray($host instanceof NodeHost ? $host->argv() : []),
            $flags
        );
        $process->defineOwnData('platform', 'linux', $flags);
        $process->defineOwnData('arch', 'x64', $flags);
        $process->defineOwnData('version', 'v18.0.0', $flags);
        $versions = $realm->newObject();
        $versions->defineOwnData('node', '18.0.0');
        $versions->defineOwnData('phpjs', '0.1.0');
        $process->defineOwnData('versions', $versions, $flags);

        $realm->defineMethod($process, 'cwd', 'node.process.cwd', 0);
        $realm->defineMethod($process, 'nextTick', 'node.process.nextTick', 1);
        $realm->defineMethod($process, 'hrtime', 'node.process.hrtime', 1);
        $realm->defineMethod($process, 'exit', 'node.process.exit', 1);
        $realm->defineMethod($process, 'on', 'node.process.on', 2);
        $realm->defineMethod($process, 'emitWarning', 'node.process.emitWarning', 1);

        foreach (['stdout', 'stderr'] as $stream) {
            $obj = $realm->newObject();
            $realm->defineMethod($obj, 'write', 'node.process.stdoutWrite', 1);
            $obj->defineOwnData('isTTY', false);
            $process->defineOwnData($stream, $obj, $flags);
        }
        return $process;
    }

    public static function cwd(Vm $vm, mixed $t, array $args): mixed
    {
        return NodeHost::of($vm)->root;
    }

    /**
     * process.nextTick runs ahead of promise jobs in Node. The engine has one
     * queue, so ticks are appended to it: ordering against other ticks is
     * preserved, ordering against promise reactions is not.
     */
    public static function nextTick(Vm $vm, mixed $t, array $args): mixed
    {
        $fn = $args[0] ?? JSUndefined::$undefined;
        if (!$fn instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Callback must be a function');
        }
        $vm->realm->enqueueMicrotask($fn, array_slice($args, 1));
        return JSUndefined::$undefined;
    }

    public static function stdoutWrite(Vm $vm, mixed $t, array $args): mixed
    {
        NodeHost::of($vm)->write(Conversions::toString($vm, $args[0] ?? JSUndefined::$undefined));
        return true;
    }

    public static function hrtime(Vm $vm, mixed $t, array $args): mixed
    {
        $now = microtime(true);
        $seconds = (int)$now;
        $nanos = (int)(($now - $seconds) * 1e9);
        $previous = $args[0] ?? null;
        if ($previous instanceof \PhpJs\Runtime\JSArray) {
            $list = $previous->toList();
            $seconds -= (int)Conversions::toNumber($vm, $list[0] ?? 0);
            $nanos -= (int)Conversions::toNumber($vm, $list[1] ?? 0);
            if ($nanos < 0) {
                $seconds--;
                $nanos += 1_000_000_000;
            }
        }
        return $vm->realm->newArray([$seconds, $nanos]);
    }

    public static function exit(Vm $vm, mixed $t, array $args): mixed
    {
        $vm->throwError('Error', 'process.exit() is not supported in this host');
    }

    public static function noop(Vm $vm, mixed $t, array $args): mixed
    {
        return JSUndefined::$undefined;
    }
}
