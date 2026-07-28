<?php

declare(strict_types=1);

namespace PhpJs\Vm;

use PhpJs\Builtins\BuiltinRegistry;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSBoundFunction;
use PhpJs\Runtime\JSFunction;
use PhpJs\Runtime\JSHole;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSThrowSignal;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\StringOps;
use PhpJs\Runtime\TypeOps;

/**
 * The bytecode VM: a single dispatch loop (DESIGN.md §4). JS frames live in
 * $frames (our own data, not the PHP call stack); the operand stack is one
 * shared array with function locals occupying a register window at the base
 * of each frame. JS exceptions are in-loop control flow; JSThrowSignal only
 * crosses the native boundary.
 */
final class Vm
{
    // Frame slot indices
    private const F_TPL = 0;
    private const F_BASE = 1;
    private const F_PC = 2;
    private const F_ENV = 3;
    private const F_THIS = 4;
    private const F_ARGS = 5;
    private const F_HANDLERS = 6;
    private const F_FUNC = 7;
    private const F_CTOR = 8;
    private const F_RETSP = 9;
    private const F_ARGSOBJ = 10;

    private const MAX_FRAMES = 10000;
    private const MAX_REENTRY = 400;
    /** How often the dispatch loop checks the wall clock against the deadline. */
    private const DEADLINE_CHECK_INTERVAL = 100000;

    /** @var array<int, mixed> shared operand stack (locals live at frame bases) */
    public array $stack = [];
    public int $sp = 0;
    /** @var list<array<int, mixed>> */
    public array $frames = [];
    public mixed $completion;
    private int $reentry = 0;
    /**
     * Wall-clock limit for JS execution, or null for no limit. A runaway loop
     * in guest code must not hang the host request, and PHP's own time limit
     * cannot unwind the VM cleanly. Checked every
     * DEADLINE_CHECK_INTERVAL instructions so the hot path stays untouched.
     */
    private ?float $deadline = null;
    private int $ticksToDeadlineCheck = self::DEADLINE_CHECK_INTERVAL;

    /** Abort execution if JS runs for more than $seconds. Null clears the limit. */
    public function setTimeLimit(?float $seconds): void
    {
        $this->deadline = $seconds === null ? null : microtime(true) + $seconds;
        $this->ticksToDeadlineCheck = self::DEADLINE_CHECK_INTERVAL;
    }

    public function __construct(public Realm $realm)
    {
        $this->completion = JSUndefined::$undefined;
        $realm->vm ??= $this;
    }

