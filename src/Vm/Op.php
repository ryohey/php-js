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
