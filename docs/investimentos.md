# Investimentos: rendimento e imposto

O sistema sabe que produto é cada investimento, quanto ele rende por mês e
quanto o Leão leva. Tudo isso está em [`src/Investimentos.php`](../src/Investimentos.php)
— é o único lugar com regra de imposto no projeto.

Na aba **Patrimônio**, cada investimento mostra:

| Coluna | O que é |
|---|---|
| Produto | CDB, LCI, Tesouro Selic... |
| Rende | "110% do CDI", "IPCA + 6% a.a." |
| % ao mês | quanto o valor cresce por mês, com juros compostos |
| % ao mês líq. | o mesmo, já descontada a faixa de IR de hoje |
| IR/IOF hoje | o que seria cobrado se você resgatasse agora |
| Líquido hoje | o que sobraria no bolso |

E o card **Carteira, impostos e projeção** soma tudo e projeta os próximos
meses, mês a mês.

## Os produtos

| Produto | Classe | IR | IOF < 30 dias | Come-cotas | FGC |
|---|---|---|---|---|---|
| CDB | Renda fixa | regressiva | sim | não | sim |
| LCI / LCA | Renda fixa | **isento** | não | não | sim |
| Tesouro Selic | Renda fixa | regressiva | sim | não | não |
| Tesouro Prefixado | Renda fixa | regressiva | sim | não | não |
| Tesouro IPCA+ | Renda fixa | regressiva | sim | não | não |
| Poupança | Renda fixa | **isento** | não | não | sim |
| CRI / CRA | Renda fixa | **isento** | não | não | não |
| Debênture incentivada | Renda fixa | **isento** | não | não | não |
| Debênture comum | Renda fixa | regressiva | sim | não | não |
| Fundo DI / Renda Fixa | Fundo | regressiva | sim | **sim** | não |
| Fundo multimercado | Fundo | regressiva | sim | **sim** | não |
| Fundo de ações | Renda variável | 15% no resgate | não | não | não |
| Ações | Renda variável | 15% sobre o ganho | não | não | não |
| ETF | Renda variável | 15% sobre o ganho | não | não | não |
| FII | Renda variável | 20% sobre o ganho | não | não | não |
| Previdência (PGBL/VGBL) | Previdência | tabela própria | não | não | não |
| Outro | — | nenhum | não | não | não |

## As tabelas

**IR regressivo da renda fixa** (contado em dias corridos desde a aplicação,
sempre sobre o *rendimento*, nunca sobre o principal):

| Prazo | Alíquota |
|---|---|
| até 180 dias | 22,5% |
| 181 a 360 | 20% |
| 361 a 720 | 17,5% |
| acima de 720 | 15% |

**Previdência**, no regime regressivo: começa em 35% (até 2 anos) e cai de
cinco em cinco pontos a cada dois anos, até 10% depois de dez anos.

**IOF**: resgate antes de 30 dias paga IOF sobre o rendimento, de 96% no
primeiro dia a 0% no trigésimo. O IOF vem **antes** do IR — o imposto de
renda incide sobre o que sobrou. É por isso que "deixe completar um mês" é o
conselho mais repetido da renda fixa, e o sistema mostra o estrago:
um CDB de R$ 10 mil que rendeu R$ 1 mil devolve R$ 10.800 se resgatado no dia
200, e R$ 10.263,50 se resgatado no dia 10.

## Como o rendimento é calculado

O campo **taxa** quer dizer coisas diferentes conforme o **indexador**:

| Indexador | O que a taxa significa | Conta |
|---|---|---|
| CDI | % do CDI — ex.: `110` | `CDI × taxa/100` |
| SELIC | pontos acima da Selic — ex.: `0,05` | `Selic + taxa/100` |
| IPCA | juro real ao ano — ex.: `6` | `(1+IPCA) × (1+taxa/100) − 1` |
| PREFIXADO | % ao ano — ex.: `13,5` | `taxa/100` |
| POUPANÇA | não usa taxa | regra oficial (abaixo) |
| NENHUM | não usa taxa | não projeta |

