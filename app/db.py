"""Banco SQLite multiusuário: schema, migração e seed da conta 'planilha'."""
import json
import os
import re
import sqlite3
from pathlib import Path

import auth

DB_PATH = os.environ.get("DB_PATH", str(Path(__file__).parent.parent / "data" / "financas.db"))
SEED_PATH = Path(__file__).parent / "seed.json"
DEFAULT_CATEGORIES_PATH = Path(__file__).parent / "default_categories.json"

PLANILHA_USER = "planilha"
PLANILHA_PASS = "planilha"

# Promovido a admin na primeira inicialização, se ainda não houver nenhum admin.
ADMIN_EMAIL = os.environ.get("ADMIN_EMAIL", "admin@seu-dominio.com")

SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT UNIQUE NOT NULL,
    nome TEXT,
    email TEXT UNIQUE,
    senha_hash TEXT NOT NULL,
    salt TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    ultimo_login TEXT,
    criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS sessions (
    token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS settings (
    user_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    value REAL NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, key)
);
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    classificacao TEXT NOT NULL,
    grupo TEXT NOT NULL DEFAULT 'OPERACIONAL',
    tipo TEXT NOT NULL DEFAULT 'DESPESA',
    categoria TEXT NOT NULL,
    subcategoria TEXT,
    meta_mes REAL NOT NULL DEFAULT 0,
    UNIQUE (user_id, classificacao)
);
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    dt_compra TEXT,
    dt_venc TEXT NOT NULL,
    classificacao TEXT NOT NULL,
    valor REAL NOT NULL,
    instituicao TEXT,
    pessoa TEXT,
    status TEXT NOT NULL DEFAULT 'Previsto',
    obs TEXT,
    grupo TEXT NOT NULL,
    tipo TEXT NOT NULL,
    categoria TEXT NOT NULL,
    subcategoria TEXT
);
CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    descricao TEXT NOT NULL,
    valor REAL NOT NULL DEFAULT 0,
    ano INTEGER,
    prazo TEXT
);
CREATE TABLE IF NOT EXISTS investments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    instituicao TEXT NOT NULL,
    fixa_var TEXT,
    prazo_projeto TEXT,
    ativo TEXT,
    valor REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    descricao TEXT NOT NULL,
    valor REAL NOT NULL DEFAULT 0,
    saldo_devedor REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS debts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    descricao TEXT NOT NULL,
    num_parcelas INTEGER NOT NULL DEFAULT 0,
    valor_parcela REAL NOT NULL DEFAULT 0,
    saldo_devedor REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS institutions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    nome TEXT NOT NULL,
    tipo TEXT,
    saldo_inicial REAL NOT NULL DEFAULT 0,
    descricao TEXT
);
CREATE TABLE IF NOT EXISTS people (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    nome TEXT NOT NULL,
    UNIQUE (user_id, nome)
);
CREATE TABLE IF NOT EXISTS ofx_imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    data_extracao TEXT,
    banco TEXT,
    periodo TEXT,
    nome_arquivo TEXT
);
CREATE TABLE IF NOT EXISTS card_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    data_pagto TEXT,
    cartao TEXT,
    periodo TEXT,
    valor REAL NOT NULL DEFAULT 0
);
"""

_SIMPLE_TABLES = [
    "transactions", "projects", "investments", "assets", "debts",
    "institutions", "ofx_imports", "card_payments",
]

# Tudo que pertence a um usuário. Derivado do SCHEMA para não esquecer nenhuma
# tabela nova ao excluir uma conta.
_USER_TABLES = [
    m.group(1) for m in re.finditer(
        r"CREATE TABLE IF NOT EXISTS (\w+)\s*\((?:[^;]*?)\buser_id\b", SCHEMA
    )
]


def get_conn():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def _table_exists(conn, name):
    return conn.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (name,)
    ).fetchone() is not None


def _has_column(conn, table, col):
    return any(r["name"] == col for r in conn.execute(f"PRAGMA table_info({table})"))


def _ensure_columns(conn, table, cols):
    """Adiciona colunas faltantes (SQLite não aceita UNIQUE via ALTER; unicidade fica na app)."""
    existing = {r["name"] for r in conn.execute(f"PRAGMA table_info({table})")}
    for name, ddl in cols.items():
        if name not in existing:
            conn.execute(f"ALTER TABLE {table} ADD COLUMN {ddl}")


def create_user(conn, usuario, senha, nome=None, email=None, seed_categories=True):
    salt, h = auth.hash_password(senha)
    cur = conn.execute(
        "INSERT INTO users (usuario, nome, email, senha_hash, salt) VALUES (?, ?, ?, ?, ?)",
        (usuario, nome, email, h, salt),
    )
    uid = cur.lastrowid
    for key, val in (("receita_mensal", 0), ("custo_vida_mensal", 0), ("fator_reserva", 6)):
        conn.execute(
            "INSERT OR REPLACE INTO settings (user_id, key, value) VALUES (?, ?, ?)",
            (uid, key, val),
        )
    if seed_categories and DEFAULT_CATEGORIES_PATH.exists():
        cats = json.loads(DEFAULT_CATEGORIES_PATH.read_text(encoding="utf-8"))
        for c in cats:
            conn.execute(
                """INSERT OR IGNORE INTO categories
                   (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (uid, c["classificacao"], c["grupo"], c["tipo"], c["categoria"],
                 c.get("subcategoria"), c.get("meta_mes", 0)),
            )
    return uid


