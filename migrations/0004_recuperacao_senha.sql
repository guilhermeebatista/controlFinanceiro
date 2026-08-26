-- Recuperação de senha: token de uso único, guardado como hash.
--
-- Mesmo desenho de email_verifications (0001): a chave primária é o user_id,
-- então cada conta tem no máximo um pedido ativo e um pedido novo substitui o
-- anterior. Isso evita acumular tokens válidos para a mesma conta.
--
-- O token nunca é gravado em claro. O que chega ao usuário por e-mail são 64
-- caracteres hex (32 bytes de random_bytes); o banco guarda só o SHA-256. Um
-- vazamento do banco não permite redefinir a senha de ninguém.

CREATE TABLE IF NOT EXISTS password_resets (
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME     NOT NULL,
  PRIMARY KEY (user_id),
  -- A busca no resgate é pelo hash, não pelo user_id: quem chega com o token
  -- ainda não está identificado.
  KEY idx_password_resets_token (token_hash),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
