# Análise de segurança — Minhas Contas (PHP + MySQL)

Revisão da minha própria implementação, feita após a conversão do backend
Python/FastAPI + SQLite para PHP 8.3 + MySQL 8.

O sistema guarda a vida financeira de várias pessoas na mesma instalação —
quanto cada uma ganha, com quem gasta, quanto deve. O modelo de ameaça que
orientou as decisões é, em ordem de gravidade:

1. um usuário autenticado ler ou alterar os dados de outro;
2. um visitante não autenticado extrair dados via injeção;
3. um site de terceiros agir em nome de quem está logado (CSRF);
4. um dado gravado por um usuário executar script no navegador de outro (XSS
   armazenado) — inclusive no do administrador, que é quem tem mais a perder;
5. sequestro de sessão e força bruta de senha.

Cada afirmação abaixo aponta o arquivo onde a defesa está implementada. As
seções marcadas com **verificado** foram exercitadas contra o sistema rodando;
o método está em [Como foi verificado](#como-foi-verificado).

> **Atualizado depois da revisão original.** Três coisas mudaram desde que este
> documento foi escrito e estão refletidas abaixo: entrou verificação de e-mail
> no cadastro, entrou segundo fator TOTP com códigos de backup, e a produção
> passou a servir HTTPS por trás do Caddy. Recuperação de senha continua não
> existindo — segue listada nos riscos residuais.

---

## 1. SQL Injection

**Situação: mitigado.** Nenhuma consulta do sistema concatena valor vindo do
cliente.

### 1.1 Prepared statements de verdade, não emulados

Toda query passa por `Database::run()`, que faz `prepare()` + `execute($params)`
([src/Database.php:96](src/Database.php#L96)). Os valores viajam separados do
SQL e nunca são reinterpretados como sintaxe.

O detalhe que sustenta isso está na configuração do PDO
([src/Database.php:38-49](src/Database.php#L38-L49)):

```php
PDO::ATTR_EMULATE_PREPARES   => false,
PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
```

- **`EMULATE_PREPARES => false`** é o item mais importante. Com a emulação
  ligada — que é o **padrão** do PDO com MySQL — o driver monta a query final
  em PHP e escapa os valores no cliente. Isso já foi contornado no mundo real
  via incompatibilidade de charset (o clássico bypass com GBK/SJIS, onde um
  byte é engolido pelo escape e a aspa sobrevive). Desligada, o servidor recebe
  a query e os parâmetros em pacotes distintos, e a proteção deixa de depender
  de escapar corretamente.
- **`ERRMODE_EXCEPTION`**: sem isso um `prepare()` que falha retorna `false` e a
  execução segue como se nada tivesse acontecido — o cenário em que uma
  condição de segurança "passa" porque a query nem rodou.
- **`MULTI_STATEMENTS => false`**: mesmo que algo escape, não há como emendar um
  segundo comando depois de `;`.

### 1.2 Identificadores nunca vêm da requisição

Valor parametrizado resolve 90% do problema; os outros 10% são os pontos em que
a requisição escolhe **nome de tabela ou de coluna**, que não podem ser
parametrizados. A versão Python tinha três desses, e todos foram fechados:

| Ponto | Como era no Python | Como ficou |
|---|---|---|
| CRUD genérico | `INSERT INTO {table} ({cols})` com `cols` vindo das chaves do JSON (`main.py`, `make_crud`) | Registro fechado `RECURSOS` com tabela, ordenação e cada campo declarado — [src/Controllers/CrudController.php:34-152](src/Controllers/CrudController.php#L34-L152) |
| Alteração em lote | `SET {payload.field}=?` com allowlist | Allowlist mantida, mas o valor só **seleciona** uma das cinco strings SQL escritas no arquivo; nada é concatenado — [src/Controllers/TransactionController.php:213-262](src/Controllers/TransactionController.php#L213-L262) |
| Ordenação | `ORDER BY {order}` | `ordem` é constante do registro, nunca parâmetro |

O efeito colateral disso é fechar também **mass assignment**: `lerCampos()`
([CrudController.php:213](src/Controllers/CrudController.php#L213)) lê do corpo
**apenas** os campos declarados. Enviar `{"nome":"x","user_id":99,"is_admin":1}`
em `POST /api/people` grava só `nome`; o resto é descartado sem erro.

### 1.3 Os três lugares onde algo é montado em string

Auditei todo o SQL do projeto atrás de interpolação. Sobraram três construções,
todas com a entrada já reduzida a um inteiro ou a uma constante:

1. **`LIMIT`/`OFFSET`** ([TransactionController.php:52-60](src/Controllers/TransactionController.php#L52-L60)).
   O MySQL com prepare nativo não aceita placeholder aí. Ambos passam por
   `(int)` com faixa fixa (`1..5000`, `0..1000000`) antes de entrar na string —
   depois do cast não existe caractere que não seja dígito.
2. **`IN (?,?,?)`** das operações em lote
   ([Database::placeholders()](src/Database.php#L152)). O que se constrói é a
   **quantidade** de `?`, derivada de `count()`. Os IDs continuam vindo por
   bind, já filtrados a inteiros positivos e limitados a 5000 itens por
   requisição ([Http::listaIds()](src/Http.php#L246)).
3. **Fragmentos de `WHERE`** ([src/Filters.php](src/Filters.php)). São strings
   literais escritas no arquivo (`'YEAR(dt_venc) = ?'`), escolhidas por
   `if`; o valor sempre entra em `$params`.

### 1.4 Curingas do `LIKE`

A busca escapa `\`, `%` e `_` antes de montar o termo
([TransactionController.php:311](src/Controllers/TransactionController.php#L311)).
Não é injeção de SQL, mas sem isso uma busca por `%` varre a tabela inteira e
uma por `50%` casa com tudo que começa em "50" — um vetor barato de negação de
serviço e uma fonte de resultado errado.

### 1.5 Validação antes do banco

`Http` ([src/Http.php](src/Http.php)) converte cada campo para o tipo declarado
ou aborta com 400: `opcao()` só aceita valores de lista fechada (`status`,
`tipo`, `grupo`); `data()` recusa `2026-02-31` via `checkdate()`; `texto()`
recusa bytes de controle e UTF-8 inválido. Isso é defesa em profundidade — não
substitui o bind, mas garante que o que chega ao banco tem formato conhecido.

O MySQL roda em `STRICT_ALL_TABLES` ([Database.php:73](src/Database.php#L73)):
dado que não cabe na coluna vira erro, não truncamento silencioso.

**Verificado:** payloads de injeção nos filtros (`' OR '1'='1`), na busca
(`%' UNION SELECT senha_hash FROM users--`) e no campo de alteração em lote
(`field: "senha_hash"`) foram tratados como texto literal ou recusados com 400.

---

## 2. Cross-Site Scripting (XSS)

**Situação: mitigado, com duas falhas reais encontradas e corrigidas.**

O vetor que importa aqui é XSS **armazenado**: o campo "observação" de um
lançamento ou o nome de uma pessoa são texto livre, ficam salvos, e voltam para
a tela — inclusive para a tela do administrador, em `/api/admin/users`.

### 2.1 Falhas encontradas na versão original

O `app.js` herdado já tinha a função `esc()`, mas não a aplicava em todos os
pontos. Auditando as atribuições de `innerHTML`, encontrei estes casos em que
dado do usuário ia cru para o DOM:

| Local | Campo | Correção |
|---|---|---|
| `ensurePeopleDatalist` | `p.nome` dentro de `value="..."` — permitia sair do atributo | `esc(p.nome)` |
| `loadResource` (patrimônio, projetos, cadastros, registro) | qualquer coluna sem formatador: `descricao`, `instituicao`, `ativo`, `banco`… | `esc(...)` no valor renderizado |
| `loadCategoriesTable` | `classificacao`, `grupo`, `tipo` | `esc(...)` |
| `loadPessoas` | `p.pessoa` | `esc(p.pessoa)` |
| `loadFluxo` | `categoria` e `subcategoria` | `esc(...)` |

Uma pessoa cadastrada como `<img src=x onerror=alert(1)>` executava script em
qualquer tela que a listasse. Todas as ocorrências estão corrigidas em
[public/static/app.js](public/static/app.js).

Varri o arquivo em busca de interpolações restantes de dado do usuário: as que
sobraram vão para `confirm()`, `alert()`, títulos passados a `openForm()` (que
usa `textContent`) e callbacks de tooltip do Chart.js — nenhuma é sink de HTML.

### 2.2 Content-Security-Policy

Escapar no cliente é a defesa principal; a CSP é a rede embaixo dela, para o
caso de um ponto novo escapar da revisão
([Http::cabecalhosSeguranca()](src/Http.php#L45)):

```
default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self';
form-action 'self'; base-uri 'none'; frame-ancestors 'none'
```

`script-src 'self'` sem `'unsafe-inline'` significa que um `<script>` ou um
`onerror=` injetado **não executa**, mesmo que chegue ao DOM. É o que
transformaria as falhas do item 2.1 de exploráveis em inertes.

`style-src` precisa de `'unsafe-inline'` porque o Chart.js aplica estilo inline
no canvas — é a única concessão, e não permite execução de script.
`base-uri 'none'` bloqueia sequestro de URL relativa via `<base>`;
`frame-ancestors 'none'` e `X-Frame-Options: DENY` bloqueiam clickjacking.

### 2.3 Tipo de conteúdo

Toda resposta de API sai como `application/json; charset=utf-8`
([Http::json()](src/Http.php#L27)) com `X-Content-Type-Options: nosniff`. O
navegador não vai reinterpretar um JSON como HTML por heurística de sniffing.

O JSON é gerado por `json_encode` com `JSON_INVALID_UTF8_SUBSTITUTE`, então byte
inválido não quebra a serialização nem deixa a resposta truncada.

O upload é gravado em diretório temporário fora da árvore pública e apagado no
mesmo request; além disso `static/` roda com `php_admin_flag engine off`
([Dockerfile](Dockerfile)), então nada ali é interpretado como código.

---

## 3. CSRF

**Situação: mitigado, com três camadas.** Não havia proteção nenhuma na versão
Python — a autenticação era por cookie, e qualquer site podia disparar
`POST /api/transactions/bulk-delete` no navegador de quem estivesse logado.

1. **Token sincronizado com a sessão** ([Security::verificarCsrf()](src/Security.php#L60)).
   No login o servidor gera um token de 256 bits, guarda o SHA-256 dele em
   `sessions.csrf_hash` e devolve o valor num cookie legível por JS. O `app.js`
   reapresenta esse valor no header `X-CSRF-Token`
   ([app.js:26-35](public/static/app.js#L26-L35)), e o servidor compara com
   `hash_equals` contra o hash guardado.
   Não é double-submit puro: o valor esperado está **no servidor**, então mesmo
   quem conseguisse plantar um cookie no domínio (via subdomínio comprometido)
   não passaria. Para login e cadastro, onde ainda não há sessão, cai no
   double-submit contra o cookie — o suficiente contra login-CSRF.
2. **Checagem de `Origin`** ([Security::verificarOrigem()](src/Security.php#L38)).
   Requisição de escrita com `Origin` de outro host é recusada com 403 antes de
   tocar o banco.
3. **`SameSite=Lax`** em ambos os cookies ([Security::setCookie()](src/Security.php#L114)).
   O cookie de sessão nem acompanha um POST disparado de outro site.

Só métodos de escrita (POST/PUT/PATCH/DELETE) exigem token — GET não altera
estado em nenhuma rota, o que é pré-requisito para essa divisão fazer sentido.

**Verificado:** requisição sem header → 403; com token forjado → 403;
com `Origin: https://evil.example` → 403.

---

## 4. Autenticação

### 4.1 Armazenamento de senha

`password_hash()` com **bcrypt, custo 12** ([Auth::hashSenha()](src/Auth.php#L27)).
Substitui o PBKDF2 escrito à mão da versão anterior: salt aleatório por senha e
fator de custo ficam embutidos no hash, e há menos código nosso no caminho
crítico.

**Compatibilidade com as contas existentes.** A coluna `salt` foi preservada.
Quando ela está preenchida, o registro é PBKDF2-HMAC-SHA256/200k herdado e é
verificado nesse formato; no primeiro login bem-sucedido o hash é reescrito em
bcrypt e o salt é zerado ([Auth::reidratarSeNecessario()](src/Auth.php#L67)).
Ninguém precisa redefinir senha, e o formato fraco desaparece por uso.
`password_needs_rehash` cobre também a subida futura do custo.

Comparações usam `hash_equals` — o `===` de PHP em strings sai no primeiro byte
diferente, o que é um oráculo de tempo.

**Limite de 72 bytes** ([AuthController::validarSenha()](src/Controllers/AuthController.php#L136)):
bcrypt ignora o que passa disso. Aceitar uma senha de 200 caracteres e usar só
os 72 primeiros seria mentir sobre a força dela, então senhas maiores são
recusadas explicitamente.

**Mínimo de 8 caracteres** para senhas novas (era 4). Não se aplica à
verificação — contas antigas continuam entrando com a senha que têm.

### 4.2 Enumeração de contas

Usuário inexistente e senha errada devolvem a mesma mensagem e o mesmo 401
([AuthController.php:79-86](src/Controllers/AuthController.php#L79-L86)).
Distinguir os dois entregaria uma lista de e-mails cadastrados.

### 4.3 Força bruta

Freio por par (IP, identificador) em `login_attempts`: 8 tentativas erradas
bloqueiam por 15 minutos ([Security.php:181-215](src/Security.php#L181-L215)).

`X-Forwarded-For` é **ignorado de propósito** — é um header que o cliente
escreve, e confiar nele daria a qualquer um uma cota nova a cada requisição.
Atrás de proxy, o IP real deve ser resolvido no próprio proxy (`mod_remoteip`).

**Verificado:** da 8ª tentativa em diante o login é recusado com 429, e a senha
correta também é barrada durante o bloqueio.

---

## 5. Sessões

- Token de **256 bits** de `random_bytes()` (CSPRNG) — [Security::novoToken()](src/Security.php#L134).
- **Só o SHA-256 vai para o banco** ([Auth::criarSessao()](src/Auth.php#L92)).
  A versão Python guardava o token em claro: quem lesse um dump — backup,
  injeção, acesso de leitura ao MySQL — montava um cookie válido para qualquer
  conta. Agora o dump não serve para entrar. Hash simples basta porque não há
  espaço de busca a percorrer em 256 bits de entropia.
- Cookie **`HttpOnly`** (fora do alcance de `document.cookie`), **`SameSite=Lax`**
  e **`Secure` automático** quando servido por HTTPS. O único cookie legível por
  JS é o CSRF, que não autentica nada sozinho.
- **Expiração de 30 dias** no banco (`expira_em`), não só no cookie — cookie
  vencido é decisão do cliente; a linha na tabela é decisão do servidor.
  Sessões vencidas são varridas periodicamente ([Security::limpezaPeriodica()](src/Security.php#L218)).
- **Invalidação:** trocar a senha derruba as outras sessões
  ([Auth::encerrarOutrasSessoes()](src/Auth.php#L146)); um reset feito pelo
  admin derruba **todas** as da conta ([AdminController.php:56](src/Controllers/AdminController.php#L56)).
  É o que faz o reset funcionar como resposta a um comprometimento.
- Sessão nativa do PHP desligada (`session.auto_start = Off`), evitando o
  cookie `PHPSESSID` paralelo e fixation.

---

## 6. Autorização e acesso a dados de outra conta (IDOR)

**Situação: mitigado.**

Toda leitura e toda escrita são filtradas por `user_id`, e o `user_id` vem da
sessão — nunca do corpo ou da URL. Não existe rota que aceite `user_id` como
parâmetro.

O padrão é `WHERE id = ? AND user_id = ?` em cada UPDATE e DELETE
(ex.: [TransactionController.php:180](src/Controllers/TransactionController.php#L180)).
Sem o segundo termo, um id adivinhado bastaria para apagar o lançamento de
outra pessoa. Quando não casa, a resposta é 404 e não 403 — 403 confirmaria que
o registro existe.

`MYSQL_ATTR_FOUND_ROWS` ([Database.php:44](src/Database.php#L44)) faz
`rowCount()` contar linhas **casadas**, não alteradas, para que
`rowCount() === 0` signifique exatamente "não é seu" e não "você reenviou os
mesmos valores".

Dois campos não são aceitos do cliente porque mudariam o significado do dado:
`grupo`/`tipo`/`categoria` de um lançamento são copiados da classificação
cadastrada ([TransactionController.php:275-283](src/Controllers/TransactionController.php#L275-L283)),
e `classificacao` de uma categoria é derivada no servidor
([Categories::montarClassificacao()](src/Categories.php#L59)). Sem isso o
cliente poderia gravar `tipo: "RECEITA"` numa despesa e inverter o sinal do
saldo.

**Privilégio de administrador** é verificado por requisição, lendo `is_admin`
da tabela `users` via JOIN na sessão ([Auth::exigirAdmin()](src/Auth.php#L181)) —
não de algo gravado no cookie no momento do login. Quem for rebaixado perde o
acesso na requisição seguinte, sem precisar deslogar.

Duas travas de consistência: não é possível rebaixar nem excluir o último
administrador (o sistema ficaria sem quem promova alguém), nem excluir a
própria conta.

**Verificado:** DELETE e PUT em lançamento de outra conta → 404; painel de admin
com sessão comum → 403.

---

## 7. Upload da planilha

O endpoint recebe arquivo de usuário autenticado e o interpreta — a superfície
mais rica do sistema. Defesas em
[ImportController::receberArquivo()](src/Controllers/ImportController.php#L44) e
[src/Xlsx/Reader.php](src/Xlsx/Reader.php):

- **`is_uploaded_file()`** confirma que o caminho veio do processamento de
  upload do PHP, e não de um valor forjado apontando para outro arquivo do
  servidor.
- **O nome original é ignorado por completo.** Nada digitado pelo usuário entra
  em caminho de arquivo — não há path traversal nem como gravar um `.php` em
  lugar servível.
- **Conteúdo, não extensão:** os 4 primeiros bytes precisam ser a assinatura ZIP
  (`PK\x03\x04`). O `Content-Type` declarado pelo navegador é ignorado.
- **Teto de 12 MB** no arquivo, com limites do PHP um pouco acima para que a
  mensagem de erro seja a nossa.
- **Zip bomb:** a soma dos tamanhos descomprimidos é lida do índice do ZIP e
  comparada a 120 MB **antes de descomprimir qualquer coisa**
  ([Reader::recusarZipBomb()](src/Xlsx/Reader.php#L106)).
- **XXE:** o XML é lido com `LIBXML_NONET` e **sem** `LIBXML_NOENT`, então
  entidade externa não é expandida.
- **Memória:** as planilhas são percorridas em fluxo (`XMLReader` + `zip://`,
  um `Generator` por linha), com teto de 200 mil linhas por aba. Um arquivo
  grande não materializa o XML descomprimido na memória.
- **Fórmulas não são avaliadas** — é lido o `<v>`, o último valor calculado.
  Macros de um `.xlsm` continuam intocadas dentro do ZIP; nada é executado.
- **Transação:** a gravação roda dentro de uma transação
  ([ImportController.php:26](src/Controllers/ImportController.php#L26)). Uma
  falha no meio não deixa metade importada — o que importa especialmente quando
  `replace=true` já apagou os lançamentos antigos.
- O temporário é apagado num bloco `finally`, com erro ou sem.

**Verificado:** zip bomb de 199 KB declarando 200 MB → recusado; arquivo que não
é ZIP → recusado; XXE apontando para `/etc/passwd` → não expandido, sem
vazamento; ZIP sem a aba `LANCAMENTO` → recusado com mensagem clara.

---

## 8. Vazamento de informação

- **Erro nunca vai para a resposta.** Exceção não prevista vira
  `{"detail":"Erro interno. Tente novamente."}` com 500, e o detalhe real vai
  para o log do container ([public/index.php:45-58](public/index.php#L45-L58)).
  `display_errors = Off` também no `php.ini`. Um `PDOException` na resposta
  entregaria nome de tabela, coluna e trecho da query.
- Só `ApiException` produz mensagem visível, e ela carrega apenas texto escrito
  por nós ([src/ApiException.php](src/ApiException.php)).
- `expose_php = Off` e `ServerTokens Prod`: sem `X-Powered-By`, sem versão do
  Apache no rodapé de erro.
- Mensagens de erro que ecoam valor recusado passam por
  [`Http::amostra()`](src/Http.php#L363), que corta em 40 caracteres e remove
  caracteres de controle.

---

## 9. Infraestrutura

- **DocumentRoot é `public/`.** `src/`, `resources/`, `bin/` e o
  `database.sql` ficam um nível acima. Mesmo que o Apache pare de interpretar
  PHP por uma configuração errada, não há URL que baixe o fonte dos controllers
  ou o schema. **Verificado:** `/database.sql` e `/../src/Config.php` → 404.
- **Código somente leitura** para o processo do Apache (`chmod -R a-w` no
  Dockerfile). Uma falha de escrita não vira execução de código.
- **O MySQL não é exposto ao host** — `expose` em vez de `ports` no
  docker-compose; só a rede interna alcança a porta 3306.
- **Credenciais só por variável de ambiente** ([src/Config.php](src/Config.php)).
  Nenhuma senha em arquivo versionado; `.env` está no `.gitignore` e no
  `.dockerignore`. O `docker-compose.yml` falha explicitamente se `DB_PASS` não
  estiver definida, em vez de subir com um padrão fraco.
- **Cascata no banco, não na aplicação.** Todas as tabelas de dado de usuário
  têm `FOREIGN KEY ... ON DELETE CASCADE` ([database.sql](database.sql)).
  A versão Python apagava tabela por tabela a partir de uma lista no código —
  criar uma tabela nova e esquecer de incluí-la deixaria dados órfãos de uma
  conta excluída. **Verificado:** após excluir uma conta com 194 lançamentos,
  zero registros órfãos.
- **`utf8mb4` em todo o schema**, evitando truncamento em caractere de 4 bytes.
- **Sem Composer / sem `vendor/`**: nenhuma dependência de terceiros no
  servidor, o que zera a superfície de supply chain. O leitor e o escritor de
  XLSX foram escritos para o projeto ([src/Xlsx/](src/Xlsx/)). A contrapartida
  está anotada em riscos residuais.

---

## 10. Riscos residuais e o que não está coberto

Honestamente, o que **não** foi resolvido:

1. **HTTPS: resolvido em produção, ausente no compose de desenvolvimento.**
   `docker-compose.prod.yml` sobe um Caddy à frente da aplicação, que emite e
   renova o certificado sozinho para o domínio do `docker/Caddyfile`. O flag
   `Secure` do cookie liga sozinho sob HTTPS e a aplicação respeita
   `X-Forwarded-Proto`. O `docker-compose.yml` de desenvolvimento continua HTTP
   puro — aceitável em `localhost`, e **não** deve ser exposto à internet.
   Pendência remanescente: HSTS ainda não está configurado no Caddy.
2. **`Origin` ausente não bloqueia.** Cliente que não manda `Origin` (curl,
   integração própria) passa pela camada 1 do CSRF. A defesa real nesse caso é o
   token sincronizado, que continua exigido — a camada 1 é conveniência.
3. **Recuperação de senha: implementada.** O que antes constava aqui como
   lacuna deixou de ser: existe o fluxo de "esqueci minha senha"
   (`AuthController::esqueciSenha` / `redefinirSenha`, migração
   `migrations/0004_recuperacao_senha.sql`). Decisões que sustentam a
   segurança dele:
   - token de 32 bytes de `random_bytes`, guardado só como SHA-256 — um
     vazamento do banco não permite redefinir a senha de ninguém;
   - uso único e validade de 60 minutos;
   - resposta sempre genérica, para o endpoint não virar um oráculo de quem
     tem conta no serviço;
   - o link do e-mail é montado a partir de `APP_URL`, nunca do cabeçalho
     `Host` da requisição — usar o `Host` deixaria um atacante apontar o
     e-mail da vítima para o domínio dele, com o token válido dentro;
   - redefinir encerra **todas** as sessões da conta, porque quem redefine
     pode estar reagindo a um acesso indevido em curso.

   Também já existiam, e não constavam neste documento: verificação de e-mail
   no cadastro (`AuthController::verificarEmail`,
   `migrations/0001_verificacao_email.sql`) e segundo fator TOTP com códigos
   de backup de uso único ([src/Mfa.php](src/Mfa.php), `migrations/0002_mfa.sql`),
   com o segredo cifrado sob `MFA_ENCRYPTION_KEY`.
4. **Sem limite de taxa fora do login.** Um usuário autenticado pode martelar
   `/api/import` ou consultas pesadas. Mitigado em parte pelos tetos de tamanho
   e de linhas, mas não há cota por conta.
5. **Freio de login por IP** é contornável por quem tem muitos IPs, e pode punir
   usuários atrás de um mesmo NAT. É o compromisso usual; um limite global por
   conta seria mais rigoroso e mais fácil de virar negação de serviço contra a
   vítima.
6. **O leitor/escritor XLSX é código próprio.** Evita a cadeia de dependências
   de uma biblioteca, mas concentra o risco em código sem os anos de exposição
   que uma biblioteca madura tem. Foi escrito de forma defensiva (limites de
   tamanho, sem expansão de entidade, leitura em fluxo) e testado contra os
   arquivos malformados descritos acima — ainda assim é a parte que eu
   revisitaria primeiro num pentest.
7. **Sem trilha de auditoria.** Não há registro de quem promoveu quem, nem de
   quem excluiu qual conta. Para um sistema com dados financeiros de terceiros,
   é a lacuna que eu fecharia em seguida.
8. **Planilhas com dados reais: removidas da árvore.** `Controle Financ. Pessoal
   6.0 - R00.xlsm` e os dois `backup_guilherme_*.xlsx` continham lançamentos
   financeiros reais e estavam versionados — o `.xlsm` desde o primeiro commit
   do repositório. Foram removidos da árvore e os padrões correspondentes estão
   no `.gitignore`.

   **Atenção, e isto é o que decide se o repositório pode virar público:**
   remover da árvore **não** remove do histórico. Enquanto o histórico não for
   reescrito, qualquer pessoa com acesso ao repositório recupera os arquivos
   com um `git show` no commit de origem. Antes de tornar este repositório
   público é obrigatório reescrever o histórico (`git filter-repo` ou BFG) e
   fazer force push.

---

## Como foi verificado

Contra o sistema rodando em `docker compose`, com uma bateria de 62 checagens
funcionais e de segurança (cadastro, sessão, CRUD, lançamento parcelado,
operações em lote, painéis, importação da planilha real, painel de admin) mais
os testes específicos citados em cada seção: injeção nos filtros e na busca,
CSRF em três variantes, IDOR em duas rotas, força bruta de login, XXE, zip bomb,
arquivo não-ZIP, exposição de `src/` e `database.sql`, e conferência dos
cabeçalhos de resposta.

Resultado: **62 de 62 checagens passaram**.

Dois defeitos reais apareceram durante essa verificação e foram corrigidos:
`XMLReader::expand()` sem `DOMDocument` quebrava a importação, e o `rowCount()`
do MySQL contava linhas alteradas em vez de casadas, o que reportava "2
lançamentos alterados" quando foram 3 e podia devolver 404 num PUT válido.

O roteiro de verificação não ficou no repositório por depender do ambiente de
teste; os comandos estão no histórico da conversa que originou esta conversão.
