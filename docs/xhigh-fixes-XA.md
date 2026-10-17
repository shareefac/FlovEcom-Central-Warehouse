# Xhigh fixes XA

XA1. Pending sales are read in one REPEATABLE READ, consistent, read-only snapshot. A sale moving
from the unqueued orders into the ERPNext queue between reads remains visible exactly once.
Isolation changes for this transaction only; the website session remains read-only afterwards.
Regression: SitePendingSalesTest::testAQueueCommitBetweenTheQuantityReadsCannotHideASale.

XA2. An ERPNext replacement records its predecessor for each item and warehouse it actually
books, in that item's booking transaction. The rebase planner and booking guard follow this
ancestry and sum the original opening with its replacement's net adjustment. Another opening,
another item, or another warehouse gains no permission from that ancestry. Migration 0043
backfills existing booked items from erp_opening_run.summary.report.replaces, including failed
runs with some items committed. Document references remain case-sensitive.
Regressions: ErpOpeningT0Test::testUnitsOnlyRebaseIncludesTheOpeningReplacedByErpnext and
Migration0043Test::testExistingReplacementAncestryIsBackfilledOnlyForBookedItems.

XA3. A units-only rebase skips a merged-away item with committed opening units as merged_item.
The merge already retained stock for those units. A person reconciles the survivor; adding the
units again would leave stock on the merged-away item after dispatch.
Regression: OpeningRebaseTest::testUnitsOnlyRebaseSkipsAMergedItemWithOpeningUnitsInFlight.

XA4. A duplicate relay-line claim assigns outcome to itself, a column the application login
may update. Frozen identity columns keep their existing grants.
Regression: GrantsTest::testRelayMovementsAndQueuesRunWithTheAppGrants.

XA5. Confirming an ERPNext correction checks the locked warehouse balance's counted_at against
the correction's event time. A later physical count, including a zero-delta count, rejects it
with newer_count and leaves both stock and the review unchanged. The staff explanation is in Words.
Regression: StoreQueuesTest::testACorrectionCannotOverwriteALaterPhysicalCount.

XA6. Movement request keys are excluded from idempotency housekeeping, in both dry runs and
deletion queries. Pre-relay_line bookings have no durable line claim; retaining their request
keys preserves their existing replay protection. Other request retention is unchanged.
Regression: RelayIntakeTest::testHousekeepingPreservesLegacyMovementReplayProtection.

XA7. Every known-item mode message keeps its latest event time in relay_mode_seen, including
messages that need no review. The identity is the channel and listing, or the channel and item
for ERPNext-item-only messages. Existing review history is still compared, so earlier queued
messages remain protected when upgrading. Historical no-change event times absent from stored
history cannot be reconstructed; newly processed messages persist them.
Regression: RelayIntakeTest::testANoChangeMessageRemainsNewerThanADelayedModeChange.

XA8. Mode intake locks the existing channel row before claiming lines or reading history.
The first two messages for a subject therefore decide in sequence even with no review history.
The two-connection regression interrupts the first intake between reading history and writing
its review, proves the second waits, then retries it after the first commits.
Regression: RelayIntakeTest::testConcurrentFirstModeMessagesAreSerialisedBeforeHistoryIsRead.

Migration 0043 creates two small tables and backfills replacement metadata; it does not alter
existing tables or write stock. Both tables forbid application deletes; replacement ancestry
also forbids updates, and mode records permit updates only to their event timestamp. No new
business setting or numeric policy is introduced. These changes enforce existing snapshot,
replay, chronology and merge rules. Stock.php remains the sole stock writer and the stock lock
order is unchanged.

Validation in the read-only workspace: git apply --check and PHP syntax lint on the virtual
patched files. PHPUnit and MySQL validation are left to the integrator on staging.
