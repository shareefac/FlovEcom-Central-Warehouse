-- 0071_po_draft_look.sql — one look for a purchase order (live drafts, step 2; docs/decisions.md LP1-LP8; the owner, 18 Nov 2026, of the
-- read-only order page: "I liked how it looks on Shareef's window, the design"). A draft and a new order are drawn as the saved order's paper
-- with their boxes in place (purchase_order.php); the grey one-page form (purchase_order_edit.php) stays for one release as the way back.
--
--  * app_setting `po.draft_look` ("paper"; one of paper, form), set on the Settings page with a reason, with its version 1 (baseline) in
--    config_change. "form" draws drafts and new orders on the old form again, with no deploy. The code reads "paper" while the row is not
--    there, so it may run a moment before this file.
--
-- HOW IT IS APPLIED: two rows; no table changes, no row changed, nothing deleted. A second run adds nothing (the same key, the baseline once).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('po.draft_look','string','"paper"',1,'LP2','How a draft or new purchase order is drawn: paper (the page of a saved order, its boxes in place) or form (the one-page form as before)')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key = 'po.draft_look'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
