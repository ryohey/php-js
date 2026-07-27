<?php

declare(strict_types=1);

namespace PhpJs;

use PhpJs\Compiler\Compiler;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPromise;
use PhpJs\Runtime\JSThrowSignal;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Public facade: compile + run + drain microtasks (DESIGN.md §9).
 * One Engine = one realm = one VM.
 */
final class Engine
{
    public readonly Realm $realm;
    public readonly Vm $vm;

    public function __construct(?callable $consoleWriter = null)
    {
        $this->realm = new Realm();
        $this->vm = new Vm($this->realm);
        if ($consoleWriter !== null) {
            $this->realm->hostWriter = $consoleWriter;
        }
    }

    /** @return array<string, mixed> function template (plain array) */
    public function compile(string $source): array
    {
        return Compiler::compile($source);
    }

    /** Evaluate source; returns the completion value as a JS value. */
    public function evaluate(string $source): mixed
    {
        return $this->runTemplate($this->compile($source));
    }

    /** @param array<string, mixed> $template */
    public function runTemplate(array $template): mixed
    {
        try {
            $result = $this->vm->runProgram($template);
            $this->realm->drainMicrotasks($this->vm);
            return $result;
        } catch (JSThrowSignal $e) {
            throw JSException::from($this->vm, $e->value);
        }
    }

    /** Call a JS function value from PHP. */
    public function call(mixed $fn, mixed $thisVal = null, array $args = []): mixed
    {
        try {
            $result = $this->vm->invoke($fn, $thisVal ?? JSUndefined::$undefined, $args);
            $this->realm->drainMicrotasks($this->vm);
            return $result;
        } catch (JSThrowSignal $e) {
            throw JSException::from($this->vm, $e->value);
        }
    }

    /** @return list<mixed> promises rejected with no handler after the last drain */
    public function unhandledRejections(): array
    {
        $out = [];
        foreach ($this->realm->unhandledRejections as $p) {
            if ($p instanceof JSPromise && !$p->handled && $p->state === JSPromise::REJECTED) {
                $out[] = $p->result;
            }
        }
        return $out;
    }

    /** Convert a JS value into plain PHP data (for host consumption). */
    public function toPhp(mixed $v): mixed
    {
        if ($v instanceof JSUndefined) {
            return null;
        }
        if ($v instanceof JSArray) {
            $out = [];
            foreach ($v->toList() as $item) {
                $out[] = $this->toPhp($item);
            }
            return $out;
        }
        if ($v instanceof JSFunctionBase) {
            return '[function ' . $v->name . ']';
        }
        if ($v instanceof JSObject) {
            $out = [];
            foreach ($v->ownEnumerableKeys() as $key) {
                $out[$key] = $this->toPhp($v->get($key, $this->vm));
            }
            return $out;
        }
        return $v;
    }

    /** ToString for host code (e.g. printing REPL results). */
    public function stringify(mixed $v): string
    {
        return Conversions::toString($this->vm, $v);
    }
}
