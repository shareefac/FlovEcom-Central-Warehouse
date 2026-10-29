<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * One main action (the owner, 29 Oct 2026: "button all design follow this"; the parts board TP_Parts: "One main button per page";
 * docs/decisions.md DS5), on a page as the browser gets it: a form is submitted by at most one main (magenta: `.btn.primary`) button,
 * its own or one naming it with form="…", and at most one main link or fold button (a link or a summary submits nothing) is on the
 * page. KernelBrowser runs it on every HTML page the kernel tests open; tests/Unit/UiTemplatesTest checks the templates' source.
 */
final class MainButtons
{
    /** @return list<string> what breaks the rule on this page ([] when nothing) */
    public static function problems(string $html): array
    {
        if (!str_contains($html, 'primary')) {
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
        $xp = new \DOMXPath($doc);
        $counts = [];
        $words = [];
        $forms = 0;
        foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " btn ") and contains(concat(" ", normalize-space(@class), " "), " primary ")]') ?: [] as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $key = 'the page (links and fold buttons)';
            if (in_array(strtolower($el->nodeName), ['button', 'input'], true)) {
                $named = $el->getAttribute('form');
                if ($named !== '') {
                    $key = 'form #' . $named;
                } else {
                    for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                        if (strtolower($p->nodeName) === 'form') {
                            if ($p->getAttribute('id') !== '') {
                                $key = 'form #' . $p->getAttribute('id');
                            } else {
                                if (!$p->hasAttribute('data-main-check')) {
                                    $p->setAttribute('data-main-check', (string) ++$forms);
                                }
                                $key = 'form ' . $p->getAttribute('data-main-check') . ' (' . $p->getAttribute('action') . ')';
                            }
                            break;
                        }
                    }
                }
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $words[$key][] = trim((string) preg_replace('/\s+/u', ' ', $el->textContent));
        }
        $out = [];
        foreach ($counts as $key => $n) {
            if ($n > 1) {
                $out[] = "{$key}: {$n} main buttons (" . implode(' | ', $words[$key]) . ')';
            }
        }
        return $out;
    }
}