def init_db():
    Path(DB_PATH).parent.mkdir(parents=True, exist_ok=True)
    conn = get_conn()
    legacy = _table_exists(conn, "transactions") and not _has_column(conn, "transactions", "user_id")
    conn.executescript(SCHEMA)

    # colunas adicionadas depois da 1ª versão de auth (nome/e-mail no cadastro,
    # depois painel admin e registro de último acesso)
    _ensure_columns(conn, "users", {
        "nome": "nome TEXT",
        "email": "email TEXT",
        "is_admin": "is_admin INTEGER NOT NULL DEFAULT 0",
        "ultimo_login": "ultimo_login TEXT",
    })
    _ensure_first_admin(conn)

    planilha_id = _ensure_planilha_row(conn)
    if legacy:
        _migrate_legacy(conn, planilha_id)
    else:
        has_tx = conn.execute(
            "SELECT COUNT(*) AS n FROM transactions WHERE user_id=?", (planilha_id,)
        ).fetchone()["n"]
        if has_tx == 0 and SEED_PATH.exists():
            _seed_planilha(conn, planilha_id)

    conn.commit()
    conn.close()


def _ensure_first_admin(conn):
    """Promove ADMIN_EMAIL só enquanto não existir nenhum admin.

    A condição importa: sem ela, um admin rebaixado pelo painel voltaria a ser
    admin no próximo restart.
    """
    if conn.execute("SELECT 1 FROM users WHERE is_admin=1").fetchone():
        return
    conn.execute(
        "UPDATE users SET is_admin=1 WHERE lower(email)=? OR lower(usuario)=?",
        (ADMIN_EMAIL.lower(), ADMIN_EMAIL.lower()),
    )


def delete_user_data(conn, uid):
    """Apaga o usuário e tudo que pertence a ele."""
    for t in _USER_TABLES:
        conn.execute(f"DELETE FROM {t} WHERE user_id=?", (uid,))
    conn.execute("DELETE FROM users WHERE id=?", (uid,))


def _ensure_planilha_row(conn):
    """Garante apenas a LINHA do usuário planilha (sem mexer em settings/categories,
    que num banco antigo ainda estão no formato legado)."""
    row = conn.execute("SELECT id FROM users WHERE usuario=?", (PLANILHA_USER,)).fetchone()
    if row:
        return row["id"]
    salt, h = auth.hash_password(PLANILHA_PASS)
    cur = conn.execute(
        "INSERT INTO users (usuario, senha_hash, salt) VALUES (?, ?, ?)",
        (PLANILHA_USER, h, salt),
    )
    return cur.lastrowid


