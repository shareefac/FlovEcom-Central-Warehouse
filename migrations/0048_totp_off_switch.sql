-- 2-step verification switched off for a while (docs/decisions.md TF1-TF6; the owner's decision of 20 Oct 2026, against advice: "turn 2-step
-- verification off temporarily, I will tell you when to turn it back on"). auth.totp_off_until holds a UK time as typed on the Settings page
-- (YYYY-MM-DD HH:MM): while it is ahead, the sign-in asks for the e-mail and the password only (Auth\SecondStep, Auth\Login); at that time
-- the code is asked for again by itself. Settings::RULES refuses a time more than 90 days ahead, or one that happens twice or not at all in
-- the UK. Empty here: nothing changes until someone sets a time, with a reason. No table changes.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('auth.totp_off_until','string','""',1,'TF1','A security risk: until this UK time (YYYY-MM-DD HH:MM, at most 90 days ahead) the sign-in asks for the password only, not the 6-digit code; empty or past: the code is asked for');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key = 'auth.totp_off_until';