    /** Run a program template in the global scope; returns the completion value. */
    public function runProgram(array $template): mixed
    {
        $this->completion = JSUndefined::$undefined;
        $base = $this->sp;
        $nlocals = $template['nlocals'];
        for ($i = 0; $i < $nlocals; $i++) {
            $this->stack[$base + $i] = JSUndefined::$undefined;
        }
        $this->sp = $base + $nlocals;
        $env = $template['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv(null, $template['nenv']) : null;
        $this->frames[] = [
            $template, $base, 0, $env, $this->realm->globalObject,
            null, [], null, null, $base, null,
        ];
        return $this->execute(count($this->frames) - 1);
    }

    /** Call a JS value from PHP (native builtins, host code). */
    public function invoke(mixed $fn, mixed $thisVal, array $args, ?JSObject $ctorObj = null): mixed
    {
        while ($fn instanceof JSBoundFunction) {
            if ($ctorObj === null) {
                $thisVal = $fn->boundThis;
            }
            $args = array_merge($fn->boundArgs, $args);
            $fn = $fn->target;
        }
        if ($fn instanceof JSNativeFunction) {
            if ($ctorObj !== null && $fn->ctorId !== null) {
                return (BuiltinRegistry::get($fn->ctorId))($this, $args, $fn);
            }
            return (BuiltinRegistry::get($fn->fnId))($this, $ctorObj ?? $thisVal, $args, $fn);
        }
        if (!$fn instanceof JSFunction) {
            $this->throwError('TypeError', Conversions::toString($this, TypeOps::typeofOp($fn)) . ' is not a function');
        }
        if (++$this->reentry > self::MAX_REENTRY) {
            $this->reentry--;
            $this->throwError('RangeError', 'Maximum call stack size exceeded');
        }
        try {
            $this->pushFrame($fn, $thisVal, $args, $ctorObj);
            return $this->execute(count($this->frames) - 1);
        } finally {
            $this->reentry--;
        }
    }

    /** [[Construct]] entry point for natives (e.g. bind, Reflect-like helpers). */
    public function construct(mixed $ctor, array $args): mixed
    {
        while ($ctor instanceof JSBoundFunction) {
            $args = array_merge($ctor->boundArgs, $args);
            $ctor = $ctor->target;
        }
        if ($ctor instanceof JSNativeFunction) {
            return (BuiltinRegistry::get($ctor->ctorId ?? $ctor->fnId))($this, $args, $ctor);
        }
        if (!$ctor instanceof JSFunction) {
            $this->throwError('TypeError', 'not a constructor');
        }
        $proto = $ctor->get('prototype', $this);
        $obj = new JSObject($proto instanceof JSObject ? $proto : $this->realm->objectPrototype());
        $result = $this->invoke($ctor, $obj, $args, $obj);
        return $result instanceof JSObject ? $result : $obj;
    }

    private function pushFrame(JSFunction $func, mixed $thisVal, array $args, ?JSObject $ctorObj): void
    {
        if (count($this->frames) >= self::MAX_FRAMES) {
            $this->throwError('RangeError', 'Maximum call stack size exceeded');
        }
        $tpl = $func->template;
        $base = $this->sp;
        $argc = count($args);
        $n = min($argc, $tpl['nparams']);
        for ($i = 0; $i < $n; $i++) {
            $this->stack[$base + $i] = $args[$i];
        }
        $und = JSUndefined::$undefined;
        for ($i = $n; $i < $tpl['nlocals']; $i++) {
            $this->stack[$base + $i] = $und;
        }
        $this->sp = $base + $tpl['nlocals'];
        $env = $tpl['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv($func->env, $tpl['nenv']) : $func->env;
        if ($ctorObj !== null) {
            $thisVal = $ctorObj;
        } elseif (!$tpl['strict']) {
            $thisVal = $this->coerceThis($thisVal);
        }
        $this->frames[] = [
            $tpl, $base, 0, $env, $thisVal,
            $tpl['usesArgs'] ? $args : null, [], $func, $ctorObj, $base, null,
        ];
    }

    private function coerceThis(mixed $t): mixed
    {
        if ($t === null || $t instanceof JSUndefined) {
            return $this->realm->globalObject;
        }
        if ($t instanceof JSObject) {
            return $t;
        }
        return Conversions::toObject($this, $t);
    }

    /**
     * The dispatch loop. Runs until the frame at index $floor returns.
     * @param int $floor index of the entry frame
     */
    private function execute(int $floor): mixed
    {
        $stack = &$this->stack;
        $frames = &$this->frames;
        $realm = $this->realm;
        $und = JSUndefined::$undefined;

        $fi = count($frames) - 1;
        $frame = &$frames[$fi];
        $tpl = $frame[self::F_TPL];
        $code = $tpl['code'];
        $consts = $tpl['consts'];
        $strict = $tpl['strict'];
        $base = $frame[self::F_BASE];
        $env = $frame[self::F_ENV];
        $pc = $frame[self::F_PC];
        $sp = $this->sp;

        for (;;) {
            try {
                $this->sp = $sp; // keep reentrant helpers safe (see DESIGN.md §4.3)
                if (--$this->ticksToDeadlineCheck <= 0) {
                    $this->ticksToDeadlineCheck = self::DEADLINE_CHECK_INTERVAL;
                    if ($this->deadline !== null && microtime(true) > $this->deadline) {
                        $this->deadline = null; // let the unwind itself complete
                        $this->throwError('RangeError', 'Script execution timed out');
                    }
                }
                $op = $code[$pc++];
                switch ($op) {
                    case Op::PUSH_CONST:
                        $stack[$sp++] = $consts[$code[$pc++]];
                        break;
                    case Op::PUSH_INT:
                        $stack[$sp++] = $code[$pc++];
                        break;
                    case Op::PUSH_TRUE:
                        $stack[$sp++] = true;
                        break;
                    case Op::PUSH_FALSE:
                        $stack[$sp++] = false;
                        break;
                    case Op::PUSH_NULL:
                        $stack[$sp++] = null;
                        break;
                    case Op::PUSH_UNDEF:
                        $stack[$sp++] = $und;
                        break;
                    case Op::PUSH_HOLE:
                        $stack[$sp++] = JSHole::$hole;
                        break;
                    case Op::DUP:
                        $stack[$sp] = $stack[$sp - 1];
                        $sp++;
                        break;
                    case Op::DUP2:
                        $stack[$sp] = $stack[$sp - 2];
                        $stack[$sp + 1] = $stack[$sp - 1];
                        $sp += 2;
                        break;
                    case Op::POP:
                        $sp--;
                        break;
                    case Op::SWAP:
                        $t = $stack[$sp - 1];
                        $stack[$sp - 1] = $stack[$sp - 2];
                        $stack[$sp - 2] = $t;
                        break;

                    case Op::GET_LOCAL:
                        $stack[$sp++] = $stack[$base + $code[$pc++]];
                        break;
                    case Op::SET_LOCAL:
                        $stack[$base + $code[$pc++]] = $stack[$sp - 1];
                        break;
                    case Op::GET_ENV: {
                        $d = $code[$pc++];
                        $e = $env;
                        while ($d--) {
                            $e = $e->parent;
                        }
                        $stack[$sp++] = $e->slots[$code[$pc++]];
                        break;
                    }
                    case Op::SET_ENV: {
                        $d = $code[$pc++];
                        $e = $env;
                        while ($d--) {
                            $e = $e->parent;
                        }
                        $e->slots[$code[$pc++]] = $stack[$sp - 1];
                        break;
                    }
                    case Op::GET_GLOBAL: {
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        if (null !== ($v = $g->props[$name] ?? null)) {
                            $stack[$sp++] = $v;
                        } else {
                            $stack[$sp++] = $this->globalGet($name);
                        }
                        break;
                    }
                    case Op::SET_GLOBAL:
                        $this->globalSet($consts[$code[$pc++]], $stack[$sp - 1], $strict);
                        break;
                    case Op::DECL_GLOBAL: {
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        if (!$g->hasOwn($name)) {
                            $g->defineOwnData($name, $und, JSObject::W | JSObject::E);
                        }
                        break;
                    }
                    case Op::TYPEOF_GLOBAL: {
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        $stack[$sp++] = $g->hasProperty($name)
                            ? TypeOps::typeofOp($g->get($name, $this))
                            : 'undefined';
                        break;
                    }
                    case Op::DEL_GLOBAL: {
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        $stack[$sp++] = $g->hasOwn($name) ? $g->deleteKey($name, $this, false) : true;
                        break;
                    }

                    case Op::GET_PROP: {
                        $key = $consts[$code[$pc++]];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSObject && null !== ($v = $obj->props[$key] ?? null)) {
                            $stack[$sp - 1] = $v;
                        } elseif ($obj instanceof JSArray && $key === 'length') {
                            $stack[$sp - 1] = $obj->length;
                        } else {
                            $stack[$sp - 1] = $this->getMember($obj, $key);
                        }
                        break;
                    }
                    case Op::SET_PROP: {
                        $key = $consts[$code[$pc++]];
                        $val = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSObject && $obj->descs === null && isset($obj->props[$key])
                            && !($obj instanceof JSArray && $key === 'length')) {
                            $obj->props[$key] = $val;
                        } else {
                            $this->setMember($obj, $key, $val, $strict);
                        }
                        $stack[$sp - 1] = $val;
                        break;
                    }
                    case Op::GET_ELEM: {
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSArray && is_int($key)) {
                            if (null !== ($v = $obj->elements[$key] ?? null)) {
                                $stack[$sp - 1] = $v;
                            } elseif (array_key_exists($key, $obj->elements)) {
                                $stack[$sp - 1] = null;
                            } else {
                                $stack[$sp - 1] = $this->getMember($obj, $key);
                            }
                        } else {
                            $stack[$sp - 1] = $this->getMember($obj, $key);
                        }
                        break;
                    }
                    case Op::SET_ELEM: {
                        $val = $stack[--$sp];
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSArray && is_int($key) && $key >= 0 && $key <= 4294967294
                            && $obj->extensible) {
                            $obj->elements[$key] = $val;
                            if ($key >= $obj->length) {
                                $obj->length = $key + 1;
                            }
                        } else {
                            $this->setMember($obj, $key, $val, $strict);
                        }
                        $stack[$sp - 1] = $val;
                        break;
                    }
                    case Op::DEL_ELEM: {
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $stack[$sp - 1] = $this->deleteMember($obj, $key, $strict);
                        break;
                    }
                    case Op::GET_METHOD: {
                        $key = $consts[$code[$pc++]];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSObject && null !== ($v = $obj->props[$key] ?? null)) {
                            $fn = $v;
                        } else {
                            $fn = $this->getMember($obj, $key);
                        }
                        $stack[$sp - 1] = $fn;
                        $stack[$sp++] = $obj;
                        break;
                    }
                    case Op::GET_METHOD_ELEM: {
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $stack[$sp - 1] = $this->getMember($obj, $key);
                        $stack[$sp++] = $obj;
                        break;
                    }
                    case Op::DEFINE_DATA: {
                        $key = $consts[$code[$pc++]];
                        $val = $stack[--$sp];
                        $stack[$sp - 1]->defineOwnData($key, $val);
                        break;
                    }
                    case Op::DEFINE_GETTER: {
                        $key = $consts[$code[$pc++]];
                        $fn = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $prev = $obj->descs[$key] ?? null;
                        $obj->defineOwnAccessor($key, $fn, $prev !== null && ($prev[2] & JSObject::ACCESSOR) ? $prev[1] : null, JSObject::E | JSObject::C);
                        break;
                    }
                    case Op::DEFINE_SETTER: {
                        $key = $consts[$code[$pc++]];
                        $fn = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $prev = $obj->descs[$key] ?? null;
                        $obj->defineOwnAccessor($key, $prev !== null && ($prev[2] & JSObject::ACCESSOR) ? $prev[0] : null, $fn, JSObject::E | JSObject::C);
                        break;
                    }

                    case Op::ADD: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a + $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = $a . $b;
                        } else {
                            $stack[$sp - 1] = TypeOps::add($this, $a, $b);
                        }
                        break;
                    }
                    case Op::SUB: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $a = Conversions::toNumber($this, $a);
                        }
                        if (!(is_int($b) || is_float($b))) {
                            $b = Conversions::toNumber($this, $b);
                        }
                        $stack[$sp - 1] = $a - $b;
                        break;
                    }
                    case Op::MUL: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $a = Conversions::toNumber($this, $a);
                        }
                        if (!(is_int($b) || is_float($b))) {
                            $b = Conversions::toNumber($this, $b);
                        }
                        $stack[$sp - 1] = $a * $b;
                        break;
                    }
                    case Op::DIV: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $a = Conversions::toNumber($this, $a);
                        }
                        if (!(is_int($b) || is_float($b))) {
                            $b = Conversions::toNumber($this, $b);
                        }
                        $stack[$sp - 1] = fdiv((float)$a, (float)$b);
                        break;
                    }
                    case Op::MOD: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (is_int($a) && is_int($b) && $b !== 0) {
                            // PHP % truncates toward zero, matching JS sign rules.
                            $stack[$sp - 1] = $a % $b;
                        } else {
                            if (!(is_int($a) || is_float($a))) {
                                $a = Conversions::toNumber($this, $a);
                            }
                            if (!(is_int($b) || is_float($b))) {
                                $b = Conversions::toNumber($this, $b);
                            }
                            $stack[$sp - 1] = fmod((float)$a, (float)$b);
                        }
                        break;
                    }
                    case Op::NEG: {
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $a = Conversions::toNumber($this, $a);
                        }
                        // Negating int 0 must produce -0, which only float has.
                        $stack[$sp - 1] = $a === 0 ? -0.0 : -$a;
                        break;
                    }
                    case Op::TONUM: {
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $stack[$sp - 1] = Conversions::toNumber($this, $a);
                        }
                        break;
                    }
                    case Op::NOT:
                        $stack[$sp - 1] = !Conversions::toBoolean($stack[$sp - 1]);
                        break;
                    case Op::BNOT: {
                        $a = $stack[$sp - 1];
                        $i = is_int($a) ? ((($a & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($this, $a);
                        $stack[$sp - 1] = ~$i;
                        break;
                    }
                    case Op::TYPEOF:
                        $stack[$sp - 1] = TypeOps::typeofOp($stack[$sp - 1]);
                        break;

                    case Op::BAND:
                    case Op::BOR:
                    case Op::BXOR:
                    case Op::SHL:
                    case Op::SHR:
                    case Op::USHR: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        $a = is_int($a) ? ((($a & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($this, $a);
                        $b = is_int($b) ? ((($b & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($this, $b);
                        $stack[$sp - 1] = match ($op) {
                            Op::BAND => $a & $b,
                            Op::BOR => $a | $b,
                            Op::BXOR => $a ^ $b,
                            Op::SHL => (((($a << ($b & 31)) & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000),
                            Op::SHR => $a >> ($b & 31),
                            Op::USHR => ($a & 0xFFFFFFFF) >> ($b & 31),
                        };
                        break;
                    }

                    case Op::EQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a == $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = $a === $b;
                        } else {
                            $stack[$sp - 1] = TypeOps::looseEquals($this, $a, $b);
                        }
                        break;
                    }
                    case Op::NEQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a != $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = $a !== $b;
                        } else {
                            $stack[$sp - 1] = !TypeOps::looseEquals($this, $a, $b);
                        }
                        break;
                    }
                    case Op::SEQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        $stack[$sp - 1] = (is_int($a) || is_float($a))
                            ? ((is_int($b) || is_float($b)) && $a == $b)
                            : $a === $b;
                        break;
                    }
                    case Op::SNEQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        $stack[$sp - 1] = !((is_int($a) || is_float($a))
                            ? ((is_int($b) || is_float($b)) && $a == $b)
                            : $a === $b);
                        break;
                    }
                    case Op::LT: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a < $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) < 0;
                        } else {
                            $stack[$sp - 1] = TypeOps::lessThan($this, $a, $b, true) ?? false;
                        }
                        break;
                    }
                    case Op::GT: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a > $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) > 0;
                        } else {
                            $stack[$sp - 1] = TypeOps::lessThan($this, $b, $a, false) ?? false;
                        }
                        break;
                    }
                    case Op::LE: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a <= $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) <= 0;
                        } else {
                            $r = TypeOps::lessThan($this, $b, $a, false);
                            $stack[$sp - 1] = $r === null ? false : !$r;
                        }
                        break;
                    }
                    case Op::GE: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a >= $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) >= 0;
                        } else {
                            $r = TypeOps::lessThan($this, $a, $b, true);
                            $stack[$sp - 1] = $r === null ? false : !$r;
                        }
                        break;
                    }
                    case Op::IN_OP: {
                        $obj = $stack[--$sp];
                        $stack[$sp - 1] = TypeOps::inOp($this, $stack[$sp - 1], $obj);
                        break;
                    }
                    case Op::INSTANCEOF: {
                        $ctor = $stack[--$sp];
                        $stack[$sp - 1] = TypeOps::instanceofOp($this, $stack[$sp - 1], $ctor);
                        break;
                    }

                    case Op::JMP:
                        $pc = $code[$pc];
                        break;
                    case Op::JT: {
                        $t = $code[$pc++];
                        $v = $stack[--$sp];
                        if ($v === true ? true : ($v === false ? false : Conversions::toBoolean($v))) {
                            $pc = $t;
                        }
                        break;
                    }
                    case Op::JF: {
                        $t = $code[$pc++];
                        $v = $stack[--$sp];
                        if (!($v === true ? true : ($v === false ? false : Conversions::toBoolean($v)))) {
                            $pc = $t;
                        }
                        break;
                    }
                    case Op::JT_KEEP: {
                        $t = $code[$pc++];
                        if (Conversions::toBoolean($stack[$sp - 1])) {
                            $pc = $t;
                        } else {
                            $sp--;
                        }
                        break;
                    }
                    case Op::JF_KEEP: {
                        $t = $code[$pc++];
                        if (!Conversions::toBoolean($stack[$sp - 1])) {
                            $pc = $t;
                        } else {
                            $sp--;
                        }
                        break;
                    }

                    case Op::CALL: {
                        $argc = $code[$pc++];
                        $funcPos = $sp - $argc - 2;
                        $func = $stack[$funcPos];
                        if ($func instanceof JSFunction) {
                            if ($fi + 1 >= self::MAX_FRAMES) {
                                $this->throwError('RangeError', 'Maximum call stack size exceeded');
                            }
                            $frame[self::F_PC] = $pc;
                            $ntpl = $func->template;
                            $nbase = $funcPos + 2;
                            $argsArr = null;
                            if ($ntpl['usesArgs']) {
                                $argsArr = [];
                                for ($i = 0; $i < $argc; $i++) {
                                    $argsArr[] = $stack[$nbase + $i];
                                }
                            }
                            $nlocals = $ntpl['nlocals'];
                            for ($i = ($argc < $ntpl['nparams'] ? $argc : $ntpl['nparams']); $i < $nlocals; $i++) {
                                $stack[$nbase + $i] = $und;
                            }
                            $sp = $nbase + $nlocals;
                            $thisVal = $stack[$funcPos + 1];
                            if (!$ntpl['strict']) {
                                if ($thisVal === null || $thisVal instanceof JSUndefined) {
                                    $thisVal = $realm->globalObject;
                                } elseif (!$thisVal instanceof JSObject) {
                                    $thisVal = Conversions::toObject($this, $thisVal);
                                }
                            }
                            $nenv = $ntpl['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv($func->env, $ntpl['nenv']) : $func->env;
                            $frames[] = [
                                $ntpl, $nbase, 0, $nenv, $thisVal,
                                $argsArr, [], $func, null, $funcPos, null,
                            ];
                            $fi++;
                            $frame = &$frames[$fi];
                            $tpl = $ntpl;
                            $code = $ntpl['code'];
                            $consts = $ntpl['consts'];
                            $strict = $ntpl['strict'];
                            $base = $nbase;
                            $env = $nenv;
                            $pc = 0;
                        } else {
                            $args = [];
                            for ($i = 0; $i < $argc; $i++) {
                                $args[] = $stack[$funcPos + 2 + $i];
                            }
                            $thisVal = $stack[$funcPos + 1];
                            $sp = $funcPos;
                            $this->sp = $sp;
                            $frame[self::F_PC] = $pc;
                            if ($func instanceof JSNativeFunction) {
                                $ret = (BuiltinRegistry::get($func->fnId))($this, $thisVal, $args, $func);
                            } elseif ($func instanceof JSBoundFunction) {
                                $ret = $this->invoke($func, $thisVal, $args);
                            } else {
                                $this->throwError('TypeError', TypeOps::typeofOp($func) . ' value is not a function');
                            }
                            $stack[$sp++] = $ret;
                        }
                        break;
                    }

                    case Op::NEW_OP: {
                        $argc = $code[$pc++];
                        $ctorPos = $sp - $argc - 1;
                        $ctor = $stack[$ctorPos];
                        if ($ctor instanceof JSFunction) {
                            if ($fi + 1 >= self::MAX_FRAMES) {
                                $this->throwError('RangeError', 'Maximum call stack size exceeded');
                            }
                            $proto = $ctor->get('prototype', $this);
                            $newObj = new JSObject($proto instanceof JSObject ? $proto : $realm->objectPrototype());
                            // Shift args up one cell to make room for the `this` slot,
                            // matching the CALL layout [func this args...].
                            for ($i = $sp; $i > $ctorPos + 1; $i--) {
                                $stack[$i] = $stack[$i - 1];
                            }
                            $sp++;
                            $stack[$ctorPos + 1] = $newObj;
                            $frame[self::F_PC] = $pc;
                            $ntpl = $ctor->template;
                            $nbase = $ctorPos + 2;
                            $argsArr = null;
                            if ($ntpl['usesArgs']) {
                                $argsArr = [];
                                for ($i = 0; $i < $argc; $i++) {
                                    $argsArr[] = $stack[$nbase + $i];
                                }
                            }
                            $nlocals = $ntpl['nlocals'];
                            for ($i = ($argc < $ntpl['nparams'] ? $argc : $ntpl['nparams']); $i < $nlocals; $i++) {
                                $stack[$nbase + $i] = $und;
                            }
                            $sp = $nbase + $nlocals;
                            $nenv = $ntpl['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv($ctor->env, $ntpl['nenv']) : $ctor->env;
                            $frames[] = [
                                $ntpl, $nbase, 0, $nenv, $newObj,
                                $argsArr, [], $ctor, $newObj, $ctorPos, null,
                            ];
                            $fi++;
                            $frame = &$frames[$fi];
                            $tpl = $ntpl;
                            $code = $ntpl['code'];
                            $consts = $ntpl['consts'];
                            $strict = $ntpl['strict'];
                            $base = $nbase;
                            $env = $nenv;
                            $pc = 0;
                        } else {
                            $args = [];
                            for ($i = 0; $i < $argc; $i++) {
                                $args[] = $stack[$ctorPos + 1 + $i];
                            }
                            $sp = $ctorPos;
                            $this->sp = $sp;
                            $frame[self::F_PC] = $pc;
                            $stack[$sp++] = $this->construct($ctor, $args);
                        }
                        break;
                    }

                    case Op::RETURN_UNDEF:
                        $stack[$sp++] = $und;
                        // fallthrough
                    case Op::RETURN: {
                        $ret = $stack[--$sp];
                        $old = array_pop($frames);
                        $fi--;
                        if ($old[self::F_CTOR] !== null && !($ret instanceof JSObject)) {
                            $ret = $old[self::F_CTOR];
                        }
                        if ($fi < $floor) {
                            $this->sp = $old[self::F_RETSP];
                            return $ret;
                        }
                        $frame = &$frames[$fi];
                        $tpl = $frame[self::F_TPL];
                        $code = $tpl['code'];
                        $consts = $tpl['consts'];
                        $strict = $tpl['strict'];
                        $base = $frame[self::F_BASE];
                        $env = $frame[self::F_ENV];
                        $pc = $frame[self::F_PC];
                        $sp = $old[self::F_RETSP];
                        $stack[$sp++] = $ret;
                        break;
                    }
                    case Op::SET_COMPLETION:
                        $this->completion = $stack[--$sp];
                        break;
                    case Op::RETURN_COMPLETION: {
                        $ret = $this->completion;
                        $old = array_pop($frames);
                        $fi--;
                        if ($fi < $floor) {
                            $this->sp = $old[self::F_RETSP];
                            return $ret;
                        }
                        $frame = &$frames[$fi];
                        $tpl = $frame[self::F_TPL];
                        $code = $tpl['code'];
                        $consts = $tpl['consts'];
                        $strict = $tpl['strict'];
                        $base = $frame[self::F_BASE];
                        $env = $frame[self::F_ENV];
                        $pc = $frame[self::F_PC];
                        $sp = $old[self::F_RETSP];
                        $stack[$sp++] = $ret;
                        break;
                    }

                    case Op::NEW_OBJECT:
                        $stack[$sp++] = new JSObject($realm->objectPrototype());
                        break;
                    case Op::NEW_ARRAY: {
                        $n = $code[$pc++];
                        $arr = new JSArray($realm->arrayPrototype());
                        $start = $sp - $n;
                        $hole = JSHole::$hole;
                        for ($i = 0; $i < $n; $i++) {
                            $v = $stack[$start + $i];
                            if ($v !== $hole) {
                                $arr->elements[$i] = $v;
                            }
                        }
                        $arr->length = $n;
                        $sp = $start;
                        $stack[$sp++] = $arr;
                        break;
                    }
                    case Op::NEW_FUNC:
                        $stack[$sp++] = new JSFunction($tpl['children'][$code[$pc++]], $env, $realm);
                        break;
                    case Op::NEW_REGEXP: {
                        $pattern = $consts[$code[$pc++]];
                        $flags = $consts[$code[$pc++]];
                        $stack[$sp++] = $realm->createRegExp($pattern, $flags);
                        break;
                    }
                    case Op::PUSH_THIS:
                        $stack[$sp++] = $frame[self::F_THIS];
                        break;
                    case Op::PUSH_CALLEE:
                        $stack[$sp++] = $frame[self::F_FUNC];
                        break;
                    case Op::ARGUMENTS: {
                        if ($frame[self::F_ARGSOBJ] === null) {
                            $args = $frame[self::F_ARGS] ?? [];
                            $obj = new JSObject($realm->objectPrototype());
                            $obj->className = 'Arguments';
                            foreach ($args ?? [] as $i => $v) {
                                $obj->props[$i] = $v;
                            }
                            $obj->defineOwnData('length', count($args ?? []), JSObject::W | JSObject::C);
                            if (!$strict && $frame[self::F_FUNC] !== null) {
                                $obj->defineOwnData('callee', $frame[self::F_FUNC], JSObject::W | JSObject::C);
                            }
                            $frame[self::F_ARGSOBJ] = $obj;
                        }
                        $stack[$sp++] = $frame[self::F_ARGSOBJ];
                        break;
                    }

                    case Op::THROW:
                        $exc = $stack[--$sp];
                        goto unwind;
                    case Op::TRY_ENTER:
                        $frame[self::F_HANDLERS][] = [$code[$pc++], $sp];
                        break;
                    case Op::TRY_LEAVE:
                        array_pop($frame[self::F_HANDLERS]);
                        break;

                    case Op::FORIN_INIT: {
                        $slot = $code[$pc++];
                        $v = $stack[--$sp];
                        if ($v === null || $v instanceof JSUndefined) {
                            $stack[$base + $slot] = [[], 0, null];
                        } else {
                            $o = Conversions::toObject($this, $v);
                            $seen = [];
                            $keys = [];
                            for ($p = $o; $p !== null; $p = $p->proto) {
                                foreach ($p->ownEnumerableKeys() as $k) {
                                    if (!isset($seen[$k])) {
                                        $seen[$k] = true;
                                        $keys[] = $k;
                                    }
                                }
                            }
                            $stack[$base + $slot] = [$keys, 0, $o];
                        }
                        break;
                    }
                    case Op::FORIN_NEXT: {
                        $slot = $code[$pc++];
                        $target = $code[$pc++];
                        $it = $stack[$base + $slot];
                        $n = count($it[0]);
                        $i = $it[1];
                        $pushed = false;
                        while ($i < $n) {
                            $k = $it[0][$i++];
                            // Properties deleted during iteration must be skipped.
                            if ($it[2] === null || $it[2]->hasProperty($k)) {
                                $stack[$sp++] = $k;
                                $pushed = true;
                                break;
                            }
                        }
                        $stack[$base + $slot][1] = $i;
                        if (!$pushed) {
                            $pc = $target;
                        }
                        break;
                    }

                    case Op::NOP:
                        break;

                    default:
                        throw new \LogicException('VM bug: unknown opcode ' . Op::name($op) . " at pc=" . ($pc - 1));
                }
                continue;
            } catch (JSThrowSignal $sig) {
                $exc = $sig->value;
            }

            unwind:
            $fi = count($frames) - 1;
            for (;;) {
                if ($fi < $floor) {
                    $retsp = $frames[$floor][self::F_RETSP];
                    array_splice($frames, $floor);
                    $this->sp = $retsp;
                    throw new JSThrowSignal($exc);
                }
                if (!empty($frames[$fi][self::F_HANDLERS])) {
                    break;
                }
                $fi--;
            }
            if ($fi < count($frames) - 1) {
                array_splice($frames, $fi + 1);
            }
            $frame = &$frames[$fi];
            $h = array_pop($frame[self::F_HANDLERS]);
            $tpl = $frame[self::F_TPL];
            $code = $tpl['code'];
            $consts = $tpl['consts'];
            $strict = $tpl['strict'];
            $base = $frame[self::F_BASE];
            $env = $frame[self::F_ENV];
            $pc = $h[0];
            $sp = $h[1];
            $stack[$sp++] = $exc;
        }
    }

    // ---- Slow-path member access -------------------------------------------

    public function getMember(mixed $obj, mixed $key): mixed
    {
        if ($obj instanceof JSObject) {
            return $obj->get($this->toKeyString($key), $this);
        }
        if (is_string($obj)) {
            if ($key === 'length') {
                return StringOps::length16($obj);
            }
            if (is_int($key)) {
                return StringOps::charAt($obj, $key) ?? JSUndefined::$undefined;
            }
            $k = $this->toKeyString($key);
            if ($k === 'length') {
                return StringOps::length16($obj);
            }
            $idx = JSArray::asIndex($k);
            if ($idx !== null) {
                return StringOps::charAt($obj, $idx) ?? JSUndefined::$undefined;
            }
            return $this->realm->stringPrototype()->get($k, $this, $obj);
        }
        if (is_int($obj) || is_float($obj)) {
            return $this->realm->numberPrototype()->get($this->toKeyString($key), $this, $obj);
        }
        if (is_bool($obj)) {
            return $this->realm->booleanPrototype()->get($this->toKeyString($key), $this, $obj);
        }
        $desc = is_string($key) || is_int($key) ? " '" . $key . "'" : '';
        $this->throwError('TypeError', 'Cannot read properties of '
            . ($obj === null ? 'null' : 'undefined') . " (reading$desc)");
    }

    public function setMember(mixed $obj, mixed $key, mixed $value, bool $strict): void
    {
        if ($obj instanceof JSObject) {
            $obj->set($this->toKeyString($key), $value, $this, $strict);
            return;
        }
        if ($obj === null || $obj instanceof JSUndefined) {
            $this->throwError('TypeError', 'Cannot set properties of ' . ($obj === null ? 'null' : 'undefined'));
        }
        // Assignment to properties of other primitives is a silent no-op
        // (strict mode: TypeError). Prototype setters are not consulted.
        if ($strict) {
            $this->throwError('TypeError', 'Cannot create property on primitive value');
        }
    }

    public function deleteMember(mixed $obj, mixed $key, bool $strict): bool
    {
        if ($obj instanceof JSObject) {
            return $obj->deleteKey($this->toKeyString($key), $this, $strict);
        }
        if ($obj === null || $obj instanceof JSUndefined) {
            $this->throwError('TypeError', 'Cannot convert ' . ($obj === null ? 'null' : 'undefined') . ' to object');
        }
        return true;
    }

    private function toKeyString(mixed $key): string
    {
        if (is_string($key)) {
            return $key;
        }
        if (is_int($key)) {
            return (string)$key;
        }
        return Conversions::toString($this, $key);
    }

    // ---- Globals -----------------------------------------------------------

    public function globalGet(string $name): mixed
    {
        $g = $this->realm->globalObject;
        if ($g->hasProperty($name)) {
            return $g->get($name, $this);
        }
        $this->throwError('ReferenceError', "$name is not defined");
    }

    public function globalSet(string $name, mixed $value, bool $strict): void
    {
        $g = $this->realm->globalObject;
        if ($strict && !$g->hasProperty($name)) {
            $this->throwError('ReferenceError', "$name is not defined");
        }
        $g->set($name, $value, $this, $strict);
    }

    // ---- Errors ------------------------------------------------------------

    /** @return never */
    public function throwError(string $kind, string $message): mixed
    {
        throw new JSThrowSignal($this->realm->createError($kind, $message));
    }

    public function throwValue(mixed $value): never
    {
        throw new JSThrowSignal($value);
    }

    /** Current JS stack description, innermost first (for Error.stack). */
    public function captureStack(): string
    {
        $lines = [];
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            $f = $this->frames[$i];
            $tpl = $f[self::F_TPL];
            $name = $tpl['name'] !== '' ? $tpl['name'] : '<anonymous>';
            $line = $this->lineForPc($tpl, $f[self::F_PC]);
            $lines[] = "    at $name" . ($line > 0 ? " (line $line)" : '');
        }
        return implode("\n", $lines);
    }

    private function lineForPc(array $tpl, int $pc): int
    {
        $line = 0;
        foreach ($tpl['lines'] as $entry) {
            if ($entry[0] > $pc) {
                break;
            }
            $line = $entry[1];
        }
        return $line;
    }
}
