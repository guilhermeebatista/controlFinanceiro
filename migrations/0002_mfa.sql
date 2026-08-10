-- MFA (TOTP): segredo cifrado em users + tabelas de pendência de login e de
-- códigos de backup.

ALTER TABLE users
  ADD COLUMN mfa_secret_cifrado VARBINARY(255) NULL,
  ADD COLUMN mfa_ativado_em     DATETIME       NULL;

CREATE TABLE IF NOT EXISTS mfa_pending (
  token_hash  CHAR(64)                NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED            NOT NULL,
  modo        ENUM('setup','verify')  NOT NULL,
  criado_em   DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME                NOT NULL,
  KEY ix_mfa_pending_user (user_id),
  CONSTRAINT fk_mfa_pending_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_backup_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  codigo_hash CHAR(64)     NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usado_em    DATETIME     NULL,
  KEY ix_mfa_backup_codes_user (user_id),
  CONSTRAINT fk_mfa_backup_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
