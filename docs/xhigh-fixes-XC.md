# Xhigh fixes XC

XC1. Repack costs are calculated inside Movements' single stock booking, after its balance locks and every affected item's value
clock lock. Both source and destination clocks are acquired in item order before reading the global average, including receipts at
other warehouses. Stock remains the only balance/ledger writer; its sequence allocation and final feed lock are unchanged.

XC2. A stored unusable barcode resolves to no item and cannot fall through to a numeric item id. Family bulk assignment reports
the existing not-found result, and pack/repack entry points share this resolver. Usable barcodes and explicit CW numbers keep working.

XC3. Reorder refresh acquires the per-item reorder_line mutex before computing a line, then captures read_at. The computation sees
drafts committed while it waited. Locks are acquired in item order and retained through both summary and detail writes. The refresh takes shared sku locks first,
matching the low-stock settings writer's sku-before-reorder-line order.

XC4. The mutex exists even for a valid item with no suggestion: an unlisted marker is inserted before computation. Every absent
result advances read_at, including an already unlisted row; changed_at advances only when a listed row leaves the list. The full
run's cleanup retains its base-time guard and records the time it actually confirmed absence. No reorder data is deleted.

XC5. Bulk low-stock updates lock item settings before calculating safety stock. Both the bulk action and saveItem also lock the
sku first, so a concurrent first settings row cannot slip past a missing-row lock under READ COMMITTED. These operations do not lock
stock balances; low-stock refresh still precedes the final feed write.

XC6. reservation_unit.was_paid records payment membership independently of cancellation state, including unlinked units and opening
orders. Payment marks only allocated units after the commit plan has been applied. Cancel, ship, return and uncancel preserve that
membership. Reports exclude known unpaid units and retain paid units cancelled later.

Migration 0045 backfills active paid units and cancellations proven by the stock ledger. Some legacy cancelled units, especially
those unlinked throughout, have no evidence distinguishing cancellation before payment from cancellation afterwards. They remain
NULL (unknown), retaining their previous report totals. This ambiguity cannot be reconstructed from a request hash. All newly
created units start at zero and payment sets them to one. The migration preserves original states, timestamps and source records.

XC7. report_item.last_other_sale_on retains ERP/trade/imported history separately. The report refresh recomputes affected items'
latest qualifying store sale across all days and stores, including items whose cancellation or uncancel was recorded today even
when their payment day is outside the overlap window. The combined last date may decrease or clear. Imported history continues to
use the original batch watermark; cancellation does not rematch already-consumed history.

Migration 0045 explicitly copies reservation_unit and report_item under shared locks. It resets only rebuildable item summaries
and report cursors, retaining all rows and source data. After applying it, run the report refresh until both value-sequence and
sales-day backlogs have caught up before relying on report figures. The normal configured work limits still apply. The scratch-schema
migration test checks the backfill, source preservation and rebuild.

XC8. Every resolved Sales by Store period passes through the existing reports.sales_max_days limit, including named and fallback
periods. Invalid-date and shortened-period warnings both use existing Words entries.

XC9. The sales filter carries its active Any time period as a hidden field. Typed dates continue to take precedence, and the
filtered page's CSV uses the same period.

XC10. No new business setting, configurable number or screen wording is introduced. The patch was prepared without filesystem
writes, CW execution, database access or network requests. PHP syntax checks and git apply --check passed. PHPUnit was not run.
The integrator must run PHPUnit on staging, including RepackTest,
FamiliesTest, ReorderEngineTest, LowStockLevelsTest, ReportRefreshTest, ReportsUnitTest, SalesScreensTest and Migration0045Test,
then the existing integration suite.
