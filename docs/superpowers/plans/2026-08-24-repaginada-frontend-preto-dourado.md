# Repaginada do frontend (preto & dourado) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o tema dark roxo/ciano por um tema preto & dourado sóbrio e trocar todos os emojis por um sprite SVG inline, sem alterar nenhuma regra de negócio.

**Architecture:** O frontend é vanilla e vive em três arquivos sob `public/static/`. Não há build. Um sprite `<svg hidden>` com `<symbol>` entra no início do `<body>` do `index.html`; HTML e JS o consomem via `<use href="#i-*">`. As cores dos gráficos continuam vindo do CSS através de `cssVar()`, então retematizar o `style.css` retinge o Chart.js sem tocar no JS.

**Tech Stack:** HTML/CSS/JS vanilla, Chart.js self-hosted, PHP 8 (só para o script de teste), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-08-24-repaginada-frontend-preto-dourado-design.md`

## Global Constraints

- **Nada de backend.** Nenhum arquivo fora de `public/static/` e `tests/` é modificado. Nenhum endpoint, regra de negócio, migração ou consulta muda.
- **Nenhum `id`, `data-*` ou nome de classe consumido pelo JS pode ser renomeado.** Renomear quebra os listeners.
- **As 8 variáveis CSS lidas por `app.js` via `cssVar()` devem continuar existindo com o mesmo nome:** `--accent`, `--grid`, `--seq-300`, `--seq-450`, `--series-despesa`, `--series-receita`, `--surface-1`, `--text-secondary`. Só os valores mudam.
- **Regra do dourado:** `--gold` só aparece em estado ativo, ação primária, anel de foco e série principal do gráfico. Nunca em texto de corpo, nunca em gradiente, nunca em `box-shadow` difuso (glow).
- **Paleta exata** (valores literais, copiar sem alterar):
  ```
  --page #0A0A0B   --surface-1 #121213   --surface-2 #1A1A1C   --surface-3 #232326
  --border rgba(255,255,255,.07)         --border-strong rgba(255,255,255,.12)
  --text-primary #EDEDEF                 --text-secondary #9A9AA2   --muted #6E6E76
  --gold #D4AF37   --gold-soft #E5C76B   --gold-dim rgba(212,175,55,.10)
  --gold-border rgba(212,175,55,.35)     --on-gold #0A0A0B
  --good #3FB950   --bad #E5534B         --radius 12px   --radius-sm 8px
  ```
- **Geometria dos ícones:** `viewBox="0 0 24 24"` em cada `<symbol>`. Os cinco valores de traço (`fill: none`, `stroke: currentColor`, `stroke-width: 1.5`, `stroke-linecap: round`, `stroke-linejoin: round`) vão **na regra CSS `.ico`**, não como atributos no `<svg>` do sprite.

  **Por quê (verificado em Chrome real, não presumido):** o `<use>` clona o `<symbol>` numa shadow tree cujo pai é o próprio `<use>` — a herança segue a posição do **uso**, não a da definição. Atributos de apresentação no `<svg id="sprite">` portanto nunca alcançam o clone, e o ícone renderiza com o `fill` padrão (preto sólido), virando um borrão. Propriedades CSS herdáveis aplicadas em `.ico` **atravessam** para a shadow tree e funcionam. Uma versão anterior deste plano afirmava o contrário e estava errada.
- **Idioma:** toda string visível ao usuário em português do Brasil, como já é hoje.
- **Commits:** mensagem no formato `<tipo>: <descrição>` em português, conforme o histórico do repositório.

## Correções ao spec descobertas na exploração

Aplicar estas, que substituem o que o spec diz:

1. O spec manda preservar `--series-1` e `--baseline` "porque `app.js` os consome". **Não consome.** Junto com `--grad`, são definidas e nunca referenciadas em `style.css`, `app.js` ou `index.html`. São código morto e devem ser **removidas**.
2. O spec não lista todos os call sites de `--primary`. São 6 além dos gradientes (`style.css` linhas 284, 359, 441, 467, 504) mais `--primary-soft` na linha 455. Todos precisam ser remapeados no mesmo commit em que as variáveis somem — a Task 2 cobre isso.
3. O spec não menciona os literais roxo/ciano hardcoded: `style.css` linhas 19, 52, 299 (×2), 353 (×2), 354. A Task 2 cobre.

---

## Task 1: Sprite SVG e helper `ico()`

Cria a iconografia. Nenhum emoji é substituído ainda — isso é Task 3 e 4.

**Files:**
- Create: `tests/frontend_test.php`
- Modify: `public/static/index.html` (inserir sprite logo após `<body>`, linha 14)
- Modify: `public/static/app.js` (adicionar helper perto de `cssVar`, linha 55)
- Modify: `public/static/style.css` (classe `.ico`)

**Interfaces:**
- Consumes: nada.
- Produces:
  - 23 `<symbol>` com os ids: `i-dashboard`, `i-list`, `i-users`, `i-trend`, `i-landmark`, `i-target`, `i-inbox`, `i-settings`, `i-shield`, `i-wallet`, `i-mail`, `i-lock`, `i-key`, `i-x`, `i-pencil`, `i-trash`, `i-check`, `i-refresh`, `i-arrow-up`, `i-arrow-down`, `i-user`, `i-menu`, `i-plus`.
  - `const ico = (nome) => string` em `app.js`, escopo de módulo, usado nas Tasks 4.
  - Classe CSS `.ico`.
  - `afirmar(bool, string)` em `tests/frontend_test.php`, usado pelas Tasks 2–5.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/frontend_test.php`. Segue a convenção de `tests/mfa_test.php`: PHP puro, sem framework, função `afirmar()` que sai com código 1 na primeira falha.

