/* Minhas Contas — SPA */
"use strict";

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];

const fmt = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
const money = (v) => fmt.format(v || 0);
const MESES = ["Jan", "Fev", "Mar", "Abr", "Mai", "Jun", "Jul", "Ago", "Set", "Out", "Nov", "Dez"];
const mesLabel = (ym) => {
  const [y, m] = ym.split("-");
  return `${MESES[+m - 1]}/${y.slice(2)}`;
};
const dateBR = (iso) => (iso ? iso.split("-").reverse().join("/") : "");

/* Escapa texto vindo do usuário antes de ir para innerHTML. */
const esc = (s) => String(s ?? "").replace(/[&<>"']/g,
  (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

/* "2026-07-09 14:33:02" (UTC, vindo do banco) -> "09/07/2026" */
const dataHoraBR = (s) => (s ? dateBR(s.split(" ")[0]) : "–");

/* O servidor manda o token CSRF num cookie legível (o de sessão continua
   HttpOnly). Reapresentá-lo num header é o que prova que a requisição partiu
   desta página: um site de terceiros não consegue ler o cookie nem definir
   headers numa requisição cross-origin. */
const csrfToken = () =>
  document.cookie.split("; ").find((c) => c.startsWith("csrf="))?.slice(5) ?? "";

/* Cabeçalhos de toda chamada à API. */
const cabecalhos = (extra = {}) => ({
  "Content-Type": "application/json",
  "X-CSRF-Token": csrfToken(),
  ...extra,
});

async function api(path, opts = {}) {
  const res = await fetch(path, {
    ...opts,
    headers: cabecalhos(opts.headers),
  });
  if (res.status === 401) {
    showAuth();
    throw new Error("Não autenticado");
  }
  if (!res.ok) {
    let msg = res.statusText;
    try { msg = (await res.json()).detail || msg; } catch {}
    alert("Erro: " + msg);
    throw new Error(msg);
  }
  return res.json();
}

function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// Devolve a string de um icone do sprite, para concatenar nos template
// literals que montam tabelas via innerHTML. Decorativo: quem precisa de
// rotulo acessivel poe aria-label no <button> que o envolve.
const ico = (nome) =>
  `<svg class="ico" aria-hidden="true"><use href="#i-${nome}"/></svg>`;

/* (Re)constrói o seletor de anos preservando a seleção atual. */
function fillYears(sel, years) {
  const atual = sel.value;
  sel.innerHTML = "";
  sel.append(new Option("Todos", ""));
  (years || []).forEach((y) => sel.append(new Option(y, y)));
  sel.value = (years || []).includes(atual) ? atual : "";
}

/* Preenche meses uma única vez (não mudam). */
function ensureMonths(sel) {
  if (sel.options.length) return;
  sel.append(new Option("Todos", ""));
  MESES.forEach((m, i) => sel.append(new Option(m, i + 1)));
}

/* A barra superior é sticky e o cabeçalho da tabela gruda embaixo dela.
   Mede em vez de fixar no CSS: a altura muda com o zoom e com a fonte. */
function ajustarTopbar() {
  const h = $(".topbar")?.offsetHeight;
  if (h) document.documentElement.style.setProperty("--topbar-h", `${h}px`);
}
window.addEventListener("resize", ajustarTopbar);

/* ---------- Menu lateral (gaveta no mobile) ---------- */
function fecharMenu() {
  $("#sidebar").classList.remove("open");
  $("#sidebar-backdrop").hidden = true;
  $("#menu-toggle").setAttribute("aria-expanded", "false");
}
function abrirMenu() {
  $("#sidebar").classList.add("open");
  $("#sidebar-backdrop").hidden = false;
  $("#menu-toggle").setAttribute("aria-expanded", "true");
}
$("#menu-toggle").addEventListener("click", () => {
  $("#sidebar").classList.contains("open") ? fecharMenu() : abrirMenu();
});
$("#sidebar-backdrop").addEventListener("click", fecharMenu);

/* ---------- Tabs ---------- */
const loaders = {};
$$("#tabs button").forEach((btn) => {
  btn.addEventListener("click", () => {
    $$("#tabs button").forEach((b) => b.classList.toggle("active", b === btn));
    $$(".tab").forEach((t) => t.classList.remove("active"));
    $("#tab-" + btn.dataset.tab).classList.add("active");
    loaders[btn.dataset.tab]?.();
    fecharMenu();
  });
});

/* ---------- Combo com busca ----------
   Digitar "feira" acha "ALIMENTAÇÃO - Feira"; "ali feira" também. Ignora
   acentos e maiúsculas, e só aceita valores que existam na lista. */
const semAcento = (s) =>
  String(s ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

const LIMITE_SUGESTOES = 60;

function filtrarOpcoes(opcoes, termo) {
  const partes = semAcento(termo).split(/\s+/).filter(Boolean);
  const casa = partes.length
    ? opcoes.filter((o) => { const n = semAcento(o); return partes.every((p) => n.includes(p)); })
    : opcoes;
  return casa.slice(0, LIMITE_SUGESTOES);
}

function criarCombo(nome, opcoes, valorInicial = "") {
  const wrap = document.createElement("div");
  wrap.className = "combo";
  const input = document.createElement("input");
  Object.assign(input, { type: "text", name: nome, autocomplete: "off", value: valorInicial || "" });
  input.placeholder = "Digite para buscar…";
  const lista = document.createElement("ul");
  lista.className = "combo-list";
  lista.hidden = true;
  wrap.append(input, lista);

  let marcado = -1;
  let visiveis = [];
  let ultimoValido = opcoes.includes(input.value) ? input.value : "";

  function desenhar() {
    visiveis = filtrarOpcoes(opcoes, input.value);
    lista.innerHTML = visiveis
      .map((o, i) => `<li data-i="${i}"${i === marcado ? ' class="marcado"' : ""}>${esc(o)}</li>`)
      .join("");
    lista.hidden = !visiveis.length;
  }

  function escolher(valor) {
    input.value = valor;
    ultimoValido = valor;
    lista.hidden = true;
    marcado = -1;
  }

  input.addEventListener("focus", () => { marcado = -1; desenhar(); });
  input.addEventListener("input", () => { marcado = -1; desenhar(); });

  input.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      if (lista.hidden) { desenhar(); return; }
      marcado = (marcado + (e.key === "ArrowDown" ? 1 : -1) + visiveis.length) % visiveis.length;
      desenhar();
      lista.querySelector(".marcado")?.scrollIntoView({ block: "nearest" });
    } else if (e.key === "Enter" && !lista.hidden && visiveis.length) {
      e.preventDefault();   // Enter escolhe; só depois o Enter envia o formulário
      escolher(visiveis[marcado >= 0 ? marcado : 0]);
    } else if (e.key === "Escape") {
      lista.hidden = true;
    }
  });

  // mousedown (e não click) porque o blur do input dispara antes do click
  lista.addEventListener("mousedown", (e) => {
    const li = e.target.closest("li");
    if (!li) return;
    e.preventDefault();
    escolher(visiveis[li.dataset.i]);
  });

  /* Sair do campo com texto solto não pode virar classificação inválida:
     aceita se for exatamente uma opção, senão volta ao último valor bom. */
  input.addEventListener("blur", () => {
    lista.hidden = true;
    const exato = opcoes.find((o) => semAcento(o) === semAcento(input.value));
    if (exato) { input.value = exato; ultimoValido = exato; }
    else input.value = ultimoValido;
  });

  return { wrap, input };
}

/* ---------- Modal genérico ---------- */
const modal = $("#modal");
let modalSubmit = null;
$("#modal-cancel").addEventListener("click", () => modal.close());
$("#modal-form").addEventListener("submit", (e) => {
  e.preventDefault();
  modalSubmit?.();
});

function openForm(title, fields, values, onSave) {
  $("#modal-title").textContent = title;
  const box = $("#modal-fields");
  box.innerHTML = "";
  for (const f of fields) {
    const label = document.createElement("label");
    if (f.full) label.className = "full";
    label.append(f.label);
    let input;
    if (f.type === "combo") {
      const combo = criarCombo(f.name, f.options, values?.[f.name]);
      if (f.required) combo.input.required = true;
      label.append(combo.wrap);
      box.append(label);
      continue;
    }
    if (f.type === "select") {
      input = document.createElement("select");
      for (const opt of f.options) {
        const o = document.createElement("option");
        if (Array.isArray(opt)) { o.value = opt[0]; o.textContent = opt[1]; }
        else { o.value = o.textContent = opt; }
        input.append(o);
      }
    } else if (f.type === "textarea") {
      input = document.createElement("textarea");
      input.rows = 2;
    } else {
      input = document.createElement("input");
      input.type = f.type || "text";
      if (f.type === "number") input.step = f.step || "0.01";
    }
    input.name = f.name;
    if (f.required) input.required = true;
    if (f.list) input.setAttribute("list", f.list);
    const v = values?.[f.name];
    if (v !== undefined && v !== null) input.value = v;
    label.append(input);
    box.append(label);
  }
  modalSubmit = () => {
    const data = {};
    for (const f of fields) {
      const el = box.querySelector(`[name="${f.name}"]`);
      let v = el.value;
      // Enter com a lista vazia enviaria texto solto; o combo só aceita opção da lista.
      if (f.type === "combo" && v && !f.options.includes(v)) {
        alert(`Escolha uma ${f.label.toLowerCase()} da lista.`);
        el.focus();
        return;
      }
      if (f.type === "number") v = v === "" ? 0 : parseFloat(v);
      if (v === "") v = null;
      data[f.name] = v;
    }
    onSave(data).then(() => modal.close());
  };
  modal.showModal();
}

/* ---------- Dashboard ---------- */
const charts = {};
function renderChart(id, config) {
  charts[id]?.destroy();
  charts[id] = new Chart($(id), config);
}

function baseOpts(extra = {}) {
  const ink = cssVar("--text-secondary");
  const grid = cssVar("--grid");
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { labels: { color: ink, boxWidth: 12, boxHeight: 12 } },
      tooltip: {
        callbacks: {
          label: (c) => {
            const p = c.parsed;
            const v = (p && typeof p === "object")
              ? (c.chart.options.indexAxis === "y" ? p.x : p.y)
              : p;
            return `${c.dataset.label || c.label}: ${money(v)}`;
          },
        },
      },
      ...extra.plugins,
    },
    scales: extra.noScales ? undefined : {
      x: { ticks: { color: ink }, grid: { color: "transparent" }, ...extra.x },
      y: { ticks: { color: ink, callback: (v) => money(v) }, grid: { color: grid }, ...extra.y },
    },
  };
}

