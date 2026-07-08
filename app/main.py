"""API do sistema de gerenciamento de contas pessoais (multiusuário)."""
import calendar
import re
from pathlib import Path
from typing import Optional

from fastapi import Cookie, Depends, FastAPI, File, Form, HTTPException, Query, Response, UploadFile
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

import auth
import importer
from db import create_user, get_conn, init_db

app = FastAPI(title="Minhas Contas")
init_db()

STATIC = Path(__file__).parent / "static"
SIGN = "CASE WHEN tipo = 'DESPESA' THEN -valor ELSE valor END"
COOKIE = "session"


# ---------- Auth ----------
class RegisterIn(BaseModel):
    nome: str
    email: str
    senha: str


class LoginIn(BaseModel):
    email: str
    senha: str


EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


def _new_session(conn, user_id: int) -> str:
    token = auth.new_token()
    conn.execute("INSERT INTO sessions (token, user_id) VALUES (?, ?)", (token, user_id))
    return token


def _set_cookie(response: Response, token: str):
    response.set_cookie(
        COOKIE, token, httponly=True, samesite="lax", path="/", max_age=60 * 60 * 24 * 30
    )


def current_user(session: Optional[str] = Cookie(default=None)) -> int:
    if not session:
        raise HTTPException(401, "Não autenticado")
    conn = get_conn()
    row = conn.execute("SELECT user_id FROM sessions WHERE token=?", (session,)).fetchone()
    conn.close()
    if not row:
        raise HTTPException(401, "Sessão inválida")
    return row["user_id"]


@app.post("/api/auth/register")
def register(body: RegisterIn, response: Response):
    nome = body.nome.strip()
    email = body.email.strip().lower()
    if len(nome) < 2:
        raise HTTPException(400, "Informe seu nome")
    if not EMAIL_RE.match(email):
        raise HTTPException(400, "E-mail inválido")
    if len(body.senha) < 4:
        raise HTTPException(400, "A senha precisa ter ao menos 4 caracteres")
    conn = get_conn()
    if conn.execute("SELECT 1 FROM users WHERE email=? OR usuario=?", (email, email)).fetchone():
        conn.close()
        raise HTTPException(400, "Já existe uma conta com esse e-mail")
    uid = create_user(conn, email, body.senha, nome=nome, email=email)
    token = _new_session(conn, uid)
    conn.commit()
    conn.close()
    _set_cookie(response, token)
    return {"nome": nome, "email": email}


@app.post("/api/auth/login")
def login(body: LoginIn, response: Response):
    ident = body.email.strip().lower()
    conn = get_conn()
    u = conn.execute(
        "SELECT * FROM users WHERE lower(email)=? OR lower(usuario)=?", (ident, ident)
    ).fetchone()
    if not u or not auth.verify_password(body.senha, u["salt"], u["senha_hash"]):
        conn.close()
        raise HTTPException(401, "E-mail ou senha inválidos")
    token = _new_session(conn, u["id"])
    conn.commit()
    conn.close()
    _set_cookie(response, token)
    return {"nome": u["nome"] or u["usuario"], "email": u["email"]}


@app.post("/api/auth/logout")
def logout(response: Response, session: Optional[str] = Cookie(default=None)):
    if session:
        conn = get_conn()
        conn.execute("DELETE FROM sessions WHERE token=?", (session,))
        conn.commit()
        conn.close()
    response.delete_cookie(COOKIE, path="/")
    return {"ok": True}


@app.get("/api/auth/me")
def me(user: int = Depends(current_user)):
    conn = get_conn()
    u = conn.execute("SELECT usuario, nome, email FROM users WHERE id=?", (user,)).fetchone()
    conn.close()
    return {"usuario": u["usuario"], "nome": u["nome"] or u["usuario"], "email": u["email"]}


# ---------- Models ----------
class TransactionIn(BaseModel):
    dt_compra: Optional[str] = None
    dt_venc: str
    classificacao: str
    valor: float
    instituicao: Optional[str] = None
    pessoa: Optional[str] = None
    status: str = "Previsto"
    obs: Optional[str] = None
    parcelas: int = 1          # nº de parcelas (1 = lançamento único)
    valor_total: bool = False  # True: valor informado é o total a dividir entre as parcelas


