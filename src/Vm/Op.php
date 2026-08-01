<?php

declare(strict_types=1);

namespace PhpJs\Vm;

/**
 * Bytecode opcodes. The code stream is a flat int array: an opcode followed by
 * its fixed number of int operands. Operand kinds (see self::OPERANDS):
 *   k = constant-pool index, i = int immediate, n = local slot,
 *   a = absolute jump target (pc), d = environment depth, e = env slot,
 *   c = argument count, f = child function-template index, t = temp local slot.
 */
final class Op
{
    // Constants / stack
    public const PUSH_CONST = 1;   // k
    public const PUSH_INT = 2;     // i
    public const PUSH_TRUE = 3;
    public const PUSH_FALSE = 4;
    public const PUSH_NULL = 5;
    public const PUSH_UNDEF = 6;
    public const PUSH_HOLE = 7;    // array-literal elision marker
    public const DUP = 8;
    public const DUP2 = 9;
    public const POP = 10;
    public const SWAP = 11;

    // Variables
    public const GET_LOCAL = 12;   // n
    public const SET_LOCAL = 13;   // n (keeps value on stack)
    public const GET_ENV = 14;     // d e
    public const SET_ENV = 15;     // d e (keeps value on stack)
    public const GET_GLOBAL = 16;  // k
    public const SET_GLOBAL = 17;  // k (keeps value on stack)
    public const DECL_GLOBAL = 18; // k
    public const TYPEOF_GLOBAL = 19; // k
    public const DEL_GLOBAL = 20;  // k

    // Properties
    public const GET_PROP = 21;    // k   [obj] -> [value]
    public const SET_PROP = 22;    // k   [obj value] -> [value]
    public const GET_ELEM = 23;    //     [obj key] -> [value]
    public const SET_ELEM = 24;    //     [obj key value] -> [value]
    public const DEL_ELEM = 25;    //     [obj key] -> [bool]
    public const GET_METHOD = 26;  // k   [obj] -> [func obj]
    public const GET_METHOD_ELEM = 27; // [obj key] -> [func obj]
    public const DEFINE_DATA = 28; // k   [obj value] -> [obj]
    public const DEFINE_GETTER = 29; // k [obj func] -> [obj]
    public const DEFINE_SETTER = 30; // k [obj func] -> [obj]

    // Unary / binary operators
    public const ADD = 31;
    public const SUB = 32;
    public const MUL = 33;
    public const DIV = 34;
    public const MOD = 35;
    public const NEG = 36;
    public const TONUM = 37;       // unary plus / ToNumber
    /**
     * ToString, for a template literal's substitutions. Not the same as
     * `"" + x`: a template converts with ToString (string hint), while `+`
     * uses ToPrimitive with the default hint, and an object carrying both
     * valueOf and toString tells the two apart.
     */
    public const TOSTR = 88;
    /**
     * `n` -> the arguments from index n onward, as an array. The frame keeps
     * its raw argument list whenever a rest parameter is present, which is the
     * only thing that list is needed for once parameters have their slots.
     */
    public const REST_ARGS = 89;   // n
    /**
     * The temporal dead zone, as a value. A `let` or `const` slot holds this
     * from block entry until its declaration runs; JSHole is reused because it
     * is already the "no value here" marker for array holes and can never be a
     * JS value in its own right.
     */
    public const PUSH_TDZ = 90;
    /** Throws ReferenceError if the value on top is still the dead-zone marker. */
    public const TDZ_CHECK = 91;
    /** Assignment to a `const` binding: always a TypeError. */
    public const THROW_CONST = 92;
    /**
     * RequireObjectCoercible on the value on top, which it leaves in place.
     * Destructuring needs it as an opcode of its own because `const {} = null`
     * still throws with no property to read, and reading one anyway would be
     * observable through a getter.
     */
    public const REQ_COERCIBLE = 93;
    /**
     * CopyDataProperties for an object rest element: `n` -> pops n already
     * matched keys and the source, pushes a fresh object carrying the source's
     * remaining own enumerable properties. The keys are on the stack rather
     * than in the operand because a computed one is only known at run time.
     */
    public const COPY_REST = 94;   // n
    /**
     * ToPropertyKey on the value on top, in place. A computed key in a pattern
     * is converted once and then used twice -- to read the property and to
     * exclude it from a rest element -- and converting twice would run a
     * user-supplied `toString` twice.
     */
    public const TO_KEY = 95;
    /**
     * GetIterator: `[obj] -> [iterator, nextMethod]`.
     *
     * `next` is read once here rather than on every step, which is observable
     * through a getter or a mutated iterator. The two results stay on the stack
     * in call order, so a step is `GET_LOCAL next; GET_LOCAL iter; CALL 0` and
     * goes through the ordinary call path with no VM re-entry.
     */
    public const ITER_GET = 96;
    /**
     * IteratorStep + IteratorValue: `[result] -> [value]`, or jump to `a` when
     * the result says done. The result must be an Object, which is the check
     * that separates a broken iterator from an empty one.
     */
    public const ITER_NEXT = 97;  // a
    /**
     * IteratorClose: pops the iterator and calls its `return` method if it has
     * one. The operand is 1 while unwinding a throw, where an error from
     * `return` is swallowed in favour of the original (7.4.9).
     */
    public const ITER_CLOSE = 98; // q

