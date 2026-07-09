# 💰 Minhas Contas

Sistema web para gerenciamento de finanças pessoais, criado a partir da planilha
**Controle Financ. Pessoal 6.0 - R00.xlsm**. Roda em container Docker com banco
SQLite persistido em volume.

## Como rodar

```bash
docker compose up -d --build
```

Acesse **http://localhost:8000**.

No Windows sem Docker Desktop, rode dentro do WSL:

```bash
wsl -e bash -lc "cd /mnt/d/gerenciamentoDasMinhasContas && docker compose up -d --build"
```

Na primeira execução o banco é criado e alimentado automaticamente com os dados
da planilha ([app/seed.json](app/seed.json)): 194 lançamentos, 163 classificações,
1 projeto, pessoas e instituições. Os dados ficam no volume `contas-data` —
sobrevivem a rebuilds. Para recomeçar do zero: `docker compose down -v`.

## Abas (equivalência com a planilha)

| Planilha | Sistema |
|---|---|
| DASHBOARD | **Dashboard** — receitas × despesas por mês, saldo acumulado, gastos por categoria/subcategoria/pessoa, operacional × não operacional, com filtros de ano/mês/status |
| LANCAMENTO | **Lançamentos** — CRUD completo com busca e filtros |
| FLUXO | **Fluxo** — pivot mensal por tipo/categoria (com opção de detalhar subcategorias) |
| PATRIMONIO | **Patrimônio** — investimentos, bens e dívidas + resumo de patrimônio líquido |
| PROJETOS | **Projetos** — projetos por prazo + necessidade de reservas (fator × custo de vida) |
| REGISTRO | **Registro** — arquivos OFX importados e pagamentos de cartão |
| CONFIGURACAO | **Configuração** — parâmetros, classificações, pessoas e instituições |

## Desenvolvimento local (sem Docker)

```bash
pip install -r requirements.txt
cd app && python -m uvicorn main:app --reload --port 8100
```

API documentada automaticamente em `/docs` (Swagger).

## Reimportar a planilha

Se a planilha mudar, gere um novo seed e recrie o banco:

```bash
python scripts/extract_seed.py
docker compose down
rm data/financas.db          # o banco vive na raiz; apagar aqui recria com o seed
docker compose up -d --build
```

> `docker compose down -v` **não** apaga mais o banco: ele é um bind mount, não um
> volume gerenciado pelo Docker. Para zerar, apague `data/financas.db` na mão.

## Stack

- **Backend**: Python 3.12, FastAPI, SQLite
- **Banco**: `data/financas.db` na raiz do projeto. Local e Docker usam o mesmo arquivo
  (o container monta `./data` em `/data`). Fora do Git — faça backup copiando o arquivo.
- **Frontend**: HTML/CSS/JS estático + Chart.js (embarcado, funciona offline), tema claro/escuro automático
