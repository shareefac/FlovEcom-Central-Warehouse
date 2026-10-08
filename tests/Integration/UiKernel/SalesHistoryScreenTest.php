<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Reorder\SalesHistoryImport;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * The sales-history screen through the real /ui kernel as cw_app (spec §8.1, §9.3; I68): coverage, the import batches with
 * their unknown and unlinked units, the snapshot days, the top unknown / unlinked variants (a link to the listing only for
 * linking.view), the full list as CSV, the roles.
 */
final class SalesHistoryScreenTest extends KernelUiTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    /** @return array{vpg: int, unlinked: int} */
    private function loaded(): array
    {
        $vpg = $this->channelOf('vapeandgo');
        $sku = $this->brandItem('Elux Legend Blue Razz', 'Elux');
        $this->listingOf($vpg, '101', $sku);
        $unlinked = $this->profiled(Caller::channel($vpg, 'vapeandgo'), '102', ['product_title' => 'Hayati Pro Max 4000', 'variant_title' => 'Blue Razz Ice <b>',
            'brand' => 'Hayati']);
        $rows = [];
        foreach (self::daily('101', '2026-07-01', '2026-09-30', 5) as $r) {
            $rows[] = [$r[0], $r[1], $r[2], 1, $r[3], $r[3], 0, 0];
        }
        foreach (self::daily('102', '2026-09-01', '2026-09-30', 2) as $r) {
            $rows[] = [$r[0], $r[1], $r[2], 1, $r[3], $r[3], 0, 0];
        }
        foreach (self::daily('104', '2026-09-20', '2026-09-30', 7) as $r) {
            $rows[] = [$r[0], $r[1], 0, 0, '0.00', '0.00', $r[2], 1];
        }
        usort($rows, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        $m = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-07-01', '2026-09-30', $rows, [], [['101', '2026-09-30', 40, 'In-Stock', 1]],
            [['date' => '2026-09-29', 'variants' => 3, 'unsellable' => 0], ['date' => '2026-09-30', 'variants' => 3, 'unsellable' => 1]]);
        (new SalesHistoryImport(self::$db))->import(Caller::system('test'), 'vapeandgo', $m);
        return ['vpg' => $vpg, 'unlinked' => $unlinked];
    }

    public function testCoverageBatchesAndTheUnlinkedList(): void
    {
        $l = $this->loaded();
        $buyer = $this->signIn($this->uiUser('buyer'));
        $page = $buyer->get('/ui/purchasing/sales-history');
        self::assertSame(200, $page->status, $page->describe());
        $text = $page->text();
        self::assertStringContainsString(Words::SALES['loaded'] . ' 1 Jul 2026 – 30 Sep 2026', $text);
        self::assertStringContainsString(Words::SALES['stock_days'] . ' 2 days (29 Sep 2026 – 30 Sep 2026)', $text);
        self::assertStringContainsString(Words::SALES['latest'] . ' 1 products on 30 Sep 2026', $text);
        self::assertStringNotContainsString('bin/', $text, 'no server command (F344)');
        self::assertStringNotContainsString('docs/', $text);
        // The batch: 92 + 30 + 11 rows; units 460 + 60 + 77; unknown 77 (104, no listing) = 12.9%; unlinked 60 (102) = 10.1%.
        self::assertStringContainsString('133 597 77 12.9% 60 10.1%', preg_replace('/,/', '', $text) ?? '');
        $xp = new \DOMXPath($page->dom());
        $top = [];
        foreach ($xp->query('//table[contains(@class, "unlinked")]/tbody/tr') ?: [] as $tr) {
            $top[] = trim((string) preg_replace('/\s+/u', ' ', (string) $tr->textContent));
        }
        self::assertSame(['104 option 104 ' . Words::SALES['unknown'] . ' 77 30 Sep 2026',
            'Hayati Pro Max 4000 Blue Razz Ice <b> · option 102 · Hayati ' . Words::SALES['not_matched'] . ' 60 30 Sep 2026'], $top,
            'most units first; titles from the listing profile (escaped); the problem in words (F347)');
        self::assertNotContains('/ui/review/listing/' . $l['unlinked'], $page->hrefs(), 'no link without linking.view');
        self::assertSame(['Reorder', 'Purchase Orders', 'Suppliers'], self::tabLabels($page));
        self::assertSame(['Purchasing', 'Reorder'], [self::currentSection($page), self::currentTab($page)]);
        self::assertSame([['Suggestions', false], ['Brands', false], ['Anomalies', false], ['Sales history', true]],
            array_map(static fn (array $s): array => [$s['label'], $s['current']], self::segments($page)));

        $auditor = $this->signIn($this->uiUser('auditor'));
        $page = $auditor->get('/ui/purchasing/sales-history');
        self::assertSame(200, $page->status);
        self::assertContains('/ui/review/listing/' . $l['unlinked'], $page->hrefs(), 'linking.view: a link to the listing');

        $csv = $buyer->get('/ui/purchasing/sales-history/unlinked.csv', ['channel' => 'vapeandgo']);
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('attachment; filename="vapeandgo-unlinked.csv"', (string) $csv->header('content-disposition'));
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(preg_split('/\r\n/', substr($csv->body, 3)) ?: [])));
        self::assertSame(['channel', 'variant_id', 'units_91d', 'last_sale', 'mapping', 'listing_id', 'product_title', 'variant_title', 'brand'], $rows[0]);
        self::assertSame([['vapeandgo', '104', '77', '2026-09-30', 'unknown', '', '', '', ''],
            ['vapeandgo', '102', '60', '2026-09-30', 'unlinked (unmapped)', (string) $l['unlinked'], 'Hayati Pro Max 4000', 'Blue Razz Ice <b>', 'Hayati']], array_slice($rows, 1));
        self::assertSame(404, $buyer->get('/ui/purchasing/sales-history/unlinked.csv', ['channel' => 'electrofag'])->status);
    }

    public function testTheRolesAndAnEmptyHistory(): void
    {
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $page = $reviewer->get('/ui/purchasing/sales-history');
        self::assertSame(200, $page->status);
        self::assertStringContainsString(Words::SALES['none'], $page->text());
        foreach (['purchasing_desk', 'goods_in', 'stock_controller', 'mapper'] as $role) {
            self::assertSame(403, $this->signIn($this->uiUser($role))->get('/ui/purchasing/sales-history')->status, $role);
        }
    }
}