    /**
     * An iterator record in a local slot, for array destructuring:
     * `[iterator, nextMethod, done]`. Held the way FORIN_INIT holds its key
     * list -- a VM-internal tuple in a frame slot, never on the JS heap.
     *
     * Destructuring needs the record rather than a bare pair because a pattern
     * that runs out mid-way keeps taking `undefined` without calling `next`
     * again, and the `done` flag is what remembers that.
     */
    public const ITER_REC = 99;   // t   [obj] -> []
    /** The next value, or undefined once done. */
    public const ITER_TAKE = 100; // t   [] -> [value]
    /** Everything left, as an array; marks the record done. */
    public const ITER_REST = 101; // t   [] -> [array]
    /** IteratorClose unless the record already finished; q=1 while unwinding. */
    public const ITER_FIN = 102;  // tq

    /** `[arr value] -> [arr]`: append at arr.length, a hole only bumping it. */
    public const ARR_APPEND = 103;
    /** `[arr iterable] -> [arr]`: iterate and append each value. */
    public const ARR_SPREAD = 104;
    /** `[obj source] -> [obj]`: CopyDataProperties for `{...src}`. */
    public const OBJ_SPREAD = 105;
    /** `[func this argsArray] -> [result]`, for a call carrying a spread. */
    public const CALL_SPREAD = 106;
    /** `[ctor argsArray] -> [result]`, for a `new` carrying a spread. */
    public const NEW_SPREAD = 107;
    /**
     * The computed-key forms of DEFINE_DATA/GETTER/SETTER, taking the key from
     * the stack: `[obj key value] -> [obj]`. `k` is a constant-pool index, and
     * a computed key is not known until it runs.
     */
    public const DEFINE_DATA_ELEM = 108;
    public const DEFINE_GETTER_ELEM = 109;
    public const DEFINE_SETTER_ELEM = 110;
    /**
     * GetTemplateObject (13.2.8.3): `[] -> [templateObject]`. `k1` is the
     * cache key (a string constant unique to this call site within its
     * compilation), `k2`/`k3` are the cooked and raw string arrays.
     *
     * The object is built once and kept in `Realm::$templateObjectCache`
     * keyed by `k1`, not recreated on every evaluation -- a loop that
     * evaluates the same tagged template site N times must hand the tag the
     * same array identity all N times (test262
     * language/expressions/tagged-template/cache-*). Building it fresh here
     * would be observably wrong, not just wasteful.
     */
    public const NEW_TAG_TEMPLATE = 111; // kkk

