-- 0059_mode_review_off.sql — ERPNext's selling-mode changes stop waiting in Approvals (the owner, 4 Nov 2026: "you close it all and it
-- should not come to approvals any more"; docs/decisions.md MO1-MO3). While CW only listens ERPNext owns the selling modes, so a person
-- answering them changed nothing useful (CW-209), and at the switch CW starts from the shop's modes.
--
--  * app_setting `relay.mode_review` (bool, OFF; Settings > Rules and numbers > Messages from ERPNext, "Ask about ERPNext mode
--    changes"), with its version 1 (baseline) in config_change. Agreed (provisional 0): the owner asked for it. Off: RelayIntake notes
--    each change (relay_line no_change, reason review_off; relay_mode_seen keeps its time) and asks no one; an older request of the same
--    variant still open is closed as superseded, as a change that changes nothing does. On: as before 0059.
--  * Every ERPNext mode change still open is closed (dismissed, resolution review_off, with a note), one audit row saying how many.
--    Nothing else changes: no stock rule, no selling mode, no low-stock level, no other queue (count_gate and the other policy_review
--    sources stay as they are).
--
-- HOW IT IS APPLIED: the setting row, its baseline, the audit row (only while some are open and no 0059 audit row exists yet: written
-- before the close, so it counts them), then the close. Each statement is safe to run again: a second run adds no row and changes none,
-- and a run stopped between the audit row and the close writes no second audit row when it is run again (the Codex review, MEDIUM 1).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description)
  SELECT 'relay.mode_review', 'bool', 'false', 0, 'MO1',
    'On: each selling-mode change ERPNext makes waits in Approvals > Mode changes for a person. Off: CW notes it and asks no one'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM app_setting s WHERE s.setting_key = 'relay.mode_review');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key = 'relay.mode_review'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);

INSERT INTO audit_log (actor, action, entity_type, entity_id, detail)
  SELECT 'system:migrate', 'queues.modes.close_all', 'policy_review', 'erp_update_product',
    JSON_OBJECT('closed', COUNT(*), 'oldest', MIN(created_at), 'newest', MAX(created_at), 'migration', '0059_mode_review_off.sql')
  FROM policy_review WHERE source = 'erp_update_product' AND status = 'open'
    AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.entity_type = 'policy_review' AND a.entity_id = 'erp_update_product'
      AND a.action = 'queues.modes.close_all' AND a.actor = 'system:migrate')
  HAVING COUNT(*) > 0;

UPDATE policy_review SET status = 'dismissed', resolution = 'review_off', resolved_at = UTC_TIMESTAMP(6),
    note = 'Closed in one go: ERPNext mode changes no longer wait in Approvals (the owner, 4 Nov 2026; setting relay.mode_review off)'
  WHERE source = 'erp_update_product' AND status = 'open';
