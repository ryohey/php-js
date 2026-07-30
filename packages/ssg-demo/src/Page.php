<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/** One rendered page, plus what it cost to produce. */
final class Page
{
    public function __construct(
        public readonly string $path,
        public readonly int $status,
        public readonly string $title,
        /** The full document, `<!DOCTYPE html>` included. */
        public readonly string $html,
        public readonly float $renderMs,
    ) {
    }

    public function bytes(): int
    {
        return strlen($this->html);
    }

    /**
     * Substitute markup into the empty element the layout reserved for the
     * host's own timings.
     *
     * The element is rendered by React as exactly `<div id="phpjs-metrics">
     * </div>` (no other attributes, so no attribute-order assumption), and a
     * deployment that wants no toolbar simply leaves it empty.
     */
    public function withToolbar(string $toolbarHtml): string
    {
        $placeholder = '<div id="' . Renderer::METRICS_ID . '"></div>';
        $at = strpos($this->html, $placeholder);
        if ($at === false) {
            // The layout changed and no longer reserves the element. Serving
            // the page unchanged is better than failing over a toolbar.
            return $this->html;
        }
        return substr_replace($this->html, $toolbarHtml, $at, strlen($placeholder));
    }
}
