<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Builtins\ArrayBuiltins;
use PhpJs\Builtins\BooleanBuiltins;
use PhpJs\Builtins\ConsoleBuiltins;
use PhpJs\Builtins\DateBuiltins;
use PhpJs\Builtins\ErrorBuiltins;
use PhpJs\Builtins\FunctionBuiltins;
use PhpJs\Builtins\JsonBuiltins;
use PhpJs\Builtins\MathBuiltins;
use PhpJs\Builtins\NumberBuiltins;
use PhpJs\Builtins\ObjectBuiltins;
use PhpJs\Builtins\PromiseBuiltins;
use PhpJs\Builtins\RegExpBuiltins;
use PhpJs\Builtins\StringBuiltins;
use PhpJs\Vm\Vm;

/**
 * A realm: global object + builtins + job queue. Builtins are materialized
 * lazily — a request that never touches Array creates no Array objects
 * (DESIGN.md §11.2).
 */
final class Realm
{
    public JSGlobalObject $globalObject;
    public ?Vm $vm = null;

    /** @var list<array{0: mixed, 1: list<mixed>}> microtask queue: [callable, args] */
    public array $microtasks = [];
    /** @var list<mixed> promises rejected with no handler (checked after drain) */
    public array $unhandledRejections = [];

    /** Host-side output sink for console.* — not part of the JS heap. */
    public mixed $hostWriter = null;

    /** @var array<string, JSObject> memoized builtin containers */
    private array $memo = [];
    /**
     * Globals that have already been materialized. Lazy initialization must
     * happen exactly once per name: without this, deleting a builtin global
     * would resurrect it on the next property miss.
     *
     * @var array<string, true>
     */
    private array $materialized = [];

    private const GLOBAL_NAMES = [
        'undefined', 'NaN', 'Infinity', 'globalThis',
        'Object', 'Function', 'Array', 'String', 'Number', 'Boolean',
        'Math', 'JSON', 'console',
        'Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'EvalError', 'URIError',
        'RegExp', 'Date', 'Promise',
        'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'eval',
        'encodeURIComponent', 'decodeURIComponent', 'encodeURI', 'decodeURI',
    ];

    public function __construct()
    {
        JSUndefined::init();
        JSHole::init();
        $this->globalObject = new JSGlobalObject();
        $this->globalObject->realm = $this;
        $this->globalObject->proto = $this->objectPrototype();
        $this->globalObject->className = 'global';
    }

    // ---- Lazy global materialization ---------------------------------------

    public function materializeGlobal(string $name): bool
    {
        if (isset($this->materialized[$name])) {
            return false;
        }
        $g = $this->globalObject;
        $value = match ($name) {
            'undefined' => JSUndefined::$undefined,
            'NaN' => NAN,
            'Infinity' => INF,
            'globalThis' => $g,
            'Object' => $this->objectConstructor(),
            'Function' => $this->functionConstructor(),
            'Array' => $this->arrayConstructor(),
            'String' => $this->stringConstructor(),
            'Number' => $this->numberConstructor(),
            'Boolean' => $this->booleanConstructor(),
            'Math' => $this->mathObject(),
            'JSON' => $this->jsonObject(),
            'console' => $this->consoleObject(),
            'Error', 'TypeError', 'RangeError', 'ReferenceError',
            'SyntaxError', 'EvalError', 'URIError' => $this->errorConstructor($name),
            'RegExp' => $this->regexpConstructor(),
            'Date' => $this->dateConstructor(),
            'Promise' => $this->promiseConstructor(),
            'parseInt' => $this->nativeFn('global.parseInt', 'parseInt', 2),
            'parseFloat' => $this->nativeFn('global.parseFloat', 'parseFloat', 1),
            'isNaN' => $this->nativeFn('global.isNaN', 'isNaN', 1),
            'isFinite' => $this->nativeFn('global.isFinite', 'isFinite', 1),
            'eval' => $this->nativeFn('global.eval', 'eval', 1),
            'encodeURIComponent' => $this->nativeFn('global.encodeURIComponent', 'encodeURIComponent', 1),
            'decodeURIComponent' => $this->nativeFn('global.decodeURIComponent', 'decodeURIComponent', 1),
            'encodeURI' => $this->nativeFn('global.encodeURI', 'encodeURI', 1),
            'decodeURI' => $this->nativeFn('global.decodeURI', 'decodeURI', 1),
            default => '__miss__',
        };
        if ($value === '__miss__' && $name !== '__miss__') {
            return false;
        }
        $this->materialized[$name] = true;
        $flags = match ($name) {
            'undefined', 'NaN', 'Infinity' => 0,
            default => JSObject::W | JSObject::C,
        };
        $g->defineOwnData($name, $value, $flags);
        return true;
    }