async function loadDashboard() {
  const year = $("#f-year").value;
  const month = $("#f-month").value;
  const status = $("#f-status").value;
  const qs = new URLSearchParams();
  if (year) qs.set("year", year);
  if (month) qs.set("month", month);
  if (status && status !== "Todos") qs.set("status", status);
  const d = await api("/api/dashboard?" + qs);

  // reconstrói anos a cada carga (preserva seleção); auto-seleciona o ano atual na 1ª vez
  const ySel = $("#f-year");
  const primeiraVez = !ySel.options.length;
  fillYears(ySel, d.years);
  if (primeiraVez) {
    const current = String(new Date().getFullYear());
    if (d.years.includes(current)) {
      ySel.value = current;
      return loadDashboard();
    }
  }

  const t = d.totals;
  $("#t-receitas").textContent = money(t.receitas);
  $("#t-receitas").className = "tile-value pos";
  $("#t-despesas").textContent = money(t.despesas);
  $("#t-despesas").className = "tile-value neg";
  $("#t-resultado").textContent = money(t.resultado);
  $("#t-resultado").className = "tile-value " + (t.resultado >= 0 ? "pos" : "neg");
  $("#t-lancamentos").textContent = t.lancamentos;

  const labels = d.monthly.map((m) => mesLabel(m.mes));
  const receita = cssVar("--series-receita");
  const despesa = cssVar("--series-despesa");
  const blue = cssVar("--seq-450");
  const blueLight = cssVar("--seq-300");

  renderChart("#c-monthly", {
    type: "bar",
    data: {
      labels,
      datasets: [
        { label: "Receitas", data: d.monthly.map((m) => m.receitas), backgroundColor: receita, borderRadius: 4, maxBarThickness: 26 },
        { label: "Despesas", data: d.monthly.map((m) => m.despesas), backgroundColor: despesa, borderRadius: 4, maxBarThickness: 26 },
      ],
    },
    options: baseOpts(),
  });

  renderChart("#c-saldo", {
    type: "line",
    data: {
      labels,
      datasets: [{
        label: "Saldo acumulado",
        data: d.monthly.map((m) => m.acumulado),
        borderColor: blue,
        backgroundColor: blue + "22",
        fill: true,
        borderWidth: 2,
        pointRadius: 3,
        tension: 0.25,
      }],
    },
    options: baseOpts(),
  });

  const hbar = (items, labelKey, color) => ({
    type: "bar",
    data: {
      labels: items.map((i) => i[labelKey]),
      datasets: [{ label: "Total", data: items.map((i) => i.total), backgroundColor: color, borderRadius: 4, maxBarThickness: 18 }],
    },
    options: {
      ...baseOpts({ plugins: { legend: { display: false } } }),
      indexAxis: "y",
      scales: {
        x: { ticks: { color: cssVar("--text-secondary"), callback: (v) => money(v) }, grid: { color: cssVar("--grid") } },
        y: { ticks: { color: cssVar("--text-secondary") }, grid: { color: "transparent" } },
      },
    },
  });

  renderChart("#c-categoria", hbar(d.by_categoria, "categoria", blue));
  renderChart("#c-subcategoria", hbar(
    d.by_subcategoria.map((s) => ({ ...s, label: s.subcategoria || s.categoria })),
    "label", blueLight,
  ));

  renderChart("#c-pessoa", {
    type: "bar",
    data: {
      labels: d.by_pessoa.map((p) => p.pessoa),
      datasets: [{ label: "Despesas", data: d.by_pessoa.map((p) => p.total), backgroundColor: blue, borderRadius: 4, maxBarThickness: 40 }],
    },
    options: baseOpts({ plugins: { legend: { display: false } } }),
  });

  renderChart("#c-grupo", {
    type: "doughnut",
    data: {
      labels: d.by_grupo.map((g) => g.grupo),
      datasets: [{
        data: d.by_grupo.map((g) => g.total),
        backgroundColor: [blue, cssVar("--accent")],
        borderColor: cssVar("--surface-1"),
        borderWidth: 2,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: "bottom", labels: { color: cssVar("--text-secondary"), boxWidth: 12 } },
        tooltip: { callbacks: { label: (c) => `${c.label}: ${money(c.parsed)}` } },
      },
    },
  });
}
["#f-year", "#f-month", "#f-status"].forEach((s) =>
  $(s).addEventListener("change", loadDashboard));
loaders.dashboard = loadDashboard;

/* ---------- Lançamentos ---------- */
let cachedCategories = [];
let cachedInstitutions = [];
let cachedPeople = [];

async function refreshLookups() {
  const [cats, insts, people] = await Promise.all([
    api("/api/categories"), api("/api/institutions"), api("/api/people"),
  ]);
  cachedCategories = cats.items;
  cachedInstitutions = insts.items;
  cachedPeople = people.items;
}