class CategoryIn(BaseModel):
    grupo: str = "OPERACIONAL"
    tipo: str = "DESPESA"
    categoria: str
    subcategoria: Optional[str] = None
    meta_mes: float = 0


class ProjectIn(BaseModel):
    descricao: str
    valor: float = 0
    ano: Optional[int] = None
    prazo: Optional[str] = None


class InvestmentIn(BaseModel):
    instituicao: str
    fixa_var: Optional[str] = None
    prazo_projeto: Optional[str] = None
    ativo: Optional[str] = None
    valor: float = 0


class AssetIn(BaseModel):
    descricao: str
    valor: float = 0
    saldo_devedor: float = 0


class DebtIn(BaseModel):
    descricao: str
    num_parcelas: int = 0
    valor_parcela: float = 0
    saldo_devedor: float = 0


class InstitutionIn(BaseModel):
    nome: str
    tipo: Optional[str] = None
    saldo_inicial: float = 0
    descricao: Optional[str] = None


class PersonIn(BaseModel):
    nome: str


class OfxIn(BaseModel):
    data_extracao: Optional[str] = None
    banco: Optional[str] = None
    periodo: Optional[str] = None
    nome_arquivo: Optional[str] = None


class CardPaymentIn(BaseModel):
    data_pagto: Optional[str] = None
    cartao: Optional[str] = None
    periodo: Optional[str] = None
    valor: float = 0


class SettingsIn(BaseModel):
    receita_mensal: float = 0
    custo_vida_mensal: float = 0
    fator_reserva: float = 6


class BulkDelete(BaseModel):
    ids: list[int]


class BulkUpdate(BaseModel):
    ids: list[int]
    field: str
    value: Optional[str] = None


ALLOWED_BULK_FIELDS = {"status", "pessoa", "instituicao", "classificacao", "dt_venc"}


# ---------- Helpers ----------
def rows(cur):
    return [dict(r) for r in cur.fetchall()]


def add_months(iso: str, k: int) -> str:
    """Soma k meses a uma data 'YYYY-MM-DD', ajustando o dia ao fim do mês quando necessário."""
    y, m, d = (int(x) for x in iso.split("-"))
    total = (m - 1) + k
    y2, m2 = y + total // 12, total % 12 + 1
    d2 = min(d, calendar.monthrange(y2, m2)[1])
    return f"{y2:04d}-{m2:02d}-{d2:02d}"


def category_for(conn, user_id, classificacao: str):
    cat = conn.execute(
        "SELECT * FROM categories WHERE user_id=? AND classificacao=?",
        (user_id, classificacao),
    ).fetchone()
    if not cat:
        raise HTTPException(400, f"Classificação não cadastrada: {classificacao}")
    return cat


def tx_filters(user_id, year, month, status, categoria, pessoa, tipo, grupo):
    where, params = ["user_id = ?"], [user_id]
    if year:
        where.append("strftime('%Y', dt_venc) = ?")
        params.append(f"{year:04d}")
    if month:
        where.append("strftime('%m', dt_venc) = ?")
        params.append(f"{month:02d}")
    if status and status != "Todos":
        where.append("status = ?")
        params.append(status)
    if categoria:
        where.append("categoria = ?")
        params.append(categoria)
    if pessoa:
        where.append("pessoa = ?")
        params.append(pessoa)
    if tipo:
        where.append("tipo = ?")
        params.append(tipo)
    if grupo:
        where.append("grupo = ?")
        params.append(grupo)
    return "WHERE " + " AND ".join(where), params


