# Xhigh fixes B

The pack C and pack H findings were checked against the current tree after packs J
and C2. All ten still apply. These changes preserve the stock writer and its lock
order. They introduce no adjustable thresholds, so no Settings rule or migration
is needed. No existing decision file is changed.

1. **XB1 (C1): acceptance covers the displayed differences.** The acceptance form
   carries the fingerprint of the same match used to render its figures. The service
   requires it and compares it with a fresh match while holding the existing document,
   receipt and invoice locks. Missing or changed fingerprints give 409
   `match_changed`; the page shows the current figures and keeps the reason typed.
   Existing permissions and the second-person switch still apply.

2. **XB2 (C2): allocate every returned unit before residual money.** Automatic credit
   notes walk all matching invoice goods lines in line order, respecting posted
   credits and units already allocated in the new draft. A money-only remainder is
   added after this allocation, rather than after the first matching line.

3. **XB3 (C3): preserve packs and reconcile the exact claim.** Complete packs retain
   the invoice's pack price when it represents the return's unit cost. Partial packs
   retain the return's unit cost rounded to the existing four-decimal price format.
   The difference between those goods amounts and the return's exact claim is an
   `other` amount of either sign. The existing signed-other representation is also
   accepted on credit notes; freight, duty and service amounts remain positive, and
   the existing nonnegative document-total check remains in force. No price
   precision, tolerance, stock movement or value-event formula is changed.

4. **XB4 (C4): explicit return links reserve their quantities.** Normalisation counts
   all explicitly linked draft goods by the header delivery's line and destination
   bucket before allocating unlinked goods. This includes explicit links later in
   the submitted list. VERIFY, UNSTAMPED and accepted quantities remain separate.

5. **XB5 (C5): draft order quantities belong to their order.** Adding an order
   subtracts only draft quantities linked to that order, even when another order has
   the same line number. Adding the same order again still refuses duplicates.

6. **XB6 (H1): one payment per submitted form.** Each customer's payment form has its
   own FormOnce key. The insert, audit and saved redirect share its transaction.
   Retries replay the redirect, changed values with the same key are refused, and
   validation failures preserve the key so a corrected form can be submitted.

7. **XB7 (H2): validate price separators before stripping them.** Price imports accept
   the existing pound prefix, dot decimals and correctly grouped thousands commas.
   Ambiguous comma decimals, malformed grouping, internal spaces and values beyond
   the existing precision/range are skipped as bad prices, with no remembered-price
   write for those rows.

8. **XB8 (H3): one company version per hand-over posting.** Posting reads the company
   profile once and passes it to VAT resolution, then stores that same profile in
   the snapshot. A concurrently saved profile applies to subsequent postings.

9. **XB9 (H4): unstamped extras participate in the SQL filter.** A stamp-required
   receipt with damaged or over units and no stamp is classified as unstamped even
   when its ordinary unstamped-unit count is zero. Counts, pages and exports share
   this classification. Declaration classification retains precedence.

10. **XB10 (H5): count physical stamps independently of stock destination.** Damaged
    and over units without a stamp count as unstamped even when a declaration sent
    them to VERIFY. Extras on a stamped declaration line remain stamped. Short and
    wrong-item units are excluded as before.

Regression coverage is in the invoice and hand-over XhighFixesBTest classes,
DutyRegisterTest, HandoverScreensTest and InvoiceScreensTest. Race tests use the
existing HookedStatement helper on an extra connection. The patch passed
`git apply --check --whitespace=error-all` and PHP syntax checks for all 17 changed
or added PHP files. Runtime PHPUnit validation is left to the integrator; no
database or URL was accessed while preparing the diff.
