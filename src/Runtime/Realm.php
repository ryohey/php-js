<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Builtins\ArrayBufferBuiltins;
use PhpJs\Builtins\ArrayBuiltins;
use PhpJs\Builtins\BooleanBuiltins;
use PhpJs\Builtins\ConsoleBuiltins;
use PhpJs\Builtins\DataViewBuiltins;
use PhpJs\Builtins\DateBuiltins;
use PhpJs\Builtins\ErrorBuiltins;
use PhpJs\Builtins\FunctionBuiltins;
use PhpJs\Builtins\IteratorBuiltins;
use PhpJs\Builtins\JsonBuiltins;
use PhpJs\Builtins\MathBuiltins;
use PhpJs\Builtins\NumberBuiltins;
use PhpJs\Builtins\ObjectBuiltins;
use PhpJs\Builtins\PromiseBuiltins;
use PhpJs\Builtins\RegExpBuiltins;
use PhpJs\Builtins\StringBuiltins;
use PhpJs\Builtins\SymbolBuiltins;
use PhpJs\Builtins\TypedArrayBuiltins;
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
    /**
     * Handle for whatever host layer owns this realm (a module loader, a
     * request context). Host-side only: it is never reachable from a JSObject,
     * so it does not violate the snapshot constraints of §11.3.
     */
    public mixed $hostContext = null;

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
        'Math', 'JSON', 'console', 'Symbol',
        'Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'EvalError', 'URIError',
        'RegExp', 'Date', 'Promise',
        'ArrayBuffer', 'DataView',
        'Int8Array', 'Uint8Array', 'Uint8ClampedArray', 'Int16Array', 'Uint16Array',
        'Int32Array', 'Uint32Array', 'Float32Array', 'Float64Array',
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
            'Symbol' => $this->symbolConstructor(),
            'Math' => $this->mathObject(),
            'JSON' => $this->jsonObject(),
            'console' => $this->consoleObject(),
            'Error', 'TypeError', 'RangeError', 'ReferenceError',
            'SyntaxError', 'EvalError', 'URIError' => $this->errorConstructor($name),
            'RegExp' => $this->regexpConstructor(),
            'Date' => $this->dateConstructor(),
            'Promise' => $this->promiseConstructor(),
            'ArrayBuffer' => $this->arrayBufferConstructor(),
            'DataView' => $this->dataViewConstructor(),
            'Int8Array' => $this->typedArrayConstructor('Int8'),
            'Uint8Array' => $this->typedArrayConstructor('Uint8'),
            'Uint8ClampedArray' => $this->typedArrayConstructor('Uint8Clamped'),
            'Int16Array' => $this->typedArrayConstructor('Int16'),
            'Uint16Array' => $this->typedArrayConstructor('Uint16'),
            'Int32Array' => $this->typedArrayConstructor('Int32'),
            'Uint32Array' => $this->typedArrayConstructor('Uint32'),
            'Float32Array' => $this->typedArrayConstructor('Float32'),
            'Float64Array' => $this->typedArrayConstructor('Float64'),
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

    /**
     * GetTemplateObject's cache (13.2.8.3), keyed by a string the compiler
     * assigns each tagged-template call site: unique within its compilation
     * unit and stable across every evaluation of that site, so a loop that
     * runs the same tag hands it the same array identity every time. Per
     * realm rather than static, like the symbol tables above -- two realms in
     * one process must not share the cache (DESIGN.md §11), and a fresh
     * request gets a fresh one.
     *
     * @var array<string, JSArray>
     */
    private array $templateObjectCache = [];

    /**
     * Build (once) or return (thereafter) the frozen `[cooked...]` array with
     * a frozen `raw` array attached, for `$key`.
     *
     * `$cooked` entries are `string|null`; `null` is a malformed escape in a
     * tagged template, whose cooked value the spec requires to be `undefined`
     * (12.9.6) while `$raw` still carries the literal text.
     *
     * @param list<string|null> $cooked
     * @param list<string>      $raw
     */
    public function templateObject(string $key, array $cooked, array $raw): JSArray
    {
        if (isset($this->templateObjectCache[$key])) {
            return $this->templateObjectCache[$key];
        }
        $rawArr = $this->newArray($raw);
        ObjectBuiltins::freeze($this->vm, JSUndefined::$undefined, [$rawArr]);

        $und = JSUndefined::$undefined;
        $obj = $this->newArray(array_map(static fn ($v) => $v ?? $und, $cooked));
        // Not writable, not enumerable, not configurable (13.2.8.3 step 8) --
        // own data, not the array elements DEFINE_DATA on cooked values uses.
        $obj->defineOwnData('raw', $rawArr, 0);
        ObjectBuiltins::freeze($this->vm, JSUndefined::$undefined, [$obj]);

        return $this->templateObjectCache[$key] = $obj;
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

    /** $pcre is the pre-translated form when it came from a literal. */
    public function createRegExp(string $pattern, string $flags, ?string $pcre = null): JSObject
    {
        return RegExpBuiltins::create($this, $pattern, $flags, $pcre);
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

    public function symbolPrototype(): JSObject
    {
        if (!isset($this->memo['Symbol.prototype'])) {
            $proto = new JSObject($this->objectPrototype());
            $proto->className = 'Symbol';
            $this->memo['Symbol.prototype'] = $proto;
            SymbolBuiltins::populateProto($this, $proto);
            $this->symbolConstructor();
        }
        return $this->memo['Symbol.prototype'];
    }

    public function symbolConstructor(): JSNativeFunction
    {
        return $this->memo['Symbol'] ??= SymbolBuiltins::makeConstructor($this);
    }

    /**
     * Symbols by their private property key, so `Object.getOwnPropertySymbols`
     * can turn a property table's keys back into the symbols that made them.
     *
     * Per realm rather than static: a symbol is heap state, and two realms in
     * one process must not share one (DESIGN.md §11).
     * @var array<string, JSSymbol>
     */
    private array $symbolsByKey = [];
    /** @var array<string, JSSymbol> the `Symbol.for` registry */
    private array $symbolRegistry = [];

    public function newSymbol(?string $description, ?string $registryKey = null): JSSymbol
    {
        $symbol = new JSSymbol($description, $registryKey);
        $this->symbolsByKey[$symbol->propertyKey] = $symbol;
        return $symbol;
    }

    public function symbolForKey(string $key): JSSymbol
    {
        return $this->symbolRegistry[$key] ??= $this->newSymbol($key, $key);
    }

    /**
     * %IteratorPrototype% — the shared ancestor that makes every iterator
     * iterable, so `for (const x of arr.values())` works.
     */
    public function iteratorPrototype(): JSObject
    {
        if (!isset($this->memo['%IteratorPrototype%'])) {
            $proto = new JSObject($this->objectPrototype());
            $this->memo['%IteratorPrototype%'] = $proto;
            IteratorBuiltins::populateIteratorProto($this, $proto);
        }
        return $this->memo['%IteratorPrototype%'];
    }

    public function arrayIteratorPrototype(): JSObject
    {
        if (!isset($this->memo['%ArrayIteratorPrototype%'])) {
            $proto = new JSObject($this->iteratorPrototype());
            $this->memo['%ArrayIteratorPrototype%'] = $proto;
            IteratorBuiltins::populateArrayIteratorProto($this, $proto);
        }
        return $this->memo['%ArrayIteratorPrototype%'];
    }

    public function stringIteratorPrototype(): JSObject
    {
        if (!isset($this->memo['%StringIteratorPrototype%'])) {
            $proto = new JSObject($this->iteratorPrototype());
            $this->memo['%StringIteratorPrototype%'] = $proto;
            IteratorBuiltins::populateStringIteratorProto($this, $proto);
        }
        return $this->memo['%StringIteratorPrototype%'];
    }

    /**
     * %GeneratorPrototype% (27.5.1): `next`/`return`/`throw`, sitting on
     * %IteratorPrototype% so a generator is iterable through the same
     * `[Symbol.iterator]` every other iterator gets. Each generator
     * function's own (lazily materialized) `.prototype` object -- what a
     * generator instance actually inherits from -- points here, matching
     * how an ordinary function's `.prototype` points at
     * `%Object.prototype%` (`JSFunction::ensureOwn`).
     */
    public function generatorPrototype(): JSObject
    {
        if (!isset($this->memo['%GeneratorPrototype%'])) {
            $proto = new JSObject($this->iteratorPrototype());
            $this->memo['%GeneratorPrototype%'] = $proto;
            \PhpJs\Builtins\GeneratorBuiltins::populateGeneratorProto($this, $proto);
        }
        return $this->memo['%GeneratorPrototype%'];
    }

    /** `Symbol.iterator` and the rest; described in SymbolBuiltins. */
    public function wellKnownSymbol(string $name): JSSymbol
    {
        return $this->memo['@@' . $name] ??= $this->newSymbol('Symbol.' . $name);
    }

    /** The symbol a private property key came from, if this realm made it. */
    public function symbolByKey(string $propertyKey): ?JSSymbol
    {
        return $this->symbolsByKey[$propertyKey] ?? null;
    }

    /**
     * Prototype for host-provided collection iterators (node-compat's Map and
     * Set). Kept here because it is realm state and the host has nowhere else
     * to memoize it.
     */
    public function collectionIteratorPrototype(): JSObject
    {
        return $this->memo['CollectionIterator.prototype'] ??= new JSObject($this->objectPrototype());
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

    public function arrayBufferPrototype(): JSObject
    {
        if (!isset($this->memo['ArrayBuffer.prototype'])) {
            $proto = new JSObject($this->objectPrototype());
            $proto->nativeId = 'ArrayBuffer.prototype';
            $this->memo['ArrayBuffer.prototype'] = $proto;
            ArrayBufferBuiltins::populateProto($this, $proto);
            $this->arrayBufferConstructor();
        }
        return $this->memo['ArrayBuffer.prototype'];
    }

    public function arrayBufferConstructor(): JSNativeFunction
    {
        return $this->memo['ArrayBuffer'] ??= ArrayBufferBuiltins::makeConstructor($this);
    }

    public function dataViewPrototype(): JSObject
    {
        if (!isset($this->memo['DataView.prototype'])) {
            $proto = new JSObject($this->objectPrototype());
            $proto->nativeId = 'DataView.prototype';
            $this->memo['DataView.prototype'] = $proto;
            DataViewBuiltins::populateProto($this, $proto);
            $this->dataViewConstructor();
        }
        return $this->memo['DataView.prototype'];
    }

    public function dataViewConstructor(): JSNativeFunction
    {
        return $this->memo['DataView'] ??= DataViewBuiltins::makeConstructor($this);
    }

    /**
     * `%TypedArray%.prototype` -- the abstract intrinsic every concrete
     * kind's own prototype inherits from (`Int8Array.prototype.__proto__
     * === typedArrayPrototype()`). Not exposed under any global name of its
     * own; only reachable, same as the spec has it, by walking up from a
     * concrete kind.
     */
    public function typedArrayPrototype(): JSObject
    {
        if (!isset($this->memo['%TypedArray%.prototype'])) {
            $proto = new JSObject($this->objectPrototype());
            $proto->nativeId = '%TypedArray%.prototype';
            $this->memo['%TypedArray%.prototype'] = $proto;
            TypedArrayBuiltins::populateSharedProto($this, $proto);
        }
        return $this->memo['%TypedArray%.prototype'];
    }

    /**
     * `%TypedArray%` -- the abstract intrinsic constructor every concrete
     * kind's own constructor inherits from (`Object.getPrototypeOf(Int8Array)
     * === typedArrayConstructorAbstract()`), the same way each kind's
     * prototype inherits from `typedArrayPrototype()`. This is where `from`
     * and `of` live once, so every concrete constructor gets them through the
     * chain rather than each carrying its own copy. Calling or `new`-ing it
     * directly throws -- it exists only to be a common ancestor.
     */
    public function typedArrayConstructorAbstract(): JSNativeFunction
    {
        if (!isset($this->memo['%TypedArray%'])) {
            // A ctorId, not null: %TypedArray% has an abstract [[Construct]]
            // slot per 23.2.1.1, so IsConstructor(%TypedArray%) is true --
            // %TypedArray%.from.call(%TypedArray%, ...) must get past that
            // check before the "abstract, cannot instantiate" TypeError this
            // ctorId itself throws when actually invoked.
            $ctor = $this->nativeFn('%TypedArray%', 'TypedArray', 0, '%TypedArray%.ctor');
            $ctor->nativeId = '%TypedArray%';
            $this->memo['%TypedArray%'] = $ctor;
            $this->linkPair($ctor, $this->typedArrayPrototype());
            TypedArrayBuiltins::populateAbstractConstructor($this, $ctor);
        }
        return $this->memo['%TypedArray%'];
    }

    /** @param 'Int8'|'Uint8'|'Uint8Clamped'|'Int16'|'Uint16'|'Int32'|'Uint32'|'Float32'|'Float64' $kind */
    public function typedArrayKindPrototype(string $kind): JSObject
    {
        if (!isset($this->memo["{$kind}Array.prototype"])) {
            TypedArrayBuiltins::makePair($this, $kind);
        }
        return $this->memo["{$kind}Array.prototype"];
    }

    /** @param 'Int8'|'Uint8'|'Uint8Clamped'|'Int16'|'Uint16'|'Int32'|'Uint32'|'Float32'|'Float64' $kind */
    public function typedArrayConstructor(string $kind): JSNativeFunction
    {
        if (!isset($this->memo["{$kind}Array"])) {
            TypedArrayBuiltins::makePair($this, $kind);
        }
        return $this->memo["{$kind}Array"];
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