# ---------- Transactions ----------
@app.get("/api/transactions")
def list_transactions(
    user: int = Depends(current_user),
    year: Optional[int] = None, month: Optional[int] = None,
    status: Optional[str] = None, categoria: Optional[str] = None,
    pessoa: Optional[str] = None, tipo: Optional[str] = None,
    grupo: Optional[str] = None, q: Optional[str] = None,
    limit: int = Query(500, le=5000), offset: int = 0,
):
    conn = get_conn()
    where, params = tx_filters(user, year, month, status, categoria, pessoa, tipo, grupo)
    if q:
        where += " AND (classificacao LIKE ? OR obs LIKE ? OR pessoa LIKE ?)"
        params += [f"%{q}%"] * 3
    total = conn.execute(f"SELECT COUNT(*) AS n FROM transactions {where}", params).fetchone()["n"]
    cur = conn.execute(
        f"""SELECT *, {SIGN} AS saldo FROM transactions {where}
            ORDER BY dt_venc DESC, id DESC LIMIT ? OFFSET ?""",
        params + [limit, offset],
    )
    data = rows(cur)
    years = [r["y"] for r in conn.execute(
        "SELECT DISTINCT strftime('%Y', dt_venc) AS y FROM transactions WHERE user_id=? ORDER BY y",
        (user,)).fetchall()]
    conn.close()
    return {"total": total, "items": data, "years": years}


