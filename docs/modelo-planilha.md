# O modelo de planilha

Um modelo só para as duas pontas: o arquivo que você baixa para preencher é o
mesmo que a exportação gera e o mesmo que a importação lê de volta.

Em **Configuração → Planilha**:

| Botão | O que sai |
|---|---|
| Modelo em branco | `modelo-minhas-contas.xlsx` — cabeçalhos, instruções e um exemplo preenchido |
| Meus dados de hoje | `minhas-contas-AAAA-MM-DD.xlsx` — a conta inteira, no mesmo modelo |

Pela linha de comando: `php bin/export_backup.php --modelo destino.xlsx` e
`php bin/export_backup.php <email-ou-usuario> destino.xlsx`.

## As regras

1. **Cabeçalho na linha 1, dados a partir da linha 2**, começando na coluna A.
2. **A coluna é encontrada pelo nome, não pela posição.** Dá para reordenar as
   colunas, apagar as que não interessam e escrever "INSTITUICAO" sem acento —
   maiúsculas, acentos e pontuação são ignorados na comparação.
3. **Aba que você não usa pode ficar vazia ou ser apagada.** Na prática só a
   aba `Lançamentos` é indispensável.
4. **Colunas marcadas com `*` são obrigatórias.** Linha sem elas é ignorada em
   silêncio — é o que deixa subtotal e anotação no meio da tabela sem estragar
   a importação.
5. **Datas em DD/MM/AAAA** (ISO e o formato de data do próprio Excel também
   funcionam).
6. **Valores sempre positivos**: quem diz se entra ou sai é a coluna `Tipo`.
   `R$ 1.234,56`, `1.234,56`, `1234.56` e `(80,00)` (negativo) são todos
   entendidos.
7. **Classificação é derivada**, nunca digitada: `CATEGORIA - Subcategoria`,
   montada pela mesma regra da tela de cadastro. Categoria, subcategoria,
   pessoa e instituição que ainda não existirem são criadas na importação.

## As abas

### Lançamentos

| Coluna | Obrigatória | Observação |
|---|---|---|
| Vencimento | sim | a data que posiciona o lançamento no mês |
| Categoria | sim | ex.: Alimentação |
| Subcategoria | | ex.: Mercado |
| Valor | sim | sempre positivo |
| Tipo | | `RECEITA` ou `DESPESA`; em branco, vale o que estiver em Classificações |
| Status | | `Previsto` ou `Realizado`; em branco, `Previsto` |
| Instituição | | banco, cartão, carteira |
| Pessoa | | de quem é o gasto |
| Data da compra | | quando diferente do vencimento |
| Observação | | texto livre |

### Classificações

`Categoria*`, `Subcategoria`, `Tipo`, `Grupo` (`OPERACIONAL` / `NÃO
OPERACIONAL`), `Meta mensal`.

Preencher é opcional: toda categoria usada em Lançamentos é criada sozinha.
A aba serve para definir tipo, grupo e meta de cada uma.

### Projetos

`Descrição*`, `Valor`, `Ano`, `Prazo`.

### Investimentos, Bens e Dívidas

| Aba | Colunas |
|---|---|
| Investimentos | `Instituição*`, `Ativo`, `Renda`, `Prazo / Projeto`, `Valor` |
| Bens | `Bem*`, `Valor`, `Saldo devedor` |
| Dívidas | `Dívida*`, `Nº de parcelas`, `Valor da parcela`, `Saldo devedor` |

### Instituições e Pessoas

`Nome*` (+ `Tipo`, `Saldo inicial` e `Descrição` nas instituições). Opcionais:
o que aparece nos lançamentos é cadastrado sozinho. Servem para completar o
cadastro — dizer que "Nubank" é um cartão de crédito, por exemplo.

### Parâmetros

Uma linha por parâmetro, rótulo numa coluna e valor na outra:
`Receita mensal`, `Custo de vida mensal` e `Fator da reserva`.

### Instruções e Exemplo

Duas abas de leitura, geradas a partir da definição do modelo. A aba
`Exemplo (não importado)` tem lançamentos preenchidos para copiar; o nome dela
não é reconhecido pelo importador, então nada dali entra na conta.

## Importar

A opção **substituir** apaga lançamentos, projetos, investimentos, bens e
dívidas da conta antes de gravar — é o que permite reimportar o próprio backup
sem duplicar nada. Classificações, pessoas e instituições não são apagadas:
têm nome único e a importação atualiza em vez de repetir. Sem marcar, o
conteúdo da planilha é somado ao que já existe.

A importação também aceita a planilha original **"Controle Financ. Pessoal"**
(aba `LANCAMENTO`, colunas em posição fixa). O formato é detectado pelo nome
das abas do arquivo enviado.

## Onde isso mora no código

| Arquivo | Papel |
|---|---|
| [`src/Modelo.php`](../src/Modelo.php) | a definição do formato — abas, colunas, tipos e ajuda. Fonte única |
| [`src/Exporter.php`](../src/Exporter.php) | escreve a planilha (em branco ou com dados) |
| [`src/Importer.php`](../src/Importer.php) | escolhe o leitor e trata o modelo antigo |
| [`src/Xlsx/Valor.php`](../src/Xlsx/Valor.php) | coerção de célula: "R$ 1.234,56" → `1234.56` |
| [`tests/modelo_test.php`](../tests/modelo_test.php) | ida e volta completa, sem banco |

Acrescentar uma coluna é mexer só em `Modelo::ABAS`: a exportação, a
importação e a aba de instruções saem da mesma definição.