```php
<?php
declare(strict_types=1);

/**
 * Invariantes do frontend estático. Não sobe servidor nem banco — só lê os
 * três arquivos de public/static/ e checa propriedades estruturais que
 * quebram silenciosamente no navegador (símbolo de ícone faltando, variável
 * CSS que o Chart.js lê e não existe mais, botão só-ícone sem rótulo).
 */

function afirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: {$mensagem}\n");
        exit(1);
    }
    echo "ok: {$mensagem}\n";
}

$raiz = __DIR__ . '/../public/static';
$html = file_get_contents($raiz . '/index.html');
$js   = file_get_contents($raiz . '/app.js');
$css  = file_get_contents($raiz . '/style.css');
afirmar($html !== false && $js !== false && $css !== false, 'os tres arquivos de public/static foram lidos');

// ---- Sprite de icones ----

$esperados = [
    'i-dashboard', 'i-list', 'i-users', 'i-trend', 'i-landmark', 'i-target',
    'i-inbox', 'i-settings', 'i-shield', 'i-wallet', 'i-mail', 'i-lock',
    'i-key', 'i-x', 'i-pencil', 'i-trash', 'i-check', 'i-refresh',
    'i-arrow-up', 'i-arrow-down', 'i-user', 'i-menu', 'i-plus',
];

preg_match_all('/<symbol id="(i-[a-z-]+)"/', $html, $m);
$definidos = $m[1];

foreach ($esperados as $id) {
    afirmar(in_array($id, $definidos, true), "sprite define <symbol id=\"{$id}\">");
}
afirmar(count($definidos) === count(array_unique($definidos)), 'nenhum id de symbol duplicado');

// O <svg> do sprite carrega os atributos de traco que os symbols herdam.
afirmar((bool) preg_match('/<svg[^>]*id="sprite"[^>]*stroke="currentColor"/', $html),
    'sprite usa stroke="currentColor" (icone herda a cor do texto)');
afirmar((bool) preg_match('/<svg[^>]*id="sprite"[^>]*stroke-width="1\.5"/', $html),
    'sprite usa stroke-width 1.5');
afirmar((bool) preg_match('/<svg[^>]*id="sprite"[^>]*\shidden/', $html),
    'sprite esta oculto (nao ocupa espaco no layout)');

// ---- Helper ico() ----

afirmar(str_contains($js, 'const ico ='), 'app.js define o helper ico()');

// ---- Classe .ico ----

afirmar((bool) preg_match('/^\.ico\s*\{/m', $css), 'style.css define a classe .ico');

echo "\nTodos os testes de frontend passaram.\n";
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
php tests/frontend_test.php
```

Esperado: `FALHOU: sprite define <symbol id="i-dashboard">`, saída 1.

- [ ] **Step 3: Inserir o sprite no `index.html`**

Logo após a linha `<body>` (linha 14), antes de `<div id="auth-screen">`:

```html
<!-- Sprite de icones. Os atributos de traco ficam aqui e sao herdados por
     todos os <symbol>; cada uso vira <svg class="ico"><use href="#i-x"/></svg>
     e herda a cor do texto do elemento pai via currentColor. -->
<svg id="sprite" hidden aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
     fill="none" stroke="currentColor" stroke-width="1.5"
     stroke-linecap="round" stroke-linejoin="round">
  <symbol id="i-dashboard" viewBox="0 0 24 24">
    <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
    <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
  </symbol>
  <symbol id="i-list" viewBox="0 0 24 24">
    <path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>
  </symbol>
  <symbol id="i-users" viewBox="0 0 24 24">
    <path d="M16 19.5V18a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1.5"/><circle cx="9" cy="7" r="3.5"/>
    <path d="M22 19.5V18a4 4 0 0 0-3-3.87"/><path d="M15.5 3.6a4 4 0 0 1 0 7.75"/>
  </symbol>
  <symbol id="i-trend" viewBox="0 0 24 24">
    <path d="M3 3v17a1 1 0 0 0 1 1h17"/><path d="m7 15 4-4 3 3 5-6"/><path d="M15 8h4v4"/>
  </symbol>
  <symbol id="i-landmark" viewBox="0 0 24 24">
    <path d="M3 21h18"/><path d="M4 10h16"/><path d="M12 3 21 8H3l9-5Z"/>
    <path d="M6 10v11M10 10v11M14 10v11M18 10v11"/>
  </symbol>
  <symbol id="i-target" viewBox="0 0 24 24">
    <circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><path d="M12 12h.01"/>
  </symbol>
  <symbol id="i-inbox" viewBox="0 0 24 24">
    <path d="M3 12h5l2 3h4l2-3h5"/><path d="M5.5 5h13l2.5 7v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6l2.5-7Z"/>
  </symbol>
  <symbol id="i-settings" viewBox="0 0 24 24">
    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z"/>
    <circle cx="12" cy="12" r="3"/>
  </symbol>
  <symbol id="i-shield" viewBox="0 0 24 24">
    <path d="M12 22s8-4 8-10V5.5l-8-3-8 3V12c0 6 8 10 8 10Z"/>
  </symbol>
  <symbol id="i-wallet" viewBox="0 0 24 24">
    <path d="M3 6a2 2 0 0 1 2-2h12.5A1.5 1.5 0 0 1 19 5.5V8"/>
    <path d="M3 6v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2H5a2 2 0 0 1-2-2Z"/>
    <path d="M16.5 14h.01"/>
  </symbol>
  <symbol id="i-mail" viewBox="0 0 24 24">
    <rect x="2.5" y="4.5" width="19" height="15" rx="2"/>
    <path d="m3 7 8.4 5.6a1 1 0 0 0 1.2 0L21 7"/>
  </symbol>
  <symbol id="i-lock" viewBox="0 0 24 24">
    <rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
  </symbol>
  <symbol id="i-key" viewBox="0 0 24 24">
    <circle cx="7.5" cy="15.5" r="3.5"/><path d="m10 13 8.5-8.5"/><path d="m16 7 2.5 2.5"/><path d="m19 4 2 2"/>
  </symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
  <symbol id="i-pencil" viewBox="0 0 24 24">
    <path d="M4 20h4L18.5 9.5a2.83 2.83 0 0 0-4-4L4 16v4Z"/><path d="m14.5 6.5 3 3"/>
  </symbol>
  <symbol id="i-trash" viewBox="0 0 24 24">
    <path d="M4 7h16"/><path d="M10 4h4a1 1 0 0 1 1 1v2H9V5a1 1 0 0 1 1-1Z"/>
    <path d="M6 7v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"/><path d="M10.5 11v6M13.5 11v6"/>
  </symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="m4.5 12.5 5 5 10-11"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24">
    <path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v5h-5"/>
  </symbol>
  <symbol id="i-arrow-up" viewBox="0 0 24 24"><path d="M12 20V4"/><path d="m5.5 10.5 6.5-6.5 6.5 6.5"/></symbol>
  <symbol id="i-arrow-down" viewBox="0 0 24 24"><path d="M12 4v16"/><path d="m5.5 13.5 6.5 6.5 6.5-6.5"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24">
    <circle cx="12" cy="8" r="3.75"/><path d="M4.5 20.5v-1a5 5 0 0 1 5-5h5a5 5 0 0 1 5 5v1"/>
  </symbol>
  <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
</svg>
```

