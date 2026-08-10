# 💰 Minhas Contas

Sistema web para gerenciamento de finanças pessoais, criado a partir da planilha
**Controle Financ. Pessoal 6.0 - R00.xlsm**. Backend em **PHP 8.3** com banco
**MySQL 8**, rodando em containers Docker.

Multiusuário: cada conta tem seus próprios lançamentos, classificações,
projetos e patrimônio, isolados no banco por `user_id`.

## Como rodar

```bash
cp .env.example .env      # e troque as senhas
docker compose up -d --build
```

Acesse **http://localhost:8000**.

Na primeira subida o MySQL cria o schema a partir de [database.sql](database.sql)
e a aplicação cria a conta de demonstração `planilha`, alimentada por
[resources/seed.json](resources/seed.json) — 194 lançamentos, classificações,
pessoas e instituições. **A senha inicial dessa conta é gerada aleatoriamente e
aparece uma única vez no log:**

```bash
docker compose logs app | grep "senha inicial"
```

Os dados ficam no volume `mysql-data` e sobrevivem a rebuilds. Para recomeçar do
zero: `docker compose down -v`.

O primeiro administrador é a conta cujo e-mail estiver em `ADMIN_EMAIL`,
promovida automaticamente enquanto ainda não houver nenhum admin no sistema.

## Abas (equivalência com a planilha)

| Planilha | Sistema |
|---|---|
| DASHBOARD | **Dashboard** — receitas × despesas por mês, saldo acumulado, gastos por categoria/subcategoria/pessoa, operacional × não operacional, com filtros de ano/mês/status |
| LANCAMENTO | **Lançamentos** — CRUD completo, com busca, filtros por coluna, parcelamento e edição em lote |
| — | **Por Pessoa** — quanto se deve e quanto se tem a receber de cada pessoa |
| FLUXO | **Fluxo** — pivot mensal por tipo/categoria (com opção de detalhar subcategorias) |
| PATRIMONIO | **Patrimônio** — investimentos, bens e dívidas + resumo de patrimônio líquido |
| PROJETOS | **Projetos** — projetos por prazo + necessidade de reservas (fator × custo de vida) |
| REGISTRO | **Registro** — arquivos OFX importados e pagamentos de cartão |
| CONFIGURACAO | **Configuração** — parâmetros, classificações, pessoas, instituições e importação de planilha |
| — | **Admin** — contas do sistema: redefinir senha, promover/rebaixar, excluir |

## Estrutura

```
public/          DocumentRoot — o único diretório servido pela web
  index.php      front controller (todas as rotas)
  static/        interface: HTML, CSS, JS e Chart.js embarcado
src/             código da aplicação (fora do alcance da web)
  Controllers/   um por área da API
  Xlsx/          leitor e escritor de .xlsx/.xlsm, sem dependência externa
resources/       plano de contas padrão e seed da conta de demonstração
bin/             scripts de linha de comando
database.sql     schema completo do MySQL
```

Sem Composer e sem `vendor/`: o projeto não tem dependência de terceiros no
servidor.

## Importar uma planilha

Pela interface, em **Configuração → Importar planilha** (.xlsx ou .xlsm no
modelo "Controle Financ. Pessoal"). A opção *substituir* apaga os lançamentos
atuais da conta antes de importar.

## Backup

Gera um `.xlsx` no mesmo layout do modelo, reimportável pela própria interface:

```bash
docker compose exec app php bin/export_backup.php fulano@exemplo.com /tmp/backup.xlsx
docker compose cp app:/tmp/backup.xlsx ./backup.xlsx
```

## Migrar da versão anterior (SQLite)

O backend original era Python/FastAPI com SQLite. Para trazer os dados:

```bash
docker compose cp data/financas.db app:/tmp/financas.db
docker compose exec app php bin/import_sqlite.php /tmp/financas.db --dry-run   # confere
docker compose exec app php bin/import_sqlite.php /tmp/financas.db             # aplica
```

Contas já existentes no MySQL são puladas. **As senhas continuam valendo:** os
hashes PBKDF2 antigos são reconhecidos e convertidos para bcrypt no primeiro
login de cada conta, sem que a pessoa perceba.

## Desenvolvimento

```bash
docker compose exec app bash              # shell no container
docker compose logs -f app                # erros de PHP saem aqui
docker compose exec app php bin/migrate.php
```

Para ver a mensagem de erro real na resposta HTTP, defina `APP_ENV=development`
no `.env` — **só fora de um servidor exposto**.

## Segurança

A análise da implementação está em [security_review.md](security_review.md):
injeção de SQL, XSS, CSRF, autenticação, sessões, IDOR, upload de arquivo,
infraestrutura e riscos residuais.

Antes de expor à internet, leia a seção *Riscos residuais* — em especial o item
sobre **HTTPS**, que precisa ser terminado por um proxy na frente da aplicação.

## Stack

- **Backend**: PHP 8.3 (Apache + mod_php), MySQL 8.4, PDO com prepared
  statements nativos
- **Frontend**: HTML/CSS/JS estático + Chart.js embarcado (funciona offline),
  tema claro/escuro automático
