<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;
use CW\Reports\ReportRefresh;
use CW\Settings;

/**
 * The profit settings (0074; docs/decisions.md PR4, PR12, PR15, PR20, PR21), read once per instance, each with the value the code uses
 * while its row is not there (a schema before 0074 never reaches this code, but a test may remove a row).
 */
final class ProfitSettings
{
    public const DEFAULT_VAT_BP = 2000;
    public const DEFAULT_COST_START = '2026-11-16';
    public const DEFAULT_HISTORY_FROM = '2025-12-15';
    public const BEFORE_HISTORY = ['estimate', 'none'];

    private readonly Settings $settings;

    public function __construct(private readonly Db $db, ?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings($db);
    }

    /** VAT in basis points (2000 = 20%). */
    public function vatBp(): int
    {
        $v = $this->settings->has('profit.vat_rate_pct') ? $this->settings->get('profit.vat_rate_pct') : null;
        return is_string($v) ? (ProfitMath::vatBp($v) ?? self::DEFAULT_VAT_BP) : self::DEFAULT_VAT_BP;
    }

    /** The UK day CW's own buying price starts. */
    public function costStartDay(): string
    {
        return $this->day('profit.cost_start', self::DEFAULT_COST_START);
    }

    /** The UTC time CW's own buying price starts (midnight UK of that day). */
    public function costStartAt(): string
    {
        return \CW\Clock::db(ReportRefresh::bounds($this->costStartDay())[0]);
    }

    /** The UTC time ERPNext's cost history starts (midnight UK of profit.erp_history_from). */
    public function historyFromAt(): string
    {
        return \CW\Clock::db(ReportRefresh::bounds($this->day('profit.erp_history_from', self::DEFAULT_HISTORY_FROM))[0]);
    }

    /** What a sale before ERPNext's history costs: `estimate` (today's buying price, marked so) or `none`. */
    public function beforeHistory(): string
    {
        $v = $this->settings->has('profit.before_history') ? (string) $this->settings->get('profit.before_history') : 'estimate';
        return in_array($v, self::BEFORE_HISTORY, true) ? $v : 'estimate';
    }

    public function refreshOn(): bool
    {
        return !$this->settings->has('profit.cost_refresh_on') || $this->settings->get('profit.cost_refresh_on') === true;
    }

    private function day(string $key, string $default): string
    {
        $v = $this->settings->has($key) ? $this->settings->get($key) : null;
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 ? $v : $default;
    }
}
