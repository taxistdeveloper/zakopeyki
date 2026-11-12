-- AI Virtual Try-On (docs/tryon/03-database.md). Дублирует ensureSchema() в App\Models\TryOnJob / TryOnAsset.
-- Запускать вручную на dev/staging; на проде — только после согласования.

ALTER TABLE products
  ADD COLUMN tryon_type VARCHAR(16) DEFAULT NULL AFTER category;

CREATE TABLE IF NOT EXISTS tryon_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  tryon_type VARCHAR(16) NOT NULL,
  garment_image_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'queued',
  provider VARCHAR(32) DEFAULT NULL,
  provider_model VARCHAR(64) DEFAULT NULL,
  external_id VARCHAR(128) DEFAULT NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_poll_at DATETIME DEFAULT NULL,
  locked_until DATETIME DEFAULT NULL,
  locked_by VARCHAR(64) DEFAULT NULL,
  cost_estimate_micros INT UNSIGNED NOT NULL DEFAULT 0,
  cost_actual_micros INT UNSIGNED DEFAULT NULL,
  error_code VARCHAR(64) DEFAULT NULL,
  consent_version VARCHAR(32) NOT NULL,
  consent_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uk_tryon_public (public_id),
  UNIQUE KEY uk_tryon_idem (user_id, idempotency_key),
  INDEX idx_tryon_worker (status, next_poll_at),
  INDEX idx_tryon_user (user_id, created_at),
  INDEX idx_tryon_expires (expires_at),
  CONSTRAINT fk_tryon_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tryon_jobs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tryon_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,
  storage_path VARCHAR(255) DEFAULT NULL,
  mime VARCHAR(32) NOT NULL DEFAULT 'image/jpeg',
  bytes INT UNSIGNED NOT NULL DEFAULT 0,
  width SMALLINT UNSIGNED DEFAULT NULL,
  height SMALLINT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  deleted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uk_tryon_asset (job_id, kind),
  INDEX idx_tryon_assets_expires (deleted_at, expires_at),
  INDEX idx_tryon_assets_sha (owner_user_id, sha256),
  CONSTRAINT fk_tryon_assets_job FOREIGN KEY (job_id) REFERENCES tryon_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