    // ---- Classes ----
    /**
     * ClassDefinitionEvaluation's constructor + prototype step (15.7.14):
     * `f` is the constructor's child template. `i` is 1 when the class has an
     * `extends` clause, 0 otherwise -- distinct from `extends null`, which
     * still pops a value (it just happens to be `null`).
     *
     * `[superOrNothing?] -> [ctor, proto]`. With `i=1`, pops the superclass
     * value (a constructor, or `null`) and builds `proto` from its
     * `.prototype` (or with no proto at all, for `extends null`), and sets the
     * constructor's own `[[Prototype]]` to the superclass itself -- the static
     * side of inheritance, `B.sm` resolving through `A.sm`. With `i=0`, `proto`
     * inherits from `%Object.prototype%` and the constructor keeps the
     * ordinary `%Function.prototype%` it was created with.
     *
     * `proto` is left on the stack because the class body's own DEFINE_METHOD
     * etc. need it, and re-reading `ctor.prototype` would cost a property
     * lookup for a value already in hand.
     */
    public const NEW_CLASS = 112; // fi
    /**
     * The computed and non-computed forms of defining a class method:
     * `[obj func] -> [obj]` / `[obj key func] -> [obj]`. Not DEFINE_DATA/_ELEM,
     * because a class method is non-enumerable (15.7.10) where an object
     * literal's is not -- `for…in` and `Object.keys` must not see it.
     */
    public const DEFINE_METHOD = 113;      // k
    public const DEFINE_METHOD_ELEM = 114; //
    /** The class-method forms of DEFINE_GETTER/SETTER: non-enumerable. */
    public const DEFINE_CLASS_GETTER = 115;      // k
    public const DEFINE_CLASS_SETTER = 116;      // k
    public const DEFINE_CLASS_GETTER_ELEM = 117; //
    public const DEFINE_CLASS_SETTER_ELEM = 118; //
    /**
     * Sets `[[HomeObject]]` (15.7.9): `[func home] -> [func]`. Follows the
     * NEW_FUNC that built a class method, before it is attached to the
     * prototype/constructor -- `super.prop` inside that method resolves
     * against `home`'s own `[[Prototype]]`, read back off the running
     * function at evaluation time (see GET_SUPER).
     */
    public const SET_HOME_OBJECT = 119;
    /**
     * `super.prop` / `super[expr]` (13.3.7.1, 13.3.7.2): `[] -> [value]` /
     * `[key] -> [value]`. Reads the current frame's function's
     * `[[HomeObject]]`, gets *its* `[[Prototype]]`, and reads `key` there with
     * the current `this` as receiver -- not `home` itself, which is why this
     * cannot be GET_PROP on a value already in hand.
     */
    public const GET_SUPER = 120;      // k
    public const GET_SUPER_ELEM = 121; //
    /** Same lookup, packaged as `[func, this]` for a call: `super.method(...)`. */
    public const GET_SUPER_METHOD = 122;      // k
    public const GET_SUPER_METHOD_ELEM = 123; //
    /**
     * `super(...)` (13.3.7.1's SuperCall): `[parentCtor this args...] -> [this]`.
     * Not CALL, because a class constructor refuses ordinary `[[Call]]` --
     * this invokes it the way `new` does, against the `this` already built by
     * the derived class's own construction, and yields that `this` rather than
     * the callee's return value (which SuperCall's spec algorithm also
     * discards in the common case).
     */
    public const SUPER_CALL = 124; // c
    /** SUPER_CALL's CALL_SPREAD counterpart: `[parentCtor this argsArray] -> [this]`. */
    public const SUPER_CALL_SPREAD = 125;

    /**
     * `yield expr` (13.3.8), and the "yield one value out" primitive `yield*`
     * compiles its delegation loop around: `nt` -> `[value] -> []`.
     *
     * Pops the value being yielded and suspends the frame -- captures its
     * live locals/operand-stack region, pc, environment and (base-relative,
     * so they replay at whatever base the next resume happens to land on)
     * exception-handler table, then hands control back to whichever of
     * `next`/`throw`/`return` resumes it later (`GeneratorBuiltins`, via
     * `Vm::resumeGenerator()`).
     *
     * A resume never pushes onto the operand stack: it writes the sent value
     * into local `n` and a mode tag (0=next, 1=throw, 2=return -- `YIELD_*`
     * below) into local `t`, then continues at the saved pc. The few
     * instructions immediately following a YIELD are always the same fixed
     * shape (see Compiler::genYield): branch on the mode, `THROW` the sent
     * value, or run this yield's enclosing finalizers and `RETURN` it, or
     * (the ordinary case) just read it as the expression's value. `yield*`
     * uses the identical primitive but reads the mode itself instead of
     * letting this fixed shape act on it, to decide whether to forward to
     * the inner iterator's `next`/`throw`/`return`.
     */
    public const YIELD = 126; // nt

