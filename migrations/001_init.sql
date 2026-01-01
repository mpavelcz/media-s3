-- 001_init.sql
-- MySQL/MariaDB SQL (InnoDB, utf8mb4). Uprav dle svého migračního nástroje.

CREATE TABLE media_asset (
  id INT AUTO_INCREMENT PRIMARY KEY,
  profile VARCHAR(64) NOT NULL,
  source VARCHAR(16) NOT NULL, -- 'upload' | 'remote'
  source_url VARCHAR(2048) NULL,
  original_key_jpg VARCHAR(1024) NULL,
  original_key_webp VARCHAR(1024) NULL,
  original_width INT NULL,
  original_height INT NULL,
  checksum_sha1 CHAR(40) NULL,
  status VARCHAR(16) NOT NULL, -- 'QUEUED'|'PROCESSING'|'READY'|'FAILED'
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_media_asset_profile (profile),
  INDEX idx_media_asset_status (status),
  INDEX idx_media_asset_checksum (checksum_sha1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_variant (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  variant VARCHAR(64) NOT NULL,
  format VARCHAR(10) NOT NULL, -- 'jpg'|'webp'
  s3_key VARCHAR(1024) NOT NULL,
  width INT NOT NULL,
  height INT NOT NULL,
  bytes INT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_media_variant_asset_variant_format (asset_id, variant, format),
  INDEX idx_media_variant_asset (asset_id),
  CONSTRAINT fk_media_variant_asset FOREIGN KEY (asset_id)
    REFERENCES media_asset(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_owner_link (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_type VARCHAR(128) NOT NULL, -- 'Product'/'Post'/'User'... (string)
  owner_id INT NOT NULL,
  asset_id INT NOT NULL,
  role VARCHAR(64) NOT NULL, -- 'main'/'gallery'/'slide'...
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_media_owner_unique (owner_type, owner_id, role, asset_id),
  INDEX idx_media_owner_owner (owner_type, owner_id),
  INDEX idx_media_owner_asset (asset_id),
  CONSTRAINT fk_media_owner_asset FOREIGN KEY (asset_id)
    REFERENCES media_asset(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
