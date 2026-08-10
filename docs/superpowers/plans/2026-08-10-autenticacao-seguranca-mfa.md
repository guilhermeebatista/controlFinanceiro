# Segurança de conta (e-mail, recuperação de senha, bloqueio, MFA) — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar verificação de e-mail no cadastro, recuperação de senha por e-mail, aperto do bloqueio de login (5 tentativas / 1h) e MFA obrigatório via TOTP (compatível com Microsoft Authenticator) ao app `minhas-contas`.

**Architecture:** PHP puro sem framework/Composer (padrão já estabelecido no projeto). Um cliente SMTP e um módulo TOTP são escritos à mão, no mesmo espírito de `Auth.php`/`Security.php`. Uma infraestrutura de migrações incrementais é adicionada primeiro, pois hoje o schema só é aplicado uma vez (instalação nova). O fluxo de login ganha dois novos estágios sequenciais (e-mail verificado, depois MFA) antes de criar a sessão real.

**Tech Stack:** PHP 8.3 (ext-sodium e ext-pdo_mysql, ambas já disponíveis), MySQL 8.4, JS vanilla sem build step.

Spec de referência: `docs/superpowers/specs/2026-08-10-autenticacao-seguranca-mfa-design.md`

## Global Constraints

- Sem Composer e sem bibliotecas de terceiros em PHP — tudo hand-rolled, seguindo o padrão de `Auth.php`/`Security.php`/`Router.php`.
- `LOGIN_MAX_TENTATIVAS = 5`, `LOGIN_BLOQUEIO_SEGUNDOS = 3600` (por par IP+identificador, mesmo escopo já existente).
- TTLs: verificação de e-mail = 24h, recuperação de senha = 30 min, pendência de MFA = 10 min, cooldown de reenvio = 60s.
- TOTP: HMAC-SHA1, 6 dígitos, passo de 30s, tolerância de ±1 passo.
- Códigos de backup: 10 códigos de 10 caracteres, alfabeto sem `0/O/1/I`, hash SHA-256, uso único.
- Todo token (verificação, reset, pendência de MFA) grava só o SHA-256 no banco — nunca o token em si (padrão de `sessions.token_hash`).
- Nomenclatura: tabelas em inglês, colunas/métodos/variáveis em português — mesmo padrão do resto do schema e do código.
- Sem framework de teste automatizado no projeto. Módulos de lógica pura (`Mfa.php`) recebem testes reais baseados em `assert`/vetores conhecidos sob `tests/`. Controllers e fluxo HTTP são verificados manualmente via `curl` contra o `docker-compose` local — não existe harness de integração e criar um está fora do escopo deste plano.
- QR code do MFA é gerado no navegador (nenhuma biblioteca de terceiro roda no servidor, nenhum segredo trafega para fora do dispositivo do usuário).

---

### Task 1: Infraestrutura de migrações incrementais

Sem isso, nenhuma mudança de schema das tarefas seguintes chega a um banco já provisionado.

**Files:**
- Modify: `bin/migrate.php`
- Create: `migrations/` (pasta, vazia por enquanto — a Task 2 adiciona o primeiro arquivo)

**Interfaces:**
- Consumes: `Database::run()`, `Database::valor()`, `Database::transacao()`, `Database::pdo()` (já existem em `src/Database.php`), `MinhasContas\Config::raiz()`.
- Produces: tabela `schema_migrations (versao VARCHAR(190) PRIMARY KEY, aplicada_em DATETIME)`; função `aplicarMigracoes(bool $instalacaoNova): void` chamada a partir do fluxo principal do script. Migrações futuras (Task 2 em diante) são arquivos `.sql` numerados em `migrations/`, aplicados em ordem alfabética.

- [ ] **Step 1: Criar a pasta `migrations/` com um `.gitkeep`**

```bash
mkdir -p migrations
touch migrations/.gitkeep
```

- [ ] **Step 2: Reescrever `bin/migrate.php` para separar "schema existe?" de "aplicar schema" e acrescentar o runner de migrações**

Substitua o bloco principal (linhas 27-36 do arquivo atual) e a função `aplicarSchema()` (linhas 38-66) por:

```php
try {
    $instalacaoNova = !schemaExiste();
    aplicarSchema();
    aplicarMigracoes($instalacaoNova);
    $uid = garantirContaPlanilha();
    semearPlanilha($uid);
    Users::garantirPrimeiroAdmin();
    echo "[migrate] banco pronto.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

function schemaExiste(): bool
{
    return Database::um(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        ['users']
    ) !== null;
}

/**
 * Aplica o database.sql quando o schema ainda não existe.
 *
 * No Docker o próprio container do MySQL já executa o arquivo na primeira
 * subida; isto cobre a instalação manual e o caso de o volume ter sido criado
 * antes de o arquivo existir.
 */
function aplicarSchema(): void
{
    if (schemaExiste()) {
        return;
    }

    $arquivo = \MinhasContas\Config::raiz() . '/database.sql';
    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        throw new RuntimeException("database.sql não encontrado em {$arquivo}");
    }

    echo "[migrate] schema ausente; aplicando database.sql...\n";
    foreach (comandosDo($sql) as $comando) {
        Database::pdo()->exec($comando);
    }
}

/**
 * Aplica migrações incrementais de migrations/*.sql.
 *
 * Numa instalação nova o database.sql já nasce com o schema mais recente
 * (Task 2 mantém os dois em sincronia), então os arquivos só são registrados
 * como aplicados, não executados de novo — evita repetir um ALTER/backfill
 * que já está implícito no schema inicial.
 */
function aplicarMigracoes(bool $instalacaoNova): void
{
    Database::run(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            versao      VARCHAR(190) NOT NULL PRIMARY KEY,
            aplicada_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $arquivos = glob(\MinhasContas\Config::raiz() . '/migrations/*.sql') ?: [];
    sort($arquivos);

    foreach ($arquivos as $arquivo) {
        $versao = basename($arquivo);
        if (Database::valor('SELECT 1 FROM schema_migrations WHERE versao = ?', [$versao]) !== null) {
            continue;
        }

        if ($instalacaoNova) {
            Database::run('INSERT INTO schema_migrations (versao) VALUES (?)', [$versao]);
            continue;
        }

        echo "[migrate] aplicando migração {$versao}...\n";
        $sql = file_get_contents($arquivo);
        if ($sql === false) {
            throw new RuntimeException("Não foi possível ler {$arquivo}");
        }
        Database::transacao(static function () use ($sql, $versao): void {
            foreach (comandosDo($sql) as $comando) {
                Database::pdo()->exec($comando);
            }
            Database::run('INSERT INTO schema_migrations (versao) VALUES (?)', [$versao]);
        });
    }
}
```

Mantenha `comandosDo()`, `garantirContaPlanilha()` e `semearPlanilha()` como estão hoje.

- [ ] **Step 3: Testar manualmente contra um banco novo (simula instalação nova)**

```bash
docker compose up -d mysql
docker compose run --rm app php bin/migrate.php
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT versao FROM schema_migrations;"
```

Esperado: o comando roda sem erro (mesmo sem nenhum arquivo em `migrations/` ainda) e a tabela `schema_migrations` existe (vazia).

- [ ] **Step 4: Testar que rodar de novo é idempotente**

```bash
docker compose run --rm app php bin/migrate.php
```

Esperado: roda de novo sem erro, sem repetir a criação da conta 'planilha' nem duplicar linhas em `schema_migrations`.

- [ ] **Step 5: Commit**

```bash
git add bin/migrate.php migrations/.gitkeep
git commit -m "feat: adiciona runner de migrações incrementais ao bin/migrate.php"
```

---

### Task 2: Schema — colunas e tabelas novas

**Files:**
- Create: `migrations/0001_seguranca_conta.sql`
- Modify: `database.sql:41-54` (tabela `users`), `database.sql` (logo após o bloco de `login_attempts`, por volta da linha 92)

**Interfaces:**
- Consumes: runner da Task 1.
- Produces: colunas `users.email_verificado_em`, `users.mfa_secret_cifrado`, `users.mfa_ativado_em`; tabelas `email_verifications`, `password_resets`, `mfa_pending`, `mfa_backup_codes` — schema exato usado por todas as tarefas seguintes.

- [ ] **Step 1: Criar `migrations/0001_seguranca_conta.sql`**

```sql
-- Segurança de conta: verificação de e-mail, MFA e tabelas de tokens.
-- Idempotente: pode ser reaplicado sem erro.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verificado_em DATETIME       NULL,
  ADD COLUMN IF NOT EXISTS mfa_secret_cifrado   VARBINARY(255) NULL,
  ADD COLUMN IF NOT EXISTS mfa_ativado_em       DATETIME       NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  token_hash  CHAR(64)     NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME     NOT NULL,
  KEY ix_email_verifications_user (user_id),
  CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  token_hash  CHAR(64)     NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME     NOT NULL,
  KEY ix_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Backfill único: contas existentes nunca tiveram a chance de confirmar
-- e-mail, então entram já verificadas. Cadastros feitos depois desta
-- migração nascem com email_verificado_em = NULL normalmente (a migração só
-- roda esta linha uma vez, controlada por schema_migrations).
UPDATE users SET email_verificado_em = criado_em WHERE email_verificado_em IS NULL;
```

- [ ] **Step 2: Atualizar `database.sql` para instalação nova ficar em sincronia**

Em `database.sql:41-54`, adicione as três colunas na tabela `users` (antes de `PRIMARY KEY`):

