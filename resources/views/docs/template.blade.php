<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Atom Documentation' }}</title>
    <meta name="description" content="Documentation for the Atom PHP framework.">

    <script>
        // Set the theme before first paint to avoid a flash of the wrong theme.
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="/css/docs.css?v={{ @filemtime(public_path('css/docs.css')) ?: '1' }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" id="prism-theme">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup-templating.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js" defer></script>
    <script src="/js/docs.js?v={{ @filemtime(public_path('js/docs.js')) ?: '1' }}" defer></script>
</head>
<body>
    <div id="docs-container">
        <header class="site-header">
            <button class="menu-toggle" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>

            <a class="brand" href="/">
                <svg class="brand-mark" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="2.2" fill="currentColor" stroke="none"></circle>
                    <ellipse cx="12" cy="12" rx="10" ry="4.4"></ellipse>
                    <ellipse cx="12" cy="12" rx="10" ry="4.4" transform="rotate(60 12 12)"></ellipse>
                    <ellipse cx="12" cy="12" rx="10" ry="4.4" transform="rotate(120 12 12)"></ellipse>
                </svg>
                <span class="brand-text">
                    <span class="brand-title">Atom Docs</span>
                    <span class="brand-sub">the Atom PHP framework</span>
                </span>
            </a>

            <div class="header-actions">
                <label class="visually-hidden" for="version-dropdown">Version</label>
                <select id="version-dropdown" onchange="window.location.href=this.value;">
                    @foreach ($versions ?? [] as $_version)
                        <option value="/docs/{{ $_version }}/{{ $page }}" {{ $version === $_version ? 'selected' : '' }}>{{ \Eyika\Atom\Framework\Support\Str::pascal($_version) }}</option>
                    @endforeach
                </select>

                <button id="theme-toggle" type="button" aria-label="Toggle color theme" title="Toggle theme">
                    <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
                    <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"></path></svg>
                </button>
            </div>
        </header>

        <div id="main-content">
            <div class="sidebar-overlay" hidden></div>
            <aside id="sidebar" aria-label="Documentation navigation">
                <nav class="sidebar-nav">
                    <ul class="nav-list">
                        @foreach ($navigation as $section => $links)
                            @if (is_string($links))
                                <li class="nav-item">
                                    <a class="nav-link" href="/docs/{{ $version }}/{{ $section }}">{{ $links }}</a>
                                </li>
                            @else
                                <li class="nav-group">
                                    <button class="nav-group-toggle" type="button" aria-expanded="false">
                                        <span>{{ $links['index'] ?? \Eyika\Atom\Framework\Support\Str::pascal($section) }}</span>
                                        <span class="chevron" aria-hidden="true"></span>
                                    </button>
                                    <ul class="nav-group-list">
                                        @foreach ($links as $link => $label)
                                            <li>
                                                <a class="nav-link" href="/docs/{{ $version }}/{{ $section }}/{{ $link }}">{{ $label }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </nav>
            </aside>

            <main class="docs-main">
                <div class="docs-body">
                    <div class="docs-left">
                        <article class="docs-content">
                            {!! $content !!}
                        </article>

                        <nav class="pagination" aria-label="Pagination">
                            @if($previousPageUrl)
                                <a href="{{ $previousPageUrl }}" class="page-link page-prev"><span class="page-dir">← Previous</span></a>
                            @else
                                <span class="page-link page-prev is-disabled"><span class="page-dir">← Previous</span></span>
                            @endif
                            @if($nextPageUrl)
                                <a href="{{ $nextPageUrl }}" class="page-link page-next"><span class="page-dir">Next →</span></a>
                            @else
                                <span class="page-link page-next is-disabled"><span class="page-dir">Next →</span></span>
                            @endif
                        </nav>

                        <footer class="docs-footer">
                            <p>&copy; {{ date('Y') }} Eyika. Built with the <a href="https://github.com/eyika">Atom Framework</a>.</p>
                        </footer>
                    </div>

                    <aside class="toc" aria-label="On this page">
                        <p class="toc-title">On this page</p>
                        <nav class="toc-nav"></nav>
                    </aside>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
