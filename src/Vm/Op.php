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
    public const NEW_REGEXP = 71;  // k k (pattern, flags)
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

    public const NOP = 80;

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
        self::NEW_REGEXP => 'kk', self::PUSH_THIS => '', self::PUSH_CALLEE => '',
        self::ARGUMENTS => '',
        self::THROW => '', self::TRY_ENTER => 'a', self::TRY_LEAVE => '',
        self::FORIN_INIT => 't', self::FORIN_NEXT => 'ta',
        self::NOP => '',
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
