-- 0055_nightly_confirm.sql — the nightly Confirm all (the owner, 1 Nov 2026: "Make it automatic", then "Yes, build it" on NightlyConfirm.dc.html;
-- docs/decisions.md NC1-NC12): Settings > Rules and numbers > Messages from ERPNext gets a switch, the time it runs and the most a night;
-- bin/confirm_corrections_nightly.php (cron, every 5 minutes) does what Confirm all ready does, once a night, while the switch is on.
--
--  * app_setting `relay.nightly_confirm` (bool, OFF), `relay.nightly_confirm_at` ("04:30", UK time HH:MM, 00:00-23:59) and
--    `relay.nightly_confirm_max` (500; 1-5000), each with its version 1 (baseline) in config_change, provisional as every new setting
--    ("Not agreed yet" until the owner ticks it agreed). The big-change limit is 0054's relay.correction_bulk_max_units. The code reads
--    each with its default, so it may run a moment before this file (off: nothing happens).
--
-- HOW IT IS APPLIED: three rows and their three baselines; no table changes, no row changed, nothing deleted. Both statements are safe to
-- run again (as 0054): a run stopped between them, or a second run, neither fails nor duplicates a baseline nor overwrites a value set since.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description)
  SELECT k.setting_key, k.value_type, k.value_json, 1, 'NC2', k.description FROM (
    SELECT 'relay.nightly_confirm' AS setting_key, 'bool' AS value_type, 'false' AS value_json,
      'On: every night CW confirms the ready ERPNext corrections by itself, the same way as Confirm all ready' AS description
    UNION ALL SELECT 'relay.nightly_confirm_at', 'string', '"04:30"', 'The UK time (HH:MM) the nightly Confirm all runs; it starts within the hour after it'
    UNION ALL SELECT 'relay.nightly_confirm_max', 'int', '500', 'The most ERPNext corrections the nightly Confirm all confirms in one night'
  ) k WHERE NOT EXISTS (SELECT 1 FROM app_setting s WHERE s.setting_key = k.setting_key);

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('relay.nightly_confirm', 'relay.nightly_confirm_at', 'relay.nightly_confirm_max')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
