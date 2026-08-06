<?php

declare(strict_types=1);

namespace PhpJs\Phext;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;

/**
 * Renders a matched route to HTML.
 *
 * The composition happens in PHP rather than in a JavaScript shim, which is
 * worth explaining because the opposite would be the obvious choice. A
 * framework normally ships a JS entry point that imports the user's pages and
 * calls the renderer; here the host is already inside the JS runtime, so it
 * can `require` the page and its layouts itself and build
 * `createElement(Layout, {children: createElement(Page, props)})` directly.
 * That removes a file that would otherwise have to live somewhere resolvable
 * from the app's own `node_modules`, and with it the question of how a
 * framework's private module ends up on the user's module path at all.
 *
 * React itself stays the *app's* dependency, not this package's — exactly as
 * `next` does not vendor React. It is resolved through the host, from the app
 * root, so the app's own version is what renders.
 */
final class Renderer
{
    /**
     * React's synchronous server renderer, by path.
     *
     * Not `react-dom/server`: that entry also pulls in the streaming renderer,
     * which needs `MessageChannel` and `AbortController` — Web APIs this
     * runtime does not provide. `react-dom/server` assigns
     * `renderToString`/`renderToStaticMarkup` straight from this file, so this
     * is the same function that entry point would hand back.
     */
    private const REACT_DOM_SERVER = 'react-dom/cjs/react-dom-server-legacy.node.production.js';

    private mixed $createElement = null;
    private mixed $renderFn = null;
    /** @var array<string, mixed> module path => its exports */
    private array $modules = [];

    /**
     * @param string $method `renderToString` (hydratable markup) or
     *                       `renderToStaticMarkup` (no React bookkeeping in
     *                       the output, for a page nothing will hydrate)
     */
    public function __construct(
        private readonly NodeHost $host,
        private readonly string $method = 'renderToString',
    ) {
    }

    /**
     * @param array<string, string|list<string>> $searchParams the query string,
     *                        already parsed; handed to the page as-is
     */
    public function render(RouteMatch $match, array $searchParams = [], int $status = 200): Page
    {
        $started = microtime(true);
        $vm = $this->host->vm();
        $realm = $this->host->realm();
        $this->boot();

        $pathname = $match->route->pathFor($match->params);

        $props = $realm->newObject();
        $props->defineOwnData('params', self::toJs($realm, $match->params));
        $props->defineOwnData('searchParams', self::toJs($realm, $searchParams));
        $props->defineOwnData('pathname', $pathname);

        $element = $this->host->call($this->createElement, null, [
            $this->componentIn($match->route->pageFile),
            $props,
        ]);
        // Outermost layout last, so each wraps what is already built.
        foreach (array_reverse($match->route->layouts) as $layout) {
            $layoutProps = $realm->newObject();
            $layoutProps->defineOwnData('params', self::toJs($realm, $match->params));
            // Next.js does not give a server layout the current path, which is
            // why highlighting the active nav link there needs a client
            // component. A synchronous server render knows it for certain, so
            // withholding it would be imitating a limitation rather than a
            // design.
            $layoutProps->defineOwnData('pathname', $pathname);
            $layoutProps->defineOwnData('children', $element);
            $element = $this->host->call($this->createElement, null, [
                $this->componentIn($layout),
                $layoutProps,
            ]);
        }

        $html = Conversions::toString($vm, $this->host->call($this->renderFn, null, [$element]));
        $html = Metadata::apply($html, $this->metadataFor($match->route));
        // React renders elements, and a doctype is not one. A root layout that
        // renders <html> is rendering a document, so it gets a document's
        // preamble; a route that renders a fragment is left alone.
        if (str_starts_with($html, '<html')) {
            $html = "<!DOCTYPE html>\n" . $html;
        }

        return new Page(
            $match->route->pathFor($match->params),
            $status,
            $html,
            (microtime(true) - $started) * 1000,
        );
    }

    /**
     * The parameter sets a dynamic route should be built for, from its page
     * module's `generateStaticParams()` — Next.js's name for the same thing.
     *
     * A route that exports none contributes no static pages, which is not an
     * error: it stays renderable on demand by a live server, and is simply
     * not part of a static export.
     *
     * @return list<array<string, string>>
     */
    public function staticParams(Route $route): array
    {
        $vm = $this->host->vm();
        $generate = $this->exportsOf($route->pageFile)->get('generateStaticParams', $vm);
        if ($generate instanceof JSUndefined || !$generate instanceof JSObject) {
            return [];
        }
        $result = $this->host->call($generate, null, []);
        $out = [];
        foreach (self::toPhpList($vm, $result) as $entry) {
            if (!$entry instanceof JSObject) {
                continue;
            }
            $params = [];
            foreach ($entry->ownEnumerableKeys() as $key) {
                $params[$key] = Conversions::toString($vm, $entry->get($key, $vm));
            }
            $out[] = $params;
        }
        return $out;
    }

    /** @return array<string, string> a page's `export const metadata`, merged over its layouts' */
    private function metadataFor(Route $route): array
    {
        $metadata = [];
        foreach ([...$route->layouts, $route->pageFile] as $file) {
            $metadata = array_merge($metadata, Metadata::from($this->host, $this->exportsOf($file)));
        }
        return $metadata;
    }

    /** A module's default export, which is what a page or layout *is*. */
    private function componentIn(string $file): mixed
    {
        $vm = $this->host->vm();
        $exports = $this->exportsOf($file);
        $component = $exports->get('default', $vm);
        if ($component instanceof JSUndefined) {
            throw new \RuntimeException(
                "$file has no default export, so there is no component to render. "
                . 'A page or layout must `export default` its component.'
            );
        }
        return $component;
    }

    private function exportsOf(string $file): JSObject
    {
        if (!isset($this->modules[$file])) {
            $exports = $this->host->requireModule($file);
            if (!$exports instanceof JSObject) {
                throw new \RuntimeException("$file did not export an object");
            }
            $this->modules[$file] = $exports;
        }
        return $this->modules[$file];
    }

    private function boot(): void
    {
        if ($this->createElement !== null) {
            return;
        }
        $vm = $this->host->vm();
        $react = $this->host->requireModule('react');
        $this->createElement = $react->get('createElement', $vm);
        $server = $this->host->requireModule(self::REACT_DOM_SERVER);
        $renderFn = $server->get($this->method, $vm);
        if ($renderFn instanceof JSUndefined) {
            throw new \InvalidArgumentException(
                "react-dom's server build has no {$this->method}()"
            );
        }
        $this->renderFn = $renderFn;
    }

    /** @param array<string, mixed> $values */
    private static function toJs(\PhpJs\Runtime\Realm $realm, array $values): JSObject
    {
        $object = $realm->newObject();
        foreach ($values as $key => $value) {
            $object->defineOwnData(
                (string)$key,
                is_array($value) ? $realm->newArray(array_values($value)) : $value
            );
        }
        return $object;
    }

    /** @return list<mixed> */
    private static function toPhpList(\PhpJs\Vm\Vm $vm, mixed $value): array
    {
        if ($value instanceof \PhpJs\Runtime\JSArray) {
            return $value->toList();
        }
        return [];
    }
}
