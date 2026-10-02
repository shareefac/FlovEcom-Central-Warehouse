<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/** One HTTP answer from the /ui screens under test. */
final class UiResponse
{
    /** @param array<string, list<string>> $headers lower-case name => every value (Set-Cookie repeats) */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return ($this->headers[strtolower($name)] ?? [null])[0];
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    /** The raw Set-Cookie line of a cookie, or null. */
    public function setCookie(string $name): ?string
    {
        foreach ($this->headerValues('set-cookie') as $line) {
            if (str_starts_with($line, $name . '=')) {
                return $line;
            }
        }
        return null;
    }

    public function dom(): \DOMDocument
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="utf-8"?>' . $this->body, LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        return $doc;
    }

    /** The visible text of the page (whitespace collapsed). */
    public function text(): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $this->dom()->textContent ?? ''));
    }

    /** Every href of the page, in order. @return list<string> */
    public function hrefs(): array
    {
        $out = [];
        foreach ($this->dom()->getElementsByTagName('a') as $a) {
            $out[] = $a->getAttribute('href');
        }
        return $out;
    }

    /**
     * The fields a browser would submit for the (first) form whose action contains $action:
     * hidden, text, number, search fields, the checked radio of each group, checked boxes and
     * the first option of a select; with $textareas also every textarea (its text). Disabled controls are left out.
     *
     * @return array<string, string>
     */
    public function form(string $action, bool $textareas = false): array
    {
        foreach ($this->dom()->getElementsByTagName('form') as $form) {
            if (!str_contains($form->getAttribute('action'), $action)) {
                continue;
            }
            $out = [];
            foreach ($form->getElementsByTagName('input') as $in) {
                $name = $in->getAttribute('name');
                if ($name === '' || $in->hasAttribute('disabled')) {
                    continue;
                }
                $type = strtolower($in->getAttribute('type') ?: 'text');
                if (in_array($type, ['radio', 'checkbox'], true)) {
                    if ($in->hasAttribute('checked')) {
                        $out[$name] = $in->getAttribute('value') ?: 'on';
                    }
                    continue;
                }
                if (in_array($type, ['submit', 'button', 'reset', 'file'], true)) {
                    continue;
                }
                $out[$name] = $in->getAttribute('value');
            }
            foreach ($form->getElementsByTagName('select') as $sel) {
                $options = $sel->getElementsByTagName('option');
                $chosen = null;
                foreach ($options as $o) {
                    if ($chosen === null || $o->hasAttribute('selected')) {
                        $chosen = $o->getAttribute('value');
                    }
                }
                $out[$sel->getAttribute('name')] = (string) $chosen;
            }
            if ($textareas) {
                foreach ($form->getElementsByTagName('textarea') as $ta) {
                    if ($ta->getAttribute('name') !== '' && !$ta->hasAttribute('disabled')) {
                        // A browser drops the one line break that may follow the opening tag.
                        $out[$ta->getAttribute('name')] = (string) preg_replace('/^\r?\n/', '', (string) $ta->textContent);
                    }
                }
            }
            return $out;
        }
        return [];
    }

    /** Whether a form with this action exists. */
    public function hasForm(string $action): bool
    {
        foreach ($this->dom()->getElementsByTagName('form') as $form) {
            if (str_contains($form->getAttribute('action'), $action)) {
                return true;
            }
        }
        return false;
    }

    /** The radio values of a group that are enabled. @return list<string> */
    public function radios(string $name): array
    {
        $out = [];
        foreach ($this->dom()->getElementsByTagName('input') as $in) {
            if ($in->getAttribute('type') === 'radio' && $in->getAttribute('name') === $name && !$in->hasAttribute('disabled')) {
                $out[] = $in->getAttribute('value');
            }
        }
        return $out;
    }

    public function describe(): string
    {
        return "HTTP {$this->status} " . substr($this->text(), 0, 300);
    }
}
