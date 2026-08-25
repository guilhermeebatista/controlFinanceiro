<?php
declare(strict_types=1);

/**
 * As regras de imposto e de rentabilidade dos investimentos.
 *
 * Sem banco e sem servidor: Investimentos é matemática pura sobre arrays, e
 * é justamente a parte em que um erro passa despercebido — ninguém confere
 * de cabeça se a alíquota do dia 181 caiu de 22,5% para 20%.
 */

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Investimentos;

function afirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: {$mensagem}\n");
        exit(1);
    }
    echo "ok: {$mensagem}\n";
}

/** Comparação de float com folga, para não brigar com o binário. */
function perto(float $esperado, float $obtido, float $tolerancia = 0.0001): bool
{
    return abs($esperado - $obtido) <= $tolerancia;
}

/** Índices de referência dos testes — não são os de hoje, são os do teste. */
const INDICES = ['cdi' => 0.149, 'selic' => 0.15, 'ipca' => 0.045, 'tr' => 0.01];

// =====================================================================
//  Tabela regressiva do IR (renda fixa)
// =====================================================================

// As viradas de faixa são o dia seguinte ao limite: no dia 180 ainda é 22,5%,
// no 181 já é 20%. Errar isso por um dia é o bug clássico deste cálculo.
$faixas = [
    [1, 0.225], [180, 0.225], [181, 0.200], [360, 0.200],
    [361, 0.175], [720, 0.175], [721, 0.150], [5000, 0.150],
];
foreach ($faixas as [$dias, $esperado]) {
    afirmar(perto($esperado, Investimentos::aliquotaIr('CDB', $dias)),
        "CDB com {$dias} dias paga " . ($esperado * 100) . '% de IR');
}

// Isentos: prazo nenhum muda nada.
foreach (['LCI', 'LCA', 'POUPANCA', 'CRI_CRA', 'DEBENTURE_INCENTIVADA'] as $tipo) {
    afirmar(Investimentos::aliquotaIr($tipo, 30) === 0.0, "{$tipo} é isento de IR");
    afirmar(Investimentos::aliquotaIr($tipo, 5000) === 0.0, "{$tipo} continua isento no longo prazo");
}

// Renda variável: alíquota fixa, o prazo não conta.
afirmar(perto(0.15, Investimentos::aliquotaIr('ACOES', 10)), 'ações pagam 15% sobre o ganho');
afirmar(perto(0.15, Investimentos::aliquotaIr('ACOES', 5000)), 'ações continuam em 15% no longo prazo');
afirmar(perto(0.20, Investimentos::aliquotaIr('FII', 800)), 'ganho de capital em FII paga 20%');
afirmar(perto(0.15, Investimentos::aliquotaIr('ETF', 800)), 'ETF paga 15%');

// Previdência tem tabela própria, que começa mais alta e termina mais baixa.
$prev = [[720, 0.35], [721, 0.30], [1440, 0.30], [2160, 0.25], [2880, 0.20], [3600, 0.15], [3601, 0.10]];
foreach ($prev as [$dias, $esperado]) {
    afirmar(perto($esperado, Investimentos::aliquotaIr('PREVIDENCIA', $dias)),
        "previdência com {$dias} dias paga " . ($esperado * 100) . '%');
}
afirmar(Investimentos::aliquotaIr('PREVIDENCIA', 3601) < Investimentos::aliquotaIr('CDB', 3601),
    'previdência de mais de 10 anos fica abaixo dos 15% da renda fixa');

// =====================================================================
//  IOF dos 30 primeiros dias
// =====================================================================

$iof = [[1, 0.96], [10, 0.66], [15, 0.50], [29, 0.03], [30, 0.0], [90, 0.0]];
foreach ($iof as [$dias, $esperado]) {
    afirmar(perto($esperado, Investimentos::aliquotaIof('CDB', $dias)),
        "IOF de CDB resgatado no dia {$dias}: " . ($esperado * 100) . '%');
}
afirmar(Investimentos::aliquotaIof('LCI', 5) === 0.0, 'LCI não tem IOF');
afirmar(Investimentos::aliquotaIof('ACOES', 5) === 0.0, 'ação não tem IOF');
afirmar(count(Investimentos::IOF_ATE_30_DIAS) === 30, 'a tabela de IOF tem os 30 dias');

