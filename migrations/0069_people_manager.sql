-- 0069_people_manager.sql — the People manager job (docs/decisions.md PM1-PM7; the owner, 16 Nov 2026: "i want you to build the option to
-- create users, admin roles etc for my cw account. so i can create users there"; 18 Nov 2026: "build it in so Fazil can create [staff]
-- himself"). A job that adds people, gives and takes their jobs, switches them on and off and resets their sign-in, and does nothing
-- else; unlike Admin it goes with any working job, so the owner and Fazil keep posting and reviewing (CW\Auth\Permissions::ROLES,
-- PEOPLE_MANAGER_TASKS). Given to an existing account on the server with bin/grant_role.php, then on Settings › People.
--
--  * staff_role.role gets one more value, `people_manager`, at the END of the ENUM: the stored number of every existing grant stays
--    what it was (1-14), so no row moves and the history reads as before; the new job is number 15.
--  * The permission rows of 0034 (role_permission) are left as they are: the code gives the People manager its four tasks whatever a row
--    says (catalogue.view, reference.view and staff.manage are locked; staff.view always keeps it, Permissions::REQUIRED), and never
--    another one (Permissions::sanitise reads a row naming it anyway without it).
-- No row is written or changed. The old code runs on this schema (it never writes the new value, and reads only the values it knows).
--
-- HOW IT IS APPLIED: staff_role is tiny (one row per job given; a few dozen on live), so it is copied once, on purpose and said out loud:
-- ONE statement, ALGORITHM = COPY, LOCK = SHARED (reads go on; a change of someone's jobs waits the moment the copy takes). A second run
-- changes nothing (the same definition again).

ALTER TABLE staff_role MODIFY COLUMN role ENUM('viewer','mapper','mapping_lead','warehouse','manager','admin','buyer','purchasing_manager',
    'goods_in','purchasing_desk','stock_controller','reviewer','accountant','auditor','people_manager') NOT NULL,
  ALGORITHM = COPY, LOCK = SHARED;
