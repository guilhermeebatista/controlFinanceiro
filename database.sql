-- =====================================================================
--  Minhas Contas — schema MySQL 8
-- =====================================================================
--  Gerenciador de contas pessoais multiusuário.
--
--  Convenções adotadas em todo o schema:
--    * InnoDB + utf8mb4 / utf8mb4_unicode_ci  (acentuação e emoji corretos,
--      comparação case- e accent-insensitive — a busca por "alimentacao"
--      acha "ALIMENTAÇÃO").
--    * Todo dado de usuário carrega user_id com FOREIGN KEY ... ON DELETE
--      CASCADE. Excluir a conta apaga tudo dela no próprio banco, sem
--      depender da aplicação lembrar de cada tabela.
--    * Valores monetários em DOUBLE (paridade com o REAL do SQLite de onde
--      este sistema veio; os arredondamentos da aplicação assumem float).
--    * Datas em DATE / DATETIME reais — não texto. Toda a conexão roda em
--      UTC (time_zone = '+00:00'), então CURRENT_TIMESTAMP é UTC.
--
--  Rodar:  mysql -u root -p < database.sql
--  No Docker Compose o arquivo é montado em /docker-entrypoint-initdb.d/
--  e executado sozinho na primeira subida do container do MySQL.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `minhas_contas`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `minhas_contas`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';