```sql
CREATE TABLE IF NOT EXISTS `users` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario`             VARCHAR(190)  NOT NULL,
  `nome`                VARCHAR(190)  DEFAULT NULL,
  `email`               VARCHAR(190)  DEFAULT NULL,
  `senha_hash`          VARCHAR(255)  NOT NULL,
  `salt`                VARCHAR(64)   NOT NULL DEFAULT '',
  `is_admin`            TINYINT(1)    NOT NULL DEFAULT 0,
  `email_verificado_em` DATETIME      DEFAULT NULL,
  `mfa_secret_cifrado`  VARBINARY(255) DEFAULT NULL,
  `mfa_ativado_em`      DATETIME      DEFAULT NULL,
  `ultimo_login`        DATETIME      DEFAULT NULL,
  `criado_em`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_usuario` (`usuario`),
  UNIQUE KEY `uq_users_email`   (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Logo após o bloco `login_attempts` (depois da linha `) ENGINE=InnoDB ... ;` que fecha essa tabela, por volta da linha 92, e antes do comentário de `settings`, insira as quatro tabelas novas (mesmo `CREATE TABLE` do Step 1, sem a linha de `ALTER TABLE` e sem o `UPDATE` de backfill — instalação nova já nasce com `email_verificado_em` `NULL` para todo mundo, que é o estado correto para contas criadas depois deste ponto).

- [ ] **Step 3: Testar em banco novo**

```bash
docker compose down -v   # recria o volume do MySQL do zero — ok em ambiente de dev/teste
docker compose up -d mysql
docker compose run --rm app php bin/migrate.php
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_NAME" \
  -e "DESCRIBE users; SHOW TABLES LIKE '%mfa%'; SHOW TABLES LIKE '%verif%'; SHOW TABLES LIKE '%reset%';"
```

Esperado: `users` tem as três colunas novas; `mfa_pending`, `mfa_backup_codes`, `email_verifications`, `password_resets` existem.

- [ ] **Step 4: Testar migração incremental em banco "existente" (simulação)**

```bash
# Reverte só a migração 0001 para simular um banco de antes desta mudança:
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_NAME" -e "
  ALTER TABLE users DROP COLUMN email_verificado_em, DROP COLUMN mfa_secret_cifrado, DROP COLUMN mfa_ativado_em;
  DROP TABLE email_verifications, password_resets, mfa_pending, mfa_backup_codes;
  DELETE FROM schema_migrations WHERE versao = '0001_seguranca_conta.sql';
"
docker compose run --rm app php bin/migrate.php
docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT id, email, email_verificado_em FROM users;"
```

Esperado: a migração roda (log "aplicando migração 0001..."), as colunas/tabelas voltam a existir, e toda conta que já tinha `criado_em` aparece com `email_verificado_em` preenchido igual a `criado_em` (backfill funcionou).

- [ ] **Step 5: Commit**

```bash
git add migrations/0001_seguranca_conta.sql database.sql
git commit -m "feat: adiciona colunas e tabelas de verificação de e-mail, reset de senha e MFA"
```

---

### Task 3: Aperto do bloqueio de login

**Files:**
- Modify: `src/Config.php:24-28`

**Interfaces:**
- Consumes: nada novo.
- Produces: `Config::LOGIN_MAX_TENTATIVAS = 5`, `Config::LOGIN_BLOQUEIO_SEGUNDOS = 3600` (consumidos por `Security::registrarFalhaLogin()`, já existente).

- [ ] **Step 1: Alterar as constantes**

```php
    /** Tentativas de login erradas antes do bloqueio temporário. */
    public const LOGIN_MAX_TENTATIVAS = 5;

    /** Duração do bloqueio após estourar o limite (segundos). */
    public const LOGIN_BLOQUEIO_SEGUNDOS = 3600;
```

- [ ] **Step 2: Verificar manualmente**

```bash
docker compose up -d
for i in 1 2 3 4 5; do
  curl -s -X POST http://localhost:8000/api/auth/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"planilha","senha":"errada"}' | python3 -c "import sys,json;print(json.load(sys.stdin))"
done
```

Esperado: nas 5 tentativas a resposta é `401 E-mail ou senha inválidos`; numa 6ª tentativa imediata a resposta vira `429 Muitas tentativas. Tente novamente em 60 minuto(s).`

- [ ] **Step 3: Commit**

```bash
git add src/Config.php
git commit -m "fix: reduz bloqueio de login para 5 tentativas / 1 hora"
```

---

### Task 4: Configuração — variáveis de ambiente novas

**Files:**
- Modify: `src/Config.php` (novos métodos, após `debug()`)
- Modify: `.env.example`
- Modify: `docker-compose.yml` (bloco `environment` do serviço `app`)

**Interfaces:**
- Consumes: `Config::env()` (já existe).
- Produces: `Config::appUrl(): string`, `Config::smtpHost(): string`, `Config::smtpPort(): int`, `Config::smtpUser(): string`, `Config::smtpPass(): string`, `Config::smtpFromEmail(): string`, `Config::smtpFromNome(): string`, `Config::mfaEncryptionKey(): string`; constantes `Config::EMAIL_VERIFICACAO_TTL_SEGUNDOS`, `Config::RESET_SENHA_TTL_SEGUNDOS`, `Config::MFA_PENDENTE_TTL_SEGUNDOS`, `Config::TOKEN_REENVIO_COOLDOWN_SEGUNDOS`.

- [ ] **Step 1: Adicionar constantes em `src/Config.php`, logo abaixo de `BCRYPT_COST`**

```php
    /** Validade do link de verificação de e-mail (segundos). */
    public const EMAIL_VERIFICACAO_TTL_SEGUNDOS = 24 * 3600;

    /** Validade do link de recuperação de senha (segundos). */
    public const RESET_SENHA_TTL_SEGUNDOS = 30 * 60;

    /** Validade da pendência de MFA entre a senha e o código (segundos). */
    public const MFA_PENDENTE_TTL_SEGUNDOS = 10 * 60;

    /** Intervalo mínimo entre reenvios de e-mail de verificação/recuperação (segundos). */
    public const TOKEN_REENVIO_COOLDOWN_SEGUNDOS = 60;
```

- [ ] **Step 2: Adicionar métodos em `src/Config.php`, logo abaixo de `debug()`**

```php
    /** URL pública do app, usada para montar os links dos e-mails. */
    public static function appUrl(): string
    {
        return rtrim(self::env('APP_URL', 'http://localhost:8000'), '/');
    }

    public static function smtpHost(): string
    {
        return self::env('SMTP_HOST');
    }

    public static function smtpPort(): int
    {
        return (int) self::env('SMTP_PORT', '587');
    }

    public static function smtpUser(): string
    {
        return self::env('SMTP_USER');
    }

    public static function smtpPass(): string
    {
        return self::env('SMTP_PASS');
    }

    public static function smtpFromEmail(): string
    {
        return self::env('SMTP_FROM_EMAIL');
    }

    public static function smtpFromNome(): string
    {
        return self::env('SMTP_FROM_NOME', 'Minhas Contas');
    }

    /** Chave (32 bytes, base64) usada para cifrar o segredo TOTP em repouso. */
    public static function mfaEncryptionKey(): string
    {
        return self::env('MFA_ENCRYPTION_KEY');
    }
```

- [ ] **Step 3: Documentar as variáveis em `.env.example`, após `APP_ENV=production`**

```bash
# URL pública usada nos links dos e-mails de verificação e recuperação de
# senha. Em produção, aponte para o domínio real (https://...).
APP_URL=http://localhost:8000

# Envio de e-mail (verificação de cadastro e recuperação de senha). Use as
# credenciais SMTP do seu provedor (Gmail com senha de app, SendGrid,
# Mailgun...).
SMTP_HOST=smtp.exemplo.com
SMTP_PORT=587
SMTP_USER=usuario@exemplo.com
SMTP_PASS=troque-esta-senha-smtp
SMTP_FROM_EMAIL=usuario@exemplo.com
SMTP_FROM_NOME=Minhas Contas

