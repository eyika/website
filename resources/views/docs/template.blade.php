<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Atom Documentation' }}</title>
    <link rel="stylesheet" href="/css/docs.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup-templating.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
    <script src="/js/docs.js" defer></script>
</head>
<body>
    <div id="docs-container">
        <header>
            <button class="hamburger-menu" onclick="toggleSidebar()">☰</button>
            <div class="header-content">
                <h1><a href="/">Atom Docs</a></h1>
                <h3>(Powered by <a href="https://github.com/eyika">Atom Framework</a> and <a href="https://parsedown.org/">Parsdown</a>)</h3>

                <!-- Header Actions -->
                <div class="header-action-items">
                    <!-- Version Dropdown -->
                    <select id="version-dropdown" onchange="window.location.href=this.value;">
                        @foreach ($versions ?? [] as $_version)
                            <option value="/docs/{{ $_version }}/{{ $page }}" {{ $version === $_version ? 'selected' : '' }}>{{ \Eyika\Atom\Framework\Support\Str::pascal($_version) }}</option>
                        @endforeach
                    </select>

                    <!-- Dark/Light Mode Toggle -->
                    <button id="mode-toggle" onclick="toggleDarkMode()" aria-label="Toggle Dark Mode">🌙</button>
                </div>
            </div>
        </header>

        <div id="main-content">
            <aside id="sidebar">
                <h3><a class="powered-by" href="https://github.com/eyika">Powered by Atom Framework</a><a class="powered-by" href="https://parsedown.org/"> and Parsdown</a></h3>
                <nav>
                    <ul class="navigation">
                        @foreach ($navigation as $section => $links)
                            <li class="nav-section expandable-menu">
                                @if (is_string($links))
                                    <a href="/docs/{{ $version }}/{{ $section }}">{{ $links }}</a>
                                @else
                                    <button class="nav-header" aria-expanded="false" onclick="toggleSection('nav-{{ $section }}')">
                                        {{ $section }}
                                    </button>
                                    <ul class="nav-links collapsible" id="nav-{{ $section }}">
                                        @foreach ($links as $link => $label)
                                            <li><a href="/docs/{{ $version }}/{{ $section }}/{{ $link }}">{{ $label }}</a></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>

            <main>
                {!! $content !!}
                <div class="pagination">
                    <a href="{{ $previousPageUrl }}" class="btn btn-prev" @if(!$previousPageUrl) disabled @endif>Previous</a>
                    <a href="{{ $nextPageUrl }}" class="btn btn-next" @if(!$nextPageUrl) disabled @endif>Next</a>
                </div>
            </main>
        </div>

        <footer>
            <p>&copy; {{ date('Y') }} Eyika. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Toggle collapsible navigation sections
        function toggleSection(sectionId) {
            const section = document.getElementById(sectionId);
            const isCollapsed = section.classList.contains('collapsed');
            section.classList.toggle('collapsed', !isCollapsed);
            section.previousElementSibling.setAttribute('aria-expanded', !isCollapsed);
        }

        // Dark mode toggle
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            document.getElementById('mode-toggle').textContent = isDark ? '☀️' : '🌙';
        }
    </script>
</body>
</html>
        