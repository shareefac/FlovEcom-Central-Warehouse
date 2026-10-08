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
        self::assertSame(25, $xp->query('//table[contains(@class, "reasons")]/tbody/tr')->length, '22 of 0008 and the 3 PO reversal reasons of 0010');
        self::assertSame(1, $xp->query('//table[contains(@class, "reasons") and contains(@class, "stack")]')->length, 'one card per reason on a phone');
        self::assertStringContainsString('Free gift (not vaping/nicotine products from 29 Oct 2026)', $reasons->text());
        self::assertStringContainsString('Replaced by an amended order', $reasons->text());
        self::assertSame(['Rejected at review', 'In use', 'review_rejected', ucfirst(Words::REASON_USE['reversal']), 'Up or down', 'No', 'No', 'Yes'],
            array_map(static fn (\DOMNode $c): string => trim((string) $c->textContent), iterator_to_array($xp->query('//table[contains(@class, "reasons")]/tbody/tr[24]/*'))),
            'the reason by its name first, its code kept for the files (plan §1.9)');
        self::assertSame(Words::PAGE_TITLE['reasons'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertStringNotContainsString('Phase', $reasons->text());
        self::assertContains('/ui/reference/reasons.csv', $reasons->hrefs());
        self::assertSame([['Company', '/ui/reference/company'], ['Warehouses', '/ui/reference/warehouses'], ['Users', '/ui/reference/access'],
            ['Approval Rules', '/ui/reference/approvals'], ['Reasons', '/ui/reference/reasons'], ['System', '/ui/reference/settings']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($reasons)),
            'the tabs of Settings a buyer may open (no Stores: system.view); Users opens "Roles and permissions" for them');
        self::assertSame(['Settings', 'Reasons'], [self::currentSection($reasons), self::currentTab($reasons)]);

        $series = $web->get('/ui/reference/series');
        self::assertSame(200, $series->status);
        self::assertSame(['PO-000001', 'GRN-000001', 'SINV-000001', 'DN-000001', 'CNT-000001', 'ADJ-000001', 'WO-000001', 'TRD-000001'], self::column($series, 4));
        self::assertSame(array_fill(0, 8, 'none yet'), self::column($series, 3));
        self::assertSame('In use', self::column($series, 2)[0], 'PO since the I-2 pos task');
        self::assertSame('In use', self::column($series, 2)[1], 'GRN since the I-3 receiving task (IM6)');
        self::assertSame('Coming later', self::column($series, 2)[2], 'SINV; no phase codes');
        self::assertSame('In use', self::column($series, 2)[5], 'the fixture ADJ type of the tests');
        self::assertSame(['Purchase orders', 'Deliveries'], array_slice(self::column($series, 1), 0, 2));
        self::assertSame(['Every one', 'Over £10,000 (no VAT)'], [self::column($series, 5)[0], self::column($series, 6)[0]]);
        self::assertSame('Putting back over 10 items without a supplier document', self::column($series, 6)[5]);
        self::assertSame('Over 10 items', self::column($series, 5)[4]);
        self::assertSame(Words::PAGE_TITLE['series'], trim((string) (new \DOMXPath($series->dom()))->evaluate('string(//main//h1)')));
        self::assertSame(['Settings', 'System'], [self::currentSection($series), self::currentTab($series)]);
        self::assertSame([['Settings', false], ['Numbering', true]], array_map(static fn (array $s): array => [$s['label'], $s['current']], self::segments($series)),
            'a buyer has no System checks (system.view)');
        self::assertStringNotContainsString('Phase', $series->text());
        self::assertStringNotContainsString('decision 11', $series->text());

        $poster = $this->uiUser('stock_controller');
        $docs = new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
        $d = $docs->createDraft(Caller::staff($poster['id']), 'ADJ', ['external_ref' => 'SUP-REF']);
        $d = $docs->setLines(Caller::staff($poster['id']), $d->id, $d->version, [['sku_id' => self::makeSku('Ref item'), 'qty' => 1]]);
        $docs->post(Caller::staff($poster['id']), $d->id, $d->version);
        $series = $web->get('/ui/reference/series');
        self::assertSame('ADJ-000001', self::column($series, 3)[5]);
        self::assertSame('ADJ-000002', self::column($series, 4)[5]);
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
        self::assertSame(26, substr_count($csv->body, "\r\n"), 'header + 25 codes (0008, 0010), CRLF');
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

    /** @return list<string> the text of column $n (1-based) of the page's table body */
    private static function column(UiResponse $r, int $n): array
    {
        $out = [];
        foreach ((new \DOMXPath($r->dom()))->query("//table/tbody/tr/*[{$n}]") ?: [] as $cell) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', (string) $cell->textContent));
        }
        return $out;
    }
}