function txFields(isNew) {
  const fields = [
    { name: "classificacao", label: "Classificação", type: "combo", required: true, full: true,
      options: cachedCategories.map((c) => c.classificacao) },
    { name: "valor", label: "Valor (R$)", type: "number", required: true },
  ];
  if (isNew) {
    fields.push(
      { name: "parcelas", label: "Nº de parcelas", type: "number", step: "1" },
      { name: "valor_tipo", label: "O valor informado é", type: "select",
        options: [["parcela", "De cada parcela"], ["total", "Total (dividir entre as parcelas)"]] },
    );
  }
  fields.push(
    { name: "status", label: "Status", type: "select", options: ["Previsto", "Realizado"] },
    { name: "dt_compra", label: "Data compra", type: "date" },
    { name: "dt_venc", label: isNew ? "Vencimento (1ª parcela)" : "Data vencimento", type: "date", required: true },
    { name: "instituicao", label: "Instituição", type: "select",
      options: ["", ...new Set(cachedInstitutions.map((i) => i.nome))] },
    { name: "pessoa", label: "Pessoa", list: "dl-people" },
    { name: "obs", label: "Observação", type: "textarea", full: true },
  );
  return fields;
}

function ensurePeopleDatalist() {
  let dl = $("#dl-people");
  if (!dl) {
    dl = document.createElement("datalist");
    dl.id = "dl-people";
    document.body.append(dl);
  }
  dl.innerHTML = cachedPeople.map((p) => `<option value="${esc(p.nome)}">`).join("");
}

/* ---------- Lançamentos: filtros por coluna + ordenação ---------- */
const VAZIO = "(vazio)";

/* Ordem igual à do <thead>, deslocada de 1 por causa da coluna de seleção. */
const COLS_TX = [
  { chave: "dt_venc", titulo: "Venc.", filtro: true },
  { chave: "classificacao", titulo: "Classificação", filtro: true },
  { chave: "obs", titulo: "Obs", filtro: true, classe: "obs" },
  { chave: "pessoa", titulo: "Pessoa", filtro: true },
  { chave: "instituicao", titulo: "Instituição", filtro: true },
  { chave: "status", titulo: "Status", filtro: true },
  { chave: "saldo", titulo: "Valor", num: true },
];

let txItens = [];                       // tudo que veio do servidor
let txTotal = 0;
const txFiltros = {};                   // chave -> Set de valores marcados
let txOrdem = { chave: "dt_venc", asc: false };
let cabecalhoPronto = false;

/* Texto usado tanto para exibir quanto para agrupar no filtro. */
function textoCol(tx, col) {
  if (col.chave === "dt_venc") return dateBR(tx.dt_venc);
  if (col.num) return money(tx[col.chave]);
  const v = tx[col.chave];
  return v === null || v === undefined || v === "" ? VAZIO : String(v);
}

function compararTx(a, b) {
  const { chave, asc } = txOrdem;
  let x = a[chave], y = b[chave];
  if (chave === "saldo") { x = +x; y = +y; }
  else { x = String(x ?? "").toLowerCase(); y = String(y ?? "").toLowerCase(); }
  if (x < y) return asc ? -1 : 1;
  if (x > y) return asc ? 1 : -1;
  return b.id - a.id;
}

/* Um Set ausente = coluna sem filtro. Set vazio = usuário desmarcou tudo. */
function txVisiveis() {
  return txItens
    .filter((tx) => COLS_TX.every((col) => {
      const sel = txFiltros[col.chave];
      return !sel || sel.has(textoCol(tx, col));
    }))
    .sort(compararTx);
}

function decorarCabecalho() {
  if (cabecalhoPronto) return;
  const ths = $$("#l-table thead th");
  COLS_TX.forEach((col, i) => {
    const th = ths[i + 1];
    th.textContent = "";
    const inner = document.createElement("div");
    inner.className = "th-inner";
    const titulo = document.createElement("span");
    titulo.className = "th-sort";
    titulo.textContent = col.titulo;
    titulo.addEventListener("click", () => {
      txOrdem = { chave: col.chave, asc: txOrdem.chave === col.chave ? !txOrdem.asc : true };
      renderTx();
    });
    const seta = document.createElement("span");
    seta.className = "th-arrow";
    inner.append(titulo, seta);
    if (col.filtro) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "th-filter";
      btn.textContent = "▾";
      btn.title = `Filtrar ${col.titulo}`;
      btn.addEventListener("click", (e) => { e.stopPropagation(); abrirFiltro(col, btn); });
      inner.append(btn);
    }
    th.append(inner);
    col._seta = seta;
    col._btn = inner.querySelector(".th-filter");   // null nas colunas sem filtro
  });
  cabecalhoPronto = true;
}

let popFiltro = null;
function fecharFiltro() { popFiltro?.remove(); popFiltro = null; }
document.addEventListener("click", fecharFiltro);

function abrirFiltro(col, btn) {
  fecharFiltro();
  const valores = [...new Set(txItens.map((tx) => textoCol(tx, col)))]
    .sort((a, b) => a.localeCompare(b, "pt-BR"));
  const sel = new Set(txFiltros[col.chave] ?? valores);

  const pop = document.createElement("div");
  pop.className = "filtro-pop";
  pop.addEventListener("click", (e) => e.stopPropagation());
  pop.innerHTML = `
    <input type="search" class="filtro-busca" placeholder="Buscar…">
    <label class="filtro-todos"><input type="checkbox"> (Selecionar todos)</label>
    <div class="filtro-itens"></div>
    <div class="filtro-acoes">
      <button type="button" class="btn small" data-limpar>Limpar filtro</button>
      <button type="button" class="btn small primary" data-ok>Aplicar</button>
    </div>`;

  const caixaTodos = pop.querySelector(".filtro-todos input");
  const itens = pop.querySelector(".filtro-itens");
  const busca = pop.querySelector(".filtro-busca");

  const sincronizarTodos = () => { caixaTodos.checked = sel.size === valores.length; };
  const desenhar = () => {
    const termo = semAcento(busca.value);
    itens.innerHTML = "";
    for (const v of valores.filter((x) => semAcento(x).includes(termo))) {
      const l = document.createElement("label");
      const c = document.createElement("input");
      c.type = "checkbox";
      c.checked = sel.has(v);
      c.addEventListener("change", () => {
        c.checked ? sel.add(v) : sel.delete(v);
        sincronizarTodos();
      });
      l.append(c, document.createTextNode(" " + v));
      itens.append(l);
    }
    sincronizarTodos();
  };
  desenhar();

  busca.addEventListener("input", desenhar);
  caixaTodos.addEventListener("change", () => {
    if (caixaTodos.checked) valores.forEach((v) => sel.add(v));
    else sel.clear();
    desenhar();
  });
  pop.querySelector("[data-limpar]").addEventListener("click", () => {
    delete txFiltros[col.chave];
    fecharFiltro();
    renderTx();
  });
  pop.querySelector("[data-ok]").addEventListener("click", () => {
    // marcar tudo equivale a não filtrar
    if (sel.size === valores.length) delete txFiltros[col.chave];
    else txFiltros[col.chave] = sel;
    fecharFiltro();
    renderTx();
  });

  document.body.append(pop);
  const r = btn.getBoundingClientRect();
  const maxEsq = window.scrollX + document.documentElement.clientWidth - pop.offsetWidth - 12;
  pop.style.top = `${r.bottom + window.scrollY + 6}px`;
  pop.style.left = `${Math.max(8, Math.min(r.left + window.scrollX, maxEsq))}px`;
  popFiltro = pop;
  busca.focus();
}