- [ ] **Step 4: Adicionar o helper `ico()` no `app.js`**

Logo depois da função `cssVar` (que termina na linha 57), inserir:

```js
// Devolve a string de um icone do sprite, para concatenar nos template
// literals que montam tabelas via innerHTML. Decorativo: quem precisa de
// rotulo acessivel poe aria-label no <button> que o envolve.
const ico = (nome) =>
  `<svg class="ico" aria-hidden="true"><use href="#i-${nome}"/></svg>`;
```

- [ ] **Step 5: Adicionar a classe `.ico` no `style.css`**

No fim do arquivo:

```css
/* ---- Icones (sprite SVG inline) ---- */
.ico {
  width: 18px;
  height: 18px;
  flex: 0 0 auto;
  display: inline-block;
  vertical-align: -4px;
}
.btn .ico { width: 15px; height: 15px; vertical-align: -3px; }
```

- [ ] **Step 6: Rodar o teste e confirmar que passa**

```bash
php tests/frontend_test.php
```

Esperado: todas as linhas `ok:` e `Todos os testes de frontend passaram.`

- [ ] **Step 7: Conferir no navegador que o sprite não afeta o layout**

```bash
docker compose up -d
```

Abrir `http://localhost:8080`, confirmar que a tela de login aparece igual a antes (o sprite é `hidden`, não deve deslocar nada) e que o console não tem erro.

- [ ] **Step 8: Commit**

```bash
git add tests/frontend_test.php public/static/index.html public/static/app.js public/static/style.css
git commit -m "feat: adiciona sprite de icones SVG e teste de invariantes do frontend"
```

---

## Task 2: Tokens do tema preto & dourado

Troca a paleta e remapeia todos os call sites das variáveis que somem. Ainda com emoji — isso é Task 3 e 4.

**Files:**
- Modify: `public/static/style.css` (bloco `:root` linhas 2–31; `body::before` linhas 43–55; e as linhas 75, 105, 197, 284, 299, 353–354, 359, 374–375, 378, 395, 441, 455, 467, 504)
- Modify: `tests/frontend_test.php` (novas asserções)

**Interfaces:**
- Consumes: `afirmar()` da Task 1.
- Produces: as variáveis da seção "Global Constraints" disponíveis para as Tasks 3–5.

- [ ] **Step 1: Escrever as asserções que falham**

Em `tests/frontend_test.php`, antes da linha `echo "\nTodos os testes de frontend passaram.\n";`, inserir:

```php
// ---- Tema: variaveis ----

// As 8 que o Chart.js le via cssVar(). Se alguma sumir, o grafico renderiza
// com cor vazia e o bug so aparece a olho nu.
preg_match_all('/cssVar\("(--[a-z0-9-]+)"\)/', $js, $m);
foreach (array_unique($m[1]) as $var) {
    afirmar((bool) preg_match('/^\s*' . preg_quote($var, '/') . '\s*:/m', $css),
        "variavel {$var}, lida pelo app.js, existe no style.css");
}

// Variaveis do tema antigo, removidas.
foreach (['--glow', '--grad', '--grad-btn', '--primary', '--primary-dark',
          '--primary-soft', '--series-1', '--baseline'] as $morta) {
    afirmar(!str_contains($css, $morta),
        "variavel do tema antigo {$morta} nao aparece mais no style.css");
}

// Variaveis do tema novo.
foreach (['--page', '--surface-1', '--surface-2', '--surface-3', '--border',
          '--border-strong', '--text-primary', '--text-secondary', '--muted',
          '--gold', '--gold-soft', '--gold-dim', '--gold-border', '--on-gold',
          '--good', '--bad', '--radius', '--radius-sm'] as $nova) {
    afirmar((bool) preg_match('/^\s*' . preg_quote($nova, '/') . '\s*:/m', $css),
        "variavel do tema novo {$nova} definida");
}

// ---- Tema: sem residuo roxo/ciano nem glow ----

afirmar(!preg_match('/108,\s*99,\s*255/', $css), 'sem literal roxo #6C63FF em rgba()');
afirmar(!preg_match('/0,\s*212,\s*255/', $css), 'sem literal ciano #00D4FF em rgba()');
afirmar(!preg_match('/#6C63FF|#4f46e5|#a5a0ff|#00D4FF/i', $css), 'sem hex do tema antigo');
afirmar(!str_contains($css, 'body::before'), 'halo radial roxo do fundo removido');
afirmar(!preg_match('/linear-gradient/', $css), 'nenhum gradiente sobrou no CSS');

// A regra do dourado: nada de glow. Sombras difusas so as pretas de
// elevacao (dialog, combo, popover), nunca coloridas.
preg_match_all('/box-shadow:[^;]+;/', $css, $m);
foreach ($m[0] as $sombra) {
    afirmar(!preg_match('/212,\s*175,\s*55/', $sombra),
        'box-shadow sem dourado (regra do dourado racionado): ' . trim($sombra));
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
php tests/frontend_test.php
```

