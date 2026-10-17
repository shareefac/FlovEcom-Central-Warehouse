-- XA2: a replacement's earlier opening is part of its ancestry, per item actually booked.
-- New tables only: no existing table is rebuilt, and no stock or configuration row is changed.
-- No foreign keys: these records must not acquire item locks ahead of stock_balance.
CREATE TABLE opening_replacement (
  warehouse_id     SMALLINT UNSIGNED NOT NULL,
  sku_id           INT UNSIGNED NOT NULL,
  doc_ref          VARCHAR(191) COLLATE utf8mb4_0900_bin NOT NULL,
  replaces_doc_ref VARCHAR(191) COLLATE utf8mb4_0900_bin NOT NULL,
  PRIMARY KEY (warehouse_id, sku_id, doc_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Existing completed or failed imports recorded --replaces in summary.report. Join their booked
-- items only: a failed run must not grant ancestry to an item it never reached.
INSERT INTO opening_replacement (warehouse_id, sku_id, doc_ref, replaces_doc_ref)
SELECT DISTINCT l.warehouse_id, l.sku_id, r.doc_ref COLLATE utf8mb4_0900_bin,
  JSON_UNQUOTE(JSON_EXTRACT(r.summary, '$.report.replaces'))
FROM erp_opening_run r
JOIN stock_ledger l ON l.doc_ref COLLATE utf8mb4_0900_bin = r.doc_ref COLLATE utf8mb4_0900_bin
  AND l.actor = 'system:opening_estimate' AND l.bucket = 'on_hand'
WHERE JSON_TYPE(JSON_EXTRACT(r.summary, '$.report.replaces')) = 'STRING'
  AND JSON_UNQUOTE(JSON_EXTRACT(r.summary, '$.report.replaces')) <> ''
  AND JSON_UNQUOTE(JSON_EXTRACT(r.summary, '$.report.replaces')) COLLATE utf8mb4_0900_bin
      <> r.doc_ref COLLATE utf8mb4_0900_bin;

-- XA7: the latest known event time survives even when no staff review is needed.
-- The channel row serialises intake before reading or writing this table (XA8).
CREATE TABLE relay_mode_seen (
  channel_id   SMALLINT UNSIGNED NOT NULL,
  subject_kind ENUM('listing','sku') NOT NULL,
  subject_id   INT UNSIGNED NOT NULL,
  changed_at   DATETIME(6) NOT NULL,
  PRIMARY KEY (channel_id, subject_kind, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
