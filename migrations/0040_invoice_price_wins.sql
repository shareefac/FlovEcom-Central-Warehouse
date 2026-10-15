-- 0040_invoice_price_wins.sql — pack C follow-up (docs/decisions.md PC19; the owner, 15 Oct 2026: "end of the day whatever comes in
-- purchase invoice is the right price"). 0032 is applied on staging, so the new switch comes in a migration of its own.
--
--  * invoices.check_price (bool, off): off, a supplier invoice's price that differs from the order (else the delivery) never puts a
--    line outside the 3-way match, whatever its size: the line is matched, the difference is shown on the invoice as information and
--    still recorded for the stock value (invoice_value_event, as before: the invoice's price is the cost carried forward). On, the two
--    price tolerances (invoices.price_tolerance_pct / _gbp) apply as before. The quantity is checked either way: billing more units
--    than arrived still needs a person on "To match". Not provisional: it is the owner's own decision. Its version 1 in config_change
--    (docs/dev.md rule 5).
--
-- The matches stored before this migration keep the rules they were worked out with (supplier_invoice.match_rules has no check_price:
-- read as on, InvoiceInvariants V4); "Check these again" on "To match", or any later change on their deliveries, works them out with
-- the switch.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('invoices.check_price','bool','false',0,'PC19','Check an invoice line''s price against the order (else the delivery) with the two price tolerances; off: the invoice''s price is the right price (the difference is shown and recorded, never a mismatch)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key = 'invoices.check_price';