Esperado: `FALHOU: variavel do tema antigo --glow nao aparece mais no style.css`, saída 1.

- [ ] **Step 3: Substituir o bloco `:root`**

Trocar as linhas 1–31 de `style.css` (do comentário `/* ===== Tema BuilderTech...` até o `}` que fecha o `:root`) por:

```css
/* ===== Tema preto & dourado =====
   O dourado e racionado: so estado ativo, acao primaria, anel de foco e a
   serie principal do grafico. Nunca em texto de corpo, nunca em gradiente,
   nunca em sombra difusa. */
:root {
  --page: #0A0A0B;
  --surface-1: #121213;   /* cards, menu lateral */
  --surface-2: #1A1A1C;   /* inputs, cabecalho de tabela */
  --surface-3: #232326;   /* hover */
  --text-primary: #EDEDEF;
  --text-secondary: #9A9AA2;
  --muted: #6E6E76;
  --border: rgba(255, 255, 255, 0.07);
  --border-strong: rgba(255, 255, 255, 0.12);
  --grid: rgba(255, 255, 255, 0.07);

  --gold: #D4AF37;                          /* 9.4:1 sobre --page (WCAG AAA) */
  --gold-soft: #E5C76B;
  --gold-dim: rgba(212, 175, 55, 0.10);
  --gold-border: rgba(212, 175, 55, 0.35);
  --on-gold: #0A0A0B;                       /* 9.4:1 sobre --gold */

  --radius: 12px;
  --radius-sm: 8px;

  /* usados pelos graficos (Chart.js le via getComputedStyle) */
  --series-receita: #3FB950;
  --series-despesa: #E5534B;
  --seq-450: #D4AF37;
  --seq-300: #8A7A45;   /* dourado dessaturado: 2o segmento do donut */
  --accent: #8A7A45;
  --good: #3FB950;
  --bad: #E5534B;
}
```

- [ ] **Step 4: Remover o halo radial do fundo**

Apagar o bloco inteiro `body::before { ... }` (o comentário `/* brilho suave de marca no topo da página */` e as ~13 linhas seguintes, originalmente 42–55).

- [ ] **Step 5: Remapear os call sites**

Aplicar exatamente estas substituições em `style.css`:

| Antes | Depois |
|---|---|
| `.auth-card` → `box-shadow: var(--glow);` | apagar a linha |
| `.auth-tabs button.active { background: var(--grad-btn); color: #fff; box-shadow: var(--glow); }` | `.auth-tabs button.active { background: var(--surface-3); color: var(--text-primary); }` |
| `.auth-error` → `background: rgba(255, 95, 87, 0.15);` | `background: rgba(229, 83, 75, 0.15);` |
| `.auth-error` → `border: 1px solid rgba(255, 95, 87, 0.4);` | `border: 1px solid rgba(229, 83, 75, 0.4);` |
| `select:focus, input:focus, textarea:focus { outline: none; border-color: var(--primary); }` | `select:focus, input:focus, textarea:focus { outline: none; border-color: var(--gold-border); }` |
| `.tile:hover { border-color: rgba(108, 99, 255, 0.4); box-shadow: 0 8px 40px rgba(108, 99, 255, 0.12); }` | `.tile:hover { border-color: var(--border-strong); }` |
| `.bulkbar` → `background: linear-gradient(135deg, rgba(108, 99, 255, 0.12), rgba(0, 212, 255, 0.05));` | `background: var(--gold-dim);` |
| `.bulkbar` → `border: 1px solid rgba(108, 99, 255, 0.3);` | `border: 1px solid var(--gold-border);` |
| `.chk input { ... accent-color: var(--primary); }` | `.chk input { ... accent-color: var(--gold); }` |
| `.btn.primary { background: var(--grad-btn); border: none; color: #fff; }` | `.btn.primary { background: var(--gold); border: 1px solid var(--gold); color: var(--on-gold); font-weight: 600; }` |
| `.btn.primary:hover { box-shadow: var(--glow); filter: brightness(1.08); }` | `.btn.primary:hover { background: var(--gold-soft); border-color: var(--gold-soft); }` |
| `.btn.danger:hover { border-color: rgba(255, 95, 87, 0.5); background: rgba(255, 95, 87, 0.08); }` | `.btn.danger:hover { border-color: rgba(229, 83, 75, 0.5); background: rgba(229, 83, 75, 0.08); }` |
| `dialog` → `box-shadow: var(--glow);` | `box-shadow: 0 24px 60px rgba(0, 0, 0, .6);` |
| `.combo-list li:hover, .combo-list li.marcado { background: var(--primary); color: #fff; }` | `.combo-list li:hover, .combo-list li.marcado { background: var(--gold-dim); color: var(--text-primary); }` |
| `.th-arrow { font-size: 10px; color: var(--primary-soft); }` | `.th-arrow { font-size: 10px; color: var(--gold); }` |
| `.th-filter.ativo { color: #fff; background: var(--primary); }` | `.th-filter.ativo { color: var(--on-gold); background: var(--gold); }` |
| `.filtro-itens input, .filtro-todos input { accent-color: var(--primary); flex: 0 0 auto; }` | `.filtro-itens input, .filtro-todos input { accent-color: var(--gold); flex: 0 0 auto; }` |
| `.btn:hover { border-color: rgba(255, 255, 255, 0.2); background: rgba(255, 255, 255, 0.06); }` | `.btn:hover { border-color: var(--border-strong); background: var(--surface-3); }` |