    /**
     * Resume-mode tags written to YIELD's `t` operand slot by a resume.
     * Deliberately outside the 1..126 opcode range: `Op::name()` reflects
     * every public int constant on this class, and a value colliding with a
     * real opcode number would corrupt that lookup.
     */
    public const YIELD_NEXT = 1000;
    public const YIELD_THROW = 1001;
    public const YIELD_RETURN = 1002;

    /**
     * `yield* expr`'s one call into the inner iterable per delegation-loop
     * pass (13.3.8.1): `[iter, next, mode, sentValue] -> [value, done]`.
     *
     * `next` is the method GetIterator captured once (like `ITER_GET`); the
     * mode selects between calling it, or looking up `throw`/`return` on
     * `iter` fresh (per spec, unlike `next` those are re-fetched every
     * pass). Missing `throw` closes the inner iterator and raises the
     * protocol-violation TypeError (13.3.8.1's Note); missing `return`
     * completes the delegation with `sentValue` directly. `done` decides,
     * back in the compiled loop, whether to yield `value` out and continue
     * or -- only when `mode` was RETURN -- treat it as a `return` completion
     * propagating out through this yield*'s own enclosing finalizers.
     */
    public const YIELD_DELEGATE_STEP = 127;

    /**
     * SetFunctionName (10.2.11), for the one case its name is not already
     * baked into the function's template at compile time: a computed
     * property/method key on an object literal or a class, whose value is
     * not known until it runs. `k` is a prefix constant ("" / "get " /
     * "set ", known at compile time even when the key is not).
     *
     * `[value, key] -> [value]`. `key` is the same already-`TO_KEY`-converted
     * string `DEFINE_METHOD_ELEM`/`DEFINE_DATA_ELEM`/etc. will use, not the
     * raw pre-conversion value -- a symbol's conversion is reversed back to
     * the original through `Realm::symbolByKey()` to recover its
     * `[[Description]]` for the bracketed `"[desc]"` form the spec asks for
     * (or `""`, for a symbol with no description; never the private storage
     * string that conversion produced). Every other trigger (a `var`/`let`
     * declarator, a plain assignment to an identifier, a parameter default,
     * a non-computed property key) has a syntactically static name and
     * needs no opcode at all -- `Compiler::analyzeMaybeNamed` threads it
     * straight into the child function's template.
     */
    public const SET_FUNC_NAME = 128; // k

    /**
     * Per-iteration loop bindings (CreatePerIterationEnvironment, 14.7.4.3),
     * for a loop with a `let`/`const`/`class` binding a closure inside it
     * captures -- the one place this VM creates an environment record
     * anywhere but a function call. Three opcodes, used together by
     * `Compiler::genLoopIterEnv`/`emitExitCleanup`:
     *
     * `CAPTURE_ENV n` -- `[] -> []`. Stashes the *current* environment (the
     * loop's own outer scope, unchanged for the loop's whole lifetime) into
     * local `n`, once, before the first per-iteration environment replaces
     * it as current. Every later per-iteration environment is created as a
     * *sibling* of the previous one, not a child of it -- chaining them
     * would grow the parent chain without bound over a long-running loop,
     * and every iteration's environment needs the same depth relative to
     * whatever is outside the loop regardless of how many have run -- so
     * this same stashed reference, not "whatever is current", is `NEW_ITER_ENV`'s
     * parent on every call.
     *
     * `NEW_ITER_ENV n, i` -- `[] -> []`. Replaces the current environment
     * with a fresh one of `i` slots, parented on local `n` (what `CAPTURE_ENV`
     * stashed). The old one is not reachable from here afterward, but stays
     * alive through any closure that already captured it -- that is the
     * entire point.
     *
     * `RESTORE_ENV` -- `[env] -> []`. Pops an environment reference (what
     * `CAPTURE_ENV` stashed, read back with `GET_LOCAL`) and makes it current
     * again. Emitted at every statically-known exit from the loop
     * (`emitExitCleanup`, the same mechanism a `for…of` loop's iterator-close
     * already uses to unwind on `break`/`return`) -- an exception unwinding
     * through instead needs no opcode of its own, because `TRY_ENTER` now
     * captures the environment live at that point in its handler-table entry
     * and the unwind path restores exactly that, which already used to be
     * true for `sp` and just needed doing for `env` too.
     */
    public const CAPTURE_ENV = 129;  // n
    public const NEW_ITER_ENV = 130; // ni
    public const RESTORE_ENV = 131;  //