// =====================================================================
//  Rentabilidade
// =====================================================================

afirmar(perto(0.1639, Investimentos::taxaAnual('CDI', 110, INDICES)),
    '110% do CDI com CDI a 14,9% dá 16,39% ao ano');
afirmar(perto(0.149, Investimentos::taxaAnual('CDI', 100, INDICES)),
    '100% do CDI é o próprio CDI');
afirmar(perto(0.1505, Investimentos::taxaAnual('SELIC', 0.05, INDICES)),
    'Selic + 0,05% dá 15,05% ao ano');
// Inflação e juro real se multiplicam: 4,5% e 6% dão 10,77%, não 10,5%.
afirmar(perto(0.1077, Investimentos::taxaAnual('IPCA', 6, INDICES)),
    'IPCA + 6% com IPCA a 4,5% dá 10,77% ao ano (composto, não somado)');
afirmar(perto(0.135, Investimentos::taxaAnual('PREFIXADO', 13.5, INDICES)),
    'prefixado devolve a própria taxa');
afirmar(Investimentos::taxaAnual('NENHUM', 999, INDICES) === 0.0,
    'sem indexador não há projeção, mesmo com taxa preenchida');

// Poupança: com Selic alta são 0,5% ao mês + TR.
$trMensal = Investimentos::mensalizar(0.01);
afirmar(perto(0.005 + $trMensal, Investimentos::poupancaMensal(0.15, 0.01)),
    'poupança com Selic acima de 8,5% rende 0,5% ao mês + TR');
// Com Selic baixa, passa a 70% da Selic + TR.
afirmar(perto(Investimentos::mensalizar(0.08) * 0.7 + $trMensal, Investimentos::poupancaMensal(0.08, 0.01)),
    'poupança com Selic em 8% rende 70% da Selic + TR');
afirmar(Investimentos::poupancaMensal(0.15, 0.01) > Investimentos::poupancaMensal(0.08, 0.01),
    'a regra da poupança rende mais no juro alto');

// Conversão ano <-> mês é composta, não divisão por 12.
$mensal = Investimentos::mensalizar(0.1239);
afirmar(perto(0.0097813, $mensal, 0.000001), '12,39% ao ano dão 0,97813% ao mês');
afirmar($mensal < 0.1239 / 12, 'o mês composto é menor que a divisão simples por 12');
afirmar(perto(0.1239, Investimentos::anualizar($mensal)), 'anualizar desfaz mensalizar');

// =====================================================================
//  Cálculo de um investimento
// =====================================================================

$hoje = '2026-07-20';

// CDB de R$ 10 mil que virou R$ 11 mil em 200 dias: faixa de 20%.
$cdb = [
    'tipo' => 'CDB', 'indexador' => 'CDI', 'taxa' => 110.0,
    'valor' => 11000.0, 'valor_aplicado' => 10000.0, 'dt_aplicacao' => '2026-01-01',
];
$c = Investimentos::calcular($cdb, INDICES, $hoje);

afirmar($c['dias_aplicado'] === 200, 'contou 200 dias desde a aplicação');
afirmar(perto(0.20, $c['aliquota_ir']), 'com 200 dias, a alíquota é 20%');
afirmar(perto(1000.0, $c['rendimento_bruto']), 'o rendimento é a diferença, não o total');
afirmar(perto(0.0, $c['iof_devido']), 'passados 30 dias, não há IOF');
afirmar(perto(200.0, $c['ir_devido']), 'IR de R$ 200 sobre R$ 1.000 de rendimento');
afirmar(perto(10800.0, $c['valor_liquido']), 'sobram R$ 10.800 no bolso');
afirmar(perto(0.1639, $c['taxa_anual_bruta']), 'o CDB rende 16,39% ao ano');
afirmar(perto(0.0127284, $c['taxa_mensal_bruta'], 0.000001), 'que dão 1,27284% ao mês');
afirmar(perto($c['taxa_mensal_bruta'] * 0.8, $c['taxa_mensal_liquida']),
    'a taxa líquida desconta a faixa de IR de hoje');
