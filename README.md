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
| CONFIGURACAO | **Configuração** — parâmetros, classificações, pessoas, instituições e a planilha (exportar/importar) |
| — | **Admin** — contas do sistema: redefinir senha, promover/rebaixar, excluir |

## Estrutura

```
public/          DocumentRoot — o único diretório servido pela web
  index.php      front controller (todas as rotas)
  static/        interface: HTML, CSS, JS e Chart.js embarcado
src/             código da aplicação (fora do alcance da web)
  Controllers/   um por área da API
  Modelo.php     o layout da planilha — fonte única de exportação e importação
  Exporter.php   escreve o modelo (em branco ou com os dados da conta)
  Importer.php   lê o modelo e a planilha antiga "Controle Financ. Pessoal"
  Xlsx/          leitor e escritor de .xlsx/.xlsm, sem dependência externa
resources/       plano de contas padrão e seed da conta de demonstração
bin/             scripts de linha de comando
tests/           testes de linha de comando, sem banco nem servidor
database.sql     schema completo do MySQL
```

Sem Composer e sem `vendor/`: o projeto não tem dependência de terceiros no
servidor.

## Planilha: exportar e importar

Tudo em **Configuração → Planilha**, com um modelo só nas duas pontas:

- **Modelo em branco** — o arquivo para preencher, com uma aba de instruções e
  um exemplo já preenchido.
- **Meus dados de hoje** — a conta inteira no mesmo modelo. Serve de backup e
  volta pela importação sem conversão nenhuma.
- **Importar** — aceita o modelo acima e também a planilha original
  "Controle Financ. Pessoal" (.xlsx ou .xlsm).

O formato está descrito em [docs/modelo-planilha.md](docs/modelo-planilha.md):
uma aba por assunto, cabeçalho na linha 1, dados a partir da linha 2, colunas
encontradas pelo nome (não pela posição) e quase tudo opcional.

A opção *substituir* apaga lançamentos, projetos, investimentos, bens e dívidas
da conta antes de importar — é o que permite reimportar o próprio backup sem
duplicar nada. Classificações, pessoas e instituições não são apagadas: têm
nome único e a importação atualiza em vez de repetir.

Pela linha de comando:

```bash
docker compose exec app php bin/export_backup.php fulano@exemplo.com /tmp/backup.xlsx
docker compose cp app:/tmp/backup.xlsx ./backup.xlsx

docker compose exec app php bin/export_backup.php --modelo /tmp/modelo.xlsx
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

Os testes não precisam de banco nem de servidor — rodam com o PHP do container:

```bash
docker compose exec app php tests/modelo_test.php     # planilha: ida e volta
docker compose exec app php tests/frontend_test.php   # invariantes da interface
docker compose exec app php tests/mfa_test.php        # TOTP e códigos de backup
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
