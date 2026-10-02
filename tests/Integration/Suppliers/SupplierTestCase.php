<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Suppliers;

use CW\Caller;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\MappingTestCase;

/**
 * Base of the supplier tests: CW\Suppliers\Suppliers and SupplierItems on the admin connection, staff with roles
 * (MappingTestCase::staffUser), and the full invariant check after every test (stock, documents and S1-S5).
 */
abstract class SupplierTestCase extends MappingTestCase
{
    protected Suppliers $sup;
    protected SupplierItems $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sup = new Suppliers(self::$db);
        $this->items = new SupplierItems(self::$db);
    }

    /** A date relative to the UK today ('-10 days', '+1 year'). */
    protected static function day(string $modify): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->modify($modify)->format('Y-m-d');
    }

    /**
     * The fields of a supplier that is complete for activation (Suppliers::missing() is empty).
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    protected function complete(Caller $checker, array $over = []): array
    {
        return $over + [
            'name' => 'Acme Vape Supplies', 'legal_name' => 'Acme Vape Supplies Ltd', 'company_number' => '01234567', 'vat_number' => 'GB123456789',
            'address_line1' => '1 Trading Estate', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'email' => 'orders@acme.example',
            'phone' => '0113 000 0000', 'payment_terms' => '30 days end of month', 'payment_terms_days' => '30', 'dd_checked_on' => self::day('-10 days'),
            'dd_checked_by' => (string) $checker->staffUserId, 'dd_evidence' => 'Companies House active; VAT number verified', 'dd_next_review_on' => self::day('+1 year'),
        ];
    }

    /** @param array<string, mixed> $over @return array<string, mixed> a complete draft supplier made by $buyer */
    protected function draft(Caller $buyer, array $over = []): array
    {
        return $this->sup->create($buyer, $this->complete($buyer, $over));
    }

    /**
     * A complete supplier made and asked for by $buyer and approved by a fresh reviewer.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    protected function activeSupplier(Caller $buyer, array $over = []): array
    {
        $s = $this->draft($buyer, $over);
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        return $this->sup->approve($this->staffUser('reviewer'), $this->openTask((int) $s['id']), null);
    }

    /** The open task of a supplier (of $kind; 0 when none). */
    protected function openTask(int $supplierId, string $kind = 'approval'): int
    {
        return (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND kind = ? AND state = 'open'",
            [$supplierId, $kind]);
    }

    /** @return array<string, mixed> */
    protected function taskRow(int $id): array
    {
        return (array) self::$db->one('SELECT * FROM review_task WHERE id = ?', [$id]);
    }

    /** @return list<string> the audit actions written for a supplier, oldest first */
    protected function supplierAudits(int $supplierId): array
    {
        return array_map('strval', self::$db->column("SELECT action FROM audit_log WHERE entity_type = 'supplier' AND entity_id = ? ORDER BY id", [(string) $supplierId]));
    }
}
