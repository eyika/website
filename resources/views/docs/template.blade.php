<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Atom Documentation' }}</title>
    <link rel="stylesheet" href="/css/docs.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup-templating.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
    <script src="/js/docs.js" defer></script>
</head>
<body>
    <div id="docs-container">
        <header>
            <div class="header-content">
                <h1>Atom Documentation</h1>

                <!-- Version Dropdown -->
                <select id="version-dropdown" onchange="window.location.href=this.value;">
                    @foreach ($versions ?? [] as $_version)
                        <option value="/docs/{{ $_version }}/{{ $page }}" {{ $version === $_version ? 'selected' : '' }}>{{ \Eyika\Atom\Framework\Support\Str::pascal($_version) }}</option>
                    @endforeach
                </select>

                <!-- Dark/Light Mode Toggle -->
                <button id="mode-toggle" onclick="toggleDarkMode()">🌙</button>
            </div>
        </header>

        <div id="main-content">
            <aside id="sidebar">
                <nav>
                    <ul id="navigation collapsible collapsed">
                        @foreach ($navigation as $section => $links)
                            <li class="expandable-menu nav-section">
                                @if (is_string($links))
                                    <a href="/docs/{{ $version }}/{{ $section }}">{{ $links }}</a>
                                @else
                                    <a href="javascript:void(0)" class="nav-header">{{ $section }}</a>
                                    <ul class="nav-links collapsible collapsed" id="nav-{{ $section }}">
                                        @foreach ($links as $link => $label)
                                            <li class="nav-section"><a href="/docs/{{ $version }}/{{ $section }}/{{ $link }}">{{ $label }}</a></li>
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
</body>
</html>
