-- 0052_invoice_due_date.sql — the supplier invoice on one page (the owner's request of 29 Oct 2026: the purchase invoice as easy and
-- professional as Zoho Inventory's "New Bill"; docs/decisions.md PV): the invoice's due date.
--
--  * supplier_invoice.due_date: when the supplier wants the invoice paid, as the person keys it from the paper (the page fills it from the
--    date on the paper and the supplier's payment terms, supplier.payment_terms_days, when CW has them; the person may change or clear it).
--    NULL: not known (every invoice before this file, every credit note: a credit note is not paid). It changes nothing in the 3-way
--    match, the postings or the stock: the list of invoices shows it beside what is still owed. Payments are not in CW (PC15).
--
-- HOW IT IS APPLIED: one column at the end of the row, ALGORITHM = INSTANT (no table copy, no lock beyond a moment's metadata lock); no row
-- is changed and nothing is deleted. Apply it before the code that reads the column (the deploy runs migrations first); the old code runs
-- on this schema (its writes of supplier_invoice name their columns; a SELECT * takes the new one along).

ALTER TABLE supplier_invoice ADD COLUMN due_date DATE NULL COMMENT 'when the supplier wants it paid, as keyed (NULL: not known; a credit note has none)', ALGORITHM = INSTANT;
