<?php

declare(strict_types=1);

namespace PhpJs\Transpile\Tests;

use PhpJs\Engine;
use PhpJs\JSException;
use PhpJs\Transpile\Artifact;
use PhpJs\Transpile\NodeIntegration;
use PHPUnit\Framework\TestCase;

/**
 * What is lost when a function stops being interpreted.
 *
 * The README says generated PHP must come from trusted, pinned input, and gives
 * two reasons. Reasons in a README rot; these are the same two reasons as
 * executable assertions, so that if the emitter ever grows the guards back, this
 * is what says so.
 */
final class TrustBoundaryTest extends TestCase
{
    private static int $seq = 0;

    /** @return array{0: array<string, mixed>, 1: int} [template, converted] */
    private function build(string $source): array
    {
        $artifact = Artifact::build($source, 'trust' . (++self::$seq));
        $artifact->registerDirect();
        return [$artifact->template, $artifact->converted];
    }

    public function testTheInterpreterStopsARunawayLoop(): void
    {
        $engine = new Engine();
        $engine->vm->setTimeLimit(0.25);
        $started = microtime(true);
        try {
            $engine->evaluate('function spin() { while (true) {} } spin()');
            $this->fail('the time limit did not fire');
        } catch (JSException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    /**
     * The compiled version of the same program does not stop, so this cannot
     * run it. What it can check is the reason: the deadline is a dispatch-loop
     * concern, and nothing in the generated PHP consults it.
     */
    public function testGeneratedCodeContainsNoDeadlineCheck(): void
    {
        $artifact = Artifact::build('function spin() { while (true) {} } spin()', 'trustspin');
        $this->assertSame(1, $artifact->converted, 'the loop was not compiled, so this proves nothing');
        $this->assertStringContainsString('while (true)', $artifact->php);
        // If any of these ever appear, generated code has become interruptible
        // and the first reason in the README needs revisiting.
        $this->assertStringNotContainsString('deadline', $artifact->php);
        $this->assertStringNotContainsString('setTimeLimit', $artifact->php);
        $this->assertStringNotContainsString('microtime', $artifact->php);
    }

    /**
     * Interpreted JS frames live on the VM's own stack, so runaway recursion is
     * a catchable RangeError. Compiled frames are PHP frames -- the same program
     * exhausts the process instead, which is why this only asserts the
     * interpreted half and the mechanism.
     */
    public function testInterpretedRecursionRaisesACatchableError(): void
    {
        $result = (new Engine())->evaluate(
            'function deep(n) { return deep(n + 1); } try { deep(0) } catch (e) { e.constructor.name }'
        );
        $this->assertSame('RangeError', $result);
    }

    public function testACompiledCallGoesThroughPhpsOwnCallStack(): void
    {
        $artifact = Artifact::build('function deep(n) { return deep(n + 1); } deep', 'trustdeep');
        $this->assertSame(1, $artifact->converted);
        // A plain PHP call through the VM, with no frame pushed onto the VM's
        // own stack -- which is exactly what makes it fast and what makes deep
        // recursion the caller's problem.
        $this->assertStringContainsString('invoke(', $artifact->php);
        $this->assertStringNotContainsString('pushFrame', $artifact->php);
    }

    public function testPinnedDependenciesAcceptsOnlyNodeModules(): void
    {
        $accept = NodeIntegration::pinnedDependencies();
        $this->assertTrue($accept('/srv/app/node_modules/react/index.js'));
        $this->assertTrue($accept('/srv/app/node_modules/.pnpm/react@19/node_modules/react/index.js'));
        $this->assertFalse($accept('/srv/app/src/app.js'));
        $this->assertFalse($accept('/srv/app/uploads/tenant-42/handler.js'));
        // A directory merely *named* like one does not count.
        $this->assertFalse($accept('/srv/app/my_node_modules_backup.js'));
    }

    public function testPinnedDependenciesCanNameOnePackage(): void
    {
        $accept = NodeIntegration::pinnedDependencies('react');
        $this->assertTrue($accept('/srv/app/node_modules/react/index.js'));
        $this->assertFalse($accept('/srv/app/node_modules/lodash/index.js'));
        // A package name is a whole path segment: naming `react` does not also
        // name `react-dom`, so a build wanting both has to say so.
        $this->assertFalse($accept('/srv/app/node_modules/react-dom/index.js'));
        $this->assertTrue(
            NodeIntegration::pinnedDependencies('react-dom')('/srv/app/node_modules/react-dom/index.js')
        );
    }

    /**
     * Bytecode is data, and that is the whole reason precompiling it needs no
     * trust: loading a template file defines nothing and calls nothing.
     */
    public function testABytecodeTemplateIsPlainData(): void
    {
        [$template] = $this->build('function f(x) { return x * 2; } f(21)');
        $exported = var_export($template, true);
        $this->assertStringNotContainsString('function', $exported);
        $this->assertStringNotContainsString('Closure', $exported);
        $this->assertStringNotContainsString('::', $exported);
        // Round-trips through a file, which is what opcache holds.
        $this->assertSame($template, eval('return ' . $exported . ';'));
    }
}
