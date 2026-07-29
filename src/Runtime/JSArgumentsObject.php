<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * The mapped arguments exotic object (10.6). In non-strict code
 * `arguments[i]` and the i-th parameter are two views of one binding.
 *
 * The link is an environment record, not a VM frame: `JSEnv` is a heap value
 * the snapshot rules already allow, whereas holding a frame would put foreign
 * PHP state on the JS heap (DESIGN.md §11.3). The compiler therefore forces
 * the parameters of a non-strict function that mentions `arguments` into env
 * slots, and $map records which slot each index refers to.
 *
 * Strict functions get a plain JSObject instead: their arguments object is an
 * unmapped copy.
 */
final class JSArgumentsObject extends JSObject
{
    /** @var array<int, int> argument index => environment slot */
    public array $map = [];

    public function __construct(
        ?JSObject $proto,
        public ?JSEnv $env = null,
    ) {
        parent::__construct($proto);
        $this->className = 'Arguments';
        // A mapped index reads through the environment slot, which the
        // matching $props entry can lag behind after a parameter assignment.
        $this->ownPropsArePlain = false;
    }

    private function slotFor(string $key): ?int
    {
        if ($this->env === null || $this->map === []) {
            return null;
        }
        $idx = JSArray::asIndex($key);
        return ($idx !== null && isset($this->map[$idx])) ? $this->map[$idx] : null;
    }

    public function getOwn(string $key, Vm $vm, mixed $receiver, bool &$found): mixed
    {
        $slot = $this->slotFor($key);
        if ($slot !== null) {
            $found = true;
            return $this->env->slots[$slot];
        }
        return parent::getOwn($key, $vm, $receiver, $found);
    }

    public function set(string $key, mixed $value, Vm $vm, bool $strict): void
    {
        $slot = $this->slotFor($key);
        if ($slot !== null) {
            $this->env->slots[$slot] = $value;
            $this->props[$key] = $value;
            return;
        }
        parent::set($key, $value, $vm, $strict);
    }

    public function ownDescriptor(string $key): ?array
    {
        $slot = $this->slotFor($key);
        if ($slot !== null) {
            $flags = $this->descs[$key][2] ?? self::DEFAULT_ATTRS;
            return [$this->env->slots[$slot], null, $flags];
        }
        return parent::ownDescriptor($key);
    }

    public function defineOwnProperty(string $key, array $desc, Vm $vm, bool $throw = true): bool
    {
        $slot = $this->slotFor($key);
        if ($slot === null) {
            return parent::defineOwnProperty($key, $desc, $vm, $throw);
        }
        // Keep the shared value in sync, then drop the link if the redefinition
        // makes the two views distinguishable (accessor, or non-writable).
        if (\array_key_exists('value', $desc)) {
            $this->env->slots[$slot] = $desc['value'];
            $this->props[$key] = $desc['value'];
        } else {
            $this->props[$key] = $this->env->slots[$slot];
        }
        $ok = parent::defineOwnProperty($key, $desc, $vm, $throw);
        if ($ok && (\array_key_exists('get', $desc) || \array_key_exists('set', $desc)
            || (\array_key_exists('writable', $desc) && $desc['writable'] === false))) {
            $idx = JSArray::asIndex($key);
            unset($this->map[$idx]);
        }
        return $ok;
    }

    public function deleteKey(string $key, Vm $vm, bool $strict): bool
    {
        $slot = $this->slotFor($key);
        if ($slot !== null) {
            $idx = JSArray::asIndex($key);
            $deleted = parent::deleteKey($key, $vm, $strict);
            if ($deleted) {
                unset($this->map[$idx]);
            }
            return $deleted;
        }
        return parent::deleteKey($key, $vm, $strict);
    }
}