afirmar($c['rende_por_mes'] > $c['rende_por_mes_liquido'], 'o rendimento líquido é menor que o bruto');
afirmar($c['fgc'] === true, 'CDB é coberto pelo FGC');

// O mesmo CDB resgatado no 10º dia: IOF de 66% e IR na primeira faixa.
$curto = ['dt_aplicacao' => '2026-07-10'] + $cdb;
$c2 = Investimentos::calcular($curto, INDICES, $hoje);
afirmar($c2['dias_aplicado'] === 10, 'contou 10 dias');
afirmar(perto(0.66, $c2['aliquota_iof']), 'IOF de 66% no décimo dia');
afirmar(perto(660.0, $c2['iof_devido']), 'IOF come R$ 660 dos R$ 1.000');
// O IR vem depois do IOF, sobre o que sobrou: 22,5% de R$ 340.
afirmar(perto(76.50, $c2['ir_devido']), 'o IR incide sobre o rendimento já sem o IOF');
afirmar(perto(10263.50, $c2['valor_liquido']), 'resgate no dia 10 devolve R$ 10.263,50');
afirmar($c2['valor_liquido'] < $c['valor_liquido'], 'resgatar cedo custa caro');

// LCI: mesmo dinheiro, imposto nenhum.
$lci = ['tipo' => 'LCI', 'indexador' => 'CDI', 'taxa' => 90.0] + $cdb;
$c3 = Investimentos::calcular($lci, INDICES, $hoje);
afirmar($c3['isento_ir'] === true, 'LCI é marcada como isenta');
afirmar(perto(0.0, $c3['ir_devido']), 'LCI não paga IR');
afirmar(perto(11000.0, $c3['valor_liquido']), 'na LCI o líquido é o próprio saldo');
afirmar(perto($c3['taxa_mensal_bruta'], $c3['taxa_mensal_liquida']),
    'sem IR, a taxa líquida é igual à bruta');

// 90% do CDI isento rende mais no bolso que 110% do CDI tributado a 22,5%.
$liquidaLci = Investimentos::taxaAnual('CDI', 90, INDICES);
$liquidaCdb = Investimentos::taxaAnual('CDI', 110, INDICES) * (1 - 0.225);
afirmar($liquidaLci > $liquidaCdb,
    'LCI a 90% do CDI ganha de CDB a 110% na faixa de 22,5% — é para isso que serve o cálculo');

// Sem data de aplicação, usa a faixa mais baixa e avisa.
$semData = ['dt_aplicacao' => null] + $cdb;
$c4 = Investimentos::calcular($semData, INDICES, $hoje);
afirmar($c4['dias_aplicado'] === null, 'sem data, não há prazo para mostrar');
afirmar($c4['sem_data_aplicacao'] === true, 'a resposta avisa que falta a data');
afirmar(perto(0.15, $c4['aliquota_ir']), 'sem data, assume a última faixa (15%)');

// Sem valor aplicado, ninguém inventa lucro para tributar.
$semAplicado = ['valor_aplicado' => 0.0] + $cdb;
$c5 = Investimentos::calcular($semAplicado, INDICES, $hoje);
afirmar(perto(0.0, $c5['rendimento_bruto']), 'sem principal informado, o rendimento é zero');
afirmar(perto(0.0, $c5['ir_devido']), 'e não há IR a pagar');
afirmar(perto(11000.0, $c5['valor_liquido']), 'o líquido é o saldo inteiro');

// Data futura não vira prazo negativo.
$futuro = ['dt_aplicacao' => '2027-01-01'] + $cdb;
afirmar(Investimentos::calcular($futuro, INDICES, $hoje)['dias_aplicado'] === 0,
    'data de aplicação no futuro conta como zero dia');