    public function materializeAllGlobals(): void
    {
        foreach (self::GLOBAL_NAMES as $name) {
            $this->materializeGlobal($name);
        }
    }

    // ---- Factory helpers -----------------------------------------------------

    public function nativeFn(string $fnId, string $name, int $arity, ?string $ctorId = null, mixed $data = null): JSNativeFunction
    {
        return new JSNativeFunction($fnId, $name, $arity, $this->functionPrototype(), $ctorId, $data);
    }

    public function defineMethod(JSObject $obj, string $name, string $fnId, int $arity): void
    {
        $obj->defineOwnData($name, $this->nativeFn($fnId, $name, $arity), JSObject::W | JSObject::C);
    }

    public function newObject(): JSObject
    {
        return new JSObject($this->objectPrototype());
    }

    /** @param list<mixed> $values */
    public function newArray(array $values = []): JSArray
    {
        return JSArray::fromList($values, $this->arrayPrototype());
    }

    public function createError(string $kind, string $message): JSObject
    {
        $err = new JSObject($this->errorPrototype($kind));
        $err->className = 'Error';
        $err->defineOwnData('message', $message, JSObject::W | JSObject::C);
        if ($this->vm !== null) {
            $err->defineOwnData('stack', $kind . ': ' . $message . "\n" . $this->vm->captureStack(), JSObject::W | JSObject::C);
        }
        return $err;
    }

    public function createRegExp(string $pattern, string $flags): JSObject
    {
        return RegExpBuiltins::create($this, $pattern, $flags);
    }

    public function enqueueMicrotask(mixed $callable, array $args): void
    {
        $this->microtasks[] = [$callable, $args];
    }

    /** Drain the microtask queue (run-to-completion; DESIGN.md §9). */
    public function drainMicrotasks(Vm $vm): void
    {
        while ($this->microtasks !== []) {
            [$fn, $args] = array_shift($this->microtasks);
            $vm->invoke($fn, JSUndefined::$undefined, $args);
        }
    }

    // ---- Builtin containers (constructor/prototype pairs, built on demand) --

    public function objectPrototype(): JSObject
    {
        if (!isset($this->memo['Object.prototype'])) {
            $proto = new JSObject(null);
            $proto->nativeId = 'Object.prototype';
            $this->memo['Object.prototype'] = $proto;
            ObjectBuiltins::populateProto($this, $proto);
            $this->objectConstructor();
        }
        return $this->memo['Object.prototype'];
    }

    public function functionPrototype(): JSObject
    {
        if (!isset($this->memo['Function.prototype'])) {
            // Function.prototype is itself callable (returns undefined).
            $proto = new JSNativeFunction('Function.prototype', '', 0, $this->objectPrototype());
            $proto->nativeId = 'Function.prototype';
            $this->memo['Function.prototype'] = $proto;
            FunctionBuiltins::populateProto($this, $proto);
            $this->functionConstructor();
        }
        return $this->memo['Function.prototype'];
    }

    public function objectConstructor(): JSNativeFunction
    {
        return $this->memo['Object'] ??= ObjectBuiltins::makeConstructor($this);
    }

    public function functionConstructor(): JSNativeFunction
    {
        return $this->memo['Function'] ??= FunctionBuiltins::makeConstructor($this);
    }

    public function arrayPrototype(): JSObject
    {
        if (!isset($this->memo['Array.prototype'])) {
            $proto = new JSArray($this->objectPrototype());
            $proto->nativeId = 'Array.prototype';
            $this->memo['Array.prototype'] = $proto;
            ArrayBuiltins::populateProto($this, $proto);
            $this->arrayConstructor();
        }
        return $this->memo['Array.prototype'];
    }

    public function arrayConstructor(): JSNativeFunction
    {
        return $this->memo['Array'] ??= ArrayBuiltins::makeConstructor($this);
    }

    public function stringPrototype(): JSObject
    {
        if (!isset($this->memo['String.prototype'])) {
            $proto = new JSPrimitiveWrapper('', 'String', $this->objectPrototype());
            $proto->nativeId = 'String.prototype';
            $this->memo['String.prototype'] = $proto;
            StringBuiltins::populateProto($this, $proto);
            $this->stringConstructor();
        }
        return $this->memo['String.prototype'];
    }

