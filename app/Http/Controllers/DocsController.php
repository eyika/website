<?php

namespace App\Http\Controllers;

use App\Helpers\CustomParsedown;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Arr;

class DocsController extends Controller
{
    /** Fallback when a page has no usable first paragraph. */
    private const DEFAULT_DESCRIPTION = 'Documentation for Atom — a Laravel-inspired PHP framework: routing, models, migrations, queues, auth and more.';

    public function generatePage(Request $request, $resource = 'docs', $version = 'beta', $page1 = 'index', $page2 = '') {
        if ($resource !== 'docs') {
            $page2 = $version == 'beta' ? '' : $version;
            $version = 'beta';
            $page1 = $resource;
        }
        $_page2 = empty($page2) ? "" : "/$page2";
        $filePath = base_path("app/docs/$version/$page1{$_page2}.md");

        $navig = config('navigation');
        $versions = [];
        foreach ($navig as $key => $value) {
            array_push($versions, $key);
        }
    
        $canonical = $this->canonicalUrl($request, $resource, $version, $page1, $page2);

        if (!file_exists($filePath)) {
            http_response_code(404);
            return response()->view('docs.template', [
                'title' => 'Page not found — Atom PHP Framework',
                'description' => self::DEFAULT_DESCRIPTION,
                'canonical' => $canonical,
                'ogImage' => $this->baseUrl($request) . '/img/og-default.png',
                'noindex' => true,
                'version' => $version,
                'versions' => $versions,
                'page' => $page1.$page2,
                'navigation' => config("navigation.$version"),
                'content' => '<h2>Page not found</h2><p>The requested documentation page does not exist.</p>',
                'previousPageUrl' => '',
                'nextPageUrl' => null,
            ]);
        }

        $markdown = file_get_contents($filePath);
        $content = (new CustomParsedown())->text($markdown);
        $navigation = $this->generatePaginationButtons($version, $page1, $page2);
        $label = $this->pageLabel($version, $page1, $page2);

        return response()->view('docs.template', [
            // Front-loaded page name, then the site — the shape search results and social
            // previews truncate best. The docs root gets the descriptive form instead of the
            // slug, which used to render as the unhelpful "(Atom) Index".
            'title' => $page1 === 'index' && !$page2
                ? 'Atom — PHP Framework Documentation'
                : "$label — Atom PHP Framework",
            'description' => $this->pageDescription($markdown),
            'canonical' => $canonical,
            'ogImage' => $this->baseUrl($request) . '/img/og-default.png',
            'noindex' => false,
            'version' => $version,
            'versions' => $versions,
            'page' => $page1.$page2,
            'navigation' => config("navigation.$version"),
            'content' => $content,
            'previousPageUrl' => $navigation['previousPageUrl'],
            'nextPageUrl' => $navigation['nextPageUrl'],
        ]);
    }

    /**
     * The human label for a page, taken from config/navigation.php so titles match the sidebar
     * ("Drivers & Grammars", not "Drivers"). Falls back to a de-slugged filename.
     */
    private function pageLabel(string $version, string $page1, string $page2): string
    {
        $nav = config("navigation.$version") ?? [];
        $entry = $nav[$page1] ?? null;

        if ($page2) {
            $label = is_array($entry) ? ($entry[$page2] ?? null) : null;
        } else {
            $label = is_array($entry) ? ($entry['index'] ?? null) : $entry;
        }

        return is_string($label) && $label !== ''
            ? $label
            : ucfirst(str_replace('-', ' ', $page2 ?: $page1));
    }

    /**
     * A per-page description for search results and link previews, taken from the page's first
     * real paragraph. Every page shared the same static sentence before, which tells a reader
     * nothing about which page they are looking at.
     */
    private function pageDescription(string $markdown): string
    {
        // Drop fenced code, blockquote/heading/list markers, then find the first prose line.
        $text = preg_replace('/```.*?```/s', '', $markdown) ?? $markdown;

        foreach (preg_split('/\R{2,}/', $text) as $block) {
            $block = trim($block);

            if ($block === '' || preg_match('/^([#>\-*|]|\d+\.)/', $block)) {
                continue;
            }

            // Strip inline markdown: links -> text, emphasis and code fences -> plain.
            $block = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $block) ?? $block;
            $block = str_replace(['**', '`', '*', '_'], '', $block);
            $block = trim(preg_replace('/\s+/', ' ', $block) ?? $block);

            if ($block === '') {
                continue;
            }

            return mb_strlen($block) > 155 ? mb_substr($block, 0, 152) . '…' : $block;
        }

        return self::DEFAULT_DESCRIPTION;
    }

    /** Absolute URL for this page — needed by canonical and og:url, which reject relative paths. */
    private function canonicalUrl(Request $request, string $resource, string $version, string $page1, string $page2): string
    {
        $path = $resource !== 'docs'
            ? '/' . trim($page1, '/')
            : rtrim("/docs/$version/$page1" . ($page2 ? "/$page2" : ''), '/');

        return $this->baseUrl($request) . $path;
    }

    /**
     * Origin to build absolute metadata URLs from.
     *
     * Derived from the REQUEST rather than taken from config, because `APP_URL` defaults to
     * `http://localhost` — and an og:image pointing at localhost is unreachable to a scraper, so
     * the link preview stays blank however correct the tags are. config('app.url') is honoured
     * only when it has been set to a real public origin.
     */
    public function baseUrl(Request $request): string
    {
        $configured = rtrim((string) config('app.url'), '/');

        if ($configured !== ''
            && preg_match('#^https?://#i', $configured)
            && !preg_match('#^https?://(localhost|127\.0\.0\.1)#i', $configured)) {
            return $configured;
        }

        $host = $request->host();

        if (!$host) {
            return $configured !== '' ? $configured : '';
        }

        return $request->scheme() . '://' . $host;
    }

    private function generatePaginationButtons($version, $page1, $page2) {
        $previousPageUrl = '';
        $nextPageUrl = '';

        $previousPage = Arr::previousItem(config("navigation.$version"), $page2 ? "$page1.$page2" : $page1, true);
        $previousPage = $previousPage ? str_replace('.', '/', $previousPage) : null;

        $nextPage = Arr::nextItem(config("navigation.$version"), $page2 ? "$page1.$page2" : $page1, true);
        $nextPage = $nextPage ? str_replace('.', '/', $nextPage) : null;
    
        $previousPageUrl = $previousPage ? "/docs/$version/$previousPage" : null;
        $nextPageUrl = $nextPage ? "/docs/$version/$nextPage" : null;
    
        return [
            'previousPageUrl' => $previousPageUrl,
            'nextPageUrl' => $nextPageUrl,
        ];
    }
}
