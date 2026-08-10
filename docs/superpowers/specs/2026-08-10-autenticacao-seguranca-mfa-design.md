# Segurança de conta: verificação de e-mail, recuperação de senha, bloqueio de login e MFA

Data: 2026-08-10

## Contexto

O app (`minhas-contas`) é PHP puro sem framework e sem Composer — sessões,
CSRF, hash de senha e o freio de força bruta em `src/Security.php` e
`src/Auth.php` são escritos à mão de propósito. Hoje:

- não existe nenhuma infraestrutura de envio de e-mail (sem SMTP, sem
  `mail()`, sem biblioteca);
- o cadastro só valida o *formato* do e-mail (`AuthController::validarEmail`),
  não a posse da caixa de entrada;
- o freio de força bruta já existe (`login_attempts`, `Security::verificarBloqueioLogin`
  / `registrarFalhaLogin`), com 8 tentativas / 15 min, por par (IP, identificador);
- não existe MFA;
- `bin/migrate.php` só aplica `database.sql` quando a tabela `users` ainda
  não existe — não há mecanismo para evoluir o schema de um banco já em
  produção.

Este documento cobre as quatro peças pedidas (verificação de e-mail no
cadastro, recuperação de senha, bloqueio de login a 5 tentativas / 1h e MFA
compatível com o Microsoft Authenticator) como um projeto único, já que
compartilham infraestrutura (envio de e-mail) e o mesmo fluxo de login.

## 1. Infraestrutura de migrações (pré-requisito)

Sem isso, qualquer coluna/tabela nova não chega ao banco já provisionado do
usuário.

- Tabela nova `schema_migrations` (`versao VARCHAR(190) PRIMARY KEY`,
  `aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`).
- Pasta `migrations/`, arquivos numerados (`0001_seguranca_conta.sql`, ...).
- `bin/migrate.php` passa a, depois de garantir o schema base:
  1. se o schema acabou de ser criado agora (instalação nova): registrar
     todos os arquivos de `migrations/` em `schema_migrations` sem executá-los
     — `database.sql` já nasce com o schema final;
  2. senão: aplicar em ordem, dentro de transação, qualquer arquivo de
     `migrations/` ainda não registrado, e gravar a linha em
     `schema_migrations` ao final de cada um.
