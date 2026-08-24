# Repaginada do frontend: tema preto & dourado e sistema de ícones SVG

Data: 2026-08-24

## Contexto

O frontend do `minhas-contas` é vanilla e mora inteiro em três arquivos sob
`public/static/`: `index.html` (400 linhas), `app.js` (1562) e `style.css`
(511). Não há build, bundler nem framework — o HTML é servido estático e o JS
monta as tabelas com template literals e `innerHTML`. As dependências
(`chart.umd.js`, `qrcode.js`) são self-hosted em `public/static/vendor/`.

O tema atual ("BuilderTech") é dark roxo/ciano e usa recursos que puxam o
visual para um registro chamativo, não profissional:

- gradientes (`--grad`, `--grad-btn`) no botão primário, na aba de
  autenticação ativa e no item ativo da sidebar;
- `--glow` (halo roxo de 40px) aplicado junto com esses gradientes;
- `body::before`: um brilho radial roxo fixo de 900×500px no topo da página;
- hover dos tiles com sombra roxa de 40px.

E toda a iconografia é emoji nativo do sistema — 9 na navegação lateral
(📊 🧾 👥 📈 🏦 🎯 📥 ⚙️ 🛡️), mais 💰 na marca e nas telas de auth, e
✏️ 🗑 ✖ ✅ 🔑 🔁 ⬆️ ⬇️ 👤 espalhados em botões de ação, barra de seleção e
painel admin. Emoji renderiza diferente em cada SO, não herda a cor do texto,
não tem controle de peso de traço e carrega uma conotação informal — é a
origem direta da queixa de que a sidebar parece "de criança".

Duas restrições descobertas na exploração e que moldam o plano:

1. `app.js` lê **todas** as cores dos gráficos via `cssVar()`
   (`--text-secondary`, `--grid`, `--series-receita`, `--series-despesa`,
   `--seq-450`, `--seq-300`, `--accent`, `--surface-1`). Retematizar o
   `style.css` retinge os 5 gráficos Chart.js automaticamente, **desde que os
   nomes das variáveis sejam preservados**.
2. `app.js:1241` e `app.js:1537` fazem
   `$("#user-name").textContent = "👤 " + usuario`. `textContent` não aceita
   markup, então esse ponto exige mudança de estrutura no HTML, não só troca
   de string.

## Objetivo

Visual mais limpo e profissional, em preto e dourado, com iconografia SVG
coerente — **sem alterar nenhuma regra de negócio, endpoint ou fluxo**.

## 1. Paleta

Base preta levemente quente. O dourado é racionado: aparece somente em
**estado ativo, ação primária, anel de foco e série principal do gráfico**.
Nunca em texto de corpo, nunca em gradiente, nunca em glow. Essa regra é o
que separa "sóbrio" de "cafona" e vale como critério de revisão.

```
--page            #0A0A0B    preto quente
--surface-1       #121213    cards, sidebar
--surface-2       #1A1A1C    inputs, cabeçalho de tabela
--surface-3       #232326    hover
--border          rgba(255,255,255,.07)
--border-strong   rgba(255,255,255,.12)

--text-primary    #EDEDEF
--text-secondary  #9A9AA2
--muted           #6E6E76

--gold            #D4AF37    acento único
--gold-soft       #E5C76B    hover
--gold-dim        rgba(212,175,55,.10)   fundo do item ativo
--gold-border     rgba(212,175,55,.35)
--on-gold         #0A0A0B    texto sobre preenchimento dourado

--good            #3FB950    receita (menos neon que o #22c55e atual)
--bad             #E5534B    despesa
```

Contraste verificado: `#D4AF37` sobre `#0A0A0B` = **9.4:1**, e `#0A0A0B`
sobre `#D4AF37` = **9.4:1**. Ambos passam WCAG AA e AAA para texto normal.

**Removidos:** `--grad`, `--grad-btn`, `--glow`, `--primary`,
`--primary-dark`, `--primary-soft`, e o bloco `body::before` inteiro.