-- ---------------------------------------------------------------------
--  users — contas do sistema
-- ---------------------------------------------------------------------
--  senha_hash guarda o resultado de password_hash() (bcrypt, prefixo $2y$).
--  A coluna salt existe apenas para as contas herdadas da versão Python,
--  que usavam PBKDF2-HMAC-SHA256 com salt separado; no primeiro login bem
--  sucedido a aplicação re-hasheia para bcrypt e zera o salt.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario`      VARCHAR(190)  NOT NULL,
  `nome`         VARCHAR(190)  DEFAULT NULL,
  `email`        VARCHAR(190)  DEFAULT NULL,
  `senha_hash`   VARCHAR(255)  NOT NULL,
  `salt`         VARCHAR(64)   NOT NULL DEFAULT '',
  `is_admin`     TINYINT(1)    NOT NULL DEFAULT 0,
  `email_verificado_em` DATETIME DEFAULT NULL,
  `mfa_secret_cifrado`  VARBINARY(255) DEFAULT NULL,
  `mfa_ativado_em`      DATETIME DEFAULT NULL,
  `ultimo_login` DATETIME      DEFAULT NULL,
  `criado_em`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_usuario` (`usuario`),
  UNIQUE KEY `uq_users_email`   (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  sessions — sessões de login (cookie HttpOnly)
-- ---------------------------------------------------------------------
--  Guarda o SHA-256 do token, nunca o token em si: quem ler um dump do
--  banco não consegue montar um cookie válido a partir dele.
--  csrf_hash é o SHA-256 do token CSRF sincronizado com esta sessão.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `token_hash` CHAR(64)     NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `csrf_hash`  CHAR(64)     NOT NULL,
  `criado_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_em`  DATETIME     NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `ix_sessions_user`   (`user_id`),
  KEY `ix_sessions_expira` (`expira_em`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  login_attempts — freio de força bruta por (IP, identificador)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`             VARBINARY(16) NOT NULL,
  `identificador`  VARCHAR(190)  NOT NULL,
  `tentativas`     INT UNSIGNED  NOT NULL DEFAULT 0,
  `bloqueado_ate`  DATETIME      DEFAULT NULL,
  `atualizado_em`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attempt` (`ip`, `identificador`),
  KEY `ix_attempts_atualizado` (`atualizado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  email_verifications — código pendente de ativação da conta
-- ---------------------------------------------------------------------
--  Um código por conta (PK em user_id): reenviar substitui o anterior.
--  Guarda só o SHA-256 do código de 6 dígitos, nunca o código em si. A
--  consulta sempre passa por user_id (resolvido pelo e-mail informado) —
--  nunca por codigo_hash sozinho, senão bastaria acertar QUALQUER código
--  pendente de QUALQUER conta para logar como o dono dela.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `user_id`     INT UNSIGNED NOT NULL,
  `codigo_hash` CHAR(64)     NOT NULL,
  `criado_em`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_em`   DATETIME     NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  password_resets — token de redefinição de senha, uso único
-- ---------------------------------------------------------------------
--  Um pedido por conta (PK em user_id): pedir de novo substitui o anterior.
--  Guarda só o SHA-256 do token, nunca o token em si.
--
--  Aqui a busca É por token_hash sozinho, ao contrário do que a tabela
--  email_verifications acima determina — e de propósito. Aquela regra existe
--  porque o código de lá tem 6 dígitos: com espaço de 1 milhão, procurar só
--  pelo hash deixaria alguém acertar QUALQUER código pendente de QUALQUER
--  conta. O token daqui tem 32 bytes de random_bytes (256 bits), então não há
--  o que adivinhar — e quem chega com o token ainda não se identificou, não
--  existe user_id para restringir a consulta.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `user_id`    INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `criado_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_em`  DATETIME     NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_password_resets_token` (`token_hash`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  mfa_pending — pendência entre a senha e o código MFA (ou o cadastro
--  do autenticador, no primeiro login com MFA exigido)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfa_pending` (
  `token_hash` CHAR(64)               NOT NULL,
  `user_id`    INT UNSIGNED           NOT NULL,
  `modo`       ENUM('setup','verify') NOT NULL,
  `criado_em`  DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_em`  DATETIME               NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `ix_mfa_pending_user` (`user_id`),
  CONSTRAINT `fk_mfa_pending_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  mfa_backup_codes — códigos de uso único para quando o autenticador
--  não está disponível
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mfa_backup_codes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `codigo_hash` CHAR(64)     NOT NULL,
  `criado_em`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usado_em`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_mfa_backup_codes_user` (`user_id`),
  CONSTRAINT `fk_mfa_backup_codes_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  settings — parâmetros por usuário (receita_mensal, custo_vida_mensal,
--  fator_reserva). `chave`/`valor` em vez de key/value: KEY é palavra
--  reservada no MySQL e viraria uma armadilha em toda query.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `user_id` INT UNSIGNED NOT NULL,
  `chave`   VARCHAR(64)  NOT NULL,
  `valor`   DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `chave`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  categories — plano de contas ("classificações")
-- ---------------------------------------------------------------------
--  classificacao é a chave de negócio, no formato "CATEGORIA - Subcategoria".
--  Única por usuário: dois usuários podem ter a mesma sem conflito.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `classificacao` VARCHAR(190) NOT NULL,
  `grupo`         VARCHAR(60)  NOT NULL DEFAULT 'OPERACIONAL',
  `tipo`          VARCHAR(20)  NOT NULL DEFAULT 'DESPESA',
  `categoria`     VARCHAR(120) NOT NULL,
  `subcategoria`  VARCHAR(120) DEFAULT NULL,
  `meta_mes`      DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_user_class` (`user_id`, `classificacao`),
  KEY `ix_categories_user_ordem` (`user_id`, `tipo`, `categoria`),
  CONSTRAINT `fk_categories_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  transactions — lançamentos (aba LANCAMENTO da planilha)
-- ---------------------------------------------------------------------
--  grupo/tipo/categoria/subcategoria são desnormalizados a partir de
--  categories no momento do lançamento — de propósito: o histórico não
--  muda de significado se a classificação for reclassificada depois.
--  Quem edita uma categoria propaga explicitamente (ver CategoryController).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `dt_compra`     DATE         DEFAULT NULL,
  `dt_venc`       DATE         NOT NULL,
  `classificacao` VARCHAR(190) NOT NULL,
  `valor`         DOUBLE       NOT NULL,
  `instituicao`   VARCHAR(120) DEFAULT NULL,
  `pessoa`        VARCHAR(120) DEFAULT NULL,
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'Previsto',
  `obs`           TEXT         DEFAULT NULL,
  `grupo`         VARCHAR(60)  NOT NULL,
  `tipo`          VARCHAR(20)  NOT NULL,
  `categoria`     VARCHAR(120) NOT NULL,
  `subcategoria`  VARCHAR(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tx_user_venc`   (`user_id`, `dt_venc`),
  KEY `ix_tx_user_class`  (`user_id`, `classificacao`),
  KEY `ix_tx_user_pessoa` (`user_id`, `pessoa`),
  KEY `ix_tx_user_tipo`   (`user_id`, `tipo`, `categoria`),
  CONSTRAINT `fk_tx_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  projects — aba PROJETOS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`   INT UNSIGNED NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  `valor`     DOUBLE       NOT NULL DEFAULT 0,
  `ano`       INT          DEFAULT NULL,
  `prazo`     VARCHAR(40)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_projects_user` (`user_id`, `ano`),
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  investments / assets / debts — aba PATRIMONIO
-- ---------------------------------------------------------------------
--  tipo/indexador/taxa descrevem o produto (ver src/Investimentos.php): é o
--  que permite calcular quanto rende por mês. dt_aplicacao é o que define a
--  faixa da tabela regressiva do IR, e valor_aplicado separa principal de
--  rendimento — o imposto só morde a diferença entre os dois.
CREATE TABLE IF NOT EXISTS `investments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `instituicao`    VARCHAR(120) NOT NULL,
  `fixa_var`       VARCHAR(40)  DEFAULT NULL,
  `prazo_projeto`  VARCHAR(40)  DEFAULT NULL,
  `ativo`          VARCHAR(120) DEFAULT NULL,
  `valor`          DOUBLE       NOT NULL DEFAULT 0,
  `tipo`           VARCHAR(40)  NOT NULL DEFAULT 'OUTRO',
  `indexador`      VARCHAR(20)  NOT NULL DEFAULT 'NENHUM',
  `taxa`           DOUBLE       NOT NULL DEFAULT 0,
  `valor_aplicado` DOUBLE       NOT NULL DEFAULT 0,
  `dt_aplicacao`   DATE         DEFAULT NULL,
  `dt_vencimento`  DATE         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_investments_user` (`user_id`, `instituicao`),
  CONSTRAINT `fk_investments_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assets` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `descricao`     VARCHAR(255) NOT NULL,
  `valor`         DOUBLE       NOT NULL DEFAULT 0,
  `saldo_devedor` DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_assets_user` (`user_id`, `descricao`),
  CONSTRAINT `fk_assets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `debts` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `descricao`     VARCHAR(255) NOT NULL,
  `num_parcelas`  INT          NOT NULL DEFAULT 0,
  `valor_parcela` DOUBLE       NOT NULL DEFAULT 0,
  `saldo_devedor` DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_debts_user` (`user_id`, `descricao`),
  CONSTRAINT `fk_debts_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  institutions / people — cadastros auxiliares (aba CONFIGURACAO)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `institutions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `nome`          VARCHAR(120) NOT NULL,
  `tipo`          VARCHAR(40)  DEFAULT NULL,
  `saldo_inicial` DOUBLE       NOT NULL DEFAULT 0,
  `descricao`     VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_institutions_user_nome` (`user_id`, `nome`),
  CONSTRAINT `fk_institutions_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `people` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `nome`    VARCHAR(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_people_user_nome` (`user_id`, `nome`),
  CONSTRAINT `fk_people_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  ofx_imports / card_payments — aba REGISTRO
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ofx_imports` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `data_extracao` DATE         DEFAULT NULL,
  `banco`         VARCHAR(120) DEFAULT NULL,
  `periodo`       VARCHAR(60)  DEFAULT NULL,
  `nome_arquivo`  VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ofx_user` (`user_id`),
  CONSTRAINT `fk_ofx_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_payments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `data_pagto` DATE         DEFAULT NULL,
  `cartao`     VARCHAR(120) DEFAULT NULL,
  `periodo`    VARCHAR(60)  DEFAULT NULL,
  `valor`      DOUBLE       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_card_payments_user` (`user_id`),
  CONSTRAINT `fk_card_payments_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
