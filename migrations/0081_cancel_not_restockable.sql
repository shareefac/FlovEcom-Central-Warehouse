-- 0081_cancel_not_restockable.sql — a cancelled paid unit the store marked "don't restock" is written off at once (tracker CW-1180; the
-- owner, CW chat 25 Nov 2026: "if the staff said dont restock then dont reestock", and "Yes, build it"; docs/decisions.md NR1-NR8).
-- Until now such a unit was moved to VERIFY ("Doubtful cancels awaiting recount") with a recount task nobody did (D37): 1,238 units,
-- GBP 2,435, piled up from 28 Oct to 25 Nov and were cleared by hand (What's new #625).
--
--  * app_setting `stock.cancel_not_restockable` ("write_off"; one of write_off, verify), set on the Settings page (under "Stock records")
--    with a reason, with its version 1 (baseline) in config_change. "write_off" (the owner's rule): the unit leaves stock at once, a
--    write-off at its warehouse at its average cost, no VERIFY move and no recount. "verify": the way before (D37), with no deploy.
--    The code reads "verify" (the way before) while the row is not there, so a release may run a moment before this file and changes
--    nothing until it has run.
--
-- HOW IT IS APPLIED: two rows; no table changes, no row changed, nothing deleted. A second run adds nothing (the same key, the baseline
-- once). It does not need 0080 (PayPal fees): the two files touch nothing of each other and may be applied in either order.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('stock.cancel_not_restockable','string','"write_off"',1,'NR1','A cancelled paid unit the store marked "don''t restock": write_off (written off at once at its cost) or verify (moved to Doubtful cancels for a recount, as before)')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key = 'stock.cancel_not_restockable'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
