<?php
declare(strict_types=1);

/**
 * O modelo de planilha: ida e volta.
 *
 * Não sobe banco nem servidor — Exporter escreve a partir de arrays e Modelo
 * lê de volta, então o caminho inteiro (Writer -> arquivo .xlsx -> Reader ->
 * Modelo) cabe num teste de linha de comando. É o que garante que exportar,
 * mexer no Excel e importar de volta continue fechando.
 */

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Exporter;
use MinhasContas\Modelo;
use MinhasContas\Xlsx\Reader;
use MinhasContas\Xlsx\Valor;
use MinhasContas\Xlsx\Writer;

function afirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: {$mensagem}\n");
        exit(1);
    }
    echo "ok: {$mensagem}\n";
}

/** Salva a planilha num temporário e devolve o Reader dela. */
function ler(Writer $w): Reader
{
    $caminho = tempnam(sys_get_temp_dir(), 'modelo-test-');
    $w->salvar($caminho);
    // O arquivo só some no fim do processo: o Reader reabre o zip:// a cada
    // aba percorrida, então apagar aqui derrubaria a leitura seguinte.
    register_shutdown_function(static fn() => @unlink($caminho));
    return new Reader($caminho);
}

function igual(mixed $esperado, mixed $obtido): bool
{
    if (is_float($esperado) || is_float($obtido)) {
        return abs((float) $esperado - (float) $obtido) < 0.005;
    }
    return $esperado === $obtido;
}

// =====================================================================
//  Valor — a coerção de célula
// =====================================================================

afirmar(Valor::chave('Instituição *') === 'instituicao', 'chave() tira acento, caixa e pontuação');
afirmar(Valor::chave('Nº de parcelas') === 'ndeparcelas', 'chave() de um título com símbolo');

afirmar(igual(1234.56, Valor::numero('R$ 1.234,56')), 'numero() lê o formato pt-BR com símbolo');
afirmar(igual(1234.56, Valor::numero('1,234.56')), 'numero() lê o formato en-US');
afirmar(igual(1234.0, Valor::numero('1.234')), 'numero() trata ponto de milhar sozinho');
afirmar(igual(1.5, Valor::numero('1.5')), 'numero() trata ponto decimal sozinho');
afirmar(igual(-80.0, Valor::numero('(80,00)')), 'numero() lê negativo entre parênteses');
afirmar(igual(-80.0, Valor::numero('-80')), 'numero() lê negativo com sinal');
afirmar(igual(42.0, Valor::numero(42)), 'numero() aceita célula numérica');
afirmar(Valor::numero('') === null && Valor::numero('abc') === null, 'numero() recusa texto sem dígito');

afirmar(Valor::data('09/07/2026') === '2026-07-09', 'data() lê DD/MM/AAAA');
afirmar(Valor::data('9/7/26') === '2026-07-09', 'data() lê D/M/AA');
afirmar(Valor::data('2026-07-09') === '2026-07-09', 'data() lê ISO');
afirmar(Valor::data('2026-07-09 00:00:00') === '2026-07-09', 'data() ignora a hora');
afirmar(Valor::data(46212) === '2026-07-09', 'data() converte o serial do Excel');
afirmar(Valor::data('31/02/2026') === null, 'data() recusa dia que não existe');
afirmar(Valor::data('qualquer coisa') === null, 'data() recusa texto solto');

afirmar(Valor::opcao('receita', ['RECEITA', 'DESPESA']) === 'RECEITA', 'opcao() ignora a caixa');
afirmar(Valor::opcao('nao operacional', ['OPERACIONAL', 'NÃO OPERACIONAL']) === 'NÃO OPERACIONAL',
    'opcao() ignora o acento');
afirmar(Valor::opcao('', ['RECEITA'], 'DESPESA') === 'DESPESA', 'opcao() cai no padrão quando vazio');
afirmar(Valor::opcao('sei lá', ['RECEITA'], 'DESPESA') === 'DESPESA', 'opcao() cai no padrão quando não casa');

afirmar(Valor::texto('  ok  ') === 'ok', 'texto() apara');
afirmar(Valor::texto('   ') === null, 'texto() devolve null para célula em branco');
afirmar(Valor::texto(12345678.0) === '12345678', 'texto() não deixa float virar "12345678.0"');

