<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * setTimeout / setInterval over a virtual clock.
 *
 * A request-scoped host has no event loop to hand out, and sleeping for real
 * would only make a render slower. Time advances to the next due timer instead,
 * so ordering is preserved and delays cost nothing — the macrotask queue
 * DESIGN.md §9 leaves to the host layer.
 */
final class TimerQueue
{
    /** @var array<int, array{at: float, seq: int, fn: mixed, args: list<mixed>, interval: ?float}> */
    private array $timers = [];
    private int $nextId = 1;
    private int $seq = 0;
    private float $now = 0.0;
    /** Guards against an interval that reschedules itself forever. */
    public int $maxIterations = 100000;

    public static function entries(): array
    {
        return [
            'node.timers.setTimeout' => [self::class, 'setTimeoutNative'],
            'node.timers.setInterval' => [self::class, 'setIntervalNative'],
            'node.timers.setImmediate' => [self::class, 'setImmediateNative'],
            'node.timers.clear' => [self::class, 'clearNative'],
            'node.queueMicrotask' => [self::class, 'queueMicrotaskNative'],
        ];
    }

    /** @return array<string, JSNativeFunction> */
    public static function globals(Realm $realm): array
    {
        return [
            'setTimeout' => $realm->nativeFn('node.timers.setTimeout', 'setTimeout', 2),
            'setInterval' => $realm->nativeFn('node.timers.setInterval', 'setInterval', 2),
            'setImmediate' => $realm->nativeFn('node.timers.setImmediate', 'setImmediate', 1),
            'clearTimeout' => $realm->nativeFn('node.timers.clear', 'clearTimeout', 1),
            'clearInterval' => $realm->nativeFn('node.timers.clear', 'clearInterval', 1),
            'clearImmediate' => $realm->nativeFn('node.timers.clear', 'clearImmediate', 1),
            // Not a timer, but it belongs to the same "when does this run"
            // surface: queueMicrotask goes straight onto the realm's job queue
            // (DESIGN.md §9), ahead of any due timer.
            'queueMicrotask' => $realm->nativeFn('node.queueMicrotask', 'queueMicrotask', 1),
        ];
    }

    public static function queueMicrotaskNative(Vm $vm, mixed $t, array $args): mixed
    {
        $fn = $args[0] ?? null;
        if (!$fn instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'queueMicrotask requires a function');
        }
        $vm->realm->enqueueMicrotask($fn, []);
        return JSUndefined::$undefined;
    }

    public function add(mixed $fn, float $delayMs, array $args, ?float $interval): int
    {
        $id = $this->nextId++;
        $this->timers[$id] = [
            'at' => $this->now + max(0.0, $delayMs),
            'seq' => $this->seq++,
            'fn' => $fn,
            'args' => $args,
            'interval' => $interval,
        ];
        return $id;
    }

    public function clear(int $id): void
    {
        unset($this->timers[$id]);
    }

    public function isEmpty(): bool
    {
        return $this->timers === [];
    }

    /**
     * Run every pending timer, advancing the virtual clock to each one's due
     * time. Returns true if anything ran.
     */
    public function runDue(Vm $vm): bool
    {
        $ran = false;
        $iterations = 0;
        while ($this->timers !== []) {
            if (++$iterations > $this->maxIterations) {
                throw new \RuntimeException('Timer queue did not settle; an interval is never cleared');
            }
            $nextId = null;
            $next = null;
            foreach ($this->timers as $id => $timer) {
                if ($next === null
                    || $timer['at'] < $next['at']
                    || ($timer['at'] === $next['at'] && $timer['seq'] < $next['seq'])) {
                    $next = $timer;
                    $nextId = $id;
                }
            }
            $this->now = max($this->now, $next['at']);
            if ($next['interval'] === null) {
                unset($this->timers[$nextId]);
            } else {
                $this->timers[$nextId]['at'] = $this->now + max(1.0, $next['interval']);
                $this->timers[$nextId]['seq'] = $this->seq++;
            }
            $vm->invoke($next['fn'], JSUndefined::$undefined, $next['args']);
            $vm->realm->drainMicrotasks($vm);
            $ran = true;
        }
        return $ran;
    }

    private static function schedule(Vm $vm, array $args, ?bool $repeating): mixed
    {
        $host = NodeHost::of($vm);
        $fn = $args[0] ?? JSUndefined::$undefined;
        if (!$fn instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Callback must be a function');
        }
        $delay = $repeating === null
            ? 0.0
            : (float)Conversions::toNumber($vm, $args[1] ?? 0);
        if (is_nan($delay)) {
            $delay = 0.0;
        }
        $extra = array_slice($args, $repeating === null ? 1 : 2);
        return $host->timers->add($fn, $delay, $extra, $repeating === true ? $delay : null);
    }

    public static function setTimeoutNative(Vm $vm, mixed $t, array $args): mixed
    {
        return self::schedule($vm, $args, false);
    }

    public static function setIntervalNative(Vm $vm, mixed $t, array $args): mixed
    {
        return self::schedule($vm, $args, true);
    }

    public static function setImmediateNative(Vm $vm, mixed $t, array $args): mixed
    {
        return self::schedule($vm, $args, null);
    }

    public static function clearNative(Vm $vm, mixed $t, array $args): mixed
    {
        $id = $args[0] ?? null;
        if (is_int($id) || is_float($id)) {
            NodeHost::of($vm)->timers->clear((int)$id);
        }
        return JSUndefined::$undefined;
    }
}
