-- 0063_api_credentials.sql — the keys of the APIs CW calls, entered on Settings › APIs instead of a server file (the owner, 5 Nov 2026:
-- "on cw give me a section called apis and add a section there to add the key i will add there and hit submit"; docs/decisions.md
-- AK1-AK12). Until now the read-only ERPNext user of the invoice copy (CW-917, EC1) was only in /etc/cw/app.env (erp_read_url,
-- erp_read_key, erp_read_secret); app.env stays the fallback while nothing is saved here.
--
--  * api_credential: one row per API CW calls, made HERE (the app login never adds or removes a row: it UPDATEs the columns of a save or a
--    removal only, src/Schema/Grants.php UPDATE_ONLY_COLUMNS). Today one: erp_read.
--  * The key and the secret are sealed with app.env ui_secret_key (Staff\SecretBox, the key of the 2-step codes: XSalsa20-Poly1305,
--    nonce || box) and never kept, shown, logged or audited in clear; key_last4 (the key's last 4 characters) is all a screen shows of
--    them. A row is whole or empty: the address, the sealed key, its last 4 and the sealed secret are all there or all NULL (a removal
--    empties the row; the row stays, with who emptied it and when).
--  * The address is https://<a name> only (no IP address, port, path, user or query: AK4), lower case, as Admin\ApiCredentials::url()
--    writes it.
--  * version: +1 on every save and removal; a form drawn at another version is refused (409), nothing written.
--
-- HOW IT IS APPLIED: one new table (CREATE TABLE IF NOT EXISTS) and its one row (INSERT guarded by NOT EXISTS). Nothing existing is
-- touched; a second run, or a run stopped between the two statements, adds nothing twice. The old code runs on this schema (it never
-- reads the table).

CREATE TABLE IF NOT EXISTS api_credential (
  name           VARCHAR(32)      NOT NULL COMMENT 'which API (erp_read: the read-only ERPNext user of the invoice copy, CW-917)',
  url            VARCHAR(200)     NULL COMMENT 'https://<name>, as Admin\\ApiCredentials::url() writes it; NULL: nothing saved',
  key_enc        VARBINARY(255)   NULL COMMENT 'the API key sealed with app.env ui_secret_key (Staff\\SecretBox: nonce || box); never shown',
  key_last4      CHAR(4)          NULL COMMENT 'the last 4 characters of the key: all a screen ever shows of it',
  secret_enc     VARBINARY(255)   NULL COMMENT 'the API secret sealed the same way; never shown',
  version        INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT '+1 on every save and removal (a form drawn at another version is refused)',
  created_actor  VARCHAR(64)      NOT NULL,
  created_at     DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by     INT UNSIGNED     NULL COMMENT 'the staff member of the last save or removal',
  updated_actor  VARCHAR(64)      NOT NULL,
  updated_at     DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (name),
  KEY ix_api_credential_updated_by (updated_by),
  CONSTRAINT fk_api_credential_updated_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_api_credential_name CHECK (REGEXP_LIKE(name, '^[a-z][a-z0-9_]{1,31}$', 'c')),
  CONSTRAINT ck_api_credential_whole CHECK ((url IS NULL) = (key_enc IS NULL) AND (key_enc IS NULL) = (key_last4 IS NULL)
    AND (key_enc IS NULL) = (secret_enc IS NULL)),
  CONSTRAINT ck_api_credential_url CHECK (url IS NULL OR REGEXP_LIKE(url, '^https://[a-z0-9.-]{1,180}$', 'c')),
  CONSTRAINT ck_api_credential_last4 CHECK (key_last4 IS NULL OR REGEXP_LIKE(key_last4, '^[A-Za-z0-9]{4}$', 'c')),
  CONSTRAINT ck_api_credential_sealed CHECK ((key_enc IS NULL OR LENGTH(key_enc) > 40) AND (secret_enc IS NULL OR LENGTH(secret_enc) > 40)),
  CONSTRAINT ck_api_credential_actor CHECK (created_actor <> '' AND updated_actor <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the keys of the APIs CW calls (Settings > APIs, 0063): sealed, never shown; one row per API, made by migration';

INSERT INTO api_credential (name, created_actor, updated_actor)
  SELECT 'erp_read', 'system:migrate', 'system:migrate' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM api_credential WHERE name = 'erp_read');