O `#tabs button.active` (linha 197) é tratado na Task 3, junto com o resto da barra lateral. Por ora, trocar só para não quebrar:

```css
#tabs button.active { background: var(--gold-dim); color: var(--text-primary); }
```

- [ ] **Step 6: Rodar o teste e confirmar que passa**

```bash
php tests/frontend_test.php
```

Esperado: todos `ok:`.

- [ ] **Step 7: Conferir os gráficos no navegador**

Com `docker compose up -d`, entrar no app e abrir Dashboard e Fluxo. Confirmar: barras de receita verdes e despesa vermelhas, linha de saldo dourada, donut com dourado + dourado dessaturado, grid e rótulos legíveis sobre o preto. Nenhum gráfico com traço invisível.

- [ ] **Step 8: Commit**

```bash
git add public/static/style.css tests/frontend_test.php
git commit -m "feat: substitui o tema roxo/ciano pela paleta preto e dourado"
```

---

## Task 3: Ícones e limpeza do `index.html`

Remove todos os emojis do HTML e reconstrói o estado ativo da barra lateral.

**Files:**
- Modify: `public/static/index.html` (linhas 17, 33, 45, 56, 62, 74–98, 103–108, 159–160, 165–166, e os 9 botões `+ Adicionar`)
- Modify: `public/static/style.css` (`.side-ico`, `#tabs button`, `.menu-toggle`, `.userbox`)
- Modify: `tests/frontend_test.php`

**Interfaces:**
- Consumes: os 23 `<symbol>` da Task 1; as variáveis de tema da Task 2.
- Produces: `<span id="user-name">` passa a conter **só o nome**, sem o prefixo `"👤 "` — a Task 4 depende disso.

- [ ] **Step 1: Escrever as asserções que falham**

Em `tests/frontend_test.php`, antes do `echo` final:

```php
// ---- index.html sem emoji ----

$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';
afirmar(!preg_match($emoji, $html), 'index.html nao contem nenhum emoji');

// Todo <use href="#i-*"> aponta para um symbol que existe.
preg_match_all('/href="#(i-[a-z-]+)"/', $html, $m);
afirmar(count($m[1]) > 0, 'index.html usa icones do sprite');
foreach (array_unique($m[1]) as $usado) {
    afirmar(in_array($usado, $definidos, true), "icone {$usado} usado no HTML existe no sprite");
}

// Os 9 itens de navegacao tem icone.
preg_match('/<nav id="tabs">(.*?)<\/nav>/s', $html, $nav);
afirmar(isset($nav[1]), 'bloco <nav id="tabs"> encontrado');
afirmar(substr_count($nav[1], '<use href="#i-') === 9, 'os 9 itens do menu lateral tem icone');

// O nome do usuario e um span proprio: app.js escreve nele via textContent
// e nao pode mais carregar o emoji junto.
afirmar((bool) preg_match('/<span id="user-name"><\/span>/', $html),
    '#user-name e um span vazio (icone fica fora dele)');
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
php tests/frontend_test.php
```

Esperado: `FALHOU: index.html nao contem nenhum emoji`, saída 1.

- [ ] **Step 3: Trocar os emojis dos títulos de autenticação**

```
linha  17: <h1>💰 Minhas Contas</h1>
       ->  <h1><svg class="ico ico-lg" aria-hidden="true"><use href="#i-wallet"/></svg> Minhas Contas</h1>
linha  33: <h1>📧 Confirme seu e-mail</h1>
       ->  <h1><svg class="ico ico-lg" aria-hidden="true"><use href="#i-mail"/></svg> Confirme seu e-mail</h1>
linha  45: <h1>🔐 Proteja sua conta</h1>
       ->  <h1><svg class="ico ico-lg" aria-hidden="true"><use href="#i-lock"/></svg> Proteja sua conta</h1>
linha  56: <h1>🗝️ Códigos de backup</h1>
       ->  <h1><svg class="ico ico-lg" aria-hidden="true"><use href="#i-key"/></svg> Códigos de backup</h1>
linha  62: <h1>🔐 Código de segurança</h1>
       ->  <h1><svg class="ico ico-lg" aria-hidden="true"><use href="#i-lock"/></svg> Código de segurança</h1>
```

- [ ] **Step 4: Reescrever a marca e a navegação lateral**

Trocar o bloco `<aside id="sidebar"> ... </aside>` (linhas 74–98) por:

```html
<aside id="sidebar">
  <div class="side-brand">
    <svg class="ico" aria-hidden="true"><use href="#i-wallet"/></svg>
    <span class="side-label">Minhas Contas</span>
  </div>
  <nav id="tabs">
    <button data-tab="dashboard" class="active" title="Dashboard">
      <svg class="ico" aria-hidden="true"><use href="#i-dashboard"/></svg><span class="side-label">Dashboard</span></button>
    <button data-tab="lancamentos" title="Lançamentos">
      <svg class="ico" aria-hidden="true"><use href="#i-list"/></svg><span class="side-label">Lançamentos</span></button>
    <button data-tab="pessoas" title="Por Pessoa">
      <svg class="ico" aria-hidden="true"><use href="#i-users"/></svg><span class="side-label">Por Pessoa</span></button>
    <button data-tab="fluxo" title="Fluxo">
      <svg class="ico" aria-hidden="true"><use href="#i-trend"/></svg><span class="side-label">Fluxo</span></button>
    <button data-tab="patrimonio" title="Patrimônio">
      <svg class="ico" aria-hidden="true"><use href="#i-landmark"/></svg><span class="side-label">Patrimônio</span></button>
    <button data-tab="projetos" title="Projetos">
      <svg class="ico" aria-hidden="true"><use href="#i-target"/></svg><span class="side-label">Projetos</span></button>
    <button data-tab="registro" title="Registro">
      <svg class="ico" aria-hidden="true"><use href="#i-inbox"/></svg><span class="side-label">Registro</span></button>
    <button data-tab="configuracao" title="Configuração">
      <svg class="ico" aria-hidden="true"><use href="#i-settings"/></svg><span class="side-label">Configuração</span></button>
    <button data-tab="admin" id="tab-admin-btn" title="Admin" hidden>
      <svg class="ico" aria-hidden="true"><use href="#i-shield"/></svg><span class="side-label">Admin</span></button>
  </nav>
</aside>
```