// =====================================================================
//  Ida e volta: dados -> planilha -> dados
// =====================================================================

$dados = Exporter::vazio();
$dados['settings'] = [
    'receita_mensal'    => 6500.0,
    'custo_vida_mensal' => 4200.5,
    'fator_reserva'     => 6.0,
    'taxa_cdi_anual'    => 14.9,
    'taxa_selic_anual'  => 15.0,
    'taxa_ipca_anual'   => 4.5,
    'taxa_tr_anual'     => 1.0,
];
$dados['categories'] = [
    ['categoria' => 'Alimentação', 'subcategoria' => 'Mercado', 'tipo' => 'DESPESA',
     'grupo' => 'OPERACIONAL', 'meta_mes' => 900.0],
    ['categoria' => 'Renda Principal', 'subcategoria' => 'Salário', 'tipo' => 'RECEITA',
     'grupo' => 'OPERACIONAL', 'meta_mes' => 0.0],
    ['categoria' => 'Viagem', 'subcategoria' => null, 'tipo' => 'DESPESA',
     'grupo' => 'NÃO OPERACIONAL', 'meta_mes' => 0.0],
];
$dados['transactions'] = [
    ['dt_venc' => '2026-07-05', 'categoria' => 'Renda Principal', 'subcategoria' => 'Salário',
     'valor' => 6500.0, 'tipo' => 'RECEITA', 'status' => 'Realizado',
     'instituicao' => 'Banco do Brasil', 'pessoa' => null, 'dt_compra' => null,
     'obs' => 'Salário de julho'],
    ['dt_venc' => '2026-07-15', 'categoria' => 'Alimentação', 'subcategoria' => 'Mercado',
     'valor' => 432.9, 'tipo' => 'DESPESA', 'status' => 'Realizado',
     'instituicao' => 'Nubank', 'pessoa' => 'Ana', 'dt_compra' => '2026-07-12',
     'obs' => 'Compra do mês & troco <1>'],
    ['dt_venc' => '2026-08-01', 'categoria' => 'Viagem', 'subcategoria' => null,
     'valor' => 1200.0, 'tipo' => 'DESPESA', 'status' => 'Previsto',
     'instituicao' => null, 'pessoa' => null, 'dt_compra' => null, 'obs' => null],
];
$dados['projects'] = [
    ['descricao' => 'Trocar de carro', 'valor' => 45000.0, 'ano' => 2027, 'prazo' => 'Médio'],
];
$dados['patrimonio'] = [
    'investimentos' => [
        // Produto e indexador vão para a planilha pelo nome comercial
        // ("Tesouro Selic") e voltam como a chave interna.
        ['instituicao' => 'XP', 'ativo' => 'Tesouro Selic 2029', 'fixa_var' => 'Fixa',
         'prazo_projeto' => 'Reserva', 'valor' => 30000.0,
         'tipo' => 'TESOURO_SELIC', 'indexador' => 'SELIC', 'taxa' => 0.05,
         'valor_aplicado' => 28000.0, 'dt_aplicacao' => '2025-03-10',
         'dt_vencimento' => '2029-03-01'],
        ['instituicao' => 'Banco do Brasil', 'ativo' => 'CDB liquidez diária',
         'fixa_var' => 'Fixa', 'prazo_projeto' => 'Curto', 'valor' => 12000.0,
         'tipo' => 'CDB', 'indexador' => 'CDI', 'taxa' => 110.0,
         'valor_aplicado' => 11000.0, 'dt_aplicacao' => '2026-01-05',
         'dt_vencimento' => null],
    ],
    'bens' => [
        ['descricao' => 'Apartamento', 'valor' => 380000.0, 'saldo_devedor' => 120000.0],
    ],
    'dividas' => [
        ['descricao' => 'Financiamento', 'num_parcelas' => 48, 'valor_parcela' => 1500.0,
         'saldo_devedor' => 72000.0],
    ],
];
$dados['institutions'] = [
    ['nome' => 'Nubank', 'tipo' => 'Cartão de Crédito', 'saldo_inicial' => 0.0, 'descricao' => 'Cartão principal'],
    ['nome' => 'Banco do Brasil', 'tipo' => 'Conta', 'saldo_inicial' => 1500.25, 'descricao' => null],
];
$dados['people'] = [['nome' => 'Ana'], ['nome' => 'João']];

