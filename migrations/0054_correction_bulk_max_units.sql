-- 0054_correction_bulk_max_units.sql — "Confirm all ready" for ERPNext corrections (the owner, 31 Oct 2026: "Design a Confirm-all", then on
-- the design "Yes, build it"; docs/decisions.md CA1-CA12): Approvals with Show = ERPNext corrections splits the list into Ready to confirm and
-- Needs a look; one rule of Needs a look is a big change, and how big is a setting (the owner's rule: everything settable in CW).
--
--  * app_setting `relay.correction_bulk_max_units` (100; 0-100000), set on the Settings page (Messages from ERPNext) with a reason, with its
--    version 1 (baseline) in config_change. A correction that adds or takes off more than this many units is left for a person by Confirm
--    all (0: only the corrections with no difference are confirmed together). One by one, a person still confirms any correction. The code
--    reads it with a default of 100, so it may run a moment before this file.
--
-- HOW IT IS APPLIED: two rows; no table changes, no row changed, nothing deleted. Both statements are safe to run again (docs/decisions.md CA19):
-- a run stopped between them, or a second run, neither fails nor duplicates the baseline nor overwrites a value set since (NOT EXISTS).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description)
  SELECT 'relay.correction_bulk_max_units', 'int', '100', 1, 'CA3',
    'Confirm all ERPNext corrections leaves a correction for a person when it adds or takes off more than this many units'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM app_setting WHERE setting_key = 'relay.correction_bulk_max_units');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key = 'relay.correction_bulk_max_units'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = 'relay.correction_bulk_max_units');