# Chave de cifragem dos segredos de MFA (TOTP), 32 bytes em base64. Gere com:
#   openssl rand -base64 32
# Trocar esta chave torna os segredos já cadastrados indecifráveis — trate
# como incidente, não como rotina.
MFA_ENCRYPTION_KEY=troque-esta-chave-de-32-bytes-em-base64
```

- [ ] **Step 4: Repassar as variáveis no `docker-compose.yml`**

No serviço `app`, dentro de `environment:`, depois de `APP_ENV: ${APP_ENV:-production}`:

```yaml
      APP_URL: ${APP_URL:-http://localhost:8000}
      SMTP_HOST: ${SMTP_HOST:-}
      SMTP_PORT: ${SMTP_PORT:-587}
      SMTP_USER: ${SMTP_USER:-}
      SMTP_PASS: ${SMTP_PASS:-}
      SMTP_FROM_EMAIL: ${SMTP_FROM_EMAIL:-}
      SMTP_FROM_NOME: ${SMTP_FROM_NOME:-Minhas Contas}
      MFA_ENCRYPTION_KEY: ${MFA_ENCRYPTION_KEY:?defina MFA_ENCRYPTION_KEY no arquivo .env}
```

`MFA_ENCRYPTION_KEY` é obrigatória (mesmo padrão de `DB_PASS`) porque todo login passa pelo MFA; `SMTP_*` ficam com padrão vazio para não travar o boot do container antes de o provedor de e-mail estar configurado — sem eles, cadastro/recuperação de senha falham com erro claro em vez do container inteiro não subir.

- [ ] **Step 5: Gerar uma chave local para testar e confirmar que o container sobe**

```bash
echo "MFA_ENCRYPTION_KEY=$(openssl rand -base64 32)" >> .env
docker compose up -d
docker compose logs app --tail 20
```

Esperado: container sobe sem erro sobre variável faltando.

- [ ] **Step 6: Commit**

```bash
git add src/Config.php .env.example docker-compose.yml
git commit -m "feat: adiciona configuração de SMTP, APP_URL e chave de cifragem do MFA"
```

---

### Task 5: Cliente SMTP (`Mailer`)

**Files:**
- Create: `src/Mailer.php`

**Interfaces:**
- Consumes: `Config::smtpHost()`, `smtpPort()`, `smtpUser()`, `smtpPass()`, `smtpFromEmail()`, `smtpFromNome()` (Task 4).
- Produces: `Mailer::enviar(string $paraEmail, string $assunto, string $corpo): void` — usado pelas Tasks 6 e 8.

- [ ] **Step 1: Criar `src/Mailer.php`**

```php
<?php
/**
 * Cliente SMTP mínimo: conecta, autentica e envia uma mensagem em texto puro.
 * Sem biblioteca externa — mesmo espírito do resto do projeto.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Mailer
{
    private const TIMEOUT_SEGUNDOS = 10;

    public static function enviar(string $paraEmail, string $assunto, string $corpo): void
    {
        if (preg_match('/[\r\n]/', $paraEmail) === 1 || preg_match('/[\r\n]/', $assunto) === 1) {
            throw new \InvalidArgumentException('Destinatário ou assunto contém quebra de linha.');
        }
        if (Config::smtpHost() === '') {
            throw new \RuntimeException('SMTP não configurado (defina SMTP_HOST no .env).');
        }

        $host = Config::smtpHost();
        $porta = Config::smtpPort();
        $usarTlsImediato = $porta === 465;

        $esquema = $usarTlsImediato ? 'ssl://' : 'tcp://';
        $contexto = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $conexao = @stream_socket_client(
            "{$esquema}{$host}:{$porta}",
            $codigoErro,
            $mensagemErro,
            self::TIMEOUT_SEGUNDOS,
            STREAM_CLIENT_CONNECT,
            $contexto
        );
        if ($conexao === false) {
            throw new \RuntimeException("Falha ao conectar no SMTP {$host}:{$porta}: {$mensagemErro}");
        }
        stream_set_timeout($conexao, self::TIMEOUT_SEGUNDOS);

        try {
            self::lerResposta($conexao, 220);
            self::comando($conexao, 'EHLO minhascontas.local', 250);

            if (!$usarTlsImediato) {
                self::comando($conexao, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Falha ao negociar TLS com o servidor SMTP.');
                }
                self::comando($conexao, 'EHLO minhascontas.local', 250);
            }

            self::comando($conexao, 'AUTH LOGIN', 334);
            self::comando($conexao, base64_encode(Config::smtpUser()), 334);
            self::comando($conexao, base64_encode(Config::smtpPass()), 235);

            $de = Config::smtpFromEmail();
            self::comando($conexao, "MAIL FROM:<{$de}>", 250);
            self::comando($conexao, "RCPT TO:<{$paraEmail}>", 250);
            self::comando($conexao, 'DATA', 354);

            $cabecalhos = implode("\r\n", [
                'From: ' . self::codificarCabecalho(Config::smtpFromNome()) . " <{$de}>",
                "To: <{$paraEmail}>",
                'Subject: ' . self::codificarCabecalho($assunto),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]);
            // Dot-stuffing: uma linha começando com "." sozinha encerraria a
            // mensagem antes da hora (RFC 5321 §4.5.2).
            $corpoEscapado = preg_replace('/^\./m', '..', $corpo) ?? $corpo;
            self::comando($conexao, "{$cabecalhos}\r\n\r\n{$corpoEscapado}\r\n.", 250);

            self::comando($conexao, 'QUIT', 221);
        } finally {
            fclose($conexao);
        }
    }

    /** @param resource $conexao */
    private static function comando($conexao, string $linha, int $codigoEsperado): void
    {
        fwrite($conexao, $linha . "\r\n");
        self::lerResposta($conexao, $codigoEsperado);
    }

    /** @param resource $conexao */
    private static function lerResposta($conexao, int $codigoEsperado): void
    {
        $ultima = '';
        do {
            $linha = fgets($conexao, 512);
            if ($linha === false) {
                throw new \RuntimeException('Conexão SMTP encerrada inesperadamente.');
            }
            $ultima = $linha;
            // Resposta multi-linha: "250-..." continua, "250 ..." é a última.
        } while (isset($linha[3]) && $linha[3] === '-');

        $codigo = (int) substr($ultima, 0, 3);
        if ($codigo !== $codigoEsperado) {
            throw new \RuntimeException("Resposta SMTP inesperada (esperava {$codigoEsperado}): {$ultima}");
        }
    }

    private static function codificarCabecalho(string $valor): string
    {
        return '=?UTF-8?B?' . base64_encode($valor) . '?=';
    }
}
```

- [ ] **Step 2: Verificar manualmente com um provedor real**

Preencha `SMTP_*` no `.env` com credenciais reais (ex.: Gmail com senha de app — https://myaccount.google.com/apppasswords) e rode:

```bash
docker compose exec app php -r "
require '/var/www/html/src/autoload.php';
MinhasContas\Mailer::enviar('seu-email@exemplo.com', 'Teste Mailer', 'Se você recebeu isto, o Mailer funciona.');
echo \"enviado\n\";
"
```

Esperado: o e-mail chega na caixa de entrada informada, sem erro no terminal.

- [ ] **Step 3: Commit**

```bash
git add src/Mailer.php
git commit -m "feat: adiciona cliente SMTP mínimo (Mailer)"
```

---

### Task 6: `Mfa` — TOTP, cifragem do segredo e códigos de backup

Módulo de lógica pura — sem banco, sem HTTP. Por isso recebe testes automatizados reais, apesar de o projeto não ter framework de teste: um script PHP simples com `assert`-style, rodado via CLI.

**Files:**
- Create: `src/Mfa.php`
- Create: `tests/mfa_test.php`

**Interfaces:**
- Consumes: `Config::mfaEncryptionKey()` (Task 4).
- Produces: `Mfa::gerarSegredo(): string`, `Mfa::uriProvisionamento(string $segredoBase32, string $rotulo): string`, `Mfa::cifrarSegredo(string $segredoBase32): string`, `Mfa::decifrarSegredo(string $armazenado): ?string`, `Mfa::verificarCodigo(?string $segredoBase32, string $codigo): bool`, `Mfa::codigoHotp(string $chaveBin, int $contador, int $digitos): string`, `Mfa::gerarCodigosBackup(): list<string>`, `Mfa::base32Codificar(string $bin): string`, `Mfa::base32Decodificar(string $valor): ?string` — todos usados pelas Tasks 9-13.

- [ ] **Step 1: Escrever o teste (vai falhar — a classe ainda não existe)**

Criar `tests/mfa_test.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Mfa;

function afirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: {$mensagem}\n");
        exit(1);
    }
    echo "ok: {$mensagem}\n";
}

// Vetores oficiais do RFC 6238 Apêndice B (segredo ASCII "12345678901234567890",
// HOTP de 8 dígitos, passo de 30s a partir de T0=0).
$segredoRfc = '12345678901234567890';
afirmar(Mfa::codigoHotp($segredoRfc, 1, 8) === '94287082', 'RFC 6238 T=59 (contador=1)');
afirmar(Mfa::codigoHotp($segredoRfc, 37037036, 8) === '07081804', 'RFC 6238 T=1111111109');
afirmar(Mfa::codigoHotp($segredoRfc, 41152263, 8) === '14050471', 'RFC 6238 T=1111111111');

// Base32: round-trip com segredo aleatório.
for ($i = 0; $i < 20; $i++) {
    $bin = random_bytes(20);
    $codificado = Mfa::base32Codificar($bin);
    afirmar(Mfa::base32Decodificar($codificado) === $bin, "base32 round-trip #{$i}");
}