Os atributos `data-tab`, `id="tab-admin-btn"` e `class="active"` do primeiro botão são preservados — o `app.js` depende deles.

- [ ] **Step 5: Trocar o hambúrguer e separar o nome do usuário**

Trocar o bloco `<header class="topbar"> ... </header>` (linhas 103–110) por:

```html
<header class="topbar">
  <button id="menu-toggle" class="menu-toggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">
    <svg class="ico" aria-hidden="true"><use href="#i-menu"/></svg>
  </button>
  <span class="topbar-brand">Minhas Contas</span>
  <div class="userbox">
    <svg class="ico ico-sm" aria-hidden="true"><use href="#i-user"/></svg>
    <span id="user-name"></span>
    <button class="btn small" id="logout-btn">Sair</button>
  </div>
</header>
```

- [ ] **Step 6: Trocar os emojis dos botões de ação e o `+`**

```
linha 159: <button class="btn" id="l-clearfilters" hidden>✖ Limpar filtros de coluna</button>
       ->  <button class="btn" id="l-clearfilters" hidden><svg class="ico" aria-hidden="true"><use href="#i-x"/></svg> Limpar filtros de coluna</button>
linha 160: <button class="btn primary" id="l-new">+ Novo lançamento</button>
       ->  <button class="btn primary" id="l-new"><svg class="ico" aria-hidden="true"><use href="#i-plus"/></svg> Novo lançamento</button>
linha 165: <button class="btn primary" id="l-bulkedit">✏️ Alterar selecionados</button>
       ->  <button class="btn primary" id="l-bulkedit"><svg class="ico" aria-hidden="true"><use href="#i-pencil"/></svg> Alterar selecionados</button>
linha 166: <button class="btn danger" id="l-bulkdel">🗑 Excluir selecionados</button>
       ->  <button class="btn danger" id="l-bulkdel"><svg class="ico" aria-hidden="true"><use href="#i-trash"/></svg> Excluir selecionados</button>
```

E nos **9 botões `+ Adicionar`** (linhas 238, 242, 246, 256, 270, 274, 332, 336, 340), trocar o texto `+ Adicionar` por:

```html
<svg class="ico" aria-hidden="true"><use href="#i-plus"/></svg> Adicionar
```

mantendo intactos os atributos `data-add="..."` / `id="s-newcat"` de cada um.

- [ ] **Step 7: Ajustar o CSS da barra lateral e da topbar**

Em `style.css`, trocar a regra `.side-ico` por nada (a classe deixa de existir) e aplicar:

```css
.ico-lg { width: 22px; height: 22px; vertical-align: -5px; }
.ico-sm { width: 15px; height: 15px; }

.side-brand .ico { color: var(--gold); }

#tabs button { position: relative; }
#tabs button:hover { background: var(--surface-2); color: var(--text-primary); }
/* Ativo: fundo discreto + barra dourada de 2px encostada na borda esquerda.
   Fica no ::before posicionado para nao empurrar o conteudo nem mudar a
   altura da linha — sem isso o item "pula" ao trocar de aba. */
#tabs button.active { background: var(--gold-dim); color: var(--text-primary); }
#tabs button.active .ico { color: var(--gold); }
#tabs button.active::before {
  content: "";
  position: absolute;
  left: 0;
  top: 7px;
  bottom: 7px;
  width: 2px;
  border-radius: 0 2px 2px 0;
  background: var(--gold);
}

.menu-toggle { display: none; align-items: center; justify-content: center; }
.userbox .ico { color: var(--text-secondary); }
```

Na media query de 900px, a regra `.menu-toggle { display: flex; }` já existe e continua valendo. Remover do bloco `.menu-toggle` original as propriedades `flex-direction: column;` e `gap: 4px;` e apagar a regra `.menu-toggle span { ... }`, que não tem mais alvo.

- [ ] **Step 8: Rodar o teste e confirmar que passa**

```bash
php tests/frontend_test.php
```

- [ ] **Step 9: Conferir no navegador**

Com o app rodando: as 9 abas com ícone, a barra dourada no item ativo sem deslocar o texto, a marca com carteira dourada, e abaixo de 900px o hambúrguer e a gaveta funcionando.

- [ ] **Step 10: Commit**

```bash
git add public/static/index.html public/static/style.css tests/frontend_test.php
git commit -m "feat: troca os emojis do HTML por icones SVG e refaz o item ativo do menu"
```

---

## Task 4: Ícones no `app.js`

**Files:**
- Modify: `public/static/app.js` (linhas 658–659, 1032–1033, 1152–1153, 1210–1212, 1241, 1296, 1298–1301, 1537)
- Modify: `tests/frontend_test.php`

**Interfaces:**
- Consumes: `ico()` da Task 1; `<span id="user-name">` vazio da Task 3.
- Produces: nada para tasks seguintes.

- [ ] **Step 1: Escrever as asserções que falham**