@app.post("/api/transactions")
def create_transaction(t: TransactionIn, user: int = Depends(current_user)):
    conn = get_conn()
    cat = category_for(conn, user, t.classificacao)
    n = max(1, min(t.parcelas or 1, 360))

    # valor de cada parcela (se for total, divide e o último absorve o arredondamento)
    if t.valor_total and n > 1:
        base = round(t.valor / n, 2)
        valores = [base] * (n - 1) + [round(t.valor - base * (n - 1), 2)]
    else:
        valores = [t.valor] * n

    first_id = None
    for k in range(n):
        venc = add_months(t.dt_venc, k) if n > 1 else t.dt_venc
        obs = t.obs
        if n > 1:
            marca = f"({k + 1}/{n})"
            obs = f"{t.obs} {marca}" if t.obs else marca
        cur = conn.execute(
            """INSERT INTO transactions
               (user_id, dt_compra, dt_venc, classificacao, valor, instituicao, pessoa,
                status, obs, grupo, tipo, categoria, subcategoria)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (user, t.dt_compra, venc, t.classificacao, valores[k], t.instituicao, t.pessoa,
             t.status, obs, cat["grupo"], cat["tipo"], cat["categoria"], cat["subcategoria"]),
        )
        if first_id is None:
            first_id = cur.lastrowid
    if t.pessoa:
        conn.execute("INSERT OR IGNORE INTO people (user_id, nome) VALUES (?, ?)", (user, t.pessoa))
    conn.commit()
    conn.close()
    return {"id": first_id, "parcelas": n}


@app.put("/api/transactions/{tx_id}")
def update_transaction(tx_id: int, t: TransactionIn, user: int = Depends(current_user)):
    conn = get_conn()
    cat = category_for(conn, user, t.classificacao)
    cur = conn.execute(
        """UPDATE transactions SET dt_compra=?, dt_venc=?, classificacao=?, valor=?,
           instituicao=?, pessoa=?, status=?, obs=?, grupo=?, tipo=?, categoria=?,
           subcategoria=? WHERE id=? AND user_id=?""",
        (t.dt_compra, t.dt_venc, t.classificacao, t.valor, t.instituicao, t.pessoa,
         t.status, t.obs, cat["grupo"], cat["tipo"], cat["categoria"],
         cat["subcategoria"], tx_id, user),
    )
    conn.commit()
    conn.close()
    if cur.rowcount == 0:
        raise HTTPException(404, "Lançamento não encontrado")
    return {"ok": True}


@app.post("/api/transactions/bulk-delete")
def bulk_delete_transactions(payload: BulkDelete, user: int = Depends(current_user)):
    if not payload.ids:
        return {"deleted": 0}
    conn = get_conn()
    marks = ",".join("?" * len(payload.ids))
    cur = conn.execute(
        f"DELETE FROM transactions WHERE user_id=? AND id IN ({marks})",
        [user, *payload.ids],
    )
    conn.commit()
    n = cur.rowcount
    conn.close()
    return {"deleted": n}


@app.post("/api/transactions/bulk-update")
def bulk_update_transactions(payload: BulkUpdate, user: int = Depends(current_user)):
    if payload.field not in ALLOWED_BULK_FIELDS:
        raise HTTPException(400, "Campo não permitido para alteração em lote")
    if not payload.ids:
        return {"updated": 0}
    conn = get_conn()
    marks = ",".join("?" * len(payload.ids))
    if payload.field == "classificacao":
        cat = category_for(conn, user, payload.value or "")
        cur = conn.execute(
            f"""UPDATE transactions SET classificacao=?, grupo=?, tipo=?, categoria=?,
               subcategoria=? WHERE user_id=? AND id IN ({marks})""",
            [payload.value, cat["grupo"], cat["tipo"], cat["categoria"],
             cat["subcategoria"], user, *payload.ids],
        )
    else:
        val = payload.value
        if payload.field in ("pessoa", "instituicao") and val == "":
            val = None
        if payload.field == "dt_venc" and not val:
            conn.close()
            raise HTTPException(400, "Data de vencimento não pode ficar vazia")
        cur = conn.execute(
            f"UPDATE transactions SET {payload.field}=? WHERE user_id=? AND id IN ({marks})",
            [val, user, *payload.ids],
        )
        if payload.field == "pessoa" and val:
            conn.execute("INSERT OR IGNORE INTO people (user_id, nome) VALUES (?, ?)", (user, val))
    conn.commit()
    n = cur.rowcount
    conn.close()
    return {"updated": n}


@app.delete("/api/transactions/{tx_id}")
def delete_transaction(tx_id: int, user: int = Depends(current_user)):
    conn = get_conn()
    cur = conn.execute("DELETE FROM transactions WHERE id=? AND user_id=?", (tx_id, user))
    conn.commit()
    conn.close()
    if cur.rowcount == 0:
        raise HTTPException(404, "Lançamento não encontrado")
    return {"ok": True}


# ---------- Contas por pessoa ----------
@app.get("/api/pessoas-balanco")
def pessoas_balanco(
    user: int = Depends(current_user),
    year: Optional[int] = None, month: Optional[int] = None, status: Optional[str] = None,
):
    conn = get_conn()
    where, params = tx_filters(user, year, month, status, None, None, None, None)
    data = rows(conn.execute(
        f"""SELECT COALESCE(pessoa, '(sem pessoa)') AS pessoa,
              COALESCE(SUM(CASE WHEN tipo='DESPESA' THEN valor END), 0) AS a_pagar,
              COALESCE(SUM(CASE WHEN tipo='RECEITA' THEN valor END), 0) AS a_receber,
              COUNT(*) AS lancamentos
            FROM transactions {where}
            GROUP BY pessoa
            ORDER BY a_pagar DESC, a_receber DESC""", params))
    for d in data:
        d["saldo"] = round(d["a_receber"] - d["a_pagar"], 2)
    totais = {
        "a_pagar": round(sum(d["a_pagar"] for d in data), 2),
        "a_receber": round(sum(d["a_receber"] for d in data), 2),
    }
    totais["saldo"] = round(totais["a_receber"] - totais["a_pagar"], 2)
    years = [r["y"] for r in conn.execute(
        "SELECT DISTINCT strftime('%Y', dt_venc) AS y FROM transactions WHERE user_id=? ORDER BY y",
        (user,)).fetchall()]
    conn.close()
    return {"itens": data, "totais": totais, "years": years}


# ---------- Importar planilha ----------
def _import_into(conn, uid, parsed, replace):
    if replace:
        conn.execute("DELETE FROM transactions WHERE user_id=?", (uid,))

    s = parsed.get("settings", {})
    for k in ("receita_mensal", "custo_vida_mensal", "fator_reserva"):
        v = s.get(k)
        if v:
            conn.execute(
                "INSERT OR REPLACE INTO settings (user_id, key, value) VALUES (?, ?, ?)",
                (uid, k, v))

    cat_map = {}
    novas_cats = 0
    for c in parsed.get("categories", []):
        cat_map[c["classificacao"]] = (
            c["grupo"], c["tipo"], c["categoria"], c.get("subcategoria"))
        cur = conn.execute(
            """INSERT OR IGNORE INTO categories
               (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
               VALUES (?, ?, ?, ?, ?, ?, ?)""",
            (uid, c["classificacao"], c["grupo"], c["tipo"], c["categoria"],
             c.get("subcategoria"), c.get("meta_mes", 0)))
        novas_cats += cur.rowcount

    people, institutions = set(), set()
    lancamentos = 0
    for t in parsed.get("transactions", []):
        classificacao = t["classificacao"]
        grupo = t.get("grupo") or (cat_map.get(classificacao) or ("OPERACIONAL",))[0] or "OPERACIONAL"
        tipo = t.get("tipo") or (cat_map.get(classificacao) or (None, "DESPESA"))[1] or "DESPESA"
        categoria = t.get("categoria") or (cat_map.get(classificacao) or (None, None, classificacao))[2] or classificacao
        sub = t.get("subcategoria") or (cat_map.get(classificacao) or (None, None, None, None))[3]
        # garante que exista uma categoria para o lançamento
        conn.execute(
            """INSERT OR IGNORE INTO categories
               (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
               VALUES (?, ?, ?, ?, ?, ?, 0)""",
            (uid, classificacao, grupo, tipo, categoria, sub))
        conn.execute(
            """INSERT INTO transactions
               (user_id, dt_compra, dt_venc, classificacao, valor, instituicao, pessoa,
                status, obs, grupo, tipo, categoria, subcategoria)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (uid, t.get("dt_compra"), t.get("dt_venc") or t.get("dt_compra"),
             classificacao, t["valor"], t.get("instituicao"), t.get("pessoa"),
             t.get("status", "Previsto"), t.get("obs"), grupo, tipo, categoria, sub))
        lancamentos += 1
        if t.get("pessoa"):
            people.add(t["pessoa"])
        if t.get("instituicao"):
            institutions.add(t["instituicao"])

    for p in sorted(people):
        conn.execute("INSERT OR IGNORE INTO people (user_id, nome) VALUES (?, ?)", (uid, p))
    for i in sorted(institutions):
        conn.execute(
            """INSERT INTO institutions (user_id, nome, tipo)
               SELECT ?, ?, ? WHERE NOT EXISTS
               (SELECT 1 FROM institutions WHERE user_id=? AND nome=?)""",
            (uid, i, "Cartão de Crédito" if i.lower() in ("credito", "crédito") else "Conta", uid, i))

    projetos = 0
    for p in parsed.get("projects", []):
        conn.execute(
            "INSERT INTO projects (user_id, descricao, valor, ano, prazo) VALUES (?, ?, ?, ?, ?)",
            (uid, p["descricao"], p["valor"], p.get("ano"), p.get("prazo")))
        projetos += 1

    pat = parsed.get("patrimonio", {})
    for inv in pat.get("investimentos", []):
        conn.execute(
            """INSERT INTO investments (user_id, instituicao, fixa_var, prazo_projeto, ativo, valor)
               VALUES (?, ?, ?, ?, ?, ?)""",
            (uid, inv["instituicao"], inv.get("fixa_var"), inv.get("prazo_projeto"),
             inv.get("ativo"), inv.get("valor", 0)))
    for b in pat.get("bens", []):
        conn.execute(
            "INSERT INTO assets (user_id, descricao, valor, saldo_devedor) VALUES (?, ?, ?, ?)",
            (uid, b["descricao"], b.get("valor", 0), b.get("saldo_devedor", 0)))
    for dv in pat.get("dividas", []):
        conn.execute(
            """INSERT INTO debts (user_id, descricao, num_parcelas, valor_parcela, saldo_devedor)
               VALUES (?, ?, ?, ?, ?)""",
            (uid, dv["descricao"], dv.get("num_parcelas", 0), dv.get("valor_parcela", 0),
             dv.get("saldo_devedor", 0)))

    return {
        "lancamentos": lancamentos,
        "categorias_novas": novas_cats,
        "projetos": projetos,
        "investimentos": len(pat.get("investimentos", [])),
        "bens": len(pat.get("bens", [])),
        "dividas": len(pat.get("dividas", [])),
    }


@app.post("/api/import")
async def import_planilha(
    file: UploadFile = File(...),
    replace: bool = Form(False),
    user: int = Depends(current_user),
):
    data = await file.read()
    if not data:
        raise HTTPException(400, "Arquivo vazio.")
    try:
        parsed = importer.parse(data)
    except ValueError as e:
        raise HTTPException(400, str(e))
    conn = get_conn()
    summary = _import_into(conn, user, parsed, replace)
    conn.commit()
    conn.close()
    return summary


# ---------- Dashboard ----------
@app.get("/api/dashboard")
def dashboard(
    user: int = Depends(current_user),
    year: Optional[int] = None, month: Optional[int] = None, status: Optional[str] = None,
):
    conn = get_conn()
    where, params = tx_filters(user, year, month, status, None, None, None, None)

    totals = conn.execute(
        f"""SELECT
              COALESCE(SUM(CASE WHEN tipo='RECEITA' THEN valor END), 0) AS receitas,
              COALESCE(SUM(CASE WHEN tipo='DESPESA' THEN valor END), 0) AS despesas,
              COALESCE(SUM({SIGN}), 0) AS resultado,
              COUNT(*) AS lancamentos
            FROM transactions {where}""", params).fetchone()

    by_categoria = rows(conn.execute(
        f"""SELECT categoria, SUM(valor) AS total FROM transactions
            {where} AND tipo='DESPESA'
            GROUP BY categoria ORDER BY total DESC""", params))

    by_subcategoria = rows(conn.execute(
        f"""SELECT categoria, subcategoria, SUM(valor) AS total FROM transactions
            {where} AND tipo='DESPESA'
            GROUP BY categoria, subcategoria ORDER BY total DESC LIMIT 12""", params))

    by_pessoa = rows(conn.execute(
        f"""SELECT COALESCE(pessoa,'(sem pessoa)') AS pessoa, SUM(valor) AS total
            FROM transactions {where} AND tipo='DESPESA'
            GROUP BY pessoa ORDER BY total DESC""", params))

    by_grupo = rows(conn.execute(
        f"""SELECT grupo, SUM(valor) AS total FROM transactions
            {where} AND tipo='DESPESA'
            GROUP BY grupo ORDER BY total DESC""", params))

    mwhere, mparams = tx_filters(user, year, None, status, None, None, None, None)
    monthly = rows(conn.execute(
        f"""SELECT strftime('%Y-%m', dt_venc) AS mes,
              COALESCE(SUM(CASE WHEN tipo='RECEITA' THEN valor END), 0) AS receitas,
              COALESCE(SUM(CASE WHEN tipo='DESPESA' THEN valor END), 0) AS despesas,
              COALESCE(SUM({SIGN}), 0) AS resultado
            FROM transactions {mwhere}
            GROUP BY mes ORDER BY mes""", mparams))
    acumulado = 0
    for m in monthly:
        acumulado += m["resultado"]
        m["acumulado"] = round(acumulado, 2)

    years = [r["y"] for r in conn.execute(
        "SELECT DISTINCT strftime('%Y', dt_venc) AS y FROM transactions WHERE user_id=? ORDER BY y",
        (user,)).fetchall()]
    conn.close()
    return {
        "totals": dict(totals),
        "by_categoria": by_categoria,
        "by_subcategoria": by_subcategoria,
        "by_pessoa": by_pessoa,
        "by_grupo": by_grupo,
        "monthly": monthly,
        "years": years,
    }


# ---------- Fluxo (pivot mensal) ----------
@app.get("/api/fluxo")
def fluxo(user: int = Depends(current_user), status: Optional[str] = None, grupo: Optional[str] = None):
    conn = get_conn()
    where, params = tx_filters(user, None, None, status, None, None, None, grupo)
    data = rows(conn.execute(
        f"""SELECT strftime('%Y-%m', dt_venc) AS mes, grupo, tipo, categoria,
              subcategoria, SUM({SIGN}) AS saldo
            FROM transactions {where}
            GROUP BY mes, grupo, tipo, categoria, subcategoria ORDER BY mes""", params))
    conn.close()
    return {"items": data}


# ---------- CRUD genérico das demais abas ----------
def make_crud(table: str, model, order: str = "id"):
    def list_items(user: int = Depends(current_user)):
        conn = get_conn()
        data = rows(conn.execute(f"SELECT * FROM {table} WHERE user_id=? ORDER BY {order}", (user,)))
        conn.close()
        return {"items": data}

    def create_item(item: model, user: int = Depends(current_user)):  # type: ignore[valid-type]
        d = item.dict()
        cols = "user_id, " + ", ".join(d)
        marks = ", ".join("?" * (len(d) + 1))
        conn = get_conn()
        cur = conn.execute(f"INSERT INTO {table} ({cols}) VALUES ({marks})", [user, *d.values()])
        conn.commit()
        new_id = cur.lastrowid
        conn.close()
        return {"id": new_id}

    def update_item(item_id: int, item: model, user: int = Depends(current_user)):  # type: ignore[valid-type]
        d = item.dict()
        sets = ", ".join(f"{k}=?" for k in d)
        conn = get_conn()
        cur = conn.execute(
            f"UPDATE {table} SET {sets} WHERE id=? AND user_id=?",
            [*d.values(), item_id, user],
        )
        conn.commit()
        conn.close()
        if cur.rowcount == 0:
            raise HTTPException(404, "Registro não encontrado")
        return {"ok": True}

    def delete_item(item_id: int, user: int = Depends(current_user)):
        conn = get_conn()
        cur = conn.execute(f"DELETE FROM {table} WHERE id=? AND user_id=?", (item_id, user))
        conn.commit()
        conn.close()
        if cur.rowcount == 0:
            raise HTTPException(404, "Registro não encontrado")
        return {"ok": True}

    return list_items, create_item, update_item, delete_item


for path, table, model, order in [
    ("projects", "projects", ProjectIn, "ano, descricao"),
    ("investments", "investments", InvestmentIn, "instituicao"),
    ("assets", "assets", AssetIn, "descricao"),
    ("debts", "debts", DebtIn, "descricao"),
    ("institutions", "institutions", InstitutionIn, "nome"),
    ("people", "people", PersonIn, "nome"),
    ("ofx", "ofx_imports", OfxIn, "id DESC"),
    ("card-payments", "card_payments", CardPaymentIn, "id DESC"),
]:
    ls, cr, up, dl = make_crud(table, model, order)
    app.get(f"/api/{path}")(ls)
    app.post(f"/api/{path}")(cr)
    app.put(f"/api/{path}/{{item_id}}")(up)
    app.delete(f"/api/{path}/{{item_id}}")(dl)


# ---------- Categories ----------
@app.get("/api/categories")
def list_categories(user: int = Depends(current_user)):
    conn = get_conn()
    data = rows(conn.execute(
        "SELECT * FROM categories WHERE user_id=? ORDER BY tipo, categoria, subcategoria", (user,)))
    conn.close()
    return {"items": data}


@app.post("/api/categories")
def create_category(c: CategoryIn, user: int = Depends(current_user)):
    classificacao = f"{c.categoria.upper()} - {c.subcategoria or c.categoria}"
    conn = get_conn()
    try:
        cur = conn.execute(
            """INSERT INTO categories (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
               VALUES (?, ?, ?, ?, ?, ?, ?)""",
            (user, classificacao, c.grupo, c.tipo, c.categoria, c.subcategoria, c.meta_mes),
        )
    except Exception:
        conn.close()
        raise HTTPException(400, "Classificação já existe")
    conn.commit()
    new_id = cur.lastrowid
    conn.close()
    return {"id": new_id, "classificacao": classificacao}


@app.put("/api/categories/{cat_id}")
def update_category(cat_id: int, c: CategoryIn, user: int = Depends(current_user)):
    conn = get_conn()
    old = conn.execute("SELECT * FROM categories WHERE id=? AND user_id=?", (cat_id, user)).fetchone()
    if not old:
        conn.close()
        raise HTTPException(404, "Categoria não encontrada")
    classificacao = f"{c.categoria.upper()} - {c.subcategoria or c.categoria}"
    conn.execute(
        """UPDATE categories SET classificacao=?, grupo=?, tipo=?, categoria=?,
           subcategoria=?, meta_mes=? WHERE id=? AND user_id=?""",
        (classificacao, c.grupo, c.tipo, c.categoria, c.subcategoria, c.meta_mes, cat_id, user),
    )
    conn.execute(
        """UPDATE transactions SET classificacao=?, grupo=?, tipo=?, categoria=?, subcategoria=?
           WHERE classificacao=? AND user_id=?""",
        (classificacao, c.grupo, c.tipo, c.categoria, c.subcategoria, old["classificacao"], user),
    )
    conn.commit()
    conn.close()
    return {"ok": True}


@app.delete("/api/categories/{cat_id}")
def delete_category(cat_id: int, user: int = Depends(current_user)):
    conn = get_conn()
    cat = conn.execute("SELECT * FROM categories WHERE id=? AND user_id=?", (cat_id, user)).fetchone()
    if not cat:
        conn.close()
        raise HTTPException(404, "Categoria não encontrada")
    used = conn.execute(
        "SELECT COUNT(*) AS n FROM transactions WHERE classificacao=? AND user_id=?",
        (cat["classificacao"], user),
    ).fetchone()["n"]
    if used:
        conn.close()
        raise HTTPException(400, f"Categoria em uso por {used} lançamento(s)")
    conn.execute("DELETE FROM categories WHERE id=? AND user_id=?", (cat_id, user))
    conn.commit()
    conn.close()
    return {"ok": True}


# ---------- Settings ----------
@app.get("/api/settings")
def get_settings(user: int = Depends(current_user)):
    conn = get_conn()
    data = {r["key"]: r["value"] for r in
            conn.execute("SELECT key, value FROM settings WHERE user_id=?", (user,)).fetchall()}
    conn.close()
    return data


@app.put("/api/settings")
def put_settings(s: SettingsIn, user: int = Depends(current_user)):
    conn = get_conn()
    for k, v in s.dict().items():
        conn.execute(
            "INSERT OR REPLACE INTO settings (user_id, key, value) VALUES (?, ?, ?)", (user, k, v))
    conn.commit()
    conn.close()
    return {"ok": True}


# ---------- Projetos: resumo de reservas ----------
@app.get("/api/projects/summary")
def projects_summary(user: int = Depends(current_user)):
    conn = get_conn()
    settings = {r["key"]: r["value"] for r in
                conn.execute("SELECT key, value FROM settings WHERE user_id=?", (user,)).fetchall()}
    fator = settings.get("fator_reserva", 6)
    custo = settings.get("custo_vida_mensal", 0)
    projetos = rows(conn.execute("SELECT * FROM projects WHERE user_id=? ORDER BY ano", (user,)))
    aplicado = conn.execute(
        "SELECT COALESCE(SUM(valor),0) AS v FROM investments WHERE user_id=?", (user,)).fetchone()["v"]
    conn.close()
    buckets = {"RESERVA": custo * fator, "CURTO": 0.0, "MÉDIO": 0.0, "LONGO": 0.0}
    for p in projetos:
        prazo = (p.get("prazo") or "").upper()
        key = "CURTO" if "CURTO" in prazo else "MÉDIO" if "MÉD" in prazo else "LONGO"
        buckets[key] += p["valor"]
    return {
        "fator_reserva": fator,
        "custo_vida_mensal": custo,
        "necessidade": buckets,
        "aplicado_total": aplicado,
        "projetos": projetos,
    }


# ---------- Static ----------
@app.get("/")
def index():
    return FileResponse(STATIC / "index.html")


app.mount("/static", StaticFiles(directory=STATIC), name="static")
