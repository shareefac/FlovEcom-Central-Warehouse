-- 0057_report_stock_value.sql — Home's stock value tile (the approved Main board's first tile, "Stock value at cost"; tracker CW-912;
-- docs/decisions.md DG5): the reports' refresh keeps the valuation total, so Home reads one row and never sums the balances on a load
-- (the fix round's RD-F8: "needs a build: a valuation total kept by the reports' refresh").
--
--  * report_refresh.stock_value (GBP, 2 dp), items_with_stock and value_at: what the valuation report says of our stock (its own figures,
--    ReportViews::valueFigures: on hand above zero in our warehouses at each item's average cost) as of the refresh's last run, written by
--    bin/refresh_reports.php (CW\Reports\ReportRefresh::run) each run. NULL until the first run after this file: Home then draws no tile.
--
-- HOW IT IS APPLIED: three NULL columns on a one-row table, in place (ALGORITHM = INPLACE, LOCK = NONE), a moment. Nothing written,
-- nothing deleted. The code reads and writes them only when they are there (a schema before this file: no tile, the refresh as before).

ALTER TABLE report_refresh
  ADD COLUMN stock_value DECIMAL(24,2) NULL COMMENT 'GBP: our stock at average cost, as the valuation report says it at value_at (DG5)',
  ADD COLUMN items_with_stock INT UNSIGNED NULL COMMENT 'items with stock in our warehouses at value_at',
  ADD COLUMN value_at DATETIME(6) NULL COMMENT 'when the refresh worked them out (UTC)',
  ALGORITHM = INPLACE, LOCK = NONE;