$wb = ler(Exporter::planilha($dados));

// ---- as abas existem e têm o nome do modelo ----
foreach (Modelo::ABAS as $aba) {
    afirmar($wb->temAba($aba['nome']), "a exportação tem a aba \"{$aba['nome']}\"");
}
afirmar($wb->temAba(Modelo::ABA_PARAMETROS), 'a exportação tem a aba de parâmetros');
afirmar($wb->temAba(Modelo::ABA_INSTRUCOES), 'a exportação tem a aba de instruções');

// ---- o cabeçalho é o do modelo, na linha 1 ----
foreach (Modelo::ABAS as $aba) {
    $primeira = $wb->linhas($aba['nome'])->current();
    $titulos = array_map(static fn($v) => Valor::chave((string) $v), $primeira);
    foreach ($aba['colunas'] as $c) {
        afirmar(in_array(Valor::chave((string) $c['titulo']), $titulos, true),
            "aba \"{$aba['nome']}\" traz a coluna \"{$c['titulo']}\" na linha 1");
    }
}

// ---- e o conteúdo volta igual ----
$volta = Modelo::ler($wb);

afirmar(Modelo::reconhece($wb), 'a planilha exportada é reconhecida como o modelo do sistema');

foreach ($dados['settings'] as $chave => $esperado) {
    afirmar(igual($esperado, $volta['settings'][$chave] ?? null), "parâmetro {$chave} volta igual");
}

/** Compara duas listas campo a campo, usando as colunas declaradas no modelo. */
function conferirLista(string $rotulo, array $colunas, array $esperados, array $obtidos): void
{
    afirmar(count($esperados) === count($obtidos),
        "{$rotulo}: voltaram " . count($obtidos) . ' de ' . count($esperados) . ' linhas');
    foreach ($esperados as $i => $esperado) {
        foreach ($colunas as $c) {
            $campo = (string) $c['campo'];
            $a = $esperado[$campo] ?? null;
            $b = $obtidos[$i][$campo] ?? null;
            // Número com padrão declarado nunca volta null: 0 e null são o
            // mesmo "em branco" para essas colunas.
            if ($a === null && isset($c['padrao'])) {
                $a = $c['padrao'];
            }
            afirmar(igual($a, $b), "{$rotulo}[{$i}].{$campo}: " . var_export($a, true)
                . ' == ' . var_export($b, true));
        }
    }
}

conferirLista('lançamentos', Modelo::ABAS['transactions']['colunas'],
    $dados['transactions'], $volta['transactions']);
conferirLista('classificações', Modelo::ABAS['categories']['colunas'],
    $dados['categories'], $volta['categories']);
conferirLista('projetos', Modelo::ABAS['projects']['colunas'],
    $dados['projects'], $volta['projects']);
conferirLista('investimentos', Modelo::ABAS['investimentos']['colunas'],
    $dados['patrimonio']['investimentos'], $volta['patrimonio']['investimentos']);
conferirLista('bens', Modelo::ABAS['bens']['colunas'],
    $dados['patrimonio']['bens'], $volta['patrimonio']['bens']);
conferirLista('dívidas', Modelo::ABAS['dividas']['colunas'],
    $dados['patrimonio']['dividas'], $volta['patrimonio']['dividas']);
conferirLista('instituições', Modelo::ABAS['institutions']['colunas'],
    $dados['institutions'], $volta['institutions']);
conferirLista('pessoas', Modelo::ABAS['people']['colunas'],
    $dados['people'], $volta['people']);

// A classificação é derivada de categoria + subcategoria, com a mesma regra
// da tela de cadastro.
afirmar($volta['transactions'][0]['classificacao'] === 'RENDA PRINCIPAL - Salário',
    'classificação do lançamento sai de categoria + subcategoria');
afirmar($volta['transactions'][2]['classificacao'] === 'VIAGEM - Viagem',
    'sem subcategoria, a categoria vira a classificação inteira');

