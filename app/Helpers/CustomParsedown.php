<?php

namespace App\Helpers;

class CustomParsedown extends ParsedownExtra
{
    /** Slugs already emitted on the current page, so duplicate headings get -1/-2 suffixes. */
    private array $usedIds = [];

    protected function blockFencedCodeComplete($Block)
    {
        $Block = parent::blockFencedCodeComplete($Block);

        if (!isset($Block['element']['text']['attributes']['class'])) {
            $Block['element']['text']['attributes']['class'] = 'language-plain';
        } else {
            $Block['element']['text']['attributes']['class'] = 'language-' . $Block['element']['text']['attributes']['class'];
        }

        return $Block;
    }

    /**
     * Give every heading a GitHub-style slug `id` so the in-page Table-of-Contents
     * anchors (`[Section](#section)`) actually jump. An explicit `{#custom-id}` on the
     * heading (handled by ParsedownExtra) always wins.
     */
    protected function blockHeader($Line)
    {
        $Block = parent::blockHeader($Line);

        if ($Block !== null
            && empty($Block['element']['attributes']['id'])
            && isset($Block['element']['handler']['argument'])
        ) {
            $Block['element']['attributes']['id'] = $this->headingSlug($Block['element']['handler']['argument']);
        }

        return $Block;
    }

    /** Same treatment for Setext (`===` / `---` underlined) headings. */
    protected function blockSetextHeader($Line, ?array $Block = null)
    {
        $Block = parent::blockSetextHeader($Line, $Block);

        if (is_array($Block)
            && isset($Block['element']['name'], $Block['element']['handler']['argument'])
            && in_array($Block['element']['name'], ['h1', 'h2'], true)
            && empty($Block['element']['attributes']['id'])
        ) {
            $Block['element']['attributes']['id'] = $this->headingSlug($Block['element']['handler']['argument']);
        }

        return $Block;
    }

    /**
     * GitHub's heading-anchor algorithm: lowercase, drop everything that isn't a
     * letter/digit/space/hyphen (so punctuation vanishes and `A & B` keeps its two
     * spaces → `a--b`), then spaces → hyphens. Ensures uniqueness per page.
     */
    private function headingSlug(string $text): string
    {
        $text = strip_tags($text);                      // any inline HTML
        $text = preg_replace('/`([^`]*)`/', '$1', $text); // inline code: keep the text, drop backticks
        $slug = mb_strtolower($text, 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N} _\-]+/u', '', $slug); // strip punctuation, keep spaces/hyphens/underscores
        $slug = str_replace(' ', '-', $slug);

        if ($slug === '') {
            $slug = 'section';
        }

        $base = $slug;
        for ($i = 1; isset($this->usedIds[$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        $this->usedIds[$slug] = true;

        return $slug;
    }
}
