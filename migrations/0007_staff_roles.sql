-- 0007_staff_roles.sql — IM1: several roles per person (docs/decisions.md I10-I16).
--
--  * staff_role keeps the history: a grant is revoked (revoked_at, revoked_by), never deleted
--    (app login: SELECT, INSERT + UPDATE of revoked_at, revoked_by; CW\Schema\Grants::UPDATE_COLUMNS). A grant is
--    never revoked before it was given (ck_staff_role_order). The authoritative record of who changed what is the
--    insert-only audit_log (staff.roles): the app login could still clear a revoked_at (I35).
--  * The stored generated column active_staff_user_id is the person while the grant is live and NULL once it is
--    revoked, so UNIQUE (active_staff_user_id, role) allows one live grant per person and role and any number of
--    revoked ones (NULLs never collide).
--  * Every person keeps the role they had: one grant each (granted_by NULL: the migration, named in audit_log),
--    then staff_user.role is dropped, so code still reading it fails loudly instead of trusting a stale column (I10).
--    Forward-only (D25): deploy this migration together with the code that reads staff_role.
CREATE TABLE staff_role (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_user_id         INT UNSIGNED NOT NULL,
  role                  ENUM('viewer','mapper','mapping_lead','warehouse','manager','admin','buyer','purchasing_manager',
                             'goods_in','purchasing_desk','stock_controller','reviewer','accountant','auditor') NOT NULL,
  granted_by            INT UNSIGNED NULL COMMENT 'staff_user.id of the admin; NULL = a CLI tool or the 0007 backfill (audit_log names it)',
  granted_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_by            INT UNSIGNED NULL,
  revoked_at            DATETIME(6) NULL,
  active_staff_user_id  INT UNSIGNED GENERATED ALWAYS AS (IF(revoked_at IS NULL, staff_user_id, NULL)) STORED COMMENT 'at most one live grant per person and role',
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_role_active (active_staff_user_id, role),
  KEY ix_staff_role_user (staff_user_id, revoked_at),
  KEY ix_staff_role_role (role, active_staff_user_id),
  KEY ix_staff_role_granted_by (granted_by),
  KEY ix_staff_role_revoked_by (revoked_by),
  CONSTRAINT fk_staff_role_user FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT fk_staff_role_granted_by FOREIGN KEY (granted_by) REFERENCES staff_user (id),
  CONSTRAINT fk_staff_role_revoked_by FOREIGN KEY (revoked_by) REFERENCES staff_user (id),
  CONSTRAINT ck_staff_role_revoked CHECK (revoked_by IS NULL OR revoked_at IS NOT NULL),
  CONSTRAINT ck_staff_role_order CHECK (revoked_at IS NULL OR revoked_at >= granted_at),
  CONSTRAINT ck_staff_role_not_self CHECK ((granted_by IS NULL OR granted_by <> staff_user_id) AND (revoked_by IS NULL OR revoked_by <> staff_user_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='roles per person with history: a grant is revoked, never deleted (app login: SELECT, INSERT + UPDATE of revoked_at, revoked_by)';

INSERT INTO staff_role (staff_user_id, role, granted_by, granted_at)
  SELECT id, CAST(role AS CHAR), NULL, created_at FROM staff_user ORDER BY id;
INSERT INTO audit_log (actor, action, entity_type, entity_id, detail)
  SELECT 'system:migrate', 'staff.roles', 'staff_user', CAST(id AS CHAR),
         JSON_OBJECT('migration', '0007_staff_roles.sql', 'roles', JSON_ARRAY(CAST(role AS CHAR))) FROM staff_user ORDER BY id;
ALTER TABLE staff_user DROP COLUMN role;