**Preservados por nome** (só os valores mudam), porque `app.js` os consome:
`--grid`, `--baseline`, `--series-1`, `--series-receita`, `--series-despesa`,
`--seq-450`, `--seq-300`, `--good`, `--bad`, `--accent`, `--surface-1`,
`--text-secondary`. Novo mapeamento dos gráficos: `--series-1`/`--seq-450`
= `--gold`; `--seq-300` e `--accent` = `#8A7A45` (dourado dessaturado, para
o segundo segmento do donut continuar distinguível do primeiro sem
introduzir uma cor nova).

## 2. Sistema de ícones

Sprite SVG inline no início do `<body>` do `index.html`: um
`<svg hidden aria-hidden="true">` contendo `<symbol id="i-*"
viewBox="0 0 24 24">`. Todos os traçados usam `stroke="currentColor"`,
`fill="none"`, `stroke-width="1.5"`, `stroke-linecap="round"`,
`stroke-linejoin="round"` — geometria no estilo Lucide, desenhada à mão no
próprio arquivo.

Decisão: sprite inline em vez de biblioteca de ícones ou fonte de ícones.
Motivos: zero requisição extra e zero dependência nova, funciona offline,
herda a cor do texto via `currentColor` (essencial para o estado ativo
dourado) e acompanha o padrão self-hosted já adotado em `vendor/`.

Consumo no HTML e nos template literals do JS:
`<svg class="ico" aria-hidden="true"><use href="#i-dashboard"/></svg>`.

Helper em `app.js` para os pontos gerados dinamicamente, que devolve a
string do SVG pronta para concatenar no template literal:

```js
const ico = (nome) =>
  `<svg class="ico" aria-hidden="true"><use href="#i-${nome}"/></svg>`;
```

### Mapeamento — navegação

| Emoji atual | `symbol` | Desenho |
|---|---|---|
| 📊 Dashboard | `i-dashboard` | grade de 4 painéis |
| 🧾 Lançamentos | `i-list` | lista de linhas |
| 👥 Por Pessoa | `i-users` | duas silhuetas |
| 📈 Fluxo | `i-trend` | linha ascendente com eixos |
| 🏦 Patrimônio | `i-landmark` | frontão com colunas |
| 🎯 Projetos | `i-target` | círculos concêntricos |
| 📥 Registro | `i-inbox` | bandeja com seta |
| ⚙️ Configuração | `i-settings` | engrenagem de 8 dentes |
| 🛡️ Admin | `i-shield` | escudo |
| 💰 marca | `i-wallet` | carteira, em `--gold` |

### Mapeamento — resto do app

| Emoji atual | Onde | `symbol` |
|---|---|---|
| 💰 | `#auth-card h1` | `i-wallet` |
| 📧 | `#verify-card h1` | `i-mail` |
| 🔐 | `#mfa-setup-card h1`, `#mfa-verify-card h1` | `i-lock` |
| 🗝️ | `#mfa-backup-card h1` | `i-key` |
| ✖ | `#l-clearfilters` | `i-x` |
| ✏️ | `#l-bulkedit`; `app.js` 658, 1032, 1152 | `i-pencil` |
| 🗑 | `#l-bulkdel`; `app.js` 659, 1033, 1153, 1301 | `i-trash` |
| ✅ | `app.js` 1211 (msg de import), 1296 (coluna admin) | `i-check` |
| 🔑 | `app.js` 1298 | `i-key` |
| 🔁 | `app.js` 1299 | `i-refresh` |
| ⬆️ ⬇️ | `app.js` 1300 | `i-arrow-up`, `i-arrow-down` |
| 👤 | `app.js` 1241, 1537 | `i-user` |
| 3× `<span>` | `.menu-toggle` | `i-menu` |

Casos que não são substituição direta:

- **`#user-name`** — hoje recebe `"👤 " + usuario` via `textContent`. Passa a
  ser, no `index.html`, um `<svg class="ico"><use href="#i-user"/></svg>`
  seguido de `<span id="user-name">`; o JS escreve só o nome no `textContent`
  do span. Preserva o escape automático do `textContent` para o nome, que é
  dado do usuário.
- **`app.js:1211`** — o ✅ está numa string atribuída a
  `result.textContent`, que não aceita markup. O elemento `#imp-result` passa
  a ser montado em duas etapas, preservando o escape do texto:

  ```js
  result.innerHTML = ico("check") + '<span class="msg"></span>';
  result.querySelector(".msg").textContent = `Importado: ${data.lancamentos} ...`;
  ```