// Cifragem do segredo: round-trip com uma chave de teste.
putenv('MFA_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
$segredo = Mfa::gerarSegredo();
afirmar(strlen($segredo) === 32, 'segredo gerado tem 32 caracteres base32 (20 bytes)');
$cifrado = Mfa::cifrarSegredo($segredo);
afirmar(Mfa::decifrarSegredo($cifrado) === $segredo, 'cifragem round-trip');
afirmar(Mfa::decifrarSegredo('lixo-invalido') === null, 'decifrar valor inválido devolve null');

// Códigos de backup: quantidade, unicidade e alfabeto sem ambiguidade.
$codigos = Mfa::gerarCodigosBackup();
afirmar(count($codigos) === 10, 'gera 10 códigos de backup');
afirmar(count(array_unique($codigos)) === 10, 'códigos de backup são únicos');
foreach ($codigos as $c) {
    afirmar(preg_match('/^[A-HJ-NP-Z2-9]{10}$/', $c) === 1, "código de backup bem formado: {$c}");
}

// TOTP: um código gerado para o passo atual precisa validar contra o mesmo segredo.
$contadorAtual = intdiv(time(), 30);
$codigoAtual = Mfa::codigoHotp(Mfa::base32Decodificar($segredo), $contadorAtual, 6);
afirmar(Mfa::verificarCodigo($segredo, $codigoAtual) === true, 'código TOTP do passo atual é aceito');
afirmar(Mfa::verificarCodigo($segredo, 'abcdef') === false, 'código não numérico é rejeitado');
afirmar(Mfa::verificarCodigo(null, $codigoAtual) === false, 'segredo nulo é rejeitado');

echo "Todos os testes de Mfa passaram.\n";
```

- [ ] **Step 2: Rodar o teste e confirmar que falha (classe `Mfa` ainda não existe)**

```bash
docker compose exec app php tests/mfa_test.php
```

Esperado: erro fatal `Class "MinhasContas\Mfa" not found`.

- [ ] **Step 3: Implementar `src/Mfa.php`**

```php
<?php
/**
 * TOTP (RFC 6238) para MFA: geração/verificação de código, segredo cifrado em
 * repouso e códigos de backup de uso único.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Mfa
{
    private const EMISSOR         = 'MinhasContas';
    private const PASSO_SEGUNDOS  = 30;
    private const DIGITOS         = 6;
    private const JANELA_PASSOS   = 1; // tolera ±1 passo (±30s) de relógio dessincronizado.
    private const BACKUP_QTD      = 10;
    private const BACKUP_TAM      = 10;
    private const BACKUP_ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O/1/I.
    private const BASE32_ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // --------------------------------------------------------------- segredo

    public static function gerarSegredo(): string
    {
        return self::base32Codificar(random_bytes(20));
    }

    public static function uriProvisionamento(string $segredoBase32, string $rotulo): string
    {
        $params = http_build_query([
            'secret'    => $segredoBase32,
            'issuer'    => self::EMISSOR,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITOS,
            'period'    => self::PASSO_SEGUNDOS,
        ]);
        $caminho = rawurlencode(self::EMISSOR . ':' . $rotulo);
        return "otpauth://totp/{$caminho}?{$params}";
    }

    // -------------------------------------------------------------- cifragem

    public static function cifrarSegredo(string $segredoBase32): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = sodium_crypto_secretbox($segredoBase32, $nonce, self::chaveCifragem());
        return base64_encode($nonce . $cifrado);
    }

    public static function decifrarSegredo(string $armazenado): ?string
    {
        $bin = base64_decode($armazenado, true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce   = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $claro = sodium_crypto_secretbox_open($cifrado, $nonce, self::chaveCifragem());
        return $claro === false ? null : $claro;
    }

    private static function chaveCifragem(): string
    {
        $chave = base64_decode(Config::mfaEncryptionKey(), true);
        if ($chave === false || strlen($chave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'MFA_ENCRYPTION_KEY ausente ou com tamanho inválido (esperado 32 bytes em base64).'
            );
        }
        return $chave;
    }

    // ------------------------------------------------------------------ TOTP

    public static function verificarCodigo(?string $segredoBase32, string $codigo): bool
    {
        $codigo = trim($codigo);
        if ($segredoBase32 === null || preg_match('/^\d{' . self::DIGITOS . '}$/', $codigo) !== 1) {
            return false;
        }
        $chaveBin = self::base32Decodificar($segredoBase32);
        if ($chaveBin === null) {
            return false;
        }
        $contadorAtual = intdiv(time(), self::PASSO_SEGUNDOS);
        for ($delta = -self::JANELA_PASSOS; $delta <= self::JANELA_PASSOS; $delta++) {
            $esperado = self::codigoHotp($chaveBin, $contadorAtual + $delta, self::DIGITOS);
            if (hash_equals($esperado, $codigo)) {
                return true;
            }
        }
        return false;
    }

    /** HOTP (RFC 4226) — separado de verificarCodigo() para testar contra os vetores oficiais. */
    public static function codigoHotp(string $chaveBin, int $contador, int $digitos): string
    {
        $bloco = pack('J', $contador); // 8 bytes big-endian.
        $hmac  = hash_hmac('sha1', $bloco, $chaveBin, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binario = ((ord($hmac[$offset]) & 0x7F) << 24)
                 | (ord($hmac[$offset + 1]) << 16)
                 | (ord($hmac[$offset + 2]) << 8)
                 | ord($hmac[$offset + 3]);
        $codigo = $binario % (10 ** $digitos);
        return str_pad((string) $codigo, $digitos, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------- backup codes

    /** @return list<string> */
    public static function gerarCodigosBackup(): array
    {
        $codigos = [];
        $alfabetoTam = strlen(self::BACKUP_ALFABETO);
        for ($i = 0; $i < self::BACKUP_QTD; $i++) {
            $codigo = '';
            for ($j = 0; $j < self::BACKUP_TAM; $j++) {
                $codigo .= self::BACKUP_ALFABETO[random_int(0, $alfabetoTam - 1)];
            }
            $codigos[] = $codigo;
        }
        return $codigos;
    }

    // -------------------------------------------------------------- base32

    public static function base32Codificar(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $grupo = str_pad($grupo, 5, '0', STR_PAD_RIGHT);
            $saida .= self::BASE32_ALFABETO[bindec($grupo)];
        }
        return $saida;
    }

    public static function base32Decodificar(string $valor): ?string
    {
        $valor = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $valor) ?? '');
        if ($valor === '') {
            return null;
        }
        $bits = '';
        foreach (str_split($valor) as $c) {
            $indice = strpos(self::BASE32_ALFABETO, $c);
            if ($indice === false) {
                return null;
            }
            $bits .= str_pad(decbin($indice), 5, '0', STR_PAD_LEFT);
        }
        $bin = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                break; // sobra de padding, não é um byte completo.
            }
            $bin .= chr(bindec($byte));
        }
        return $bin;
    }
}
```

- [ ] **Step 4: Rodar o teste de novo e confirmar que passa**

```bash
docker compose exec app php tests/mfa_test.php
```

Esperado: todas as linhas `ok: ...` e por fim `Todos os testes de Mfa passaram.`, sem `FALHOU`.

- [ ] **Step 5: Commit**

```bash
git add src/Mfa.php tests/mfa_test.php
git commit -m "feat: adiciona módulo TOTP (Mfa) com testes contra os vetores do RFC 6238"
```

---

### Task 7: Cadastro com verificação de e-mail

**Files:**
- Modify: `src/Controllers/AuthController.php` (método `registrar()`, mais dois métodos novos)
- Modify: `public/index.php` (rotas)

**Interfaces:**
- Consumes: `Mailer::enviar()` (Task 5), `Config::appUrl()`, `Config::EMAIL_VERIFICACAO_TTL_SEGUNDOS`, `Config::TOKEN_REENVIO_COOLDOWN_SEGUNDOS` (Task 4), tabela `email_verifications` (Task 2), `Security::novoToken()` (já existe).
- Produces: `AuthController::verificarEmail(): void`, `AuthController::reenviarVerificacao(): void`; `registrar()` não abre mais sessão, responde `{"mensagem": "..."}`.

- [ ] **Step 1: Adicionar `use MinhasContas\Config;` e `use MinhasContas\Mailer;` ao topo de `AuthController.php`**

- [ ] **Step 2: Reescrever `AuthController::registrar()`**

Troque as duas últimas linhas (`Auth::criarSessao($uid);` e o `Http::json(...)` seguinte) por:

```php
        self::enviarVerificacao($uid, $email);

        Http::json([
            'mensagem' => 'Conta criada. Verifique seu e-mail para ativar o login.',
        ]);
    }

    private static function enviarVerificacao(int $uid, string $email): void
    {
        $token = Security::novoToken();
        Database::run('DELETE FROM email_verifications WHERE user_id = ?', [$uid]);
        Database::run(
            'INSERT INTO email_verifications (token_hash, user_id, expira_em) VALUES (?, ?, ?)',
            [hash('sha256', $token), $uid, gmdate('Y-m-d H:i:s', time() + Config::EMAIL_VERIFICACAO_TTL_SEGUNDOS)]
        );
        $link = Config::appUrl() . '/?verify_token=' . $token;
        Mailer::enviar(
            $email,
            'Confirme seu e-mail — Minhas Contas',
            "Clique no link abaixo para confirmar seu e-mail e ativar o login:\n\n{$link}\n\nO link expira em 24 horas."
        );
    }
```

(O `Auth::criarSessao($uid);` sai de dentro de `registrar()`; o restante do método, até a criação do usuário, continua igual.)

- [ ] **Step 3: Adicionar `verificarEmail()` e `reenviarVerificacao()` a `AuthController`, antes do bloco `// ---------------------------------------------------------------- util`**

```php
    public static function verificarEmail(): void
    {
        $token = Http::texto('token', 128);
        $hash  = hash('sha256', $token);

        $linha = Database::um(
            'SELECT user_id FROM email_verifications WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
            [$hash]
        );
        if ($linha === null) {
            Http::erro(400, 'Link de verificação inválido ou expirado.');
        }

        $uid = (int) $linha['user_id'];
        Database::transacao(static function () use ($uid): void {
            Database::run('UPDATE users SET email_verificado_em = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
            Database::run('DELETE FROM email_verifications WHERE user_id = ?', [$uid]);
        });

        Http::json(['ok' => true]);
    }

    public static function reenviarVerificacao(): void
    {
        $email = mb_strtolower(Http::texto('email', 190));
        self::validarEmail($email);

        $u = Database::um(
            'SELECT id FROM users WHERE LOWER(email) = ? AND email_verificado_em IS NULL',
            [$email]
        );
        if ($u !== null) {
            $uid = (int) $u['id'];
            $recente = Database::valor(
                'SELECT 1 FROM email_verifications WHERE user_id = ? AND criado_em > ?',
                [$uid, gmdate('Y-m-d H:i:s', time() - Config::TOKEN_REENVIO_COOLDOWN_SEGUNDOS)]
            );
            if ($recente === null) {
                self::enviarVerificacao($uid, $email);
            }
        }

        Http::json(['mensagem' => 'Se esse e-mail existir e ainda não tiver sido confirmado, reenviamos o link.']);
    }
```

- [ ] **Step 4: Bloquear login sem e-mail verificado**

Em `AuthController::login()`, logo depois de `Auth::reidratarSeNecessario(...)` e antes de `Database::run('UPDATE users SET ultimo_login...')`, adicione:

```php
        if ($u['email_verificado_em'] === null) {
            Http::erro(403, 'Confirme seu e-mail antes de entrar. Verifique sua caixa de entrada.');
        }
```

(A Task 9 vai substituir o restante do corpo de `login()` — por enquanto deixe o `Auth::criarSessao($uid)` como está, só com esta checagem nova antes dele.)

- [ ] **Step 5: Registrar as rotas em `public/index.php`**

Logo abaixo de `Router::post('/api/auth/register', ...)`:

