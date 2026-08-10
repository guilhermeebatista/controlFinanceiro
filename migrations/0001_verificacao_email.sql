-- Verificação de e-mail no cadastro: coluna em users + tabela do código
-- pendente.
--
-- Sem "IF NOT EXISTS" no ADD COLUMN: MySQL 8.4 não aceita essa cláusula ali
-- (testado — dá erro de sintaxe). Não precisa: o runner em bin/migrate.php já
-- garante que este arquivo roda uma única vez, via schema_migrations.

ALTER TABLE users
  ADD COLUMN email_verificado_em DATETIME NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  user_id     INT UNSIGNED NOT NULL,
  codigo_hash CHAR(64)     NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME     NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill único: contas existentes nunca tiveram chance de confirmar e-mail
-- pelo fluxo novo, então entram já verificadas (nasceram antes da exigência
-- existir). Cadastros feitos depois desta migração nascem com
-- email_verificado_em = NULL normalmente.
UPDATE users SET email_verificado_em = criado_em WHERE email_verificado_em IS NULL;
