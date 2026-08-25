-- Investimentos passam a saber que produto são, como rendem e que imposto
-- pagam. Sem estes campos não dá para calcular alíquota (depende do prazo
-- desde a aplicação) nem rendimento mensal (depende do indexador e da taxa).

ALTER TABLE investments
  ADD COLUMN tipo           VARCHAR(40) NOT NULL DEFAULT 'OUTRO',
  ADD COLUMN indexador      VARCHAR(20) NOT NULL DEFAULT 'NENHUM',
  ADD COLUMN taxa           DOUBLE      NOT NULL DEFAULT 0,
  -- Principal aplicado. O IR incide sobre (valor - valor_aplicado), nunca
  -- sobre o total; zero aqui significa "ainda não rendeu nada".
  ADD COLUMN valor_aplicado DOUBLE      NOT NULL DEFAULT 0,
  -- A data da aplicação é o que define a faixa da tabela regressiva.
  ADD COLUMN dt_aplicacao   DATE        NULL,
  ADD COLUMN dt_vencimento  DATE        NULL;