```php
    Router::post('/api/auth/verify-email', AuthController::verificarEmail(...));
    Router::post('/api/auth/resend-verification', AuthController::reenviarVerificacao(...));
```

- [ ] **Step 6: Verificar manualmente**

```bash
docker compose up -d
curl -s -X POST http://localhost:8000/api/auth/register -H 'Content-Type: application/json' \
  -d '{"nome":"Teste","email":"seu-email-real@exemplo.com","senha":"senha12345"}'
```

Esperado: resposta `{"mensagem":"Conta criada. Verifique seu e-mail..."}`, e-mail chega com um link `.../?verify_token=...`. Copie o token da URL e confirme:

```bash
curl -s -X POST http://localhost:8000/api/auth/verify-email -H 'Content-Type: application/json' \
  -d '{"token":"COLE_O_TOKEN_AQUI"}'
```

Esperado: `{"ok":true}`. Tentar de novo com o mesmo token deve dar `400` (token já consumido).

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/AuthController.php public/index.php
git commit -m "feat: exige verificação de e-mail no cadastro antes de permitir login"
```

---

### Task 8: Recuperação de senha

**Files:**
- Modify: `src/Controllers/AuthController.php` (dois métodos novos)
- Modify: `public/index.php` (rotas)

**Interfaces:**
- Consumes: `Mailer::enviar()`, `Config::appUrl()`, `Config::RESET_SENHA_TTL_SEGUNDOS`, `Config::TOKEN_REENVIO_COOLDOWN_SEGUNDOS`, tabela `password_resets` (Task 2), `AuthController::senhaDoCorpo()` (já existe).
- Produces: `AuthController::esqueciSenha(): void`, `AuthController::redefinirSenha(): void`.

- [ ] **Step 1: Adicionar `esqueciSenha()` e `redefinirSenha()` a `AuthController`, depois de `reenviarVerificacao()`**

```php
    public static function esqueciSenha(): void
    {
        $email = mb_strtolower(Http::texto('email', 190));
        self::validarEmail($email);

        $u = Database::um('SELECT id FROM users WHERE LOWER(email) = ?', [$email]);
        if ($u !== null) {
            $uid = (int) $u['id'];
            $recente = Database::valor(
                'SELECT 1 FROM password_resets WHERE user_id = ? AND criado_em > ?',
                [$uid, gmdate('Y-m-d H:i:s', time() - Config::TOKEN_REENVIO_COOLDOWN_SEGUNDOS)]
            );
            if ($recente === null) {
                $token = Security::novoToken();
                Database::run('DELETE FROM password_resets WHERE user_id = ?', [$uid]);
                Database::run(
                    'INSERT INTO password_resets (token_hash, user_id, expira_em) VALUES (?, ?, ?)',
                    [hash('sha256', $token), $uid, gmdate('Y-m-d H:i:s', time() + Config::RESET_SENHA_TTL_SEGUNDOS)]
                );
                $link = Config::appUrl() . '/?reset_token=' . $token;
                Mailer::enviar(
                    $email,
                    'Redefinição de senha — Minhas Contas',
                    "Clique no link abaixo para escolher uma nova senha:\n\n{$link}\n\n"
                    . "Se você não pediu isso, ignore este e-mail. O link expira em 30 minutos."
                );
            }
        }

        Http::json(['mensagem' => 'Se esse e-mail existir, enviamos um link de redefinição.']);
    }

    public static function redefinirSenha(): void
    {
        $token = Http::texto('token', 128);
        $novaSenha = self::senhaDoCorpo('senha_nova');
        $hash = hash('sha256', $token);

        $linha = Database::um(
            'SELECT user_id FROM password_resets WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
            [$hash]
        );
        if ($linha === null) {
            Http::erro(400, 'Link de redefinição inválido ou expirado.');
        }
        $uid = (int) $linha['user_id'];

        Database::transacao(static function () use ($uid, $novaSenha): void {
            Database::run(
                "UPDATE users SET senha_hash = ?, salt = '',
                        email_verificado_em = COALESCE(email_verificado_em, UTC_TIMESTAMP())
                  WHERE id = ?",
                [Auth::hashSenha($novaSenha), $uid]
            );
            Database::run('DELETE FROM sessions WHERE user_id = ?', [$uid]);
            Database::run('DELETE FROM password_resets WHERE user_id = ?', [$uid]);
        });

        Http::json(['ok' => true]);
    }
```

- [ ] **Step 2: Registrar as rotas em `public/index.php`**

```php
    Router::post('/api/auth/forgot-password', AuthController::esqueciSenha(...));
    Router::post('/api/auth/reset-password', AuthController::redefinirSenha(...));
```

- [ ] **Step 3: Verificar manualmente**

```bash
curl -s -X POST http://localhost:8000/api/auth/forgot-password -H 'Content-Type: application/json' \
  -d '{"email":"seu-email-real@exemplo.com"}'
```

Esperado: `{"mensagem":"Se esse e-mail existir..."}` e um e-mail com link `.../?reset_token=...`. Confirme também que um e-mail **inexistente** devolve a mesma mensagem (sem revelar se a conta existe). Depois:

```bash
curl -s -X POST http://localhost:8000/api/auth/reset-password -H 'Content-Type: application/json' \
  -d '{"token":"COLE_O_TOKEN_AQUI","senha_nova":"novaSenha123"}'
```

Esperado: `{"ok":true}`; login com a senha antiga passa a falhar, com a nova funciona (sujeito ao gate de MFA das próximas tasks).

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/AuthController.php public/index.php
git commit -m "feat: adiciona recuperação de senha por e-mail"
```

---

### Task 9: `MfaController` — pendência, cadastro e verificação de MFA no login

Esta tarefa conecta o `Mfa` (Task 6) ao fluxo HTTP: cria o "meio passo" entre a senha e a sessão real.

**Files:**
- Create: `src/Controllers/MfaController.php`
- Modify: `src/Controllers/AuthController.php` (final de `login()`)
- Modify: `public/index.php` (import + rotas)

**Interfaces:**
- Consumes: `Mfa::*` (Task 6), `Security::verificarBloqueioLogin()`/`registrarFalhaLogin()`/`limparFalhasLogin()`/`novoToken()`/`setCookie()`/`apagarCookie()` (já existem), `Auth::criarSessao()` (já existe), tabela `mfa_pending`/`mfa_backup_codes` (Task 2), `Config::MFA_PENDENTE_TTL_SEGUNDOS`.
- Produces: `MfaController::iniciarPendencia(int $uid): array` (chamado por `AuthController::login()`), endpoints `setupIniciar()`, `setupConfirmar()`, `verificar()`, `regenerarCodigosBackup()`.

- [ ] **Step 1: Criar `src/Controllers/MfaController.php`**