// =====================================================================
//  Modelo em branco
// =====================================================================

$vazio = ler(Exporter::modeloVazio());
$lidoVazio = Modelo::ler($vazio);

afirmar(Modelo::reconhece($vazio), 'o modelo em branco é reconhecido');
afirmar($lidoVazio['transactions'] === [], 'o modelo em branco não traz lançamento nenhum');
afirmar($lidoVazio['categories'] === [], 'o modelo em branco não traz classificação nenhuma');

// A aba de exemplo tem lançamentos preenchidos e NÃO pode entrar na conta de
// ninguém: o importador não reconhece o nome dela.
$abaExemplo = null;
foreach ($vazio->nomesDasAbas() as $nome) {
    if (str_starts_with($nome, 'Exemplo')) {
        $abaExemplo = $nome;
    }
}
afirmar($abaExemplo !== null, 'o modelo em branco traz uma aba de exemplo');
afirmar(iterator_count($vazio->linhas((string) $abaExemplo)) > 3, 'a aba de exemplo tem linhas preenchidas');
afirmar($lidoVazio['transactions'] === [], 'a aba de exemplo não é importada');

// Parâmetros do modelo em branco vêm zerados, mas presentes.
foreach (array_keys(Modelo::PARAMETROS) as $chave) {
    afirmar(($lidoVazio['settings'][$chave] ?? null) === 0.0, "o modelo em branco traz {$chave} zerado");
}

// =====================================================================
//  Tolerância da importação: é o nome da coluna que manda
// =====================================================================

$w = new Writer();
$w->aba('Lançamentos');
// Uma linha de título acima da tabela, colunas fora de ordem, dois títulos
// alternativos, uma coluna que o modelo não conhece e valores digitados como
// texto — tudo o que uma planilha de verdade tem.
$w->set(1, 1, 'Meus gastos de julho');
$w->cabecalho(3, 1, ['Obs', 'Valor (R$)', 'Data', 'Categoria', 'Subcategoria', 'Tipo', 'Coluna minha']);
$w->set(4, 1, 'Compra grande')->set(4, 2, 'R$ 1.234,56')->set(4, 3, '09/07/2026')
  ->set(4, 4, 'Alimentação')->set(4, 5, 'Mercado')->set(4, 6, 'despesa')->set(4, 7, 'ignore isto');
$w->set(5, 1, 'Sem data — some')->set(5, 2, '50')->set(5, 4, 'Alimentação');
$w->set(6, 1, 'TOTAL')->set(6, 2, '1.284,56');
$w->set(7, 1, 'Salário')->set(7, 2, '6.500,00')->set(7, 3, '05/07/2026')
  ->set(7, 4, 'Renda Principal')->set(7, 5, 'Salário')->set(7, 6, 'RECEITA');

$solto = Modelo::ler(ler($w));
$tx = $solto['transactions'];

afirmar(count($tx) === 2, 'só as duas linhas completas viraram lançamento');
afirmar($tx[0]['dt_venc'] === '2026-07-09', 'a coluna "Data" foi aceita como vencimento');
afirmar(igual(1234.56, $tx[0]['valor']), 'valor digitado como texto foi lido');
afirmar($tx[0]['tipo'] === 'DESPESA', 'tipo em minúsculas foi normalizado');
afirmar($tx[0]['status'] === 'Previsto', 'status ausente cai no padrão');
afirmar($tx[0]['obs'] === 'Compra grande', 'a coluna "Obs" foi aceita como observação');
afirmar($tx[1]['classificacao'] === 'RENDA PRINCIPAL - Salário', 'a segunda linha veio inteira');
afirmar(!array_key_exists('Coluna minha', $tx[0]), 'coluna desconhecida é ignorada');

// Sem nenhuma aba do modelo, a planilha não é reconhecida — é o que faz o
// arquivo antigo cair no leitor antigo em vez de voltar vazio.
$outra = new Writer();
$outra->aba('Plan1')->set(1, 1, 'nada a ver');
afirmar(!Modelo::reconhece(ler($outra)), 'planilha sem as abas do modelo não é reconhecida');

echo "\ntodos os testes do modelo passaram\n";