function renderTx() {
  const visiveis = txVisiveis();

  for (const col of COLS_TX) {
    col._seta.textContent = txOrdem.chave === col.chave ? (txOrdem.asc ? "▲" : "▼") : "";
    col._btn?.classList.toggle("ativo", !!txFiltros[col.chave]);
  }
  const temFiltro = Object.keys(txFiltros).length > 0;
  $("#l-clearfilters").hidden = !temFiltro;

  const parcial = txItens.length < txTotal;
  $("#l-count").textContent =
    `${visiveis.length} de ${txItens.length} lançamentos` +
    (temFiltro ? " (filtrado)" : "") +
    (parcial ? ` · ${txTotal} no total, filtros valem sobre os carregados` : "");

  const tbody = $("#l-table tbody");
  tbody.innerHTML = "";
  for (const tx of visiveis) {
    const tr = document.createElement("tr");
    const cls = tx.saldo >= 0 ? "pos" : "neg";
    tr.innerHTML = `
      <td class="chk"><input type="checkbox" class="rowchk" data-id="${tx.id}"></td>
      <td>${dateBR(tx.dt_venc)}</td>
      <td>${esc(tx.classificacao)}</td>
      <td class="obs">${esc(tx.obs || "")}</td>
      <td>${esc(tx.pessoa || "")}</td>
      <td>${esc(tx.instituicao || "")}</td>
      <td>${esc(tx.status)}</td>
      <td class="num ${cls}">${money(tx.saldo)}</td>
      <td class="row-actions">
        <button class="btn small" data-edit>✏️</button>
        <button class="btn small danger" data-del>🗑</button>
      </td>`;
    tr.querySelector(".rowchk").addEventListener("change", updateBulkBar);
    tr.querySelector("[data-edit]").addEventListener("click", () =>
      openForm("Editar lançamento", txFields(false), tx, async (data) => {
        await api(`/api/transactions/${tx.id}`, { method: "PUT", body: JSON.stringify(data) });
        loadLancamentos();
      }));
    tr.querySelector("[data-del]").addEventListener("click", async () => {
      if (confirm(`Excluir "${tx.classificacao}" de ${money(tx.valor)}?`)) {
        await api(`/api/transactions/${tx.id}`, { method: "DELETE" });
        loadLancamentos();
      }
    });
    tbody.append(tr);
  }
  $("#l-selall").checked = false;
  $("#l-selall").indeterminate = false;
  updateBulkBar();
}

$("#l-clearfilters").addEventListener("click", () => {
  Object.keys(txFiltros).forEach((k) => delete txFiltros[k]);
  renderTx();
});

async function loadLancamentos() {
  await refreshLookups();
  ensurePeopleDatalist();
  const ySel = $("#l-year"), mSel = $("#l-month");
  ensureMonths(mSel);
  const qs = new URLSearchParams();
  if (ySel.value) qs.set("year", ySel.value);
  if (mSel.value) qs.set("month", mSel.value);
  if ($("#l-status").value !== "Todos") qs.set("status", $("#l-status").value);
  if ($("#l-tipo").value) qs.set("tipo", $("#l-tipo").value);
  if ($("#l-q").value) qs.set("q", $("#l-q").value);
  qs.set("limit", "2000");   // filtros de coluna são no cliente: traga tudo que der
  const { total, items, years } = await api("/api/transactions?" + qs);
  fillYears(ySel, years);
  txItens = items;
  txTotal = total;
  decorarCabecalho();
  renderTx();
}

function updateBulkBar() {
  const checks = $$("#l-table .rowchk");
  const marcados = checks.filter((c) => c.checked);
  const bar = $("#l-bulkbar");
  bar.hidden = marcados.length === 0;
  $("#l-selcount").textContent =
    `${marcados.length} selecionado${marcados.length === 1 ? "" : "s"}`;
  const selall = $("#l-selall");
  selall.checked = checks.length > 0 && marcados.length === checks.length;
  selall.indeterminate = marcados.length > 0 && marcados.length < checks.length;
}

$("#l-selall").addEventListener("change", (e) => {
  $$("#l-table .rowchk").forEach((c) => (c.checked = e.target.checked));
  updateBulkBar();
});

$("#l-clearsel").addEventListener("click", () => {
  $$("#l-table .rowchk").forEach((c) => (c.checked = false));
  updateBulkBar();
});

$("#l-bulkdel").addEventListener("click", async () => {
  const ids = $$("#l-table .rowchk")
    .filter((c) => c.checked)
    .map((c) => +c.dataset.id);
  if (!ids.length) return;
  if (!confirm(`Excluir ${ids.length} lançamento${ids.length === 1 ? "" : "s"} selecionado${ids.length === 1 ? "" : "s"}? Esta ação não pode ser desfeita.`)) return;
  const r = await api("/api/transactions/bulk-delete", {
    method: "POST",
    body: JSON.stringify({ ids }),
  });
  await loadLancamentos();
  alert(`${r.deleted} lançamento(s) excluído(s).`);
});

/* --- Alteração em lote --- */
const bulkModal = $("#bulk-modal");
let bulkIds = [];

function selectFrom(options) {
  const s = document.createElement("select");
  for (const o of options) {
    const opt = document.createElement("option");
    opt.value = opt.textContent = o;
    s.append(opt);
  }
  return s;
}

function beRenderValue() {
  const field = $("#be-field").value;
  const wrap = $("#be-valuewrap");
  let ctrl, hint = "", campo = null;   // campo != ctrl quando o controle vem embrulhado (combo)
  if (field === "status") {
    ctrl = selectFrom(["Previsto", "Realizado"]);
  } else if (field === "instituicao") {
    ctrl = selectFrom(["", ...new Set(cachedInstitutions.map((i) => i.nome))]);
    hint = "Deixe em branco para limpar a instituição.";
  } else if (field === "classificacao") {
    const combo = criarCombo("be-classificacao", cachedCategories.map((c) => c.classificacao));
    ctrl = combo.wrap;
    campo = combo.input;
    hint = "Digite parte do nome para buscar. Grupo, tipo e categoria serão ajustados conforme a classificação escolhida.";
  } else if (field === "dt_venc") {
    ctrl = document.createElement("input");
    ctrl.type = "date";
  } else {
    ctrl = document.createElement("input");
    ctrl.type = "text";
    ctrl.setAttribute("list", "dl-people");
    hint = "Deixe em branco para limpar a pessoa.";
  }
  (campo || ctrl).id = "be-value";
  wrap.innerHTML = "";
  wrap.append(ctrl);
  $("#be-hint").textContent = hint;
}

$("#l-bulkedit").addEventListener("click", () => {
  bulkIds = $$("#l-table .rowchk").filter((c) => c.checked).map((c) => +c.dataset.id);
  if (!bulkIds.length) return;
  $("#bulk-title").textContent =
    `Alterar ${bulkIds.length} lançamento${bulkIds.length === 1 ? "" : "s"}`;
  ensurePeopleDatalist();
  beRenderValue();
  bulkModal.showModal();
});
$("#be-field").addEventListener("change", beRenderValue);
$("#bulk-cancel").addEventListener("click", () => bulkModal.close());
$("#bulk-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const field = $("#be-field").value;
  const value = $("#be-value").value;
  if (field === "classificacao" && !cachedCategories.some((c) => c.classificacao === value)) {
    alert("Escolha uma classificação da lista.");
    return;
  }
  const r = await api("/api/transactions/bulk-update", {
    method: "POST",
    body: JSON.stringify({ ids: bulkIds, field, value }),
  });
  bulkModal.close();
  await loadLancamentos();
  alert(`${r.updated} lançamento(s) alterado(s).`);
});

$("#l-new").addEventListener("click", () =>
  openForm("Novo lançamento", txFields(true),
    { status: "Previsto", dt_venc: new Date().toISOString().slice(0, 10), parcelas: 1, valor_tipo: "parcela" },
    async (data) => {
      data.parcelas = parseInt(data.parcelas, 10) || 1;
      data.valor_total = data.valor_tipo === "total";
      delete data.valor_tipo;
      const r = await api("/api/transactions", { method: "POST", body: JSON.stringify(data) });
      loadLancamentos();
      if (r.parcelas > 1) alert(`${r.parcelas} parcelas lançadas, uma por mês.`);
    }));
