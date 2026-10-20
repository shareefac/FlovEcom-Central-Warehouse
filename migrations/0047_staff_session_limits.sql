-- How long a person stays signed in (docs/decisions.md SL1-SL5; the owner, 20 Oct 2026: "we face session time out easily, once logged in
-- don't time out"). The two limits Auth\Sessions had fixed (30 minutes without a page, 12 hours after signing in) become settings, changed
-- on the Settings page with a reason like every other. Their values here are those limits, so nothing changes until someone raises them;
-- Settings::RULES allows 30 minutes to 30 days and 12 hours to 90 days (never shorter than before). No table changes.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('session.idle_minutes','int','30',1,'SL1','Minutes without opening a page after which a person is signed out (30 to 43200, which is 30 days)'),
 ('session.absolute_hours','int','12',1,'SL1','Hours after signing in after which a person is signed out however active (12 to 2160, which is 90 days); the sign-in cookie lives as long');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('session.idle_minutes', 'session.absolute_hours');
