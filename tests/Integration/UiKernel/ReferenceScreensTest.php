<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\Documents;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The reference screens (reference.view, every role; I20, I22) and the CSV downloads (I25): 22 reason codes, 8 number
 * series with their next numbers, reasons.csv and people.csv as Excel-safe attachments (BOM, CRLF, a display name
 * like =HYPERLINK(...) written as text), people.csv for staff.view only.
 */
final class ReferenceScreensTest extends KernelUiTestCase
{
    public function testReasonCodesAndNumberSeries(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        $reasons = $web->get('/ui/reference/reasons');
        self::assertSame(200, $reasons->status, $reasons->describe());
        $xp = new \DOMXPath($reasons->dom());
        // The settings shell (docs/decisions.md W13.11): a card of the reasons in use, a row each (it was a table of ten columns).
        self::assertSame(31, $xp->query('//section[contains(@class, "reasons")]//div[contains(@class, "set-row") and @data-code]')->length,
            '22 of 0008, the 3 PO reversal reasons of 0010 and the 6 stock reasons of 0022');
        self::assertSame(0, $xp->query('//main//table')->length, 'rows, not a table to scroll on a phone');
        self::assertStringContainsString('Free gift (not vaping/nicotine products from 29 Oct 2026)', $reasons->text());
        self::assertStringContainsString('Replaced by an amended order', $reasons->text());
        $row = $xp->query('//div[contains(@class, "set-row") and @data-code="review_rejected"]')->item(0);
        self::assertNotNull($row);
        self::assertSame(['Rejected at review', Words::SETTINGS_PAGE['used_for'] . ': ' . ucfirst(Words::REASON_USE['reversal']) . ' · Up or down', Words::SETTINGS_PAGE['by_cw'], 'review_rejected'],
            [trim((string) $xp->evaluate('string(.//a[@class="set-kt"])', $row)), trim((string) $xp->evaluate('string(.//p[@class="set-kd"][1])', $row)),
                trim((string) $xp->evaluate('string(.//p[@class="set-kd"][2])', $row)), trim((string) $xp->evaluate('string(.//code)', $row))],
            'the reason by its name first, its code kept for the files (plan §1.9); only the rules it has, as tags (given to and below zero since pack A1, SO4)');
        self::assertSame(['Rejected at review'], array_values(array_filter(array_map(static fn (\DOMNode $a): string => trim((string) $a->textContent),
            iterator_to_array($xp->query('//section[contains(@class, "reasons")]//a[@class="set-kt"]'))), static fn (string $t): bool => $t === 'Rejected at review')));
        self::assertContains('/ui/reference/reasons.csv', $reasons->hrefs(), 'the spreadsheet: under the card\'s More');
        self::assertSame(Words::PAGE_TITLE['reasons'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertStringNotContainsString('Phase', $reasons->text());
        self::assertSame([['Company', '/ui/reference/company'], ['Warehouses', '/ui/reference/warehouses'], ['Roles and permissions', '/ui/reference/access'],
            ['Approval rules', '/ui/reference/approvals'], ['Reasons', null], ['Rules and numbers', '/ui/reference/settings'], ['Numbering', '/ui/reference/series']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::segments($reasons)),
            'the Settings pages a buyer may open, its one Show (RD7; no Stores: system.view; no Users: staff.view)');
        self::assertSame(['Settings', null], [self::currentSection($reasons), self::currentTab($reasons)]);

        $series = $web->get('/ui/reference/series');
        self::assertSame(200, $series->status);
        // The settings shell (W13.10): a row per kind of record; its next number at the right, how it starts and its last number under it.
        self::assertSame(['PO-000001', 'GRN-000001', 'SIN-000001', 'SOUT-000001', 'ADJ-000001', 'TRF-000001', 'REL-000001', 'SINV-000001', 'DN-000001', 'SCN-000001',
            'CNT-000001', 'WO-000001', 'TRD-000001', 'RPK-000001'], array_column(self::series($series), 'next'),
            'in the kinds\' order, the stock records of pack A1 and the credit note of pack C among them (SO12), the repack of pack B last');
        self::assertSame(14, count(array_filter(self::series($series), static fn (array $r): bool => str_ends_with($r['since'], Words::SETTINGS_PAGE['none_yet']))));
        $live = array_column(self::series($series), 'live');
        self::assertSame(Words::SETTINGS_PAGE['live'], $live[0], 'PO since the I-2 pos task');
        self::assertSame(Words::SETTINGS_PAGE['live'], $live[1], 'GRN since the I-3 receiving task (IM6)');
        self::assertSame(array_fill(0, 5, Words::SETTINGS_PAGE['live']), array_slice($live, 2, 5), 'SIN, SOUT, ADJ, TRF, REL (pack A1)');
        self::assertSame(array_fill(0, 3, Words::SETTINGS_PAGE['live']), array_slice($live, 7, 3), 'SINV, DN, SCN (pack C)');
        self::assertSame(['Purchase orders', 'Deliveries'], array_slice(array_column(self::series($series), 'kind'), 0, 2));
        $rules = array_column(self::series($series), 'rules');
        self::assertSame(sprintf(Words::SETTINGS_PAGE['series_rules'], 'Every one', 'Over £10,000 (no VAT)', '7'), $rules[0]);
        self::assertStringContainsString('Putting back over 10 items without a supplier document', $rules[4], 'ADJ');
        self::assertSame(Words::SETTINGS_PAGE['live'], $live[10], 'CNT: stock counts are built (pack A2a)');
        self::assertStringStartsWith(explode('%s', Words::SETTINGS_PAGE['series_rules'])[0] . Words::SETTINGS_PAGE['none'] . ' ·', $rules[10],
            'and their second-person check starts off (0029, docs/decisions.md CT8)');
        self::assertSame(Words::PAGE_TITLE['series'], trim((string) (new \DOMXPath($series->dom()))->evaluate('string(//main//h1)')));
        self::assertSame(['Settings', null], [self::currentSection($series), self::currentTab($series)]);
        self::assertSame([['Rules and numbers', false], ['Numbering', true]], array_slice(array_map(static fn (array $s): array => [$s['label'], $s['current']], self::segments($series)), -2),
            'a buyer has no System checks (system.view) and no audit log (audit.view)');
        self::assertStringNotContainsString('Phase', $series->text());
        self::assertStringNotContainsString('decision 11', $series->text());

        $poster = $this->uiUser('stock_controller');
        $docs = new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
        $d = $docs->createDraft(Caller::staff($poster['id']), 'ADJ', ['external_ref' => 'SUP-REF']);
        $d = $docs->setLines(Caller::staff($poster['id']), $d->id, $d->version, [['sku_id' => self::makeSku('Ref item'), 'qty' => 1]]);
        $docs->post(Caller::staff($poster['id']), $d->id, $d->version);
        $series = $web->get('/ui/reference/series');
        self::assertSame(sprintf(Words::SETTINGS_PAGE['series_line'], 'ADJ', 'ADJ-000001'), self::series($series)[4]['since']);
        self::assertSame('ADJ-000002', self::series($series)[4]['next']);
    }

    public function testCsvDownloadsAreExcelSafe(): void
    {
        $admin = $this->uiUser('admin');
        $evil = $this->uiUser('viewer');
        self::$db->exec('UPDATE staff_user SET display_name = ? WHERE id = ?', ['=HYPERLINK("http://evil.invalid","click")', $evil['id']]);
        $web = $this->signIn($admin);

        $csv = $web->get('/ui/reference/reasons.csv');
        self::assertSame(200, $csv->status);
        self::assertSame('text/csv; charset=utf-8', $csv->header('content-type'));
        self::assertSame('attachment; filename="reason-codes.csv"; filename*=UTF-8\'\'reason-codes.csv', $csv->header('content-disposition'));
        self::assertContains('sandbox', $csv->headerValues('content-security-policy'));
        self::assertStringStartsWith("\xEF\xBB\xBF\"code\",\"label\",", $csv->body);
        self::assertSame(1, substr_count($csv->body, "\xEF\xBB\xBF"));
        self::assertSame(32, substr_count($csv->body, "\r\n"), 'header + 31 codes (0008, 0010, 0022), CRLF');
        self::assertStringContainsString("\"damaged\",\"Damaged\",\"adjustment, write_off, return, supplier_return\",\"decrease\",\"no\",\"no\",\"no\",\"yes\",10\r\n", $csv->body);

        $people = $web->get('/ui/people');
        self::assertContains('/ui/people.csv', $people->hrefs());
        $csv = $web->get('/ui/people.csv');
        self::assertSame(200, $csv->status, $csv->describe());
        self::assertSame('attachment; filename="people.csv"; filename*=UTF-8\'\'people.csv', $csv->header('content-disposition'));
        self::assertStringStartsWith("\xEF\xBB\xBF\"id\",\"name\",\"email\",\"roles\",\"active\",\"last_sign_in_utc\",\"created_utc\"\r\n", $csv->body);
        self::assertStringContainsString("{$evil['id']},\"'=HYPERLINK(\"\"http://evil.invalid\"\",\"\"click\"\")\",\"{$evil['email']}\",\"viewer\",\"yes\",", $csv->body,
            'the formula is text');
        self::assertStringNotContainsString(',"=HYPERLINK', $csv->body);
        self::assertStringNotContainsString('password', strtolower($csv->body));
        self::assertStringNotContainsString('totp', strtolower($csv->body));

        self::assertSame(403, $this->signIn($this->uiUser('buyer'))->get('/ui/people.csv')->status);
        self::assertSame(200, $this->signIn($this->uiUser('auditor'))->get('/ui/people.csv')->status);
    }

    /** @return list<array{code: string, kind: string, live: string, rules: string, since: string, next: string}> Numbering's rows, in order */
    private static function series(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $text = static fn (string $q, \DOMNode $row): string => trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate("string({$q})", $row)));
        $out = [];
        foreach ($xp->query('//section[contains(@class, "series")]//div[contains(@class, "set-row") and @data-code]') ?: [] as $row) {
            /** @var \DOMElement $row */
            $out[] = ['code' => $row->getAttribute('data-code'), 'kind' => trim((string) $xp->evaluate('string(.//p[@class="set-kt"]/text()[1])', $row)),
                'live' => $text('.//p[@class="set-kt"]/span', $row), 'rules' => $text('.//p[@class="set-kd"]', $row), 'since' => $text('.//p[@class="set-kh"]', $row),
                'next' => $text('.//code[contains(@class, "next")]', $row)];
        }
        return $out;
    }
}