["#l-year", "#l-month", "#l-status", "#l-tipo"].forEach((s) =>
  $(s).addEventListener("change", loadLancamentos));
let qTimer;
$("#l-q").addEventListener("input", () => {
  clearTimeout(qTimer);
  qTimer = setTimeout(loadLancamentos, 350);
});
loaders.lancamentos = loadLancamentos;

/* ---------- Fluxo ---------- */
async function loadFluxo() {
  const qs = new URLSearchParams();
  if ($("#x-status").value !== "Todos") qs.set("status", $("#x-status").value);
  if ($("#x-grupo").value) qs.set("grupo", $("#x-grupo").value);
  const { items } = await api("/api/fluxo?" + qs);
  const detail = $("#x-detail").checked;

  const months = [...new Set(items.map((i) => i.mes))].sort();
  const tree = {}; // tipo -> categoria -> {total, subs: {sub: {mes: v}}, meses: {mes: v}}
  const tipoTotais = {};
  for (const it of items) {
    const t = (tree[it.tipo] ??= {});
    const c = (t[it.categoria] ??= { meses: {}, subs: {} });
    c.meses[it.mes] = (c.meses[it.mes] || 0) + it.saldo;
    if (detail && it.subcategoria) {
      const s = (c.subs[it.subcategoria] ??= {});
      s[it.mes] = (s[it.mes] || 0) + it.saldo;
    }
    (tipoTotais[it.tipo] ??= {})[it.mes] = (tipoTotais[it.tipo][it.mes] || 0) + it.saldo;
  }

  const cell = (v) => v
    ? `<td class="num ${v >= 0 ? "pos" : "neg"}">${money(v)}</td>`
    : `<td class="num muted">–</td>`;
  const sum = (obj) => months.reduce((a, m) => a + (obj[m] || 0), 0);

  let html = `<table class="sticky-col"><thead><tr><th>Categoria</th>`;
  html += months.map((m) => `<th class="num">${mesLabel(m)}</th>`).join("");
  html += `<th class="num">Total</th></tr></thead><tbody>`;

  for (const tipo of ["RECEITA", "DESPESA"]) {
    if (!tree[tipo]) continue;
    const tt = tipoTotais[tipo];
    html += `<tr class="section"><td>${esc(tipo)}</td>${months.map((m) => cell(tt[m])).join("")}${cell(sum(tt))}</tr>`;
    const cats = Object.keys(tree[tipo]).sort();
    for (const cat of cats) {
      const c = tree[tipo][cat];
      html += `<tr class="cat"><td>${esc(cat)}</td>${months.map((m) => cell(c.meses[m])).join("")}${cell(sum(c.meses))}</tr>`;
      if (detail) {
        for (const sub of Object.keys(c.subs).sort()) {
          html += `<tr class="sub"><td>${esc(sub)}</td>${months.map((m) => cell(c.subs[sub][m])).join("")}${cell(sum(c.subs[sub]))}</tr>`;
        }
      }
    }
  }
  const geral = {};
  for (const tt of Object.values(tipoTotais))
    for (const [m, v] of Object.entries(tt)) geral[m] = (geral[m] || 0) + v;
  html += `<tr class="section"><td>Resultado</td>${months.map((m) => cell(geral[m])).join("")}${cell(sum(geral))}</tr>`;
  html += "</tbody></table>";
  $("#x-wrap").innerHTML = html;
}
["#x-status", "#x-grupo", "#x-detail"].forEach((s) =>
  $(s).addEventListener("change", loadFluxo));
loaders.fluxo = loadFluxo;

/* ---------- Por Pessoa ---------- */
async function loadPessoas() {
  const ySel = $("#pb-year"), mSel = $("#pb-month");
  ensureMonths(mSel);
  const qs = new URLSearchParams();
  if (ySel.value) qs.set("year", ySel.value);
  if (mSel.value) qs.set("month", mSel.value);
  if ($("#pb-status").value !== "Todos") qs.set("status", $("#pb-status").value);
  const { itens, totais, years } = await api("/api/pessoas-balanco?" + qs);
  fillYears(ySel, years);

  $("#pb-pagar").textContent = money(totais.a_pagar);
  $("#pb-receber").textContent = money(totais.a_receber);
  $("#pb-saldo").textContent = money(totais.saldo);
  $("#pb-saldo").className = "tile-value " + (totais.saldo >= 0 ? "pos" : "neg");

  const tbody = $("#pb-table tbody");
  tbody.innerHTML = itens.map((p) => `<tr>
    <td>${esc(p.pessoa)}</td>
    <td class="num ${p.a_pagar ? "neg" : "muted"}">${p.a_pagar ? money(p.a_pagar) : "–"}</td>
    <td class="num ${p.a_receber ? "pos" : "muted"}">${p.a_receber ? money(p.a_receber) : "–"}</td>
    <td class="num ${p.saldo >= 0 ? "pos" : "neg"}">${money(p.saldo)}</td>
    <td class="num">${p.lancamentos}</td>
  </tr>`).join("") || `<tr><td colspan="5" class="muted">Nenhum lançamento no período.</td></tr>`;

  renderChart("#c-pessoas-balanco", {
    type: "bar",
    data: {
      labels: itens.map((p) => p.pessoa),
      datasets: [
        { label: "A pagar", data: itens.map((p) => p.a_pagar), backgroundColor: cssVar("--series-despesa"), borderRadius: 4, maxBarThickness: 16 },
        { label: "A receber", data: itens.map((p) => p.a_receber), backgroundColor: cssVar("--series-receita"), borderRadius: 4, maxBarThickness: 16 },
      ],
    },
    options: {
      ...baseOpts(),
      indexAxis: "y",
      scales: {
        x: { ticks: { color: cssVar("--text-secondary"), callback: (v) => money(v) }, grid: { color: cssVar("--grid") } },
        y: { ticks: { color: cssVar("--text-secondary") }, grid: { color: "transparent" } },
      },
    },
  });
}
["#pb-year", "#pb-month", "#pb-status"].forEach((s) =>
  $(s).addEventListener("change", loadPessoas));
loaders.pessoas = loadPessoas;

