-- 0067_site_mode_website.sql — "Let the website decide" in the item page's "Change the selling mode" (T-556 step 2, option A; the owner,
-- 16 Nov 2026). Until now CW could set a legacy item's selling mode on a website (In-Stock, From-Warehouse, Out-Of-Stock: one
-- item_channel_mode row per item and site, 0018) but never give it back: a site whose row was written once kept CW's mode for good, and
-- the shop's admin keeps a mode CW holds (connector 0.4.12, T-556 step 1). Now the switch can give a site's mode back to the website:
-- the row stays (the app login never deletes it: NO_DELETE, and its log keeps every write, W1) and its mode becomes NULL = "the
-- website decides". CW\SiteWriter\SiteView reads a NULL mode as it reads no row at all (why site_own: the feed sends mode null, "leave
-- the site's own"), so the website's staff set the mode there again. The low-stock threshold of the row is unchanged by this (the
-- switch keeps it, or sets a new one, as for any mode). Counted (protected) items follow their policy on every site, as before.
--
--  * item_channel_mode.mode and item_channel_mode_log.mode_after may be NULL: NULL = the website decides (written by the switch only).
--  * Two CHECKs say so in SQL: a NULL mode keeps no "mode before Out-Of-Stock" and was written by the switch (a delivery always sets
--    a mode). The CHECKs of 0018 stay as they are.
-- No row's data changes. The old code runs on this schema (it always writes a mode).
--
-- HOW IT IS APPLIED: both tables are small (one row per item and site CW set a mode on; a handful on live), so each is copied once, on
-- purpose and said out loud: ONE statement per table, ALGORITHM = COPY, LOCK = SHARED (reads go on; a receipt posting or a switch
-- waits the moment the copy takes). The log first. Each ALTER (a column made NULL-able and its CHECK, in one atomic statement) runs only
-- while its CHECK is not there (a prepared statement, as 0056 and 0060), so a run stopped anywhere (the file is not recorded until its
-- last statement is done) runs again cleanly (the Codex review of option A, MEDIUM 1): an ALTER done is not done twice. A CHECK of that
-- name on a column still NOT NULL stops the migration, naming it. Every existing row already holds the two new CHECKs (their mode is NOT NULL).

SET @cw_0067_log = (SELECT CASE
  WHEN NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'item_channel_mode_log'
      AND CONSTRAINT_NAME = 'ck_item_channel_mode_log_website') THEN
    'ALTER TABLE item_channel_mode_log MODIFY COLUMN mode_after ENUM(''In-Stock'',''From-Warehouse'',''Out-Of-Stock'') NULL COMMENT ''NULL: the switch gave the mode back to the website (0067, Let the website decide)'', ADD CONSTRAINT ck_item_channel_mode_log_website CHECK (mode_after IS NOT NULL OR (previous_mode IS NULL AND source = ''switch'')), ALGORITHM = COPY, LOCK = SHARED'
  WHEN (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_channel_mode_log' AND COLUMN_NAME = 'mode_after') = 'YES'
    THEN 'DO 0'
  ELSE 'SELECT 1 FROM `0067 item_channel_mode_log has its website CHECK but mode_after is not NULL-able`' END);
PREPARE cw_0067 FROM @cw_0067_log;
EXECUTE cw_0067;
DEALLOCATE PREPARE cw_0067;

SET @cw_0067_rows = (SELECT CASE
  WHEN NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'item_channel_mode'
      AND CONSTRAINT_NAME = 'ck_item_channel_mode_website') THEN
    'ALTER TABLE item_channel_mode MODIFY COLUMN mode ENUM(''In-Stock'',''From-Warehouse'',''Out-Of-Stock'') NULL COMMENT ''NULL = the website decides (the switch gave the mode back, 0067), SiteView reads it as no row'', ADD CONSTRAINT ck_item_channel_mode_website CHECK (mode IS NOT NULL OR (previous_mode IS NULL AND source = ''switch'')), ALGORITHM = COPY, LOCK = SHARED'
  WHEN (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_channel_mode' AND COLUMN_NAME = 'mode') = 'YES'
    THEN 'DO 0'
  ELSE 'SELECT 1 FROM `0067 item_channel_mode has its website CHECK but mode is not NULL-able`' END);
PREPARE cw_0067 FROM @cw_0067_rows;
EXECUTE cw_0067;
DEALLOCATE PREPARE cw_0067;
