<?php

/**
 * Regenerates public/sitemap.xml from config/navigation.php.
 *
 *     php tools/generate-sitemap.php
 *
 * Static rather than a route: Apache serves it straight from disk (see public/.htaccess, which
 * only falls through to index.php for paths that do not exist), so there is no routing, no
 * content-type handling and no framework boot involved.
 *
 * The tradeoff is that it must be re-run when pages are ADDED or REMOVED — the navigation config
 * is the source of truth, so adding a page there and forgetting this leaves the new page out of
 * the sitemap. Search engines will still find it by crawling the nav links; the sitemap just
 * makes it faster.
 */

const BASE = 'https://basttyydev.serv00.net';

$navigation = require __DIR__ . '/../config/navigation.php';
$docsRoot   = __DIR__ . '/../app/docs';

$urls = [];

foreach ($navigation as $version => $pages) {
    foreach ($pages as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $sub => $_label) {
                $path = $sub === 'index' ? $key : "$key/$sub";
                $file = "$docsRoot/$version/" . ($sub === 'index' ? "$key/index" : "$key/$sub") . '.md';
                $urls[] = ["/docs/$version/$path", $file];
            }
            continue;
        }

        $urls[] = ["/docs/$version/$key", "$docsRoot/$version/$key.md"];
    }
}

$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
     . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

$written = 0;
$skipped = [];

foreach ($urls as [$path, $file]) {
    // Only advertise pages that actually exist — a 404 in a sitemap is a negative SEO signal.
    if (!is_file($file)) {
        $skipped[] = $path;
        continue;
    }

    $lastmod = date('Y-m-d', filemtime($file) ?: time());
    $loc = htmlspecialchars(BASE . $path, ENT_XML1);

    $xml .= "  <url>\n"
          . "    <loc>$loc</loc>\n"
          . "    <lastmod>$lastmod</lastmod>\n"
          . "    <changefreq>weekly</changefreq>\n"
          . "  </url>\n";
    $written++;
}

$xml .= "</urlset>\n";

file_put_contents(__DIR__ . '/../public/sitemap.xml', $xml);

printf("wrote public/sitemap.xml — %d urls\n", $written);

if ($skipped) {
    printf("skipped %d nav entries with no markdown file:\n  %s\n", count($skipped), implode("\n  ", $skipped));
}