/* ---------- CRUD genérico ---------- */
const RESOURCES = {
  investments: {
    title: "Investimento", path: "/api/investments",
    fields: [
      { name: "instituicao", label: "Instituição", required: true },
      { name: "fixa_var", label: "Fixa / Variável", type: "select", options: ["", "Fixa", "Variável"] },
      { name: "prazo_projeto", label: "Prazo/Projeto", type: "select", options: ["", "RESERVA", "CURTO", "MÉDIO", "LONGO", "APOSENTADORIA"] },
      { name: "ativo", label: "Ativo" },
      { name: "valor", label: "Valor (R$)", type: "number" },
    ],
    cols: [["instituicao", "Instituição"], ["fixa_var", "Fixa/Var"], ["prazo_projeto", "Prazo"], ["ativo", "Ativo"], ["valor", "Valor", money]],
  },
  assets: {
    title: "Bem", path: "/api/assets",
    fields: [
      { name: "descricao", label: "Descrição", required: true, full: true },
      { name: "valor", label: "Valor (R$)", type: "number" },
      { name: "saldo_devedor", label: "Saldo devedor (R$)", type: "number" },
    ],
    cols: [["descricao", "Descrição"], ["valor", "Valor", money], ["saldo_devedor", "Saldo devedor", money],
      ["_liquido", "Líquido", (_, r) => money(r.valor - r.saldo_devedor)]],
  },
  debts: {
    title: "Dívida", path: "/api/debts",
    fields: [
      { name: "descricao", label: "Descrição", required: true, full: true },
      { name: "num_parcelas", label: "Nº parcelas a pagar", type: "number", step: "1" },
      { name: "valor_parcela", label: "Valor da parcela (R$)", type: "number" },
      { name: "saldo_devedor", label: "Saldo devedor (R$)", type: "number" },
    ],
    cols: [["descricao", "Descrição"], ["num_parcelas", "Parcelas"], ["valor_parcela", "Parcela", money], ["saldo_devedor", "Saldo devedor", money]],
  },
  projects: {
    title: "Projeto", path: "/api/projects",
    fields: [
      { name: "descricao", label: "Descrição", required: true, full: true },
      { name: "valor", label: "Valor (R$)", type: "number" },
      { name: "ano", label: "Ano alvo", type: "number", step: "1" },
      { name: "prazo", label: "Prazo", type: "select", options: ["", "Curto", "Médio", "Longo"] },
    ],
    cols: [["descricao", "Descrição"], ["valor", "Valor", money], ["ano", "Ano"], ["prazo", "Prazo"]],
  },
  institutions: {
    title: "Instituição", path: "/api/institutions",
    fields: [
      { name: "nome", label: "Nome", required: true },
      { name: "tipo", label: "Tipo", type: "select", options: ["", "Conta", "Cartão de Crédito", "Dinheiro", "Investimento"] },
      { name: "saldo_inicial", label: "Saldo inicial (R$)", type: "number" },
      { name: "descricao", label: "Descrição", full: true },
    ],
    cols: [["nome", "Nome"], ["tipo", "Tipo"], ["saldo_inicial", "Saldo inicial", money], ["descricao", "Descrição"]],
  },
  people: {
    title: "Pessoa", path: "/api/people",
    fields: [{ name: "nome", label: "Nome", required: true, full: true }],
    cols: [["nome", "Nome"]],
  },
  ofx: {
    title: "Arquivo OFX", path: "/api/ofx",
    fields: [
      { name: "data_extracao", label: "Data extração", type: "date" },
      { name: "banco", label: "Banco" },
      { name: "periodo", label: "Período" },
      { name: "nome_arquivo", label: "Nome do arquivo", full: true },
    ],
    cols: [["data_extracao", "Data", dateBR], ["banco", "Banco"], ["periodo", "Período"], ["nome_arquivo", "Arquivo"]],
  },
  "card-payments": {
    title: "Pagamento de cartão", path: "/api/card-payments",
    fields: [
      { name: "data_pagto", label: "Data pagamento", type: "date" },
      { name: "cartao", label: "Cartão" },
      { name: "periodo", label: "Período" },
      { name: "valor", label: "Valor (R$)", type: "number" },
    ],
    cols: [["data_pagto", "Data", dateBR], ["cartao", "Cartão"], ["periodo", "Período"], ["valor", "Valor", money]],
  },
};

const RESOURCE_TABLES = {
  investments: "#p-investments", assets: "#p-assets", debts: "#p-debts",
  projects: "#j-table", institutions: "#s-institutions", people: "#s-people",
  ofx: "#r-ofx", "card-payments": "#r-cards",
};

async function loadResource(key) {
  const r = RESOURCES[key];
  const { items } = await api(r.path);
  const table = $(RESOURCE_TABLES[key]);
  let html = "<thead><tr>" + r.cols.map(([, l]) => `<th>${l}</th>`).join("") + "<th></th></tr></thead><tbody>";
  // Os formatadores (money, dateBR) produzem texto controlado por nós; o valor
  // cru vem do usuário e precisa ser escapado antes de ir para innerHTML.
  html += items.map((it) => "<tr>" + r.cols.map(([k, , f]) =>
    `<td>${f ? esc(f(it[k], it)) : esc(it[k] ?? "")}</td>`).join("") +
    `<td class="row-actions"><button class="btn small" data-edit="${it.id}">✏️</button>
     <button class="btn small danger" data-del="${it.id}">🗑</button></td></tr>`).join("");
  html += "</tbody>";
  if (!items.length) html += `<caption class="muted">Nenhum registro — use “+ Adicionar”.</caption>`;
  table.innerHTML = html;
  table.querySelectorAll("[data-edit]").forEach((b) => b.addEventListener("click", () => {
    const item = items.find((i) => i.id == b.dataset.edit);
    openForm("Editar " + r.title.toLowerCase(), r.fields, item, async (data) => {
      await api(`${r.path}/${item.id}`, { method: "PUT", body: JSON.stringify(data) });
      reloadResourceGroup(key);
    });
  }));
  table.querySelectorAll("[data-del]").forEach((b) => b.addEventListener("click", async () => {
    if (confirm("Excluir este registro?")) {
      await api(`${r.path}/${b.dataset.del}`, { method: "DELETE" });
      reloadResourceGroup(key);
    }
  }));
}

function reloadResourceGroup(key) {
  loadResource(key);
  if (["investments", "assets", "debts"].includes(key)) loadPatrimonioSummary();
  if (key === "projects") loadProjetosSummary();
}

$$("[data-add]").forEach((btn) => btn.addEventListener("click", () => {
  const key = btn.dataset.add;
  const r = RESOURCES[key];
  openForm("Novo " + r.title.toLowerCase(), r.fields, null, async (data) => {
    await api(r.path, { method: "POST", body: JSON.stringify(data) });
    reloadResourceGroup(key);
  });
}));

/* ---------- Patrimônio ---------- */
async function loadPatrimonioSummary() {
  const [inv, ass, deb] = await Promise.all([
    api("/api/investments"), api("/api/assets"), api("/api/debts"),
  ]);
  const invTotal = inv.items.reduce((a, i) => a + i.valor, 0);
  const bensLiq = ass.items.reduce((a, i) => a + i.valor - i.saldo_devedor, 0);
  const dividas = deb.items.reduce((a, i) => a + i.saldo_devedor, 0);
  const patrimonio = invTotal + bensLiq - dividas;
  $("#p-summary").innerHTML = [
    ["Investimentos", invTotal, "pos"],
    ["Bens (líquido)", bensLiq, "pos"],
    ["Dívidas", dividas, "neg"],
    ["Patrimônio líquido", patrimonio, patrimonio >= 0 ? "pos" : "neg"],
  ].map(([l, v, c]) => `<div class="tile"><span class="tile-label">${l}</span>
    <span class="tile-value ${c}">${money(v)}</span></div>`).join("");
}
loaders.patrimonio = () => {
  ["investments", "assets", "debts"].forEach(loadResource);
  loadPatrimonioSummary();
};

/* ---------- Projetos ---------- */
async function loadProjetosSummary() {
  const s = await api("/api/projects/summary");
  const n = s.necessidade;
  $("#j-summary").innerHTML = [
    ["Reserva emergência", n["RESERVA"]],
    ["Curto prazo", n["CURTO"]],
    ["Médio prazo", n["MÉDIO"]],
    ["Longo prazo", n["LONGO"]],
    ["Aplicado hoje", s.aplicado_total],
  ].map(([l, v]) => `<div class="tile"><span class="tile-label">${l}</span>
    <span class="tile-value">${money(v)}</span></div>`).join("");
}
loaders.projetos = () => {
  loadResource("projects");
  loadProjetosSummary();
};

/* ---------- Registro ---------- */
loaders.registro = () => {
  loadResource("ofx");
  loadResource("card-payments");
};