```php
<?php
/**
 * MFA (TOTP): pendência entre senha e sessão real, cadastro do autenticador e
 * verificação do código (ou de um código de backup) no login.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Config;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Mfa;
use MinhasContas\Security;

final class MfaController
{
    private const COOKIE_PENDENTE = 'mfa_pending';

    /**
     * Chamado por AuthController::login() após senha e e-mail conferidos.
     * @return array<string, mixed>
     */
    public static function iniciarPendencia(int $uid): array
    {
        $modo = self::mfaAtivo($uid) ? 'verify' : 'setup';

        $token = Security::novoToken();
        Database::run('DELETE FROM mfa_pending WHERE user_id = ?', [$uid]);
        Database::run(
            'INSERT INTO mfa_pending (token_hash, user_id, modo, expira_em) VALUES (?, ?, ?, ?)',
            [
                hash('sha256', $token),
                $uid,
                $modo,
                gmdate('Y-m-d H:i:s', time() + Config::MFA_PENDENTE_TTL_SEGUNDOS),
            ]
        );
        Security::setCookie(self::COOKIE_PENDENTE, $token, Config::MFA_PENDENTE_TTL_SEGUNDOS);

        return ['status' => $modo === 'setup' ? 'mfa_setup_required' : 'mfa_required'];
    }

    private static function mfaAtivo(int $uid): bool
    {
        return Database::valor('SELECT mfa_ativado_em FROM users WHERE id = ?', [$uid]) !== null;
    }

    /** @return array{user_id:int, modo:string} */
    private static function exigirPendencia(string $modoEsperado): array
    {
        $token = $_COOKIE[self::COOKIE_PENDENTE] ?? '';
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            Http::erro(401, 'Sessão de login expirada. Entre novamente.');
        }
        $linha = Database::um(
            'SELECT user_id, modo FROM mfa_pending WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
        if ($linha === null || $linha['modo'] !== $modoEsperado) {
            Http::erro(401, 'Sessão de login expirada. Entre novamente.');
        }
        return ['user_id' => (int) $linha['user_id'], 'modo' => (string) $linha['modo']];
    }

    private static function encerrarPendencia(int $uid): void
    {
        Database::run('DELETE FROM mfa_pending WHERE user_id = ?', [$uid]);
        Security::apagarCookie(self::COOKIE_PENDENTE);
    }

    public static function setupIniciar(): void
    {
        $p = self::exigirPendencia('setup');
        $uid = $p['user_id'];

        $u = Database::um('SELECT email, usuario FROM users WHERE id = ?', [$uid]);
        if ($u === null) {
            Http::erro(401, 'Sessão de login expirada. Entre novamente.');
        }

        $segredo = Mfa::gerarSegredo();
        Database::run('UPDATE users SET mfa_secret_cifrado = ? WHERE id = ?', [Mfa::cifrarSegredo($segredo), $uid]);

        $rotulo = (string) ($u['email'] ?? $u['usuario']);
        Http::json([
            'segredo'     => $segredo,
            'otpauth_uri' => Mfa::uriProvisionamento($segredo, $rotulo),
        ]);
    }

    public static function setupConfirmar(): void
    {
        $p = self::exigirPendencia('setup');
        $uid = $p['user_id'];
        $codigo = Http::texto('codigo', 10);

        Security::verificarBloqueioLogin("mfa:{$uid}");

        $cifrado = Database::valor('SELECT mfa_secret_cifrado FROM users WHERE id = ?', [$uid]);
        $segredo = is_string($cifrado) && $cifrado !== '' ? Mfa::decifrarSegredo($cifrado) : null;

        if (!Mfa::verificarCodigo($segredo, $codigo)) {
            Security::registrarFalhaLogin("mfa:{$uid}");
            Http::erro(401, 'Código inválido.');
        }
        Security::limparFalhasLogin("mfa:{$uid}");

        $codigosBackup = Mfa::gerarCodigosBackup();
        Database::transacao(static function () use ($uid, $codigosBackup): void {
            Database::run('UPDATE users SET mfa_ativado_em = UTC_TIMESTAMP(), ultimo_login = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
            Database::run('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$uid]);
            foreach ($codigosBackup as $c) {
                Database::run('INSERT INTO mfa_backup_codes (user_id, codigo_hash) VALUES (?, ?)', [$uid, hash('sha256', $c)]);
            }
        });

        self::encerrarPendencia($uid);
        Auth::criarSessao($uid);

        Http::json(array_merge(self::dadosUsuario($uid), ['codigos_backup' => $codigosBackup]));
    }

    public static function verificar(): void
    {
        $p = self::exigirPendencia('verify');
        $uid = $p['user_id'];
        $codigo = Http::texto('codigo', 10);

        Security::verificarBloqueioLogin("mfa:{$uid}");

        $cifrado = Database::valor('SELECT mfa_secret_cifrado FROM users WHERE id = ?', [$uid]);
        $segredo = is_string($cifrado) && $cifrado !== '' ? Mfa::decifrarSegredo($cifrado) : null;

        $usouBackup = false;
        if (!Mfa::verificarCodigo($segredo, $codigo)) {
            if (!self::consumirCodigoBackup($uid, $codigo)) {
                Security::registrarFalhaLogin("mfa:{$uid}");
                Http::erro(401, 'Código inválido.');
            }
            $usouBackup = true;
        }
        Security::limparFalhasLogin("mfa:{$uid}");

        Database::run('UPDATE users SET ultimo_login = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
        self::encerrarPendencia($uid);
        Auth::criarSessao($uid);

        Http::json(array_merge(self::dadosUsuario($uid), ['usou_codigo_backup' => $usouBackup]));
    }

    private static function consumirCodigoBackup(int $uid, string $codigo): bool
    {
        $hash = hash('sha256', strtoupper(trim($codigo)));
        $linha = Database::um(
            'SELECT id FROM mfa_backup_codes WHERE user_id = ? AND codigo_hash = ? AND usado_em IS NULL',
            [$uid, $hash]
        );
        if ($linha === null) {
            return false;
        }
        Database::run('UPDATE mfa_backup_codes SET usado_em = UTC_TIMESTAMP() WHERE id = ?', [$linha['id']]);
        return true;
    }

    public static function regenerarCodigosBackup(): void
    {
        $uid = Auth::exigirUsuario();
        $codigo = Http::texto('codigo', 10);

        Security::verificarBloqueioLogin("mfa:{$uid}");
        $cifrado = Database::valor('SELECT mfa_secret_cifrado FROM users WHERE id = ?', [$uid]);
        $segredo = is_string($cifrado) && $cifrado !== '' ? Mfa::decifrarSegredo($cifrado) : null;
        if (!Mfa::verificarCodigo($segredo, $codigo)) {
            Security::registrarFalhaLogin("mfa:{$uid}");
            Http::erro(401, 'Código inválido.');
        }
        Security::limparFalhasLogin("mfa:{$uid}");

        $codigosBackup = Mfa::gerarCodigosBackup();
        Database::transacao(static function () use ($uid, $codigosBackup): void {
            Database::run('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$uid]);
            foreach ($codigosBackup as $c) {
                Database::run('INSERT INTO mfa_backup_codes (user_id, codigo_hash) VALUES (?, ?)', [$uid, hash('sha256', $c)]);
            }
        });

        Http::json(['codigos_backup' => $codigosBackup]);
    }

    /** @return array<string, mixed> */
    private static function dadosUsuario(int $uid): array
    {
        $u = Database::um('SELECT nome, usuario, email, is_admin FROM users WHERE id = ?', [$uid]);
        return [
            'nome'     => $u['nome'] !== null && $u['nome'] !== '' ? $u['nome'] : $u['usuario'],
            'email'    => $u['email'],
            'is_admin' => (bool) $u['is_admin'],
        ];
    }
}
```

- [ ] **Step 2: Trocar o final de `AuthController::login()` para entrar na pendência de MFA em vez de criar sessão direto**

Troque as linhas finais de `login()` (a partir de `Database::run('UPDATE users SET ultimo_login...')` até o `Http::json([...])` final) por:

```php
        Http::json(\MinhasContas\Controllers\MfaController::iniciarPendencia($uid));
    }
```

(A atualização de `ultimo_login` passa a acontecer dentro do `MfaController`, só quando a sessão real é de fato criada — ver Task 9, Step 1.)

- [ ] **Step 3: Registrar as rotas em `public/index.php`**

Adicionar `use MinhasContas\Controllers\MfaController;` junto aos outros `use` de controllers, e:

```php
    Router::post('/api/auth/mfa/setup/start', MfaController::setupIniciar(...));
    Router::post('/api/auth/mfa/setup/confirm', MfaController::setupConfirmar(...));
    Router::post('/api/auth/mfa/verify', MfaController::verificar(...));
    Router::post('/api/auth/mfa/backup-codes/regenerate', MfaController::regenerarCodigosBackup(...));
```

- [ ] **Step 4: Verificar manualmente o ciclo completo**

```bash
# 1. Login com um usuário de e-mail já verificado (ex.: 'planilha', sem MFA ainda):
curl -sc /tmp/cj.txt -X POST http://localhost:8000/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"planilha","senha":"SENHA_DA_PLANILHA"}'
# Esperado: {"status":"mfa_setup_required"} e um cookie mfa_pending em /tmp/cj.txt

# 2. Iniciar o cadastro do MFA (usa o cookie da etapa anterior):
curl -sb /tmp/cj.txt -X POST http://localhost:8000/api/auth/mfa/setup/start
# Esperado: {"segredo":"...", "otpauth_uri":"otpauth://totp/..."}

# 3. Gerar o código TOTP correspondente ao segredo devolvido (ex. com oathtool):
#    oathtool --totp -b "SEGREDO_DEVOLVIDO"
curl -sb /tmp/cj.txt -X POST http://localhost:8000/api/auth/mfa/setup/confirm \
  -H 'Content-Type: application/json' -d '{"codigo":"CODIGO_GERADO"}'
# Esperado: {"nome":..., "codigos_backup":[10 códigos]}, sessão criada.

# 4. Logout e login de novo — agora deve pedir só o código, sem setup:
curl -sb /tmp/cj.txt -X POST http://localhost:8000/api/auth/logout
curl -sc /tmp/cj2.txt -X POST http://localhost:8000/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"planilha","senha":"SENHA_DA_PLANILHA"}'
# Esperado: {"status":"mfa_required"}
curl -sb /tmp/cj2.txt -X POST http://localhost:8000/api/auth/mfa/verify \
  -H 'Content-Type: application/json' -d '{"codigo":"NOVO_CODIGO_GERADO"}'
# Esperado: sessão criada, dados do usuário.

# 5. Um dos códigos de backup também deve funcionar no lugar do TOTP (uma única vez).
```

Se não tiver `oathtool` instalado, gere o código manualmente com o pequeno script:

```bash
docker compose exec app php -r "
require '/var/www/html/src/autoload.php';
echo MinhasContas\Mfa::codigoHotp(MinhasContas\Mfa::base32Decodificar('SEGREDO_DEVOLVIDO'), intdiv(time(), 30), 6), \"\n\";
"
```

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/MfaController.php src/Controllers/AuthController.php public/index.php
git commit -m "feat: adiciona MFA obrigatório (TOTP) ao fluxo de login"
```

---

### Task 10: Admin — resetar MFA de um usuário

**Files:**
- Modify: `src/Controllers/AdminController.php`
- Modify: `public/index.php` (rota)

**Interfaces:**
- Consumes: `Auth::exigirAdmin()`, `Users::buscarOuFalhar()` (já existem).
- Produces: `AdminController::resetarMfa(int $uid): void`.

- [ ] **Step 1: Adicionar o método a `AdminController`, depois de `redefinirSenha()`**

```php
    public static function resetarMfa(int $uid): void
    {
        Auth::exigirAdmin();
        Users::buscarOuFalhar($uid);

        Database::transacao(static function () use ($uid): void {
            Database::run('UPDATE users SET mfa_secret_cifrado = NULL, mfa_ativado_em = NULL WHERE id = ?', [$uid]);
            Database::run('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$uid]);
            Database::run('DELETE FROM mfa_pending WHERE user_id = ?', [$uid]);
        });

        Http::json(['ok' => true]);
    }
```

- [ ] **Step 2: Registrar a rota em `public/index.php`, junto às outras de admin**

```php
    Router::post('/api/admin/users/{id}/mfa/reset', AdminController::resetarMfa(...));
```

- [ ] **Step 3: Verificar manualmente (como um usuário admin autenticado)**

```bash
curl -sb /tmp/cj.txt -X POST http://localhost:8000/api/admin/users/2/mfa/reset \
  -H "X-CSRF-Token: $(grep csrf /tmp/cj.txt | awk '{print $7}')"
```

Esperado: `{"ok":true}`; o próximo login desse usuário volta a pedir `mfa_setup_required`.

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/AdminController.php public/index.php
git commit -m "feat: permite admin resetar o MFA de um usuário"
```

---

### Task 11: Vendorizar a biblioteca de QR code

**Files:**
- Create: `public/static/vendor/qrcode.js`

**Interfaces:**
- Consumes: nada (arquivo estático).
- Produces: função global `qrcode(typeNumber, errorCorrectionLevel)` (API da biblioteca `qrcode-generator`), usada pela Task 13 para desenhar o QR no cadastro do MFA.

- [ ] **Step 1: Baixar a biblioteca `qrcode-generator` (Kazuhiko Arase — licença permissiva, sem dependências, arquivo único) e vendorizar**

```bash
curl -fsSL https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js \
  -o public/static/vendor/qrcode.js
```

Se este comando não puder ser executado no ambiente de implementação (sem acesso à internet), baixe manualmente de https://www.npmjs.com/package/qrcode-generator (versão 1.4.4 ou mais recente) numa máquina com acesso e copie o arquivo `qrcode.js` para `public/static/vendor/`. Não reescreva o algoritmo de memória — é um encoder Reed-Solomon com tabelas de capacidade grandes; um erro de transcrição gera QR codes que não escaneiam de forma sutil e intermitente.

- [ ] **Step 2: Conferir que o arquivo baixou por completo e é JS válido**

```bash
node --check public/static/vendor/qrcode.js && wc -l public/static/vendor/qrcode.js
```

Esperado: sem erro de sintaxe, arquivo com várias centenas de linhas (não vazio/truncado).

- [ ] **Step 3: Registrar no HTML**

Em `public/index.html`, logo abaixo de `<script src="/static/vendor/chart.umd.js"></script>`:

```html
<script src="/static/vendor/qrcode.js"></script>
```

A CSP em `src/Http.php` (`script-src 'self'`) já cobre qualquer script servido de `/static/`, então nenhuma mudança é necessária ali.

- [ ] **Step 4: Testar isoladamente no console do navegador**

```bash
docker compose up -d
```

Abra `http://localhost:8000` no navegador, abra o console e rode:

```js
const qr = qrcode(0, 'M');
qr.addData('otpauth://totp/Teste:teste@exemplo.com?secret=JBSWY3DPEHPK3PXP&issuer=Teste');
qr.make();
document.body.insertAdjacentHTML('beforeend', qr.createSvgTag(4));
```

Esperado: um QR code aparece na página e é lido corretamente pelo Microsoft Authenticator (ou Google Authenticator) apontando a câmera para a tela — este é o teste de aceitação real, mais confiável que qualquer verificação de código para uma biblioteca vendorizada de terceiro.

- [ ] **Step 5: Commit**

```bash
git add public/static/vendor/qrcode.js public/static/index.html
git commit -m "chore: vendoriza biblioteca de geração de QR code para o cadastro do MFA"
```

---

### Task 12: Frontend — cadastro com verificação de e-mail e recuperação de senha

**Files:**
- Modify: `public/static/index.html` (tela de auth)
- Modify: `public/static/app.js` (seção `/* ---------- Autenticação ---------- */`)

**Interfaces:**
- Consumes: `/api/auth/register`, `/api/auth/verify-email`, `/api/auth/resend-verification`, `/api/auth/forgot-password`, `/api/auth/reset-password` (Tasks 7-8).
- Produces: telas "verifique seu e-mail", "esqueci minha senha" e "definir nova senha" dentro do `#auth-screen` existente; função `mostrarPasso(id)`.

- [ ] **Step 1: Adicionar os blocos de tela em `public/static/index.html`, dentro de `.auth-card`, logo após `<p class="muted" id="au-hint"></p>` (linha 29)**

```html
    <p class="muted" id="au-hint"></p>
    <p class="auth-link"><a href="#" id="au-forgot-link">Esqueci minha senha</a></p>

    <div id="au-check-email" class="auth-step" hidden>
      <p>Confira seu e-mail e clique no link para ativar sua conta.</p>
      <button type="button" class="btn" id="au-resend">Reenviar e-mail</button>
    </div>

    <div id="au-forgot" class="auth-step" hidden>
      <form id="forgot-form">
        <label>E-mail<input id="fg-email" type="email" required></label>
        <button type="submit" class="btn primary">Enviar link de redefinição</button>
      </form>
      <p class="muted" id="fg-msg"></p>
      <p class="auth-link"><a href="#" id="fg-back-link">Voltar para o login</a></p>
    </div>

    <div id="au-reset" class="auth-step" hidden>
      <form id="reset-form">
        <label>Nova senha<input id="rs-senha" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
        <button type="submit" class="btn primary">Redefinir senha</button>
      </form>
      <p class="auth-error" id="rs-error" hidden></p>
    </div>
```

- [ ] **Step 2: Reescrever o handler de submit do `#auth-form` em `app.js` (linhas 1337-1362) para lidar com a resposta de cadastro (sem sessão) e delegar a resposta de login**

```js
let ultimoEmailCadastro = "";

function mostrarPasso(id) {
  $$(".auth-step").forEach((el) => (el.hidden = true));
  $("#auth-form").hidden = id !== null;
  $(".auth-tabs").hidden = id !== null;
  if (id) $("#" + id).hidden = false;
}

// Placeholder — a Task 13 substitui esta função pela versão que trata
// mfa_required / mfa_setup_required.
async function tratarRespostaLogin(data) {
  await enterApp(data.nome || data.email, data.is_admin);
}

$("#auth-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const email = $("#au-user").value.trim();
  const senha = $("#au-pass").value;
  let endpoint, payload;
  if (authMode === "login") {
    endpoint = "/api/auth/login";
    payload = { email, senha };
  } else {
    endpoint = "/api/auth/register";
    payload = { nome: $("#au-nome").value.trim(), email, senha };
  }
  const res = await fetch(endpoint, {
    method: "POST",
    headers: cabecalhos(),
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = $("#au-error");
    err.textContent = data.detail || "Não foi possível continuar.";
    err.hidden = false;
    return;
  }
  if (authMode === "register") {
    ultimoEmailCadastro = email;
    mostrarPasso("au-check-email");
    return;
  }
  await tratarRespostaLogin(data);
});

$("#au-resend").addEventListener("click", async () => {
  await fetch("/api/auth/resend-verification", {
    method: "POST", headers: cabecalhos(),
    body: JSON.stringify({ email: ultimoEmailCadastro }),
  });
  alert("Se ainda não tiver confirmado, reenviamos o e-mail.");
});

$("#au-forgot-link").addEventListener("click", (e) => {
  e.preventDefault();
  mostrarPasso("au-forgot");
});
$("#fg-back-link").addEventListener("click", (e) => {
  e.preventDefault();
  mostrarPasso(null);
});
$("#forgot-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const email = $("#fg-email").value.trim();
  await fetch("/api/auth/forgot-password", {
    method: "POST", headers: cabecalhos(), body: JSON.stringify({ email }),
  });
  $("#fg-msg").textContent = "Se esse e-mail existir, enviamos um link de redefinição.";
});

$("#reset-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const params = new URLSearchParams(location.search);
  const res = await fetch("/api/auth/reset-password", {
    method: "POST", headers: cabecalhos(),
    body: JSON.stringify({ token: params.get("reset_token"), senha_nova: $("#rs-senha").value }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = $("#rs-error");
    err.textContent = data.detail || "Não foi possível redefinir a senha.";
    err.hidden = false;
    return;
  }
  history.replaceState(null, "", location.pathname);
  alert("Senha redefinida. Faça login com a nova senha.");
  mostrarPasso(null);
});
```

- [ ] **Step 3: Detectar `?verify_token=` e `?reset_token=` na carga da página**

Substitua a função `boot()` (linhas 1383-1394 atuais) por:

```js
async function boot() {
  const params = new URLSearchParams(location.search);
  if (params.has("verify_token")) {
    const res = await fetch("/api/auth/verify-email", {
      method: "POST", headers: cabecalhos(),
      body: JSON.stringify({ token: params.get("verify_token") }),
    });
    history.replaceState(null, "", location.pathname);
    setAuthMode("login");
    showAuth();
    alert(res.ok ? "E-mail confirmado! Faça login." : "Link de verificação inválido ou expirado.");
    return;
  }
  if (params.has("reset_token")) {
    setAuthMode("login");
    showAuth();
    mostrarPasso("au-reset");
    return;
  }
  setAuthMode("login");
  try {
    const res = await fetch("/api/auth/me");
    if (res.ok) {
      const { nome, usuario, is_admin } = await res.json();
      await enterApp(nome || usuario, is_admin);
      return;
    }
  } catch {}
  showAuth();
}
```

- [ ] **Step 4: Adicionar uma classe CSS simples para as telas alternativas, em `public/static/style.css`**

```css
.auth-step { margin-top: 1rem; }
.auth-link { text-align: center; font-size: 0.9rem; margin-top: 0.5rem; }
```

- [ ] **Step 5: Testar manualmente no navegador**

1. Cadastrar uma conta nova → deve mostrar "Confira seu e-mail...".
2. Clicar "Reenviar e-mail" → sem erro no console.
3. Abrir o link do e-mail (`?verify_token=...`) → alerta de confirmação, volta para tela de login.
4. Clicar "Esqueci minha senha", enviar e-mail cadastrado → mensagem genérica aparece.
5. Abrir o link do e-mail (`?reset_token=...`) → formulário de nova senha aparece; submeter → alerta de sucesso.

- [ ] **Step 6: Commit**

```bash
git add public/static/index.html public/static/app.js public/static/style.css
git commit -m "feat: adiciona telas de verificação de e-mail e recuperação de senha"
```

---

### Task 13: Frontend — cadastro e verificação de MFA no login

**Files:**
- Modify: `public/static/index.html` (telas de MFA)
- Modify: `public/static/app.js` (substitui `tratarRespostaLogin`, adiciona os handlers de MFA)
- Modify: `public/static/style.css` (lista de códigos de backup)

**Interfaces:**
- Consumes: `/api/auth/mfa/setup/start`, `/api/auth/mfa/setup/confirm`, `/api/auth/mfa/verify` (Task 9), `qrcode()` global (Task 11), `mostrarPasso(id)` (Task 12).
- Produces: `tratarRespostaLogin(data)` completo (substitui o placeholder da Task 12).

- [ ] **Step 1: Adicionar as telas de MFA em `public/static/index.html`, depois do bloco `#au-reset` da Task 12**

```html
    <div id="au-mfa-setup" class="auth-step" hidden>
      <p>Escaneie este QR code com o Microsoft Authenticator (ou outro app compatível):</p>
      <div id="mfa-qr"></div>
      <p class="muted">Não consegue escanear? Digite manualmente: <code id="mfa-secret"></code></p>
      <form id="mfa-setup-form">
        <label>Código do aplicativo<input id="mfa-setup-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
        <button type="submit" class="btn primary">Confirmar</button>
      </form>
      <p class="auth-error" id="mfa-setup-error" hidden></p>
    </div>

    <div id="au-mfa-backup" class="auth-step" hidden>
      <p><strong>Guarde estes códigos de backup em local seguro.</strong> Cada um funciona uma única vez, caso você perca acesso ao aplicativo autenticador. Eles não serão mostrados de novo.</p>
      <ul id="mfa-backup-list" class="mfa-backup-codes"></ul>
      <button type="button" class="btn primary" id="mfa-backup-ok">Já guardei, continuar</button>
    </div>

    <div id="au-mfa-verify" class="auth-step" hidden>
      <form id="mfa-verify-form">
        <label>Código do aplicativo autenticador<input id="mfa-verify-code" inputmode="numeric" maxlength="10" required></label>
        <button type="submit" class="btn primary">Entrar</button>
      </form>
      <p class="muted">Perdeu o acesso? Use um dos seus códigos de backup no mesmo campo.</p>
      <p class="auth-error" id="mfa-verify-error" hidden></p>
    </div>
```

- [ ] **Step 2: Substituir o placeholder de `tratarRespostaLogin` (Task 12) por esta versão completa, em `app.js`**

```js
let dadosAppPendentes = null;

async function tratarRespostaLogin(data) {
  if (data.status === "mfa_setup_required") {
    await iniciarCadastroMfa();
    return;
  }
  if (data.status === "mfa_required") {
    mostrarPasso("au-mfa-verify");
    return;
  }
  await enterApp(data.nome || data.email, data.is_admin);
}

async function iniciarCadastroMfa() {
  const res = await fetch("/api/auth/mfa/setup/start", { method: "POST", headers: cabecalhos() });
  const data = await res.json();
  $("#mfa-secret").textContent = data.segredo;
  $("#mfa-qr").innerHTML = "";
  const qr = qrcode(0, "M");
  qr.addData(data.otpauth_uri);
  qr.make();
  $("#mfa-qr").innerHTML = qr.createSvgTag(4);
  mostrarPasso("au-mfa-setup");
}

$("#mfa-setup-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const res = await fetch("/api/auth/mfa/setup/confirm", {
    method: "POST", headers: cabecalhos(),
    body: JSON.stringify({ codigo: $("#mfa-setup-code").value.trim() }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = $("#mfa-setup-error");
    err.textContent = data.detail || "Código inválido.";
    err.hidden = false;
    return;
  }
  dadosAppPendentes = data;
  $("#mfa-backup-list").innerHTML = data.codigos_backup.map((c) => `<li>${esc(c)}</li>`).join("");
  mostrarPasso("au-mfa-backup");
});

$("#mfa-backup-ok").addEventListener("click", async () => {
  const data = dadosAppPendentes;
  dadosAppPendentes = null;
  mostrarPasso(null);
  await enterApp(data.nome || data.email, data.is_admin);
});

$("#mfa-verify-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const res = await fetch("/api/auth/mfa/verify", {
    method: "POST", headers: cabecalhos(),
    body: JSON.stringify({ codigo: $("#mfa-verify-code").value.trim() }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = $("#mfa-verify-error");
    err.textContent = data.detail || "Código inválido.";
    err.hidden = false;
    return;
  }
  mostrarPasso(null);
  await enterApp(data.nome || data.email, data.is_admin);
});
```

- [ ] **Step 3: Estilizar a lista de códigos de backup em `style.css`**

```css
.mfa-backup-codes {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.25rem 1rem;
  font-family: monospace;
  font-size: 1.05rem;
  list-style: none;
  padding: 0.75rem;
  margin: 0.75rem 0;
  background: var(--surface-2, #f4f4f4);
  border-radius: 8px;
}
```

- [ ] **Step 4: Testar manualmente o ciclo completo no navegador**

1. Logar com uma conta com e-mail já verificado e sem MFA → tela de QR aparece.
2. Escanear com o Microsoft Authenticator de verdade → confirmar com o código do app → lista de 10 códigos de backup aparece → "Já guardei, continuar" → entra no dashboard.
3. Logout, login de novo com a mesma conta → tela pede só o código de 6 dígitos do Authenticator → entra.
4. Logout, login de novo, usar um código de backup em vez do TOTP → entra; usar o mesmo código de backup uma segunda vez → erro "Código inválido".

- [ ] **Step 5: Commit**

```bash
git add public/static/index.html public/static/app.js public/static/style.css
git commit -m "feat: adiciona cadastro e verificação de MFA na tela de login"
```

---

### Task 14: Frontend — configurações (regenerar backup codes) e admin (resetar MFA)

**Files:**
- Modify: `public/static/index.html` (bloco em Configurações + botão no admin)
- Modify: `public/static/app.js` (novo handler + `loadAdminUsers`)

**Interfaces:**
- Consumes: `/api/auth/mfa/backup-codes/regenerate` (Task 9), `/api/admin/users/{id}/mfa/reset` (Task 10), `api()` (já existe).
- Produces: nenhuma interface nova para outras tasks — é a última peça da UI.

- [ ] **Step 1: Adicionar o bloco de MFA em Configurações, em `index.html`, logo após o card "Alterar senha" (depois da linha 278)**

```html
      <div class="card">
        <h3>Autenticação em duas etapas</h3>
        <p class="muted">Sua conta usa um aplicativo autenticador (Microsoft Authenticator ou similar) a cada login.</p>
        <form id="mfa-regen-form" class="form-grid">
          <label>Código atual do aplicativo<input name="codigo" inputmode="numeric" maxlength="6" required></label>
          <button class="btn">Gerar novos códigos de backup</button>
        </form>
        <ul id="mfa-regen-list" class="mfa-backup-codes" hidden></ul>
      </div>
```

- [ ] **Step 2: Adicionar o handler em `app.js`, logo após o handler de `#pw-form` (depois da linha 1245)**

```js
$("#mfa-regen-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const codigo = e.target.elements.codigo.value.trim();
  const data = await api("/api/auth/mfa/backup-codes/regenerate", {
    method: "POST", body: JSON.stringify({ codigo }),
  });
  e.target.reset();
  const lista = $("#mfa-regen-list");
  lista.innerHTML = data.codigos_backup.map((c) => `<li>${esc(c)}</li>`).join("");
  lista.hidden = false;
});
```

- [ ] **Step 3: Adicionar o botão "Resetar MFA" na tabela de admin, em `loadAdminUsers()` (`app.js`, dentro do template da linha ~1268-1272)**

```js
    <td class="row-actions">
      <button class="btn small" data-pw="${u.id}" title="Redefinir senha">🔑</button>
      <button class="btn small" data-mfa="${u.id}" title="Resetar MFA">🔁</button>
      <button class="btn small" data-flag="${u.id}" title="${u.is_admin ? "Rebaixar" : "Tornar admin"}">${u.is_admin ? "⬇️" : "⬆️"}</button>
      <button class="btn small danger" data-del="${u.id}" title="Excluir conta"${u.eu ? " disabled" : ""}>🗑</button>
    </td></tr>`).join("");