// Indexador que não combina com o produto cai no padrão do produto.
$tesouro = ['tipo' => 'TESOURO_SELIC', 'indexador' => 'CDI', 'taxa' => 0.05] + $cdb;
afirmar(Investimentos::calcular($tesouro, INDICES, $hoje)['indexador'] === 'SELIC',
    'Tesouro Selic marcado como CDI volta para SELIC');
afirmar(Investimentos::normalizarTipo('inexistente') === 'OUTRO', 'tipo desconhecido vira OUTRO');

// Rótulos legíveis.
afirmar(Investimentos::rotuloRentabilidade('CDI', 110) === '110% do CDI', 'rótulo de % do CDI');
afirmar(Investimentos::rotuloRentabilidade('IPCA', 6) === 'IPCA + 6% a.a.', 'rótulo de IPCA+');
afirmar(Investimentos::rotuloRentabilidade('SELIC', 0) === 'Selic', 'rótulo da Selic pura');
afirmar(Investimentos::rotuloRentabilidade('PREFIXADO', 13.5) === '13,5% a.a.', 'rótulo do prefixado');

// =====================================================================
//  Projeção mês a mês
// =====================================================================

$proj = Investimentos::projecao(
    ['tipo' => 'CDB', 'indexador' => 'PREFIXADO', 'taxa' => 12.39,
     'valor' => 1000.0, 'valor_aplicado' => 1000.0, 'dt_aplicacao' => '2020-01-01'],
    INDICES,
    12,
    $hoje
);

afirmar(count($proj) === 12, 'a projeção devolve os 12 meses pedidos');
afirmar(perto(1009.78, $proj[0]['saldo'], 0.01), 'o primeiro mês rende 0,978%');
afirmar(perto(1123.90, $proj[11]['saldo'], 0.05), 'doze meses fecham nos 12,39% do ano');
afirmar($proj[0]['saldo'] < $proj[11]['saldo'], 'o saldo cresce mês a mês');
// Aplicado desde 2020: já está na faixa de 15%.
afirmar(perto(0.15, $proj[0]['aliquota_ir']), 'investimento antigo projeta na faixa de 15%');
afirmar(perto(18.59, $proj[11]['ir_no_resgate'], 0.05), 'IR de 15% sobre os R$ 123,90 de ganho');
afirmar(perto($proj[11]['saldo'] - $proj[11]['ir_no_resgate'], $proj[11]['liquido'], 0.011),
    'o líquido é o saldo menos o IR do resgate');

// Come-cotas: em maio e novembro o fundo antecipa o IR, e isso custa juros
// compostos. Partindo de abril, o primeiro mês da projeção é maio.
$fundo = ['tipo' => 'FUNDO_DI', 'indexador' => 'PREFIXADO', 'taxa' => 12.39,
          'valor' => 10000.0, 'valor_aplicado' => 9000.0, 'dt_aplicacao' => '2020-01-01'];
$projFundo = Investimentos::projecao($fundo, INDICES, 12, '2026-04-15');

afirmar($projFundo[0]['mes'] === '2026-05', 'a projeção começa em maio');
afirmar($projFundo[0]['come_cotas'] > 0, 'maio cobra come-cotas');
afirmar($projFundo[5]['come_cotas'] === 0.0, 'outubro não cobra');
afirmar($projFundo[6]['come_cotas'] > 0, 'novembro cobra de novo');

// O mesmo dinheiro num CDB, que não tem come-cotas, termina com saldo maior.
$cdbIgual = ['tipo' => 'CDB'] + $fundo;
$projCdb = Investimentos::projecao($cdbIgual, INDICES, 12, '2026-04-15');
afirmar($projCdb[11]['saldo'] > $projFundo[11]['saldo'],
    'sem come-cotas o saldo rende mais — é a perda de juros compostos');