/* ---------- Configuração ---------- */
async function loadSettings() {
  const s = await api("/api/settings");
  const form = $("#s-form");
  for (const k of ["receita_mensal", "custo_vida_mensal", "fator_reserva"]) {
    form.elements[k].value = s[k] ?? 0;
  }
}
$("#s-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const f = e.target.elements;
  await api("/api/settings", {
    method: "PUT",
    body: JSON.stringify({
      receita_mensal: +f.receita_mensal.value || 0,
      custo_vida_mensal: +f.custo_vida_mensal.value || 0,
      fator_reserva: +f.fator_reserva.value || 6,
    }),
  });
  alert("Parâmetros salvos.");
});

const catFields = [
  { name: "categoria", label: "Categoria", required: true },
  { name: "subcategoria", label: "Subcategoria" },
  { name: "grupo", label: "Grupo", type: "select", options: ["OPERACIONAL", "NÃO OPERACIONAL"] },
  { name: "tipo", label: "Tipo", type: "select", options: ["DESPESA", "RECEITA"] },
  { name: "meta_mes", label: "Meta mensal (R$)", type: "number" },
];

async function loadCategoriesTable() {
  const { items } = await api("/api/categories");
  const q = $("#s-catq").value.toLowerCase();
  const filtered = q ? items.filter((c) => c.classificacao.toLowerCase().includes(q)) : items;
  const table = $("#s-categories");
  let html = `<thead><tr><th>Classificação</th><th>Grupo</th><th>Tipo</th>
    <th class="num">Meta mês</th><th></th></tr></thead><tbody>`;
  html += filtered.map((c) => `<tr><td>${esc(c.classificacao)}</td><td>${esc(c.grupo)}</td><td>${esc(c.tipo)}</td>
    <td class="num">${c.meta_mes ? money(c.meta_mes) : "–"}</td>
    <td class="row-actions"><button class="btn small" data-edit="${c.id}">✏️</button>
    <button class="btn small danger" data-del="${c.id}">🗑</button></td></tr>`).join("");
  html += "</tbody>";
  table.innerHTML = html;
  table.querySelectorAll("[data-edit]").forEach((b) => b.addEventListener("click", () => {
    const c = items.find((i) => i.id == b.dataset.edit);
    openForm("Editar classificação", catFields, c, async (data) => {
      await api(`/api/categories/${c.id}`, { method: "PUT", body: JSON.stringify(data) });
      loadCategoriesTable();
    });
  }));
  table.querySelectorAll("[data-del]").forEach((b) => b.addEventListener("click", async () => {
    if (confirm("Excluir esta classificação?")) {
      await api(`/api/categories/${b.dataset.del}`, { method: "DELETE" });
      loadCategoriesTable();
    }
  }));
}
$("#s-newcat").addEventListener("click", () =>
  openForm("Nova classificação", catFields,
    { grupo: "OPERACIONAL", tipo: "DESPESA", meta_mes: 0 },
    async (data) => {
      await api("/api/categories", { method: "POST", body: JSON.stringify(data) });
      loadCategoriesTable();
    }));
$("#s-catq").addEventListener("input", () => {
  clearTimeout(qTimer);
  qTimer = setTimeout(loadCategoriesTable, 300);
});

$("#imp-btn").addEventListener("click", async () => {
  const input = $("#imp-file");
  const result = $("#imp-result");
  if (!input.files.length) {
    result.textContent = "Escolha um arquivo .xlsx ou .xlsm primeiro.";
    return;
  }
  const replace = $("#imp-replace").checked;
  if (replace && !confirm("Isso vai apagar TODOS os seus lançamentos atuais antes de importar. Continuar?")) return;
  const btn = $("#imp-btn");
  btn.disabled = true;
  result.textContent = "Importando… isso pode levar alguns segundos.";
  const form = new FormData();
  form.append("file", input.files[0]);
  form.append("replace", replace ? "true" : "false");
  try {
    // Sem Content-Type aqui de propósito: o navegador precisa gerar o
    // boundary do multipart. Só o token CSRF vai no header.
    const res = await fetch("/api/import", {
      method: "POST",
      headers: { "X-CSRF-Token": csrfToken() },
      body: form,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      result.textContent = "Erro: " + (data.detail || "não foi possível importar.");
      return;
    }
    result.textContent =
      `✅ Importado: ${data.lancamentos} lançamentos, ${data.categorias_novas} categorias novas, ` +
      `${data.projetos} projetos, ${data.investimentos} investimentos, ${data.bens} bens, ${data.dividas} dívidas.`;
    input.value = "";
    $("#imp-replace").checked = false;
    await refreshLookups();
    loadCategoriesTable();
    loadResource("people");
    loadResource("institutions");
  } catch (e) {
    result.textContent = "Erro ao enviar o arquivo.";
  } finally {
    btn.disabled = false;
  }
});

/* ---------- Minha conta ---------- */
async function loadMeForm() {
  const me = await api("/api/auth/me");
  const f = $("#me-form").elements;
  f.nome.value = me.nome || "";
  f.email.value = me.email || "";
}

$("#me-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const f = e.target.elements;
  const data = await api("/api/auth/profile", {
    method: "PUT",
    body: JSON.stringify({ nome: f.nome.value.trim(), email: f.email.value.trim() }),
  });
  $("#user-name").textContent = "👤 " + data.nome;
  $("#me-msg").textContent = "Perfil salvo.";
});

$("#pw-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const f = e.target.elements;
  const msg = $("#pw-msg");
  if (f.senha_nova.value !== f.senha_conf.value) {
    msg.textContent = "A confirmação não confere com a nova senha.";
    return;
  }
  await api("/api/auth/password", {
    method: "PUT",
    body: JSON.stringify({
      senha_atual: f.senha_atual.value,
      senha_nova: f.senha_nova.value,
    }),
  });
  e.target.reset();
  msg.textContent = "Senha alterada. As outras sessões foram encerradas.";
});

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

loaders.configuracao = () => {
  loadSettings();
  loadMeForm();
  loadCategoriesTable();
  loadResource("people");
  loadResource("institutions");
};

/* ---------- Admin ---------- */
async function loadAdminUsers() {
  const { items } = await api("/api/admin/users");
  const table = $("#a-users");
  let html = `<thead><tr><th>Conta</th><th>E-mail</th><th class="num">Lançamentos</th>
    <th>Criada em</th><th>Último acesso</th><th>Admin</th><th></th></tr></thead><tbody>`;
  html += items.map((u) => `<tr>
    <td>${esc(u.nome)}${u.eu ? ' <span class="muted">(você)</span>' : ""}</td>
    <td>${esc(u.email || u.usuario)}</td>
    <td class="num">${u.n_lancamentos}</td>
    <td>${dataHoraBR(u.criado_em)}</td>
    <td>${dataHoraBR(u.ultimo_login)}</td>
    <td>${u.is_admin ? "✅" : "—"}</td>
    <td class="row-actions">
      <button class="btn small" data-pw="${u.id}" title="Redefinir senha">🔑</button>
      <button class="btn small" data-mfa="${u.id}" title="Resetar MFA">🔁</button>
      <button class="btn small" data-flag="${u.id}" title="${u.is_admin ? "Rebaixar" : "Tornar admin"}">${u.is_admin ? "⬇️" : "⬆️"}</button>
      <button class="btn small danger" data-del="${u.id}" title="Excluir conta"${u.eu ? " disabled" : ""}>🗑</button>
    </td></tr>`).join("");
  html += "</tbody>";
  table.innerHTML = html;

  table.querySelectorAll("[data-pw]").forEach((b) => b.addEventListener("click", () => {
    const u = items.find((i) => i.id == b.dataset.pw);
    openForm(`Redefinir senha de ${u.nome}`, [
      { name: "senha_nova", label: "Nova senha", type: "password", required: true },
    ], {}, async (data) => {
      await api(`/api/admin/users/${u.id}/password`, {
        method: "POST", body: JSON.stringify(data),
      });
      alert(`Senha de ${u.nome} redefinida. As sessões dela foram encerradas.`);
    });
  }));

  table.querySelectorAll("[data-mfa]").forEach((b) => b.addEventListener("click", async () => {
    const u = items.find((i) => i.id == b.dataset.mfa);
    if (!confirm(`Resetar o MFA de ${u.nome}? A conta vai precisar cadastrar o autenticador de novo no próximo login.`)) return;
    await api(`/api/admin/users/${u.id}/mfa/reset`, { method: "POST" });
    alert(`MFA de ${u.nome} foi resetado.`);
  }));

  table.querySelectorAll("[data-flag]").forEach((b) => b.addEventListener("click", async () => {
    const u = items.find((i) => i.id == b.dataset.flag);
    const virar = !u.is_admin;
    if (!confirm(`${virar ? "Tornar" : "Rebaixar"} ${u.nome} ${virar ? "administrador" : "para usuário comum"}?`)) return;
    await api(`/api/admin/users/${u.id}/admin`, {
      method: "POST", body: JSON.stringify({ is_admin: virar }),
    });
    loadAdminUsers();
  }));

  table.querySelectorAll("[data-del]").forEach((b) => b.addEventListener("click", async () => {
    const u = items.find((i) => i.id == b.dataset.del);
    if (!confirm(`Excluir a conta de ${u.nome}?\n\nIsso apaga ${u.n_lancamentos} lançamento(s) e todos os dados dela. Não dá para desfazer.`)) return;
    await api(`/api/admin/users/${u.id}`, { method: "DELETE" });
    loadAdminUsers();
  }));
}
loaders.admin = loadAdminUsers;