    public function stringConstructor(): JSNativeFunction
    {
        return $this->memo['String'] ??= StringBuiltins::makeConstructor($this);
    }

    public function numberPrototype(): JSObject
    {
        if (!isset($this->memo['Number.prototype'])) {
            $proto = new JSPrimitiveWrapper(0, 'Number', $this->objectPrototype());
            $proto->nativeId = 'Number.prototype';
            $this->memo['Number.prototype'] = $proto;
            NumberBuiltins::populateProto($this, $proto);
            $this->numberConstructor();
        }
        return $this->memo['Number.prototype'];
    }

    public function numberConstructor(): JSNativeFunction
    {
        return $this->memo['Number'] ??= NumberBuiltins::makeConstructor($this);
    }

    public function booleanPrototype(): JSObject
    {
        if (!isset($this->memo['Boolean.prototype'])) {
            $proto = new JSPrimitiveWrapper(false, 'Boolean', $this->objectPrototype());
            $proto->nativeId = 'Boolean.prototype';
            $this->memo['Boolean.prototype'] = $proto;
            BooleanBuiltins::populateProto($this, $proto);
            $this->booleanConstructor();
        }
        return $this->memo['Boolean.prototype'];
    }

    public function booleanConstructor(): JSNativeFunction
    {
        return $this->memo['Boolean'] ??= BooleanBuiltins::makeConstructor($this);
    }

    public function mathObject(): JSObject
    {
        return $this->memo['Math'] ??= MathBuiltins::makeObject($this);
    }

    public function jsonObject(): JSObject
    {
        return $this->memo['JSON'] ??= JsonBuiltins::makeObject($this);
    }

    public function consoleObject(): JSObject
    {
        return $this->memo['console'] ??= ConsoleBuiltins::makeObject($this);
    }

    public function errorPrototype(string $kind = 'Error'): JSObject
    {
        if (!isset($this->memo["$kind.prototype"])) {
            ErrorBuiltins::makePair($this, $kind);
        }
        return $this->memo["$kind.prototype"];
    }

    public function errorConstructor(string $kind = 'Error'): JSNativeFunction
    {
        if (!isset($this->memo[$kind])) {
            ErrorBuiltins::makePair($this, $kind);
        }
        return $this->memo[$kind];
    }

    public function regexpPrototype(): JSObject
    {
        if (!isset($this->memo['RegExp.prototype'])) {
            $proto = new JSObject($this->objectPrototype());
            $proto->nativeId = 'RegExp.prototype';
            $this->memo['RegExp.prototype'] = $proto;
            RegExpBuiltins::populateProto($this, $proto);
            $this->regexpConstructor();
        }
        return $this->memo['RegExp.prototype'];
    }

    public function regexpConstructor(): JSNativeFunction
    {
        return $this->memo['RegExp'] ??= RegExpBuiltins::makeConstructor($this);
    }

    public function datePrototype(): JSObject
    {
        if (!isset($this->memo['Date.prototype'])) {
            $proto = new JSObject($this->objectPrototype());
            $proto->nativeId = 'Date.prototype';
            $proto->className = 'Date';
            $this->memo['Date.prototype'] = $proto;
            DateBuiltins::populateProto($this, $proto);
            $this->dateConstructor();
        }
        return $this->memo['Date.prototype'];
    }

    public function dateConstructor(): JSNativeFunction
    {
        return $this->memo['Date'] ??= DateBuiltins::makeConstructor($this);
    }

    public function promisePrototype(): JSObject
    {
        if (!isset($this->memo['Promise.prototype'])) {
            $proto = new JSObject($this->objectPrototype());
            $proto->nativeId = 'Promise.prototype';
            $this->memo['Promise.prototype'] = $proto;
            PromiseBuiltins::populateProto($this, $proto);
            $this->promiseConstructor();
        }
        return $this->memo['Promise.prototype'];
    }

    public function promiseConstructor(): JSNativeFunction
    {
        return $this->memo['Promise'] ??= PromiseBuiltins::makeConstructor($this);
    }

    /** Register a constructor/prototype pair built by a builtins class. */
    public function remember(string $key, JSObject $obj): void
    {
        $this->memo[$key] = $obj;
    }

    public function recall(string $key): ?JSObject
    {
        return $this->memo[$key] ?? null;
    }

    /** Link ctor.prototype and proto.constructor with spec attributes. */
    public function linkPair(JSNativeFunction $ctor, JSObject $proto): void
    {
        $ctor->defineOwnData('prototype', $proto, 0);
        $proto->defineOwnData('constructor', $ctor, JSObject::W | JSObject::C);
    }
}