```php
// ---- app.js sem emoji ----

afirmar(!preg_match($emoji, $js), 'app.js nao contem nenhum emoji');

preg_match_all('/\bico\("([a-z-]+)"\)/', $js, $m);
afirmar(count($m[1]) > 0, 'app.js usa o helper ico()');
foreach (array_unique($m[1]) as $nome) {
    afirmar(in_array('i-' . $nome, $definidos, true), "ico(\"{$nome}\") existe no sprite");
}

// O nome do usuario vai puro no textContent, sem prefixo decorativo.
afirmar(!preg_match('/user-name"\)\.textContent\s*=\s*"[^"]*"\s*\+/', $js),
    '#user-name recebe so o nome, sem prefixo concatenado');
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
php tests/frontend_test.php
```

Esperado: `FALHOU: app.js nao contem nenhum emoji`, saída 1.

- [ ] **Step 3: Trocar os botões das três tabelas**

Linha 658–659 (tabela de lançamentos):

```js
        <button class="btn small" data-edit aria-label="Editar lançamento">${ico("pencil")}</button>
        <button class="btn small danger" data-del aria-label="Excluir lançamento">${ico("trash")}</button>
```

Linha 1032–1033 (tabela genérica do CRUD):

```js
    `<td class="row-actions"><button class="btn small" data-edit="${it.id}" aria-label="Editar">${ico("pencil")}</button>
     <button class="btn small danger" data-del="${it.id}" aria-label="Excluir">${ico("trash")}</button></td></tr>`).join("");
```

Linha 1152–1153 (tabela de classificações):

```js
    <td class="row-actions"><button class="btn small" data-edit="${c.id}" aria-label="Editar classificação">${ico("pencil")}</button>
    <button class="btn small danger" data-del="${c.id}" aria-label="Excluir classificação">${ico("trash")}</button></td></tr>`).join("");
```

- [ ] **Step 4: Trocar a mensagem de importação**

`#imp-result` recebe hoje uma string com ✅ via `textContent`, que não aceita markup. Trocar as linhas 1210–1212 por:

```js
    // textContent nao aceita markup: monta o icone via innerHTML e escreve o
    // texto no span, preservando o escape automatico dos valores.
    result.innerHTML = ico("check") + '<span class="msg"></span>';
    result.querySelector(".msg").textContent =
      `Importado: ${data.lancamentos} lançamentos, ${data.categorias_novas} categorias novas, ` +
      `${data.projetos} projetos, ${data.investimentos} investimentos, ${data.bens} bens, ${data.dividas} dívidas.`;
```

Atenção: o caminho de erro logo acima (`result.textContent = "Erro: " + ...`) atribui `textContent` num elemento que agora pode ter filhos — isso limpa os filhos corretamente, então não precisa mudar.

- [ ] **Step 5: Trocar o painel admin**

Linha 1296 (coluna "admin"):

```js
    <td>${u.is_admin ? ico("check") : "—"}</td>
```

Linhas 1298–1301 (os quatro botões de ação):

```js
      <button class="btn small" data-pw="${u.id}" title="Redefinir senha" aria-label="Redefinir senha">${ico("key")}</button>
      <button class="btn small" data-mfa="${u.id}" title="Resetar MFA" aria-label="Resetar MFA">${ico("refresh")}</button>
      <button class="btn small" data-flag="${u.id}" title="${u.is_admin ? "Rebaixar" : "Tornar admin"}" aria-label="${u.is_admin ? "Rebaixar" : "Tornar admin"}">${u.is_admin ? ico("arrow-down") : ico("arrow-up")}</button>
      <button class="btn small danger" data-del="${u.id}" title="Excluir conta" aria-label="Excluir conta"${u.eu ? " disabled" : ""}>${ico("trash")}</button>
```

- [ ] **Step 6: Tirar o prefixo 👤 do nome do usuário**

Linha 1241:

```js
  $("#user-name").textContent = data.nome;
```

Linha 1537:

```js
  $("#user-name").textContent = usuario;
```

O ícone de usuário já vive no `index.html` ao lado do span, adicionado na Task 3.

- [ ] **Step 7: Estilizar a mensagem de importação**

Em `style.css`, no fim do arquivo:

```css
#imp-result { display: flex; align-items: center; gap: 8px; font-size: 13px; }
#imp-result .ico { color: var(--good); }
```

- [ ] **Step 8: Rodar o teste e confirmar que passa**

```bash
php tests/frontend_test.php
```

- [ ] **Step 9: Conferir no navegador**

Abrir Lançamentos e conferir lápis/lixeira nas linhas; abrir Patrimônio e Configuração (tabelas do CRUD genérico); fazer um import na aba Registro e ver a mensagem com o check verde; se a conta for admin, abrir Admin e conferir os quatro botões e a coluna de admin. Conferir também que o nome no canto superior direito aparece sem emoji.

- [ ] **Step 10: Commit**

```bash
git add public/static/app.js public/static/style.css tests/frontend_test.php
git commit -m "feat: troca os emojis gerados pelo JS por icones SVG"
```

---

## Task 5: Foco visível e polimento final

**Files:**
- Modify: `public/static/style.css`
- Modify: `tests/frontend_test.php`

**Interfaces:**
- Consumes: tudo das Tasks 1–4.
- Produces: nada.

- [ ] **Step 1: Escrever as asserções que falham**

