<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Documents\Documents;
use CW\Invoices\ErpInvoiceCopy;
use CW\Invoices\InvoiceMath;
use CW\Schema\Grants;
use CW\Settings;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The copies of ERPNext purchase invoices around the code (CW-917; docs/decisions.md EC2, EC4, EC12, EC14, EC15): every reason an invoice
 * waits or is skipped, every state, the match's new reason and the refusals have plain words; the five settings of 0060 have a name, words
 * and their topic, and the main switch reads On / Off; the job is the only system caller of the document base and only for supplier
 * invoices and credit notes; the new tables have their grants; the From ERPNext page is a Show choice of Supplier invoices with no count;
 * the cron line runs every 30 minutes on both servers, the same line, and the installers never run it.
 */
final class ErpCopyUnitTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testEveryCodeHasPlainWords(): void
    {
        foreach (ErpInvoiceCopy::PROBLEMS as $code) {
            self::assertTrue(Words::has('ERP_COPY_WHY', $code), "ERP_COPY_WHY {$code}");
            self::assertMatchesRegularExpression('/^[A-Z].*\.$/', Words::ERP_COPY_WHY[$code], "{$code}: a full sentence");
        }
        self::assertSame([], array_values(array_diff(array_keys(Words::ERP_COPY_WHY), ErpInvoiceCopy::PROBLEMS)), 'no word for a code that does not exist');
        foreach (ErpInvoiceCopy::STATES as $s) {
            self::assertTrue(Words::has('ERP_COPY_STATE', $s), $s);
        }
        foreach (InvoiceMath::WHY as $why) {
            self::assertTrue(Words::has('MATCH_WHY', $why), $why);
        }
        foreach (['erp_copy', 'credits_copy', 'copy_moves_stock', 'system_job_only'] as $code) {
            self::assertMatchesRegularExpression('/Nothing was saved\.$/', Words::INVOICE_ERROR[$code], $code);
        }
        foreach (['invoice.copy', 'credit_note.copy', 'erp_copy.copy', 'erp_copy.reverse'] as $action) {
            self::assertTrue(Words::has('AUDIT_ACTION', $action), $action);
        }
        self::assertSame('Invoices from ERPNext', Words::AUDIT_FAMILY['erp_copy']);
        self::assertSame('From ERPNext: stock already booked', Words::ERP_COPY['badge']);
    }

    public function testTheSettingsOf0060HaveTheirWordsTopicAndRules(): void
    {
        $sql = (string) file_get_contents(self::ROOT . '/migrations/0060_erp_invoice_copy.sql');
        preg_match_all("/'(erp_copy\\.[a-z_]+)'/", $sql, $m);
        $keys = array_values(array_unique($m[1]));
        self::assertSame([ErpInvoiceCopy::SWITCH, ErpInvoiceCopy::COMPANY, ErpInvoiceCopy::FROM, ErpInvoiceCopy::CREATE_SUPPLIERS, ErpInvoiceCopy::UPDATE_PRICES,
            ErpInvoiceCopy::PER_RUN], $keys);
        self::assertSame(['min' => 1, 'max' => 5000], Settings::RULES[ErpInvoiceCopy::PER_RUN]);
        self::assertStringContainsString("'erp_copy.per_run', 'int', '200'", $sql, 'the most a run reads starts at 200');
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, Words::SETTING, $key);
            self::assertArrayHasKey($key, Words::SETTING_HELP, $key);
            self::assertSame('Invoices from ERPNext', Words::settingTopic($key), $key);
            // The Settings page never says "posted" (StartSettingsWordsTest).
            self::assertStringNotContainsString('posted', Words::SETTING[$key] . ' ' . Words::SETTING_HELP[$key], $key);
        }
        self::assertSame(['On', 'Off'], Words::SETTING_BOOL[ErpInvoiceCopy::SWITCH]);
        self::assertSame(1, preg_match(Settings::RULES[ErpInvoiceCopy::COMPANY]['pattern'], 'VAPE AND GO'));
        self::assertSame(0, preg_match(Settings::RULES[ErpInvoiceCopy::COMPANY]['pattern'], '=HYPERLINK("x")'), 'a formula is refused');
        self::assertStringContainsString("'erp_copy.on' AS setting_key, 'bool' AS value_type, 'false' AS value_json", $sql, 'the main switch starts off');
    }

    public function testTheJobIsTheOnlySystemWriterAndOnlyOfSupplierInvoicesAndCreditNotes(): void
    {
        self::assertSame([ErpInvoiceCopy::JOB => ['SINV', 'SCN']], Documents::SYSTEM_JOBS);
        $job = \CW\Caller::system(ErpInvoiceCopy::JOB);
        self::assertTrue(Documents::isSystemJob($job, 'SINV'));
        self::assertTrue(Documents::isSystemJob($job, 'SCN'));
        self::assertFalse(Documents::isSystemJob($job, 'GRN'), 'never a delivery');
        self::assertFalse(Documents::isSystemJob($job, 'DN'), 'never a return (it books stock)');
        self::assertFalse(Documents::isSystemJob(\CW\Caller::system('submit_invoices'), 'SINV'));
        self::assertFalse(Documents::isSystemJob(\CW\Caller::staff(5), 'SINV'));
        self::assertFalse(Documents::isSystemJob(\CW\Caller::channelJob(3, 'vapeandgo', ErpInvoiceCopy::JOB), 'SINV'), 'a store\'s job is not the copy job');
    }

    public function testTheTablesThePageAndTheCron(): void
    {
        self::assertContains('erp_pi', Grants::NO_DELETE);
        self::assertSame(['status', 'cursor_to', 'cursor_name', 'summary', 'finished_at'], Grants::UPDATE_COLUMNS['erp_copy_run']);
        self::assertNotContains('erp_pi_line', [...Grants::NO_DELETE, ...Grants::APPEND_ONLY], 'its lines are replaced when ERPNext changes them');
        $page = null;
        foreach (Sections::MAP['purchases']['tabs']['invoices']['pages'] as $p) {
            if (($p['key'] ?? null) === 'from_erpnext') {
                $page = $p;
            }
        }
        self::assertNotNull($page);
        self::assertSame(['invoices.view', '/ui/purchasing/invoices/from-erpnext'], [$page['perm'], $page['path']]);
        self::assertArrayNotHasKey('badge', $page, 'no count: Approvals and the menu stay quiet');
        self::assertSame('From ERPNext', Words::SEGMENT['from_erpnext']);
        foreach (['staging/cw-staging.cron' => ['cw-staging', 'cw_staging'], 'production/cw-prod.cron' => ['cw-prod', 'cw_prod']] as $file => [$dir, $schema]) {
            $cron = (string) file_get_contents(self::ROOT . '/deploy/' . $file);
            self::assertMatchesRegularExpression('#^\*/30 \* \* \* \*  root  cd /opt/' . $dir . ' && php bin/copy_erp_invoices\.php --db=' . $schema
                . ' --apply >> /var/log/cw/copy_erp_invoices\.log 2>&1$#m', $cron, $file);
            self::assertSame(1, preg_match_all('#^[^\#\n]*copy_erp_invoices\.php#m', $cron), "{$file}: one line");
            $install = (string) file_get_contents(self::ROOT . '/deploy/' . dirname($file) . '/install_cron.sh');
            self::assertStringNotContainsString('copy_erp_invoices', $install, 'never run by the installer: it reads ERPNext and writes');
        }
        self::assertFileExists(self::ROOT . '/bin/copy_erp_invoices.php');
    }
}