    public const NOT = 38;
    public const BNOT = 39;
    public const TYPEOF = 40;
    public const BAND = 41;
    public const BOR = 42;
    public const BXOR = 43;
    public const SHL = 44;
    public const SHR = 45;
    public const USHR = 46;
    public const EQ = 47;
    public const NEQ = 48;
    public const SEQ = 49;
    public const SNEQ = 50;
    public const LT = 51;
    public const LE = 52;
    public const GT = 53;
    public const GE = 54;
    public const IN_OP = 55;
    public const INSTANCEOF = 56;

    // Control flow
    public const JMP = 57;         // a
    public const JT = 58;          // a (pops)
    public const JF = 59;          // a (pops)
    public const JT_KEEP = 60;     // a (jumps keeping value, else pops)
    public const JF_KEEP = 61;     // a (jumps keeping value, else pops)

    // Calls
    public const CALL = 62;        // c   [func this args...] -> [result]
    public const NEW_OP = 63;      // c   [ctor args...] -> [result]
    public const RETURN = 64;
    public const RETURN_UNDEF = 65;
    public const SET_COMPLETION = 66;    // pops into the VM completion register
    public const RETURN_COMPLETION = 67; // returns the completion register

    // Object creation
    public const NEW_OBJECT = 68;
    public const NEW_ARRAY = 69;   // i (element count popped)
    public const NEW_FUNC = 70;    // f
    public const NEW_REGEXP = 71;  // k k k (pattern, flags, translated PCRE)
    public const PUSH_THIS = 72;
    public const PUSH_CALLEE = 73;
    public const ARGUMENTS = 74;

    // Exceptions
    public const THROW = 75;
    public const TRY_ENTER = 76;   // a (handler pc)
    public const TRY_LEAVE = 77;

    // Enumeration
    public const FORIN_INIT = 78;  // t   pops object, stores iterator in slot
    public const FORIN_NEXT = 79;  // t a pushes next key or jumps to a

    /**
     * Statement-position ++/-- on an uncaptured local. The generic form costs
     * eight instructions (load, ToNumber, dup, push, add, store, pop, pop);
     * loop counters are common enough to earn one.
     */
    public const INC_LOCAL = 80;  // n
    public const DEC_LOCAL = 81;  // n

    public const NOP = 82;

    /**
     * Superinstructions. Each collapses an adjacent pair that dominates real
     * workloads (see Compiler\Peephole for the pattern table and the measured
     * frequencies). They are pure fusions: the fused opcode does exactly what
     * the two it replaces did, in the same order, so nothing observable
     * changes and the pass can be turned off without affecting semantics.
     */
    public const STORE_LOCAL = 83;    // n    SET_LOCAL + POP
    public const GET_LOCAL_PROP = 84; // n k  GET_LOCAL + GET_PROP
    public const TYPEOF_LOCAL = 85;   // n    GET_LOCAL + TYPEOF
    public const JSEQ = 86;           // a    SEQ + JT
    public const JSNEQ = 87;          // a    SEQ + JF

