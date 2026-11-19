-- 0073_store_choice_existing.sql — a store's choice "Existing item" (tracker CW-1138; docs/decisions.md SX1, SX4; the owner, 19 Nov 2026:
-- "we should have an option under new item something like existing. so i can just add the cw code there"). At Add product staff may say
-- that a new variant IS an item the warehouse already has: its CW number travels in pack_of_code, with no units (one sale takes one).
--
--  * store_choice.choice gains 'existing', added at the END of the list, so the stored values keep their numbers.
--  * ck_store_choice_pack: an existing item names its item and no units; every other choice is checked exactly as before (a pack names its
--    item and its units, a new item or not stock never both), so every row there before passes it unchanged.
-- No row is written or changed; the CHECK of match_decision already lets the store choice job make a link (0058).
--
-- HOW IT IS APPLIED: ONE statement, so it is applied whole or not at all; the CHECK makes MySQL copy the table (ALGORITHM = COPY,
-- LOCK = SHARED: reads go on, a store's new choice waits until the copy ends). store_choice is small (a row per variant added since
-- 3 Nov 2026): well under a second. Running it again changes nothing.

ALTER TABLE store_choice
  MODIFY COLUMN choice ENUM('new','pack','none','existing') NOT NULL,
  DROP CHECK ck_store_choice_pack,
  ADD CONSTRAINT ck_store_choice_pack CHECK ((choice = 'existing' AND pack_of_code IS NOT NULL AND units IS NULL)
    OR (choice <> 'existing' AND (choice = 'pack') = (pack_of_code IS NOT NULL AND units IS NOT NULL))),
  ALGORITHM = COPY, LOCK = SHARED;