```

E, logo após o bloco `table.querySelectorAll("[data-pw]")...` (linha ~1286):

```js
  table.querySelectorAll("[data-mfa]").forEach((b) => b.addEventListener("click", async () => {
    const u = items.find((i) => i.id == b.dataset.mfa);
    if (!confirm(`Resetar o MFA de ${u.nome}? A conta vai precisar cadastrar o autenticador de novo no próximo login.`)) return;
    await api(`/api/admin/users/${u.id}/mfa/reset`, { method: "POST" });
    alert(`MFA de ${u.nome} foi resetado.`);
  }));
```

- [ ] **Step 4: Testar manualmente**

1. Em Configurações, com uma conta com MFA ativo, gerar novos códigos de backup informando o código atual do Authenticator → nova lista de 10 códigos aparece; o código antigo de backup (da Task 13) deixa de funcionar no próximo login.
2. No painel Admin, clicar 🔁 num usuário → confirmação → próximo login desse usuário pede cadastro de MFA de novo.

- [ ] **Step 5: Commit**

```bash
git add public/static/index.html public/static/app.js
git commit -m "feat: adiciona regeneração de códigos de backup e reset de MFA pelo admin"
```

---

## Ordem de execução

As tarefas têm dependências majoritariamente lineares. Sequência segura: **1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13 → 14**. Tasks 3 e 4 podem trocar de ordem entre si (nenhuma depende da outra); Tasks 5 e 6 também são independentes entre si (ambas só dependem de 4) e podem ser feitas em qualquer ordem, mas a numeração acima já é uma ordem válida e é a mais simples de seguir sem pular nada.