    /** Operand kind string per opcode (one char per operand). */
    public const OPERANDS = [
        self::PUSH_CONST => 'k', self::PUSH_INT => 'i',
        self::PUSH_TRUE => '', self::PUSH_FALSE => '', self::PUSH_NULL => '',
        self::PUSH_UNDEF => '', self::PUSH_HOLE => '',
        self::DUP => '', self::DUP2 => '', self::POP => '', self::SWAP => '',
        self::GET_LOCAL => 'n', self::SET_LOCAL => 'n',
        self::GET_ENV => 'de', self::SET_ENV => 'de',
        self::GET_GLOBAL => 'k', self::SET_GLOBAL => 'k', self::DECL_GLOBAL => 'k',
        self::TYPEOF_GLOBAL => 'k', self::DEL_GLOBAL => 'k',
        self::GET_PROP => 'k', self::SET_PROP => 'k',
        self::GET_ELEM => '', self::SET_ELEM => '', self::DEL_ELEM => '',
        self::GET_METHOD => 'k', self::GET_METHOD_ELEM => '',
        self::DEFINE_DATA => 'k', self::DEFINE_GETTER => 'k', self::DEFINE_SETTER => 'k',
        self::ADD => '', self::SUB => '', self::MUL => '', self::DIV => '', self::MOD => '',
        self::NEG => '', self::TONUM => '', self::NOT => '', self::BNOT => '', self::TYPEOF => '',
        self::TOSTR => '',
        self::REST_ARGS => 'n',
        self::PUSH_TDZ => '', self::TDZ_CHECK => 'k', self::THROW_CONST => '',
        self::REQ_COERCIBLE => '', self::COPY_REST => 'n', self::TO_KEY => '',
        self::ITER_GET => '', self::ITER_NEXT => 'a', self::ITER_CLOSE => 'n',
        self::ITER_REC => 't', self::ITER_TAKE => 't', self::ITER_REST => 't',
        self::ITER_FIN => 'tn',
        self::ARR_APPEND => '', self::ARR_SPREAD => '', self::OBJ_SPREAD => '',
        self::CALL_SPREAD => '', self::NEW_SPREAD => '',
        self::DEFINE_DATA_ELEM => '', self::DEFINE_GETTER_ELEM => '',
        self::DEFINE_SETTER_ELEM => '',
        self::NEW_TAG_TEMPLATE => 'kkk',
        self::NEW_CLASS => 'fi',
        self::DEFINE_METHOD => 'k', self::DEFINE_METHOD_ELEM => '',
        self::DEFINE_CLASS_GETTER => 'k', self::DEFINE_CLASS_SETTER => 'k',
        self::DEFINE_CLASS_GETTER_ELEM => '', self::DEFINE_CLASS_SETTER_ELEM => '',
        self::SET_HOME_OBJECT => '',
        self::GET_SUPER => 'k', self::GET_SUPER_ELEM => '',
        self::GET_SUPER_METHOD => 'k', self::GET_SUPER_METHOD_ELEM => '',
        self::SUPER_CALL => 'c', self::SUPER_CALL_SPREAD => '',
        self::YIELD => 'nn', self::YIELD_DELEGATE_STEP => '',
        self::SET_FUNC_NAME => 'k',
        self::CAPTURE_ENV => 'n', self::NEW_ITER_ENV => 'ni', self::RESTORE_ENV => '',
        self::BAND => '', self::BOR => '', self::BXOR => '',
        self::SHL => '', self::SHR => '', self::USHR => '',
        self::EQ => '', self::NEQ => '', self::SEQ => '', self::SNEQ => '',
        self::LT => '', self::LE => '', self::GT => '', self::GE => '',
        self::IN_OP => '', self::INSTANCEOF => '',
        self::JMP => 'a', self::JT => 'a', self::JF => 'a',
        self::JT_KEEP => 'a', self::JF_KEEP => 'a',
        self::CALL => 'c', self::NEW_OP => 'c',
        self::RETURN => '', self::RETURN_UNDEF => '',
        self::SET_COMPLETION => '', self::RETURN_COMPLETION => '',
        self::NEW_OBJECT => '', self::NEW_ARRAY => 'i', self::NEW_FUNC => 'f',
        self::NEW_REGEXP => 'kkk', self::PUSH_THIS => '', self::PUSH_CALLEE => '',
        self::ARGUMENTS => '',
        self::THROW => '', self::TRY_ENTER => 'a', self::TRY_LEAVE => '',
        self::FORIN_INIT => 't', self::FORIN_NEXT => 'ta',
        self::INC_LOCAL => 'n', self::DEC_LOCAL => 'n',
        self::NOP => '',
        self::STORE_LOCAL => 'n', self::GET_LOCAL_PROP => 'nk',
        self::TYPEOF_LOCAL => 'n',
        self::JSEQ => 'a', self::JSNEQ => 'a',
    ];

    public static function name(int $op): string
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach ((new \ReflectionClass(self::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC) as $name => $value) {
                if (is_int($value)) {
                    $names[$value] = $name;
                }
            }
        }
        return $names[$op] ?? "OP($op)";
    }
}
