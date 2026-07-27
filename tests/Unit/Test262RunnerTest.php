<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit;

use PhpJs\Test262\FrontMatter;
use PhpJs\Test262\Runner;
use PHPUnit\Framework\TestCase;

final class Test262RunnerTest extends TestCase
{
    private function makeRunner(): Runner
    {
        $fixture = dirname(__DIR__) . '/fixtures/test262-mini';
        $runner = new Runner($fixture);
        $lists = sys_get_temp_dir() . '/phpjs-262-' . uniqid();
        mkdir($lists);
        file_put_contents("$lists/include.txt", "language\nbuilt-ins/Dummy\n");
        file_put_contents("$lists/features.txt", "Symbol\nProxy\n");
        file_put_contents("$lists/skip.txt", "built-ins/Dummy/skipped-listed.js known bad fixture\n");
        $runner->loadIncludePaths("$lists/include.txt");
        $runner->loadExcludedFeatures("$lists/features.txt");
        $runner->loadSkipList("$lists/skip.txt");
        return $runner;
    }

    public function testFrontMatterParsing(): void
    {
        $fm = FrontMatter::parse(<<<'JS'
        /*---
        description: sample
        includes: [compareArray.js, propertyHelper.js]
        flags: [onlyStrict, async]
        features:
          - Symbol
          - Proxy
        negative:
          phase: runtime
          type: TypeError
        ---*/
        JS);
        $this->assertSame(['compareArray.js', 'propertyHelper.js'], $fm->includes);
        $this->assertSame(['onlyStrict', 'async'], $fm->flags);
        $this->assertSame(['Symbol', 'Proxy'], $fm->features);
        $this->assertSame('runtime', $fm->negativePhase);
        $this->assertSame('TypeError', $fm->negativeType);
    }

    public function testFullRunTallies(): void
    {
        $runner = $this->makeRunner();
        $runner->runAll();
        // pass.js, negative-parse.js, negative-runtime.js, strict-mode.js, async-pass.js
        $this->assertSame(5, $runner->passed, $this->failureDump($runner));
        // fail.js
        $this->assertSame(1, $runner->failed, $this->failureDump($runner));
        // skipped-feature.js (feature filter) + skipped-listed.js (skip list)
        $this->assertSame(2, $runner->skipped);
    }

    public function testIndividualStatuses(): void
    {
        $runner = $this->makeRunner();
        $this->assertSame(Runner::PASS, $runner->runTest('language/pass.js')[0]);
        $this->assertSame(Runner::FAIL, $runner->runTest('language/fail.js')[0]);
        $this->assertSame(Runner::PASS, $runner->runTest('language/negative-parse.js')[0]);
        $this->assertSame(Runner::PASS, $runner->runTest('language/negative-runtime.js')[0]);
        $this->assertSame(Runner::SKIP, $runner->runTest('language/skipped-feature.js')[0]);
        $this->assertSame(Runner::SKIP, $runner->runTest('built-ins/Dummy/skipped-listed.js')[0]);
        $this->assertSame(Runner::PASS, $runner->runTest('built-ins/Dummy/async-pass.js')[0]);
    }

    public function testStrictAndSloppyModesBothRun(): void
    {
        $runner = $this->makeRunner();
        [$status, $message] = $runner->runTest('language/strict-mode.js');
        $this->assertSame(Runner::PASS, $status, $message);
    }

    private function failureDump(Runner $runner): string
    {
        $out = '';
        foreach ($runner->failures as [$path, $message]) {
            $out .= "$path: $message\n";
        }
        return $out;
    }
}
