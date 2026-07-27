<?php

declare(strict_types=1);

namespace PhpJs\Test262;

/**
 * Parses the YAML-ish front matter block of a test262 test file
 * (/*--- ... ---*​/). Only the subset of YAML test262 actually uses is
 * supported: scalar values, inline lists, indented lists, and one level of
 * nesting for `negative:`.
 */
final class FrontMatter
{
    /** @var list<string> */
    public array $includes = [];
    /** @var list<string> */
    public array $flags = [];
    /** @var list<string> */
    public array $features = [];
    public ?string $negativePhase = null;
    public ?string $negativeType = null;
    public ?string $description = null;

    public static function parse(string $source): self
    {
        $fm = new self();
        if (!preg_match('~/\*---(.*?)---\*/~s', $source, $m)) {
            return $fm;
        }
        $lines = explode("\n", $m[1]);
        $currentKey = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $km)) {
                $currentKey = $km[1];
                $value = trim($km[2]);
                if ($value !== '') {
                    $fm->assign($currentKey, $value);
                    if ($currentKey !== 'negative') {
                        $currentKey = null;
                    }
                }
                continue;
            }
            if ($currentKey !== null && preg_match('/^\s+(\w+):\s*(.*)$/', $line, $nm)) {
                // Nested key (negative: phase/type).
                if ($currentKey === 'negative') {
                    if ($nm[1] === 'phase') {
                        $fm->negativePhase = trim($nm[2]);
                    } elseif ($nm[1] === 'type') {
                        $fm->negativeType = trim($nm[2]);
                    }
                }
                continue;
            }
            if ($currentKey !== null && preg_match('/^\s+-\s*(.+)$/', $line, $lm)) {
                $fm->appendList($currentKey, trim($lm[1]));
            }
        }
        return $fm;
    }

    private function assign(string $key, string $value): void
    {
        if (str_starts_with($value, '[')) {
            $items = array_filter(array_map('trim', explode(',', trim($value, '[]'))));
            foreach ($items as $item) {
                $this->appendList($key, $item);
            }
            return;
        }
        switch ($key) {
            case 'description':
                $this->description = trim($value, "'\" |>");
                break;
            case 'includes':
            case 'flags':
            case 'features':
                $this->appendList($key, $value);
                break;
        }
    }

    private function appendList(string $key, string $item): void
    {
        $item = trim($item, "'\" ");
        if ($item === '') {
            return;
        }
        switch ($key) {
            case 'includes':
                $this->includes[] = $item;
                break;
            case 'flags':
                $this->flags[] = $item;
                break;
            case 'features':
                $this->features[] = $item;
                break;
        }
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
