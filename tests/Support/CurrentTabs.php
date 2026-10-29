<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * The tab of the page being shown never navigates (docs/decisions.md CV13; Codex's review of 7cfcc89: on a count sheet showing "Every
 * line", a quantity typed and not saved yet was lost by a click on the "Every line" tab, which reloaded the page). On a page as the
 * browser gets it, a tab (`.tab`, DS2) marked aria-current="page" whose link would lead to the page's own path has no href: it is the
 * tab's look and nothing to click, with or without the script. A current tab that leads elsewhere (the section's tab on one of its
 * records) stays a link: app.js asks first when a form holds typed values (CV14). KernelBrowser runs it on every HTML page.
 * The same for the open record's row of Approvals › Waiting for you (`.ap-row`, aria-current="true"; docs/decisions.md AV19): it is the
 * page shown, so it is no link (a click reloaded the page and lost a note typed in its answer).
 */
final class CurrentTabs
{
    /** @return list<string> what breaks the rule on this page ([] when nothing) */
    public static function problems(string $html, string $path): array
    {
        if (!str_contains($html, 'aria-current')) {
            return [];
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        $here = rtrim($path, '/');
        $out = [];
        foreach ((new \DOMXPath($doc))->query('//*[contains(concat(" ", normalize-space(@class), " "), " tab ") and @aria-current="page" and @href]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " ap-row ") and @aria-current and @href]') ?: [] as $el) {
            /** @var \DOMElement $el */
            $to = rtrim((string) parse_url(html_entity_decode($el->getAttribute('href')), PHP_URL_PATH), '/');
            if ($to === $here || $to === '') {
                $out[] = 'the current tab "' . trim((string) preg_replace('/\s+/u', ' ', $el->textContent)) . '" links to this page (' . $el->getAttribute('href') . ')';
            }
        }
        return $out;
    }
}