// Mas o líquido final se aproxima: o que foi antecipado abate o IR do resgate.
afirmar(abs($projCdb[11]['liquido'] - $projFundo[11]['liquido']) < $projCdb[11]['saldo'] * 0.01,
    'no líquido a diferença é pequena: o come-cotas antecipa, não duplica o imposto');

// =====================================================================
//  Resumo da carteira
// =====================================================================

$carteira = [
    ['tipo' => 'CDB', 'indexador' => 'CDI', 'taxa' => 110.0,
     'valor' => 11000.0, 'valor_aplicado' => 10000.0, 'dt_aplicacao' => '2026-01-01'],
    ['tipo' => 'LCI', 'indexador' => 'CDI', 'taxa' => 90.0,
     'valor' => 5000.0, 'valor_aplicado' => 5000.0, 'dt_aplicacao' => '2026-01-01'],
    ['tipo' => 'ACOES', 'indexador' => 'NENHUM', 'taxa' => 0.0,
     'valor' => 4000.0, 'valor_aplicado' => 3000.0, 'dt_aplicacao' => '2024-01-01'],
];
$r = Investimentos::resumo($carteira, INDICES, 12, $hoje);

afirmar(perto(20000.0, $r['totais']['atual']), 'o total da carteira é R$ 20 mil');
afirmar(perto(18000.0, $r['totais']['aplicado']), 'o principal aplicado é R$ 18 mil');
afirmar(perto(2000.0, $r['totais']['rendimento']), 'o rendimento acumulado é R$ 2 mil');
// R$ 200 do CDB (20% de 1.000) + R$ 150 das ações (15% de 1.000) + nada da LCI.
afirmar(perto(350.0, $r['totais']['ir']), 'o IR total soma R$ 350');
afirmar(perto(19650.0, $r['totais']['liquido']), 'o líquido da carteira é R$ 19.650');
afirmar($r['totais']['taxa_mensal_media'] > 0, 'a carteira tem taxa média mensal');
afirmar($r['totais']['taxa_mensal_media_liquida'] < $r['totais']['taxa_mensal_media'],
    'a média líquida fica abaixo da bruta');
afirmar($r['totais']['sem_data_aplicacao'] === 0, 'nenhum item sem data neste caso');

$classes = array_column($r['por_classe'], 'valor', 'classe');
afirmar(perto(16000.0, $classes['Renda fixa'] ?? 0), 'R$ 16 mil em renda fixa');
afirmar(perto(4000.0, $classes['Renda variável'] ?? 0), 'R$ 4 mil em renda variável');
afirmar(count($r['projecao']) === 12, 'a projeção da carteira tem 12 meses');
afirmar($r['projecao'][11]['saldo'] > $r['totais']['atual'],
    'a carteira projetada cresce (as ações, sem projeção, ficam paradas)');

// Carteira vazia não pode explodir nem dividir por zero.
$vazio = Investimentos::resumo([], INDICES, 12, $hoje);
afirmar($vazio['totais']['atual'] === 0.0, 'carteira vazia soma zero');
afirmar($vazio['totais']['taxa_mensal_media'] === 0.0, 'carteira vazia não divide por zero');
afirmar($vazio['projecao'] === [], 'carteira vazia não projeta nada');

// =====================================================================
//  Catálogo
// =====================================================================

$cat = Investimentos::catalogo();
afirmar(count($cat['tipos']) === count(Investimentos::TIPOS), 'o catálogo expõe todos os tipos');
foreach ($cat['tipos'] as $t) {
    afirmar($t['nome'] !== '' && $t['nota'] !== '', "{$t['chave']} tem nome e explicação");
    afirmar(in_array($t['indexador_padrao'], $t['indexadores'], true),
        "{$t['chave']}: o indexador padrão está entre os aceitos");
    foreach ($t['indexadores'] as $i) {
        afirmar(isset(Investimentos::INDEXADORES[$i]), "{$t['chave']}: indexador {$i} existe");
    }
}
afirmar(count($cat['indexadores']) === count(Investimentos::INDEXADORES), 'o catálogo expõe os indexadores');

echo "\ntodos os testes de investimentos passaram\n";