```php
// ---- Acessibilidade e polimento ----

// Todo botao cujo conteudo visivel e so um icone precisa de rotulo
// acessivel. Deteccao por lista explicita em vez de regex generica: os
// botoes so-icone sao conhecidos e finitos, e um regex que tenta casar
// "<button> cujo unico filho e um svg" gera falso positivo em botao com
// icone + texto.
$soIcone = [
    ['app.js', '/data-edit(?:="\$\{[a-z]+\.id\}")?[^>]*aria-label=/', 'botao de editar linha'],
    ['app.js', '/data-del="\$\{[a-z]+\.id\}"[^>]*aria-label=/',        'botao de excluir linha'],
    ['app.js', '/data-pw="[^"]*"[^>]*aria-label=/',                       'botao de redefinir senha'],
    ['app.js', '/data-mfa="[^"]*"[^>]*aria-label=/',                      'botao de resetar MFA'],
    ['app.js', '/data-flag="[^"]*"[^>]*aria-label=/',                     'botao de promover/rebaixar'],
    ['index.html', '/id="menu-toggle"[^>]*aria-label=/',                  'botao hamburguer'],
];
foreach ($soIcone as [$arquivo, $padrao, $descricao]) {
    $conteudo = $arquivo === 'app.js' ? $js : $html;
    afirmar((bool) preg_match($padrao, $conteudo),
        "{$descricao} ({$arquivo}) tem aria-label");
}

// E nenhum botao pode ter ficado com o icone como unico conteudo sem rotulo:
// varre os data-* de acao no app.js e exige aria-label em todos.
preg_match_all('/<button\b[^>]*data-(?:edit|del|pw|mfa|flag)\b[^>]*>/', $js, $m);
afirmar(count($m[0]) >= 8, 'encontrou os botoes de acao gerados pelo JS');
foreach ($m[0] as $tag) {
    afirmar(str_contains($tag, 'aria-label='),
        'botao de acao com aria-label: ' . trim(substr($tag, 0, 60)));
}

// Anel de foco visivel — hoje so inputs reagiam ao foco.
afirmar(str_contains($css, ':focus-visible'), 'style.css define anel de :focus-visible');
afirmar((bool) preg_match('/:focus-visible[^{]*\{[^}]*outline[^}]*var\(--gold\)/', $css),
    'anel de foco usa --gold');

// Numeros nao mudam de largura a cada atualizacao.
afirmar((bool) preg_match('/\.tile-value[^{]*\{[^}]*tabular-nums/', $css),
    '.tile-value usa tabular-nums');
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
php tests/frontend_test.php
```

Esperado: `FALHOU: style.css define anel de :focus-visible`, saída 1. Se algum `aria-label` tiver escapado nas Tasks 3–4, essas asserções falham antes — corrigir o botão apontado.

- [ ] **Step 3: Adicionar o anel de foco**

Em `style.css`, logo depois da regra `select:focus, input:focus, textarea:focus`:

```css
/* Anel de foco unico para tudo que e focavel. Antes so input/select mudavam
   a borda no :focus, e botoes e abas nao tinham indicacao nenhuma —
   navegar por teclado era as cegas. :focus-visible nao aparece no clique de
   mouse, so na navegacao por teclado. */
:focus-visible {
  outline: 2px solid var(--gold);
  outline-offset: 2px;
  border-radius: var(--radius-sm);
}
```

- [ ] **Step 4: Aplicar o polimento restante**

```css
.tile-value { font-variant-numeric: tabular-nums; }
```

E ajustar a regra existente `.tile-value` para manter `font-size: 27px; font-weight: 700; letter-spacing: -0.02em;` junto da nova propriedade, em vez de duplicar o seletor.

Trocar também:

```css
th { background: var(--surface-2); }   /* ja e o valor atual — conferir que continua */
tr:hover td { background: rgba(255, 255, 255, 0.02); }   /* mantem */
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

```bash
php tests/frontend_test.php
```

- [ ] **Step 6: Varredura final de emoji**

```bash
grep -nP '[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]' public/static/index.html public/static/app.js
```

Esperado: nenhuma saída (código 1).

- [ ] **Step 7: Verificação manual completa no navegador**

Com `docker compose up -d`, autenticado, percorrer:

1. as 9 abas — ícone renderizado, barra dourada no ativo, nenhum emoji;
2. os 5 gráficos (Dashboard ×3, Fluxo, Por Pessoa) — cores novas, legendas legíveis;
3. Lançamentos — botões de linha, barra de seleção em massa, filtro de coluna (`.th-filter.ativo` dourado), limpar filtros, modal de edição;
4. as 5 telas de auth — login, cadastro, verificação de e-mail, setup de MFA com QR, códigos de backup, verificação de MFA;
5. Admin — coluna de admin e os 4 botões por linha;
6. abaixo de 900px — hambúrguer, gaveta, backdrop, filtros empilhados;
7. `Tab` percorrendo menu lateral, filtros e botões, com o anel dourado visível em cada parada;
8. console do navegador sem erro.

- [ ] **Step 8: Commit**

```bash
git add public/static/style.css tests/frontend_test.php
git commit -m "feat: adiciona anel de foco visivel e finaliza o polimento do tema"
```

---

## Self-review (feito na escrita do plano)

- **Cobertura do spec:** §1 paleta → Task 2. §2 sprite e mapeamento → Tasks 1, 3, 4. §2 acessibilidade → Task 5 (asserção) + `aria-label` aplicados nas Tasks 3–4. §3 estado ativo → Task 3 Step 7. §4 limpeza estrutural → Tasks 2 e 5. §5 fora de escopo → Global Constraints. §6 verificação → Task 5 Step 7.
- **Consistência de nomes:** `ico(nome)` recebe o nome **sem** o prefixo `i-`; o helper monta `#i-${nome}`. Os testes que cruzam `ico("check")` com `i-check` compensam isso somando o prefixo. `$definidos` é criado na Task 1 e reutilizado nas Tasks 3 e 4 — o arquivo de teste é lido de cima para baixo, então a variável está em escopo.
- **Detecção de botão só-ícone (Task 5):** feita por lista explícita dos `data-*` de ação, não por um regex genérico de "`<button>` cujo único filho é um `<svg>`" — esse último gera falso positivo em botão com ícone + texto (`#l-new`, `#l-bulkedit`), que não precisa de `aria-label` porque já tem texto visível.