Inflação e juro real **se multiplicam, não se somam**: IPCA de 4,5% com 6% de
juro real dá 10,77% ao ano, não 10,5%.

A taxa mensal vem por juros compostos, `(1 + anual)^(1/12) − 1`, e não por
divisão por 12 — 12,39% ao ano são 0,978% ao mês, não 1,03%.

**Poupança**: 0,5% ao mês + TR enquanto a Selic está acima de 8,5% ao ano;
abaixo disso, 70% da Selic + TR.

## Come-cotas

Fundos de renda fixa e multimercado pagam IR antecipado em maio e novembro
(15% para carteiras de longo prazo). O dinheiro sai em cotas e deixa de
render, e é essa perda de juros compostos que faz o fundo ficar atrás de um
CDB de mesma taxa. A projeção mostra isso: a coluna **Come-cotas** só aparece
quando existe algum na carteira. No líquido a diferença encolhe — o que foi
antecipado abate o IR do resgate.

## Os índices

CDI, Selic, IPCA e TR ficam em **Configuração → Índices de mercado**, por
usuário. O sistema roda offline e não busca cotação sozinho: os valores que
vêm de fábrica são só um ponto de partida para a conta não sair zerada.
**Confira e atualize** quando o Copom se reunir ou o IPCA sair.

## O que o sistema NÃO calcula

Vale saber onde a conta para:

- **Isenção de R$ 20 mil/mês em ações** — depende de quanto você vendeu no
  mês, que o sistema não acompanha. A alíquota de 15% é sempre aplicada.
- **Day trade** (20%) e **compensação de prejuízo** entre meses.
- **Rendimento mensal de FII**, normalmente isento para pessoa física: os 20%
  do catálogo são o ganho de capital na venda da cota.
- **Taxa de administração e de corretagem**, que reduzem o rendimento real.
- **Marcação a mercado** — um Tesouro Prefixado resgatado antes do vencimento
  pode valer menos que a projeção; a projeção assume que você leva até o fim.
- **Carência** de LCI, LCA e CRI/CRA.

A projeção é uma estimativa com as taxas de hoje, não uma promessa.

## Quando a lei mudar

Imposto muda. Quando mudar, o lugar é [`src/Investimentos.php`](../src/Investimentos.php):

- `IR_RENDA_FIXA` — a tabela regressiva;
- `IR_PREVIDENCIA` — a tabela da previdência;
- `IOF_ATE_30_DIAS` — os 30 dias de IOF;
- `COME_COTAS_LONGO` / `COME_COTAS_CURTO`;
- o campo `ir` de cada produto em `TIPOS` (uma isenção que acabe vira
  `'REGRESSIVA'` numa linha só).

Nada disso está espalhado pelo resto do código — nem no frontend, que recebe
tudo calculado. [`tests/investimentos_test.php`](../tests/investimentos_test.php)
cobre as viradas de faixa e é o que se roda depois de mexer.

## Na planilha

A aba **Investimentos** do modelo de exportação/importação carrega os mesmos
campos: Produto, Indexador, Taxa, Valor aplicado, Valor, Data da aplicação e
Vencimento. O produto vai pelo nome ("Tesouro Selic") e volta pela chave
interna, aceitando também `TESOURO_SELIC` ou `tesouro selic`. Ver
[docs/modelo-planilha.md](modelo-planilha.md).

## No código

| Arquivo | Papel |
|---|---|
| [`src/Investimentos.php`](../src/Investimentos.php) | catálogo, tabelas de imposto, rentabilidade, projeção |
| [`src/Controllers/InvestmentController.php`](../src/Controllers/InvestmentController.php) | `GET /api/investments/summary` |
| [`src/Controllers/CrudController.php`](../src/Controllers/CrudController.php) | CRUD, com os ganchos `normalizar` e `enriquecer` |
| [`src/Controllers/SettingsController.php`](../src/Controllers/SettingsController.php) | os índices por usuário |
| [`migrations/0003_investimentos_impostos.sql`](../migrations/0003_investimentos_impostos.sql) | as colunas novas |