def _migrate_legacy(conn, planilha_id):
    """Converte um banco antigo (tabelas sem user_id) para o schema multiusuário."""
    # tabelas simples: adiciona user_id e atribui à conta planilha
    for t in _SIMPLE_TABLES:
        if _table_exists(conn, t) and not _has_column(conn, t, "user_id"):
            conn.execute(f"ALTER TABLE {t} ADD COLUMN user_id INTEGER")
            conn.execute(f"UPDATE {t} SET user_id=? WHERE user_id IS NULL", (planilha_id,))

    # categories: tinha UNIQUE(classificacao) global -> reconstrói com UNIQUE(user_id, classificacao)
    if _table_exists(conn, "categories") and not _has_column(conn, "categories", "user_id"):
        old = conn.execute(
            "SELECT classificacao, grupo, tipo, categoria, subcategoria, meta_mes FROM categories"
        ).fetchall()
        conn.execute("DROP TABLE categories")
        conn.executescript(SCHEMA)  # recria categories vazia (novo formato)
        for r in old:
            conn.execute(
                """INSERT OR IGNORE INTO categories
                   (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (planilha_id, r["classificacao"], r["grupo"], r["tipo"], r["categoria"],
                 r["subcategoria"], r["meta_mes"]),
            )

    # people: reconstrói com user_id + UNIQUE(user_id, nome)
    if _table_exists(conn, "people") and not _has_column(conn, "people", "user_id"):
        nomes = [r["nome"] for r in conn.execute("SELECT nome FROM people")]
        conn.execute("DROP TABLE people")
        conn.executescript(SCHEMA)
        for nome in nomes:
            conn.execute(
                "INSERT OR IGNORE INTO people (user_id, nome) VALUES (?, ?)",
                (planilha_id, nome),
            )

    # settings antigo: (key PK) -> (user_id, key)
    info = {r["name"] for r in conn.execute("PRAGMA table_info(settings)")}
    if "user_id" not in info:
        old = conn.execute("SELECT key, value FROM settings").fetchall()
        conn.execute("ALTER TABLE settings RENAME TO settings_old")
        conn.executescript(SCHEMA)
        for r in old:
            conn.execute(
                "INSERT OR REPLACE INTO settings (user_id, key, value) VALUES (?, ?, ?)",
                (planilha_id, r["key"], r["value"]),
            )
        conn.execute("DROP TABLE settings_old")


def _seed_planilha(conn, uid):
    seed = json.loads(SEED_PATH.read_text(encoding="utf-8"))
    for k, v in seed.get("settings", {}).items():
        conn.execute(
            "INSERT OR REPLACE INTO settings (user_id, key, value) VALUES (?, ?, ?)",
            (uid, k, v),
        )
    for c in seed.get("categories", []):
        if c["tipo"] not in ("RECEITA", "DESPESA") or " - " not in c["classificacao"]:
            continue
        conn.execute(
            """INSERT OR IGNORE INTO categories
               (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
               VALUES (?, ?, ?, ?, ?, ?, ?)""",
            (uid, c["classificacao"], c["grupo"], c["tipo"], c["categoria"],
             c.get("subcategoria"), c.get("meta_mes", 0)),
        )
    people, institutions = set(), set()
    for t in seed.get("transactions", []):
        conn.execute(
            """INSERT INTO transactions
               (user_id, dt_compra, dt_venc, classificacao, valor, instituicao, pessoa,
                status, obs, grupo, tipo, categoria, subcategoria)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (uid, t.get("dt_compra"), t.get("dt_venc") or t.get("dt_compra"),
             t["classificacao"], t["valor"], t.get("instituicao"), t.get("pessoa"),
             t.get("status", "Previsto"), t.get("obs"), t["grupo"], t["tipo"],
             t["categoria"], t.get("subcategoria")),
        )
        if t.get("pessoa"):
            people.add(t["pessoa"])
        if t.get("instituicao"):
            institutions.add(t["instituicao"])
    for p in sorted(people):
        conn.execute("INSERT OR IGNORE INTO people (user_id, nome) VALUES (?, ?)", (uid, p))
    for i in sorted(institutions):
        conn.execute(
            "INSERT INTO institutions (user_id, nome, tipo) VALUES (?, ?, ?)",
            (uid, i, "Cartão de Crédito" if i.lower() in ("credito", "crédito") else "Conta"),
        )
    for p in seed.get("projects", []):
        conn.execute(
            "INSERT INTO projects (user_id, descricao, valor, ano, prazo) VALUES (?, ?, ?, ?, ?)",
            (uid, p["descricao"], p["valor"], p.get("ano"), p.get("prazo")),
        )
    pat = seed.get("patrimonio", {})
    for inv in pat.get("investimentos", []):
        conn.execute(
            """INSERT INTO investments (user_id, instituicao, fixa_var, prazo_projeto, ativo, valor)
               VALUES (?, ?, ?, ?, ?, ?)""",
            (uid, inv["instituicao"], inv.get("fixa_var"), inv.get("prazo_projeto"),
             inv.get("ativo"), inv.get("valor", 0)),
        )
    for b in pat.get("bens", []):
        conn.execute(
            "INSERT INTO assets (user_id, descricao, valor, saldo_devedor) VALUES (?, ?, ?, ?)",
            (uid, b["descricao"], b.get("valor", 0), b.get("saldo_devedor", 0)),
        )
    for dv in pat.get("dividas", []):
        conn.execute(
            """INSERT INTO debts (user_id, descricao, num_parcelas, valor_parcela, saldo_devedor)
               VALUES (?, ?, ?, ?, ?)""",
            (uid, dv["descricao"], dv.get("num_parcelas", 0), dv.get("valor_parcela", 0),
             dv.get("saldo_devedor", 0)),
        )