- **`app.js:1296`** — `${u.is_admin ? "✅" : "—"}` numa célula de tabela
  montada por `innerHTML`: vira `ico("check")` / `—`.
- **O `+` textual** — `#l-new` ("+ Novo lançamento") e os 9 botões
  "+ Adicionar" do `index.html` trocam o caractere `+` por `i-plus`. A
  legenda de tabela vazia em `app.js:1035` (`use “+ Adicionar”`) *cita* o
  rótulo em prosa e permanece como texto puro.

### Acessibilidade

Os botões `✏️` / `🗑` das tabelas hoje têm o emoji como único conteúdo e
**nenhum rótulo acessível**. Ao virarem SVG puro, cada botão só-ícone recebe
`aria-label` explícito ("Editar", "Excluir", "Redefinir senha", "Resetar
MFA", "Tornar admin" / "Rebaixar"). Todo `<svg class="ico">` decorativo
recebe `aria-hidden="true"`. Isso é uma melhoria líquida sobre o estado atual.

## 3. Estado ativo da sidebar

Substitui `background: var(--grad-btn); box-shadow: var(--glow)` por:

- fundo `--gold-dim`;
- texto `--text-primary`, ícone `--gold`;
- barra indicadora de 2px em `--gold` na borda esquerda, via `::before`
  posicionado absolutamente — não desloca o conteúdo nem muda a altura da
  linha, então nada "pula" ao trocar de aba.

Hover permanece sem cor: `background: var(--surface-2)`.

O mesmo tratamento vale para `.auth-tabs button.active`, que hoje usa o
mesmo gradiente + glow.

## 4. Limpeza estrutural

- `.btn.primary`: preenchimento `--gold` sólido, texto `--on-gold`. Some o
  gradiente.
- `:focus-visible` com anel de 2px em `--gold` em tudo que é focável. Hoje
  só `input`/`select`/`textarea` reagem, e apenas trocando a cor da borda no
  `:focus` — botões e abas não têm indicação de foco nenhuma.
- `.tile`: hover deixa de ter sombra roxa de 40px e passa a subir só a borda
  para `--border-strong`. `.tile-value` ganha
  `font-variant-numeric: tabular-nums` para os números não mudarem de largura
  a cada atualização.
- Raio: `--radius` 16px → 12px, `--radius-sm` 10px → 8px.
- Cabeçalho de tabela em `--surface-2`.
- Tela de auth: `.auth-card` perde o `box-shadow: var(--glow)`.

## 5. Fora de escopo

Nada de backend. Nenhum endpoint, regra de negócio, fluxo de autenticação,
migração ou consulta muda. Nenhum `id`, `data-*` ou nome de classe consumido
pelo JS é renomeado — as edições em `app.js` se restringem a (a) trocar
emoji por chamada de `ico()` nos template literals, (b) a separação
ícone/nome do `#user-name`, e (c) o helper `ico()` novo. `tests/mfa_test.php`
não exercita frontend e não é afetado.

Não faz parte deste trabalho: modo claro, refatoração do `app.js` em
módulos, troca do Chart.js, ou responsividade além do que já existe.

## 6. Verificação

Subir com `docker compose up` e percorrer manualmente, autenticado:

1. as 9 abas — ícone renderizado, item ativo com barra dourada, sem emoji
   remanescente;
2. os 5 gráficos (Dashboard ×3, Fluxo, Por Pessoa) — cores novas aplicadas,
   legendas e grid legíveis;
3. tabela de Lançamentos — botões de linha, barra de seleção em massa,
   limpar filtros de coluna, modal de edição;
4. as 5 telas de auth (login, cadastro, verificação de e-mail, setup de MFA
   com QR, códigos de backup, verificação de MFA);
5. painel admin — coluna de admin, os 4 botões de ação por linha;
6. abaixo de 900px — hambúrguer, gaveta lateral, backdrop, filtros
   empilhados;
7. navegação por teclado — `Tab` percorrendo sidebar, filtros e botões com
   anel de foco dourado visível em todos.

Checagem final: `grep -P '[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]'` em
`index.html` e `app.js` deve retornar vazio.