/* dark mode: redesenha gráficos com as cores do novo tema */
window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", () => {
  if ($("#tab-dashboard").classList.contains("active")) loadDashboard();
});

/* ---------- Autenticação ---------- */
let authMode = "login";

const AUTH_CARDS = ["auth-card", "verify-card", "mfa-setup-card", "mfa-backup-card", "mfa-verify-card"];

function mostrarTela(id) {
  AUTH_CARDS.forEach((cid) => { $("#" + cid).hidden = cid !== id; });
}

function showAuth() {
  document.body.classList.remove("authed");
  $("#au-pass").value = "";
  mostrarTela("auth-card");
}

function showVerify(email) {
  $("#verify-email").textContent = email;
  $("#verify-codigo").value = "";
  $("#verify-error").hidden = true;
  mostrarTela("verify-card");
  $("#verify-codigo").focus();
}

/** Cadastro do autenticador: busca o segredo/QR e mostra a tela de confirmação. */
async function iniciarCadastroMfa() {
  const res = await fetch("/api/auth/mfa/setup/start", { method: "POST", headers: cabecalhos() });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    alert(data.detail || "Não foi possível iniciar o cadastro do autenticador.");
    return;
  }
  $("#mfa-secret").textContent = data.segredo;
  $("#mfa-setup-codigo").value = "";
  $("#mfa-setup-error").hidden = true;
  const qr = qrcode(0, "M");
  qr.addData(data.otpauth_uri);
  qr.make();
  $("#mfa-qr").innerHTML = qr.createSvgTag(4);
  mostrarTela("mfa-setup-card");
  $("#mfa-setup-codigo").focus();
}

function mostrarCodigosBackup(codigos) {
  $("#mfa-backup-list").innerHTML = codigos.map((c) => `<li>${esc(c)}</li>`).join("");
  mostrarTela("mfa-backup-card");
}

/** Roteia a resposta de /api/auth/login pelo estágio de MFA em que a conta está. */
async function tratarRespostaLogin(data) {
  if (data.status === "mfa_setup_required") {
    await iniciarCadastroMfa();
    return;
  }
  if (data.status === "mfa_required") {
    $("#mfa-verify-codigo").value = "";
    $("#mfa-verify-error").hidden = true;
    mostrarTela("mfa-verify-card");
    $("#mfa-verify-codigo").focus();
    return;
  }
  await enterApp(data.nome || data.email, data.is_admin);
}

function setAuthMode(mode) {
  authMode = mode;
  $$(".auth-tabs button").forEach((b) => b.classList.toggle("active", b.dataset.mode === mode));
  const registrando = mode === "register";
  $("#au-nome-label").hidden = !registrando;
  $("#au-nome").required = registrando;
  $("#au-submit").textContent = registrando ? "Criar conta" : "Entrar";
  $("#au-pass").autocomplete = registrando ? "new-password" : "current-password";
  $("#au-hint").textContent = registrando
    ? "É grátis. Sua conta começa com um painel limpo, pronto para usar."
    : "Ainda não tem conta? Clique em “Criar conta”.";
  $("#au-error").hidden = true;
}

$$(".auth-tabs button").forEach((b) =>
  b.addEventListener("click", () => setAuthMode(b.dataset.mode)));

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
    showVerify(data.email || email);
    return;
  }
  await tratarRespostaLogin(data);
});

$("#mfa-setup-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const codigo = $("#mfa-setup-codigo").value.trim();
  const res = await fetch("/api/auth/mfa/setup/confirm", {
    method: "POST", headers: cabecalhos(), body: JSON.stringify({ codigo }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = $("#mfa-setup-error");
    err.textContent = data.detail || "Código inválido.";
    err.hidden = false;
    return;
  }
  mfaDadosPosBackup = data;
  mostrarCodigosBackup(data.codigos_backup);
});

let mfaDadosPosBackup = null;
$("#mfa-backup-ok").addEventListener("click", async () => {
  const data = mfaDadosPosBackup;
  mfaDadosPosBackup = null;
  mostrarTela(null);
  await enterApp(data.nome || data.email, data.is_admin);
});

$("#mfa-verify-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const codigo = $("#mfa-verify-codigo").value.trim();
  const res = await fetch("/api/auth/mfa/verify", {
    method: "POST", headers: cabecalhos(), body: JSON.stringify({ codigo }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = $("#mfa-verify-error");
    err.textContent = data.detail || "Código inválido.";
    err.hidden = false;
    return;
  }
  mostrarTela(null);
  await enterApp(data.nome || data.email, data.is_admin);
});

$("#verify-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const email = $("#verify-email").textContent;
  const codigo = $("#verify-codigo").value.trim();
  const res = await fetch("/api/auth/verify-email", {
    method: "POST",
    headers: cabecalhos(),
    body: JSON.stringify({ email, codigo }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = $("#verify-error");
    err.textContent = data.detail || "Código inválido ou expirado.";
    err.hidden = false;
    return;
  }
  await enterApp(data.nome || data.email, data.is_admin);
});

$("#verify-resend").addEventListener("click", async () => {
  const email = $("#verify-email").textContent;
  await fetch("/api/auth/resend-verification", {
    method: "POST",
    headers: cabecalhos(),
    body: JSON.stringify({ email }),
  });
  alert("Se a conta ainda não tiver sido ativada, reenviamos o código.");
});

$("#logout-btn").addEventListener("click", async () => {
  await fetch("/api/auth/logout", { method: "POST", headers: cabecalhos() });
  location.reload();
});

async function enterApp(usuario, isAdmin = false) {
  $("#user-name").textContent = "👤 " + usuario;
  $("#tab-admin-btn").hidden = !isAdmin;
  document.body.classList.add("authed");
  ajustarTopbar();   // só agora a barra existe no layout (antes o shell está oculto)
  $$("#tabs button").forEach((b) => b.classList.toggle("active", b.dataset.tab === "dashboard"));
  $$(".tab").forEach((t) => t.classList.toggle("active", t.id === "tab-dashboard"));
  ["#f-year", "#l-year", "#l-month", "#pb-year", "#pb-month"].forEach((s) => {
    const el = $(s);
    if (el) el.innerHTML = "";
  });
  await loadDashboard();
}

async function boot() {
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
boot();