- `database.sql` recebe as colunas/tabelas novas diretamente (para
  instalação nova), e a migração `0001` traz o mesmo DDL de forma
  idempotente (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`) mais
  o backfill único descrito na seção 2, para quem já tem banco.

## 2. Modelo de dados

```sql
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verificado_em  DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS mfa_secret_cifrado    VARBINARY(255) NULL,
  ADD COLUMN IF NOT EXISTS mfa_ativado_em        DATETIME      NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  token_hash  CHAR(64)     NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME     NOT NULL,
  KEY ix_email_verifications_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  token_hash  CHAR(64)     NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME     NOT NULL,
  KEY ix_password_resets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_pending (
  token_hash  CHAR(64)             NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED         NOT NULL,
  modo        ENUM('setup','verify') NOT NULL,
  criado_em   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME             NOT NULL,
  KEY ix_mfa_pending_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_backup_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  codigo_hash CHAR(64)     NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usado_em    DATETIME     NULL,
  KEY ix_mfa_backup_codes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Todas seguem o padrão já usado em `sessions`: só o SHA-256 do token vive no
banco, nunca o token em si. FKs `ON DELETE CASCADE` para `users(id)`, como as
demais tabelas do schema.

**Backfill único** (dentro da migração `0001`, não repetido em boots
seguintes): `UPDATE users SET email_verificado_em = criado_em WHERE
email_verificado_em IS NULL`. Isso "adota" as contas que já existem — elas
nunca tiveram chance de confirmar e-mail, então não podem ser bloqueadas por
uma exigência que não existia quando se cadastraram. Cadastros feitos depois
dessa migração continuam nascendo com `email_verificado_em = NULL` e passam
pelo fluxo normal de verificação.

Não há backfill equivalente para `mfa_ativado_em`: ele já nasce `NULL` para
todo mundo (novo ou existente), que é exatamente o estado "precisa configurar
no próximo login" pedido.

## 3. Envio de e-mail

`src/Mailer.php`: cliente SMTP mínimo em PHP puro — conecta via
`stream_socket_client`, `EHLO`, `STARTTLS`, `AUTH LOGIN`, `MAIL FROM` / `RCPT
TO` / `DATA`, mensagens em texto simples. Sem biblioteca nova, no mesmo
espírito do resto do projeto.

Duas mensagens: verificação de cadastro e recuperação de senha, cada uma um
link `{APP_URL}/?verify_token=...` ou `{APP_URL}/?reset_token=...`.

## 4. Cadastro e verificação de e-mail

- `AuthController::registrar()`: não abre mais sessão no final. Cria a
  conta (`email_verificado_em = NULL`), gera token de verificação (24h de
  validade), envia e-mail, responde pedindo para checar a caixa de entrada.
- `AuthController::verificarEmail()` (novo, `POST /api/auth/verify-email`):
  recebe o token, valida hash + expiração, marca `email_verificado_em =
  UTC_TIMESTAMP()`, apaga os tokens do usuário.
- `AuthController::reenviarVerificacao()` (novo, `POST
  /api/auth/resend-verification`): recebe e-mail, resposta sempre genérica
  ("se existir, reenviamos") independente de a conta existir ou já estar
  verificada — evita enumeração. Cooldown de 60s por e-mail (não gera token
  novo se o mais recente tem menos de 60s).
- `AuthController::login()`: depois da senha bater, se
  `email_verificado_em IS NULL` → 403 com mensagem clara pedindo para
  confirmar o e-mail.

## 5. Recuperação de senha

- `AuthController::esqueciSenha()` (novo, `POST
  /api/auth/forgot-password`): recebe e-mail, resposta sempre genérica,
  cooldown de 60s por e-mail. Se a conta existir, gera token (30 min de
  validade) e envia o e-mail.
- `AuthController::redefinirSenha()` (novo, `POST
  /api/auth/reset-password`): recebe token + nova senha (reaproveita
  `validarSenha`), valida hash + expiração, atualiza `senha_hash`, apaga
  **todas** as sessões do usuário (força novo login em todo dispositivo,
  igual já acontece em `trocarSenha`), apaga os tokens de reset do usuário, e
  marca `email_verificado_em` se ainda estiver nulo (clicar no link prova
  posse do e-mail).

## 6. Bloqueio de login por tentativas

Só mudança de constantes em `Config.php`:

- `LOGIN_MAX_TENTATIVAS`: 8 → **5**
- `LOGIN_BLOQUEIO_SEGUNDOS`: 900 → **3600**

Mantém o escopo atual (par IP + identificador, não a conta inteira
globalmente) — é assim de propósito, para um atacante não conseguir trancar a
conta de outra pessoa só errando a senha dela 5 vezes de qualquer IP. O mesmo
mecanismo (`Security::verificarBloqueioLogin` / `registrarFalhaLogin`) é
reaproveitado para as tentativas de código MFA (seção 7), com identificador
`"mfa:{$userId}"`.

## 7. MFA (TOTP)

`src/Mfa.php`: TOTP padrão RFC 6238 — HMAC-SHA1, 6 dígitos, passo de 30s,
tolerância de ±1 passo para relógio dessincronizado. É o padrão que qualquer
app autenticador (Microsoft Authenticator, Google Authenticator, Authy...)
entende sem configuração especial.

- Segredo: 20 bytes aleatórios, codificado em base32.
- Guardado cifrado em `users.mfa_secret_cifrado` via `sodium_crypto_secretbox`,
  chave vinda de `MFA_ENCRYPTION_KEY` (32 bytes, base64, gerado com `openssl
  rand -base64 32` como já sugerido no `.env.example` para as outras senhas).
- URI de provisionamento: `otpauth://totp/MinhasContas:{email}?secret=...&issuer=MinhasContas&algorithm=SHA1&digits=6&period=30`.
- QR code gerado **no navegador** (biblioteca JS vendorizada em
  `public/static/vendor/`, mesmo padrão do `chart.umd.js`) — o segredo nunca
  sai do dispositivo do usuário nem passa por serviço de terceiro. A UI
  também mostra o segredo em texto para entrada manual.

Fluxo de login atualizado (na ordem): bloqueio → senha → e-mail verificado →
MFA:

1. **Sem MFA configurado ainda** (`mfa_ativado_em IS NULL`): gera um novo
   segredo (sobrescrevendo qualquer um anterior não confirmado — sem
   problema, já que só passa a valer em `mfa_ativado_em`), grava cifrado,
   cria linha em `mfa_pending` (`modo='setup'`, 10 min), devolve
   `{status: 'mfa_setup_required'}` e seta cookie `mfa_pending` (httpOnly,
   curto). Endpoint `POST /api/auth/mfa/setup/start` devolve a URI/QR/segredo
   para a tela de cadastro. `POST /api/auth/mfa/setup/confirm` recebe o
   código de 6 dígitos, valida contra o segredo pendente (com o mesmo freio
   de força bruta da seção 6), e em caso de sucesso: `mfa_ativado_em =
   UTC_TIMESTAMP()`, gera 10 códigos de backup (10 caracteres
   alfanuméricos, sem `0/O/1/I` para evitar ambiguidade, hash SHA-256
   salvo, mostrados em texto puro **uma única vez** na resposta), apaga a
   linha de `mfa_pending`, cria a sessão real.
2. **MFA já configurado**: cria linha em `mfa_pending` (`modo='verify'`, 10
   min), devolve `{status: 'mfa_required'}`. `POST /api/auth/mfa/verify`
   recebe um código TOTP ou um código de backup; sucesso apaga
   `mfa_pending`, marca o código de backup usado (se foi esse o caminho) e
   cria a sessão real.

`mfa_pending` viaja num cookie httpOnly próprio, exatamente como o cookie de
sessão hoje — o frontend não precisa guardar nem reenviar token nenhum
manualmente.

`POST /api/auth/mfa/backup-codes/regenerate` (autenticado, exige o código
TOTP atual como confirmação): invalida os códigos de backup antigos e gera um
conjunto novo.

## 8. Reset de MFA pelo admin

`POST /api/admin/users/{id}/mfa/reset` (novo, no `AdminController`, mesmo
padrão de `redefinirSenha`): zera `mfa_secret_cifrado` e `mfa_ativado_em`,
apaga códigos de backup e linhas de `mfa_pending` do usuário. No próximo
login, a conta passa pelo fluxo de "sem MFA configurado" de novo. Cobre o
caso de perda simultânea do celular e da lista de códigos de backup.

## 9. Frontend (`public/static/index.html` + `app.js`)

- Cadastro: em vez de logar direto, mostra "verifique seu e-mail" com opção
  de reenviar.
- Login: novo estado de tela por status da resposta —
  `mfa_setup_required` (assistente: QR + entrada manual + confirmação +
  exibição única dos códigos de backup) e `mfa_required` (campo de código,
  com alternância para "usar código de backup").
- Link "Esqueci minha senha" no formulário de login → tela de pedir e-mail
  → mensagem genérica de confirmação.
- App lê `location.search` no carregamento: `verify_token` dispara a
  confirmação automaticamente; `reset_token` mostra a tela de nova senha.
- Configurações: bloco "Autenticação em duas etapas" com botão para gerar
  novos códigos de backup.
- Painel admin: botão "Redefinir MFA" ao lado do "Redefinir senha" já
  existente por usuário.

## 10. Variáveis de ambiente novas

`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL`,
`SMTP_FROM_NOME`, `APP_URL`, `MFA_ENCRYPTION_KEY` — documentadas no
`.env.example` seguindo o estilo já usado ali (comentário explicando como
gerar valor forte).

## 11. Fora de escopo / riscos aceitos

- Não há troca de algoritmo/dígitos/período do TOTP por usuário — fixo em
  SHA1/6/30s para compatibilidade máxima.
- Perda de `MFA_ENCRYPTION_KEY` torna os segredos TOTP existentes
  indecifráveis; usuários afetados fariam login com código de backup e
  precisariam reconfigurar o MFA (ou o admin reseta, seção 8). Não há
  rotação automática de chave — troca de `MFA_ENCRYPTION_KEY` é operação
  manual e deve ser tratada como incidente.
- E-mails são texto simples (sem HTML) — suficiente para link de ação, sem
  necessidade de template visual.

## 12. Testes

Sem framework de teste automatizado no projeto hoje (não há PHPUnit nem
scripts de teste em `bin/`); a verificação será manual, cobrindo:

- cadastro → e-mail chega → link confirma → login funciona;
- login sem verificar e-mail → bloqueado com mensagem clara;
- esqueci senha → e-mail chega → redefine → sessões antigas caem → login
  novo funciona;
- 5 tentativas erradas de senha → bloqueio de 1h → contagem reseta após
  login certo;
- primeiro login após o deploy força cadastro de MFA → QR escaneado no
  Microsoft Authenticator → código confirma → códigos de backup aparecem
  uma vez;
- login seguinte pede código MFA → aceita TOTP e também código de backup
  (uso único);
- admin consegue resetar o MFA de outro usuário.
