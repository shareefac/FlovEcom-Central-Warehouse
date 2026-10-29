<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * One main action (the owner, 29 Oct 2026: "button all design follow this"; the parts board TP_Parts: "One main button per page";
 * docs/decisions.md DS5, AV13), on a page as the browser gets it:
 *  - a form is submitted by at most one main (magenta: `.btn.primary`) button, its own or one naming it with form="…";
 *  - at most one main link or fold button (a link or a summary submits nothing) is on the page;
 *  - a form that only filters or searches (a GET form, or one without a method) never holds a main button;
 *  - a main button is not repeated row by row: forms of the same route (its address with every number made `{n}`) for two or more
 *    records (different numbers) that each hold one are a repeated row action, except the documented ones (ROW_ANSWERS: a work queue whose rows are each one small form
 *    answered in turn, and the OK of each record's decision where a page lists several records).
 * KernelBrowser runs it on every HTML page the kernel tests open; tests/Unit/UiTemplatesTest checks the templates' source.
 */
final class MainButtons
{
    /**
     * The routes whose forms may each hold a main button on one page (docs/decisions.md DS5; the Codex review of DS1-DS6, AV13), as
     * patterns of the form's address with every number made `{n}`: Lines to place's Place (LP_Queue), Simple matching's Apply, and the OK
     * of a record's decision (a review or approval task, a staff request, a check of the company details, a supplier's task, the second
     * approval of a match).
     */
    public const ROW_ANSWERS = [
        '/ui/queues/suspense/{n}/pick',
        '/ui/match/apply',
        '/ui/documents/reviews/{n}/approve',
        '/ui/staff-requests/{n}/approve',
        '/ui/reference/company/reviews/{n}/approve',
        '/ui/purchasing/suppliers/tasks/{n}/approve',
        '/ui/review/decision/{n}/approve',
    ];

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
        $out = [];
        $forms = 0;
        /** @var array<string, \DOMElement> $formOf a form's key => the form */
        $formOf = [];
        foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " btn ") and contains(concat(" ", normalize-space(@class), " "), " primary ")]') ?: [] as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $key = 'the page (links and fold buttons)';
            if (in_array(strtolower($el->nodeName), ['button', 'input'], true)) {
                $form = null;
                $named = $el->getAttribute('form');
                if ($named !== '') {
                    $key = 'form #' . $named;
                    foreach ($doc->getElementsByTagName('form') as $f) {
                        if ($f->getAttribute('id') === $named) {
                            $form = $f;
                            break;
                        }
                    }
                } else {
                    for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                        if (strtolower($p->nodeName) === 'form') {
                            $form = $p;
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
                if ($form !== null) {
                    $formOf[$key] = $form;
                    $method = strtolower($el->getAttribute('formmethod') !== '' ? $el->getAttribute('formmethod') : $form->getAttribute('method'));
                    if ($method !== 'post') {
                        // A form that only filters or searches is never the page's main action (DS5).
                        $out[] = "a filter (GET) {$key}: a main button (" . self::text($el) . ')';
                    }
                }
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $words[$key][] = self::text($el);
        }
        // A main button repeated row by row: forms of one route for two or more records (their addresses differ only in their numbers) that
        // each hold one. Two forms of the very same address are one record's answers (a quick "Yes" beside the full form: one per form, DS5).
        $byRoute = [];
        $paths = [];
        foreach ($formOf as $key => $form) {
            if (strtolower($form->getAttribute('method')) === 'post') {
                $route = self::route($form->getAttribute('action'));
                $byRoute[$route][] = $key;
                $paths[$route][(string) parse_url($form->getAttribute('action'), PHP_URL_PATH)] = true;
            }
        }
        foreach ($byRoute as $route => $keys) {
            if (count($paths[$route]) > 1 && !in_array($route, self::ROW_ANSWERS, true)) {
                $counts['a row action repeated (' . $route . ')'] = count($keys);
                $words['a row action repeated (' . $route . ')'] = array_merge(...array_map(static fn (string $k): array => $words[$k], $keys));
            }
        }
        foreach ($counts as $key => $n) {
            if ($n > 1) {
                $out[] = "{$key}: {$n} main buttons (" . implode(' | ', $words[$key]) . ')';
            }
        }
        return $out;
    }

    /** A form's address as a route: its path, every number made `{n}` ("/ui/staff-requests/12/approve" -> "/ui/staff-requests/{n}/approve"). */
    public static function route(string $action): string
    {
        $path = parse_url(html_entity_decode($action, ENT_QUOTES | ENT_HTML5), PHP_URL_PATH);
        return (string) preg_replace('/[0-9]+/', '{n}', is_string($path) ? $path : '');
    }

    private static function text(\DOMElement $el): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $el->textContent));
    }
}
