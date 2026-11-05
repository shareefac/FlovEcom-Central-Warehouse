-- 0062_supplier_details_optional.sql — a supplier's address, contact, payment terms and due-diligence check are no longer needed before
-- it is usable (the owner, 5 Nov 2026: "then why we are keeping all those mandatory same as erp dont make it mandatory. change it and
-- finish the job"; docs/decisions.md SD1-SD3). ERPNext never kept any of them: its 48 suppliers hold a name only (read 5 Nov 2026), so
-- the 48 copied into CW on 4 Nov could not be made usable and Reorder could not draft orders for them.
--
--  * Four switches (Settings > Rules and numbers > Suppliers), each OFF, agreed (provisional 0: the owner asked), each with its version 1
--    (baseline) in config_change: suppliers.need_address (address line 1, postcode, country), suppliers.need_contact (e-mail or phone),
--    suppliers.need_payment_terms, suppliers.need_due_diligence (checked on, by, next review). On: as before 0062. The name is always
--    needed; an overseas supplier's import route is needed as before (no switch).
--  * Nothing else changes: no supplier row, no approval rule (the "New suppliers" second-person rule stays as it is), no order.
--
-- HOW IT IS APPLIED: the four setting rows, then their baselines. Each statement is guarded (NOT EXISTS): a second run, or a run stopped
-- between the two, adds nothing twice.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description)
  SELECT k.setting_key, 'bool', 'false', 0, 'SD1', k.description FROM (
    SELECT 'suppliers.need_address' AS setting_key,
      'On: a supplier is usable only once its address line 1, postcode and country are filled in. Off: not needed' AS description
    UNION ALL SELECT 'suppliers.need_contact', 'On: a supplier is usable only once it has an e-mail or a phone number. Off: not needed'
    UNION ALL SELECT 'suppliers.need_payment_terms', 'On: a supplier is usable only once its payment terms are filled in. Off: not needed'
    UNION ALL SELECT 'suppliers.need_due_diligence',
      'On: a supplier is usable only once its due-diligence check (when, who, next review) is recorded. Off: not needed'
  ) k WHERE NOT EXISTS (SELECT 1 FROM app_setting s WHERE s.setting_key = k.setting_key);

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('suppliers.need_address', 'suppliers.need_contact', 'suppliers.need_payment_terms', 'suppliers.need_due_diligence')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
