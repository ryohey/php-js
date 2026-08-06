<?php

declare(strict_types=1);

namespace PhpJs\Aot\Tests;

use PhpJs\Aot\Manifest;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpjs-aot-manifest-' . getmypid() . '-' . uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    public function testReadsTheDedicatedFileWhenPresent(): void
    {
        file_put_contents($this->tmp . '/phpjs-aot.json', json_encode(['libraries' => ['react']]));
        $manifest = Manifest::discover($this->tmp);
        $this->assertSame(['react'], $manifest->libraries);
    }

    public function testFallsBackToAPackageJsonKey(): void
    {
        file_put_contents($this->tmp . '/package.json', json_encode([
            'name' => 'demo',
            'phpjsAot' => ['libraries' => ['react', 'react-dom']],
        ]));
        $manifest = Manifest::discover($this->tmp);
        $this->assertSame(['react', 'react-dom'], $manifest->libraries);
    }

    public function testTheDedicatedFileWinsOverPackageJson(): void
    {
        file_put_contents($this->tmp . '/phpjs-aot.json', json_encode(['libraries' => ['dedicated']]));
        file_put_contents($this->tmp . '/package.json', json_encode([
            'phpjsAot' => ['libraries' => ['from-package-json']],
        ]));
        $manifest = Manifest::discover($this->tmp);
        $this->assertSame(['dedicated'], $manifest->libraries);
    }

    public function testAnExplicitConfigPathSkipsDiscoveryEntirely(): void
    {
        $explicit = $this->tmp . '/custom.json';
        file_put_contents($explicit, json_encode(['libraries' => ['explicit']]));
        file_put_contents($this->tmp . '/phpjs-aot.json', json_encode(['libraries' => ['dedicated']]));
        $manifest = Manifest::discover($this->tmp, $explicit);
        $this->assertSame(['explicit'], $manifest->libraries);
    }

    public function testThrowsWhenNothingIsFound(): void
    {
        $this->expectException(\RuntimeException::class);
        Manifest::discover($this->tmp);
    }

    public function testThrowsWhenLibrariesIsMissingOrEmpty(): void
    {
        file_put_contents($this->tmp . '/phpjs-aot.json', json_encode(['libraries' => []]));
        $this->expectException(\RuntimeException::class);
        Manifest::discover($this->tmp);
    }
}
