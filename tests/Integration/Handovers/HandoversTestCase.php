<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Handovers;

use CW\Caller;
use CW\Company\CompanyDetails;
use CW\Documents\Document;
use CW\Handovers\Counterparties;
use CW\Handovers\CounterpartyAccounts;
use CW\Handovers\Handovers;
use CW\Settings;
use CW\Tests\Integration\StockOps\StockOpsTestCase;

/**
 * Base of the hand-over tests (pack H; docs/decisions.md PH1-PH14): the stock records' base (the document base with the production
 * handlers, TRD included; staff with roles; warehouses; document rules changed for a test and put back), plus the hand-over service, the
 * customer list and the balances, settings changed for a test and put back, the company details saved and confirmed by a reviewer, and
 * the full invariant check after every test (stock, documents D1-D7, the stock records O1-O6, the hand-overs H1-H5).
 */
abstract class HandoversTestCase extends StockOpsTestCase
{
    protected Handovers $handovers;
    protected Counterparties $customers;
    protected CounterpartyAccounts $balances;
    /** @var array<string, string> setting_key => value_json to put back */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->handovers = new Handovers(self::$db, $this->docs);
        $this->customers = new Counterparties(self::$db);
        $this->balances = new CounterpartyAccounts(self::$db);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->savedSettings = [];
        parent::tearDown();
    }

    /** Changes a setting's value for this test (admin connection; the value put back in tearDown, its actor never touched). */
    protected function setting(string $key, string $json): void
    {
        $this->savedSettings[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    protected function settings(): Settings
    {
        return new Settings(self::$db);
    }

    /** A customer added by a purchasing desk person. @param array<string, string> $over */
    protected function customer(string $name, string $kind = 'trade', array $over = []): int
    {
        return $this->customers->add($this->staffUser('purchasing_desk'), $over + ['kind' => $kind, 'name' => $name, 'address' => "1 Trade Street\nLeeds LS1 1AA"]);
    }

    /**
     * A draft hand-over by $who to customer $cp with these lines ([sku_id, qty, unit_cost]).
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $header
     */
    protected function draftTo(Caller $who, int $cp, array $lines, array $header = []): Document
    {
        $d = $this->handovers->createDraft($who, $header + ['counterparty' => $cp]);
        return $lines === [] ? $d : $this->handovers->saveLines($who, $d->id, $d->version, $lines);
    }

    /** @param list<array<string, mixed>> $lines @param array<string, mixed> $header */
    protected function postedTo(Caller $who, int $cp, array $lines, array $header = []): Document
    {
        $d = $this->draftTo($who, $cp, $lines, $header);
        return $this->handovers->post($who, $d->id, $d->version);
    }

    /**
     * The company details saved by a reviewer (VAT registered, the VAT code $vatCode), confirmed when $confirm.
     *
     * @param array<string, string> $over
     */
    protected function company(bool $confirm, string $vatCode = 'S', array $over = []): void
    {
        $svc = new CompanyDetails(self::$db);
        $owner = $this->staffUser('reviewer');
        $cur = $svc->current();
        $r = $svc->save($owner, $cur['version'], $over + ['legal_name' => 'Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'company_number' => '01234567',
            'address' => "1 High Street\nLeeds\nLS1 1AA", 'vat_registered' => 'yes', 'vat_number' => 'GB 123 4567 82', 'vat_code' => $vatCode,
            'phone' => '0113 496 0000', 'email' => 'buying@example.co.uk', 'delivery_address' => "Unit 4, Example Park\nLeeds LS2 2BB"]);
        if ($confirm) {
            $svc->confirm($owner, $r['version']);
        }
    }

    /** @return list<array{0: string, 1: string}> a customer's entries, oldest first: [kind, amount] */
    protected function entries(int $cp): array
    {
        return array_map(static fn (array $r): array => [(string) $r['kind'], (string) $r['amount']],
            self::$db->all('SELECT kind, amount FROM counterparty_entry WHERE counterparty_id = ? ORDER BY id', [$cp]));
    }
}
