<?php
/**
 * O que o sistema sabe sobre investimento brasileiro: que produtos existem,
 * como cada um rende e quanto o Leão come de cada um.
 *
 * Este arquivo é a fonte única dessas regras — a API, a interface e a
 * projeção saem todas daqui.
 *
 * ATENÇÃO, regra de negócio com data de validade: as alíquotas abaixo são as
 * vigentes até 2026 (tabela regressiva de renda fixa, isenção de LCI/LCA/CRI/
 * CRA/debênture incentivada para pessoa física, come-cotas semestral nos
 * fundos). Imposto muda por lei, e quando mudar é AQUI que se mexe — em
 * IR_RENDA_FIXA, IR_PREVIDENCIA, IOF_ATE_30_DIAS e no campo 'ir' de cada
 * produto. Nada disso está espalhado pelo resto do código.
 *
 * As taxas de mercado (CDI, Selic, IPCA, TR) NÃO ficam aqui: são parâmetros
 * por usuário, em settings, porque mudam a cada reunião do Copom e o sistema
 * roda sem acesso à internet.
 */

declare(strict_types=1);

namespace MinhasContas;

use MinhasContas\Xlsx\Valor;

final class Investimentos
{
    /**
     * Tabela regressiva do IR sobre renda fixa e fundos (Lei 11.033/2004).
     * Contada em dias corridos desde a aplicação; a alíquota vale sobre o
     * RENDIMENTO, nunca sobre o principal.
     *
     * @var list<array{ate: int|null, aliquota: float}>
     */
    public const IR_RENDA_FIXA = [
        ['ate' => 180,  'aliquota' => 0.225],
        ['ate' => 360,  'aliquota' => 0.200],
        ['ate' => 720,  'aliquota' => 0.175],
        ['ate' => null, 'aliquota' => 0.150],
    ];

    /**
     * Tabela regressiva da previdência privada (PGBL/VGBL), quando a pessoa
     * escolhe o regime regressivo. Prazos em anos, contados por aporte.
     *
     * @var list<array{ate: int|null, aliquota: float}>
     */
    public const IR_PREVIDENCIA = [
        ['ate' => 720,   'aliquota' => 0.350],
        ['ate' => 1440,  'aliquota' => 0.300],
        ['ate' => 2160,  'aliquota' => 0.250],
        ['ate' => 2880,  'aliquota' => 0.200],
        ['ate' => 3600,  'aliquota' => 0.150],
        ['ate' => null,  'aliquota' => 0.100],
    ];

    /**
     * IOF regressivo: resgate antes de 30 dias paga IOF sobre o rendimento,
     * e o IOF vem ANTES do IR — o IR incide sobre o que sobrou.
     *
     * O índice do array é o dia do resgate (1 = resgatou no dia seguinte).
     * A partir do 30º dia o IOF é zero, e é por isso que "deixa completar um
     * mês" é o conselho mais repetido da renda fixa.
     *
     * @var list<float>
     */
    public const IOF_ATE_30_DIAS = [
        0.96, 0.93, 0.90, 0.86, 0.83, 0.80, 0.76, 0.73, 0.70, 0.66,
        0.63, 0.60, 0.56, 0.53, 0.50, 0.46, 0.43, 0.40, 0.36, 0.33,
        0.30, 0.26, 0.23, 0.20, 0.16, 0.13, 0.10, 0.06, 0.03, 0.00,
    ];

    /** Alíquota do come-cotas conforme o prazo da carteira do fundo. */
    public const COME_COTAS_LONGO = 0.15;
    public const COME_COTAS_CURTO = 0.20;

    /** Selic (ao ano) a partir da qual a poupança rende 0,5% ao mês + TR. */
    public const POUPANCA_SELIC_LIMITE = 0.085;

    /**
     * Como o dinheiro rende. É o que dá sentido ao campo `taxa` de cada
     * investimento — 110 quer dizer coisas diferentes em cada linha destas.
     *
     * @var array<string, array{nome: string, unidade: string, ajuda: string, usa_taxa: bool}>
     */
    public const INDEXADORES = [
        'CDI' => [
            'nome'     => '% do CDI',
            'unidade'  => '% do CDI',
            'ajuda'    => 'Ex.: 110 = rende 110% do CDI.',
            'usa_taxa' => true,
        ],
        'SELIC' => [
            'nome'     => 'Selic + taxa',
            'unidade'  => 'p.p. ao ano',
            'ajuda'    => 'Ex.: 0,05 = Selic + 0,05% ao ano (é assim que o Tesouro Selic é vendido).',
            'usa_taxa' => true,
        ],
        'IPCA' => [
            'nome'     => 'IPCA + taxa',
            'unidade'  => '% ao ano',
            'ajuda'    => 'Ex.: 6 = IPCA + 6% ao ano. Protege da inflação.',
            'usa_taxa' => true,
        ],
        'PREFIXADO' => [
            'nome'     => 'Prefixado',
            'unidade'  => '% ao ano',
            'ajuda'    => 'Ex.: 13,5 = 13,5% ao ano, combinados na aplicação e imutáveis.',
            'usa_taxa' => true,
        ],
        'POUPANCA' => [
            'nome'     => 'Regra da poupança',
            'unidade'  => '',
            'ajuda'    => '0,5% ao mês + TR enquanto a Selic passa de 8,5% ao ano; abaixo disso, 70% da Selic + TR.',
            'usa_taxa' => false,
        ],
        'NENHUM' => [
            'nome'     => 'Sem projeção',
            'unidade'  => '',
            'ajuda'    => 'Guarda o valor e calcula o imposto, mas não projeta rendimento.',
            'usa_taxa' => false,
        ],
    ];

    /**
     * O catálogo de produtos.
     *
     *   ir          REGRESSIVA (tabela de renda fixa), PREVIDENCIA, ISENTO
     *               ou uma alíquota fixa (ganho de capital em bolsa)
     *   iof         cobra IOF no resgate antes de 30 dias
     *   come_cotas  fundo com IR antecipado em maio e novembro
     *   fgc         coberto pelo Fundo Garantidor de Créditos
     *
     * @var array<string, array{
     *   nome: string, classe: string, indexadores: list<string>,
     *   indexador_padrao: string, taxa_padrao: float, ir: string,
     *   aliquota?: float, iof: bool, come_cotas?: string, fgc: bool, nota: string
     * }>
     */
    public const TIPOS = [
        'CDB' => [
            'nome' => 'CDB', 'classe' => 'Renda fixa',
            'indexadores' => ['CDI', 'PREFIXADO', 'IPCA'],
            'indexador_padrao' => 'CDI', 'taxa_padrao' => 100.0,
            'ir' => 'REGRESSIVA', 'iof' => true, 'fgc' => true,
            'nota' => 'Você empresta para o banco. IR na tabela regressiva, sobre o rendimento.',
        ],
        'LCI' => [
            'nome' => 'LCI', 'classe' => 'Renda fixa',
            'indexadores' => ['CDI', 'PREFIXADO', 'IPCA'],
            'indexador_padrao' => 'CDI', 'taxa_padrao' => 90.0,
            'ir' => 'ISENTO', 'iof' => false, 'fgc' => true,
            'nota' => 'Isenta de IR para pessoa física. Por isso 90% do CDI numa LCI pode render mais que 100% num CDB.',
        ],
        'LCA' => [
            'nome' => 'LCA', 'classe' => 'Renda fixa',
            'indexadores' => ['CDI', 'PREFIXADO', 'IPCA'],
            'indexador_padrao' => 'CDI', 'taxa_padrao' => 90.0,
            'ir' => 'ISENTO', 'iof' => false, 'fgc' => true,
            'nota' => 'Mesma isenção da LCI, com lastro no agronegócio. Tem carência mínima definida pelo emissor.',
        ],
        'TESOURO_SELIC' => [
            'nome' => 'Tesouro Selic', 'classe' => 'Renda fixa',
            'indexadores' => ['SELIC'],
            'indexador_padrao' => 'SELIC', 'taxa_padrao' => 0.0,
            'ir' => 'REGRESSIVA', 'iof' => true, 'fgc' => false,
            'nota' => 'O mais conservador do Tesouro Direto: acompanha a Selic e quase não oscila. Garantido pelo Tesouro Nacional, não pelo FGC.',
        ],
        'TESOURO_PREFIXADO' => [
            'nome' => 'Tesouro Prefixado', 'classe' => 'Renda fixa',
            'indexadores' => ['PREFIXADO'],
            'indexador_padrao' => 'PREFIXADO', 'taxa_padrao' => 13.0,
            'ir' => 'REGRESSIVA', 'iof' => true, 'fgc' => false,
            'nota' => 'Taxa travada na compra. Se resgatar antes do vencimento, o preço oscila e pode dar prejuízo.',
        ],
        'TESOURO_IPCA' => [
            'nome' => 'Tesouro IPCA+', 'classe' => 'Renda fixa',
            'indexadores' => ['IPCA'],
            'indexador_padrao' => 'IPCA', 'taxa_padrao' => 6.0,
            'ir' => 'REGRESSIVA', 'iof' => true, 'fgc' => false,
            'nota' => 'Rende inflação mais uma taxa real. É o que protege o poder de compra no longo prazo.',
        ],
        'POUPANCA' => [
            'nome' => 'Poupança', 'classe' => 'Renda fixa',
            'indexadores' => ['POUPANCA'],
            'indexador_padrao' => 'POUPANCA', 'taxa_padrao' => 0.0,
            'ir' => 'ISENTO', 'iof' => false, 'fgc' => true,
            'nota' => 'Isenta de IR, mas rende no aniversário: sacar um dia antes perde o mês inteiro.',
        ],
        'CRI_CRA' => [
            'nome' => 'CRI / CRA', 'classe' => 'Renda fixa',
            'indexadores' => ['CDI', 'IPCA', 'PREFIXADO'],
            'indexador_padrao' => 'IPCA', 'taxa_padrao' => 7.0,
            'ir' => 'ISENTO', 'iof' => false, 'fgc' => false,
            'nota' => 'Isento de IR para pessoa física, sem cobertura do FGC — o risco é de quem emitiu.',
        ],
        'DEBENTURE_INCENTIVADA' => [
            'nome' => 'Debênture incentivada', 'classe' => 'Renda fixa',
            'indexadores' => ['IPCA', 'CDI', 'PREFIXADO'],
            'indexador_padrao' => 'IPCA', 'taxa_padrao' => 7.0,
            'ir' => 'ISENTO', 'iof' => false, 'fgc' => false,
            'nota' => 'Dívida de empresa de infraestrutura, isenta de IR (Lei 12.431). Sem FGC.',
        ],
        'DEBENTURE' => [
            'nome' => 'Debênture comum', 'classe' => 'Renda fixa',
            'indexadores' => ['CDI', 'IPCA', 'PREFIXADO'],
            'indexador_padrao' => 'CDI', 'taxa_padrao' => 110.0,
            'ir' => 'REGRESSIVA', 'iof' => true, 'fgc' => false,
            'nota' => 'Dívida de empresa, sem isenção e sem FGC.',
        ],
        'FUNDO_DI' => [
            'nome' => 'Fundo DI / Renda Fixa', 'classe' => 'Fundo',
            'indexadores' => ['CDI', 'PREFIXADO'],
            'indexador_padrao' => 'CDI', 'taxa_padrao' => 95.0,
            'ir' => 'REGRESSIVA', 'iof' => true, 'fgc' => false,
            'come_cotas' => 'LONGO',
            'nota' => 'Tem come-cotas: em maio e novembro o IR é antecipado sobre o rendimento, o que atrapalha os juros compostos.',
        ],
        'FUNDO_MULTIMERCADO' => [
            'nome' => 'Fundo multimercado', 'classe' => 'Fundo',
            'indexadores' => ['CDI', 'PREFIXADO', 'NENHUM'],
            'indexador_padrao' => 'CDI', 'taxa_padrao' => 110.0,
            'ir' => 'REGRESSIVA', 'iof' => true, 'fgc' => false,
            'come_cotas' => 'LONGO',
            'nota' => 'Também sofre come-cotas em maio e novembro.',
        ],
        'FUNDO_ACOES' => [
            'nome' => 'Fundo de ações', 'classe' => 'Renda variável',
            'indexadores' => ['PREFIXADO', 'NENHUM'],
            'indexador_padrao' => 'NENHUM', 'taxa_padrao' => 0.0,
            'ir' => 'FIXA', 'aliquota' => 0.15, 'iof' => false, 'fgc' => false,
            'nota' => '15% de IR só no resgate — fundo de ações não tem come-cotas.',
        ],
        'ACOES' => [
            'nome' => 'Ações', 'classe' => 'Renda variável',
            'indexadores' => ['PREFIXADO', 'NENHUM'],
            'indexador_padrao' => 'NENHUM', 'taxa_padrao' => 0.0,
            'ir' => 'FIXA', 'aliquota' => 0.15, 'iof' => false, 'fgc' => false,
            'nota' => '15% sobre o lucro da venda, apurado por você. Vendas comuns que somem até R$ 20 mil no mês são isentas; day trade paga 20%.',
        ],
        'ETF' => [
            'nome' => 'ETF', 'classe' => 'Renda variável',
            'indexadores' => ['PREFIXADO', 'NENHUM'],
            'indexador_padrao' => 'NENHUM', 'taxa_padrao' => 0.0,
            'ir' => 'FIXA', 'aliquota' => 0.15, 'iof' => false, 'fgc' => false,
            'nota' => '15% sobre o ganho na venda. Diferente de ação avulsa, ETF não tem a isenção dos R$ 20 mil.',
        ],
        'FII' => [
            'nome' => 'Fundo imobiliário (FII)', 'classe' => 'Renda variável',
            'indexadores' => ['PREFIXADO', 'NENHUM'],
            'indexador_padrao' => 'NENHUM', 'taxa_padrao' => 0.0,
            'ir' => 'FIXA', 'aliquota' => 0.20, 'iof' => false, 'fgc' => false,
            'nota' => 'O aluguel mensal costuma ser isento para pessoa física; o lucro na venda da cota paga 20%, sem faixa de isenção.',
        ],
        'PREVIDENCIA' => [
            'nome' => 'Previdência (PGBL/VGBL)', 'classe' => 'Previdência',
            'indexadores' => ['CDI', 'PREFIXADO', 'IPCA', 'NENHUM'],
            'indexador_padrao' => 'CDI', 'taxa_padrao' => 100.0,
            'ir' => 'PREVIDENCIA', 'iof' => false, 'fgc' => false,
            'nota' => 'Tabela regressiva própria: começa em 35% e chega a 10% depois de dez anos. Sem come-cotas.',
        ],
        'OUTRO' => [
            'nome' => 'Outro', 'classe' => 'Outro',
            'indexadores' => ['CDI', 'SELIC', 'IPCA', 'PREFIXADO', 'NENHUM'],
            'indexador_padrao' => 'NENHUM', 'taxa_padrao' => 0.0,
            'ir' => 'ISENTO', 'iof' => false, 'fgc' => false,
            'nota' => 'Para o que não se encaixa nos demais. Sem imposto calculado.',
        ],
    ];

    // ------------------------------------------------------------- imposto

    /**
     * Alíquota de IR do produto para um prazo em dias.
     *
     * Devolve 0 para o que é isento e a alíquota fixa para renda variável,
     * onde o prazo não muda nada.
     */
    public static function aliquotaIr(string $tipo, int $dias): float
    {
        $p = self::tipo($tipo);

        return match ($p['ir']) {
            'ISENTO'      => 0.0,
            'FIXA'        => (float) ($p['aliquota'] ?? 0.15),
            'PREVIDENCIA' => self::naTabela(self::IR_PREVIDENCIA, $dias),
            default       => self::naTabela(self::IR_RENDA_FIXA, $dias),
        };
    }

    /**
     * IOF sobre o rendimento no resgate antes de 30 dias.
     *
     * @param int $dias dias corridos desde a aplicação
     */
    public static function aliquotaIof(string $tipo, int $dias): float
    {
        if (!self::tipo($tipo)['iof'] || $dias >= 30 || $dias < 1) {
            return 0.0;
        }
        return self::IOF_ATE_30_DIAS[$dias - 1] ?? 0.0;
    }

    /**
     * @param list<array{ate: int|null, aliquota: float}> $tabela
     */
    private static function naTabela(array $tabela, int $dias): float
    {
        foreach ($tabela as $faixa) {
            if ($faixa['ate'] === null || $dias <= $faixa['ate']) {
                return $faixa['aliquota'];
            }
        }
        return 0.0;
    }

    // --------------------------------------------------------- rentabilidade

    /**
     * Rentabilidade bruta ao ano, em fração (0.1639 = 16,39% ao ano).
     *
     * @param array<string, float> $indices frações ao ano: cdi, selic, ipca, tr
     */
    public static function taxaAnual(string $indexador, float $taxa, array $indices): float
    {
        $cdi   = $indices['cdi']   ?? 0.0;
        $selic = $indices['selic'] ?? 0.0;
        $ipca  = $indices['ipca']  ?? 0.0;
        $tr    = $indices['tr']    ?? 0.0;

        return match (self::normalizarIndexador($indexador)) {
            'CDI'       => $cdi * ($taxa / 100),
            'SELIC'     => $selic + ($taxa / 100),
            // Inflação e juro real se multiplicam, não se somam: IPCA de 4,5%
            // com 6% real dá 10,77% ao ano, não 10,5%.
            'IPCA'      => (1 + $ipca) * (1 + $taxa / 100) - 1,
            'PREFIXADO' => $taxa / 100,
            'POUPANCA'  => self::anualizar(self::poupancaMensal($selic, $tr)),
            default     => 0.0,
        };
    }

    /**
     * A regra oficial da poupança, em fração ao mês.
     *
     * Com a Selic acima de 8,5% ao ano são 0,5% ao mês fixos mais a TR; no
     * juro baixo passa a 70% da Selic mais a TR, para a poupança não ficar
     * mais atraente que o Tesouro.
     */
    public static function poupancaMensal(float $selicAnual, float $trAnual): float
    {
        $tr = self::mensalizar($trAnual);
        return $selicAnual > self::POUPANCA_SELIC_LIMITE
            ? 0.005 + $tr
            : self::mensalizar($selicAnual) * 0.7 + $tr;
    }

    /** Fração ao ano -> fração ao mês, com juros compostos. */
    public static function mensalizar(float $anual): float
    {
        return $anual <= -1.0 ? 0.0 : (1 + $anual) ** (1 / 12) - 1;
    }

    /** Fração ao mês -> fração ao ano. */
    public static function anualizar(float $mensal): float
    {
        return (1 + $mensal) ** 12 - 1;
    }

    // ------------------------------------------------------------- cálculo

    /**
     * Tudo o que se pode dizer de um investimento hoje: quanto rende por mês,
     * quanto o Leão leva se resgatar agora e o que sobra no bolso.
     *
     * @param array<string, mixed> $inv linha de `investments`
     * @param array<string, float> $indices frações ao ano: cdi, selic, ipca, tr
     * @return array<string, mixed>
     */
    public static function calcular(array $inv, array $indices, ?string $hoje = null): array
    {
        $tipoChave = self::normalizarTipo((string) ($inv['tipo'] ?? 'OUTRO'));
        $produto   = self::tipo($tipoChave);
        $indexador = self::normalizarIndexadorDoTipo($tipoChave, (string) ($inv['indexador'] ?? ''));
        $taxa      = (float) ($inv['taxa'] ?? 0);

        $valor    = (float) ($inv['valor'] ?? 0);
        $aplicado = (float) ($inv['valor_aplicado'] ?? 0);
        // Sem valor aplicado informado, assume-se que nada rendeu ainda: é a
        // hipótese conservadora, que nunca inventa lucro para tributar.
        if ($aplicado <= 0.0) {
            $aplicado = $valor;
        }
        $rendimento = max(0.0, $valor - $aplicado);

        $dias = self::diasAplicado($inv['dt_aplicacao'] ?? null, $hoje);
        // Linha antiga, sem data de aplicação: usa a última faixa da tabela
        // (a menor alíquota), que é onde está quem investe há mais de dois
        // anos. O campo `sem_data_aplicacao` avisa a interface para pedir a data.
        $diasParaIr = $dias ?? 100_000;

        $aliquotaIr  = self::aliquotaIr($tipoChave, $diasParaIr);
        $aliquotaIof = $dias === null ? 0.0 : self::aliquotaIof($tipoChave, $dias);

        // O IOF vem primeiro e o IR incide sobre o que sobrou — nesta ordem,
        // como na liquidação de verdade.
        $iof = $rendimento * $aliquotaIof;
        $ir  = ($rendimento - $iof) * $aliquotaIr;

        $anual  = self::taxaAnual($indexador, $taxa, $indices);
        $mensal = self::mensalizar($anual);

        return [
            'tipo'              => $tipoChave,
            'tipo_nome'         => $produto['nome'],
            'classe'            => $produto['classe'],
            'indexador'         => $indexador,
            'rende'             => self::rotuloRentabilidade($indexador, $taxa),
            'dias_aplicado'     => $dias,
            'sem_data_aplicacao' => $dias === null,

            'taxa_anual_bruta'  => round($anual, 6),
            'taxa_mensal_bruta' => round($mensal, 6),
            // Líquida: o mesmo rendimento menos a mordida da faixa de IR em
            // que o investimento está hoje. É estimativa — o IR só é cobrado
            // no resgate, e até lá a faixa pode ter melhorado.
            'taxa_mensal_liquida' => round($mensal * (1 - $aliquotaIr), 6),
            'rende_por_mes'     => round($valor * $mensal, 2),
            'rende_por_mes_liquido' => round($valor * $mensal * (1 - $aliquotaIr), 2),

            'valor_aplicado'    => round($aplicado, 2),
            'rendimento_bruto'  => round($rendimento, 2),
            'aliquota_ir'       => round($aliquotaIr, 4),
            'aliquota_iof'      => round($aliquotaIof, 4),
            'ir_devido'         => round($ir, 2),
            'iof_devido'        => round($iof, 2),
            'valor_liquido'     => round($valor - $ir - $iof, 2),

            'isento_ir'         => $produto['ir'] === 'ISENTO',
            'tem_come_cotas'    => isset($produto['come_cotas']),
            'fgc'               => $produto['fgc'],
            'nota'              => $produto['nota'],
        ];
    }

    /**
     * Projeta o saldo mês a mês, aplicando o come-cotas de maio e novembro
     * nos fundos que o têm.
     *
     * O IR final não é descontado a cada mês porque não é assim que ele é
     * cobrado: só sai no resgate. A linha traz o líquido de cada mês para
     * quem quiser saber "se eu tirasse tudo neste mês, quanto ficava".
     *
     * @param array<string, mixed> $inv
     * @param array<string, float> $indices
     * @return list<array<string, mixed>>
     */
    public static function projecao(array $inv, array $indices, int $meses = 12, ?string $hoje = null): array
    {
        $tipoChave = self::normalizarTipo((string) ($inv['tipo'] ?? 'OUTRO'));
        $produto   = self::tipo($tipoChave);
        $indexador = self::normalizarIndexadorDoTipo($tipoChave, (string) ($inv['indexador'] ?? ''));

        $mensal = self::mensalizar(self::taxaAnual($indexador, (float) ($inv['taxa'] ?? 0), $indices));

        $saldo    = (float) ($inv['valor'] ?? 0);
        $aplicado = (float) ($inv['valor_aplicado'] ?? 0);
        if ($aplicado <= 0.0) {
            $aplicado = $saldo;
        }
        // Come-cotas já pago no passado é desconhecido; a projeção parte do
        // rendimento acumulado que existe hoje.
        $irAntecipado = 0.0;

        $dias  = self::diasAplicado($inv['dt_aplicacao'] ?? null, $hoje) ?? 100_000;
        $data  = new \DateTimeImmutable($hoje ?? 'now');
        $linhas = [];

        for ($m = 1; $m <= $meses; $m++) {
            $data  = $data->modify('+1 month');
            $dias += 30;
            $saldo *= (1 + $mensal);

            $comeCotas = 0.0;
            $mes = (int) $data->format('n');
            if (isset($produto['come_cotas']) && ($mes === 5 || $mes === 11)) {
                $aliquota = $produto['come_cotas'] === 'CURTO'
                    ? self::COME_COTAS_CURTO
                    : self::COME_COTAS_LONGO;
                $comeCotas = max(0.0, ($saldo - $aplicado - $irAntecipado)) * $aliquota;
                // O come-cotas sai do próprio saldo, em cotas: o dinheiro
                // deixa de render dali em diante. É essa perda de juros
                // compostos que faz o fundo perder para o CDB no longo prazo.
                $saldo -= $comeCotas;
                $irAntecipado += $comeCotas;
            }

            $rendimento  = max(0.0, $saldo - $aplicado);
            $aliquotaIr  = self::aliquotaIr($tipoChave, $dias);
            // O que já foi antecipado abate o IR do resgate.
            $irNoResgate = max(0.0, $rendimento * $aliquotaIr - $irAntecipado);

            $linhas[] = [
                'mes'          => $data->format('Y-m'),
                'saldo'        => round($saldo, 2),
                'rendimento'   => round($rendimento, 2),
                'come_cotas'   => round($comeCotas, 2),
                'aliquota_ir'  => round($aliquotaIr, 4),
                'ir_no_resgate' => round($irNoResgate, 2),
                'liquido'      => round($saldo - $irNoResgate, 2),
            ];
        }

        return $linhas;
    }

    // ------------------------------------------------- entrada e saída da API

    /**
     * Ajusta os campos antes de gravar. É o gancho 'normalizar' do
     * CrudController, e existe porque estes campos dependem uns dos outros:
     * o indexador só faz sentido dentro do tipo escolhido.
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public static function normalizarCampos(array $v): array
    {
        $tipo = self::normalizarTipo((string) ($v['tipo'] ?? ''));
        $v['tipo']      = $tipo;
        $v['indexador'] = self::normalizarIndexadorDoTipo($tipo, (string) ($v['indexador'] ?? ''));

        // Faixa defensiva. O negativo existe de verdade: Tesouro Selic já foi
        // vendido com ágio, a "Selic - 0,01%".
        $v['taxa'] = max(-100.0, min(1000.0, (float) ($v['taxa'] ?? 0)));

        return $v;
    }

    /**
     * Acrescenta o bloco `calculo` a cada investimento da listagem. É o
     * gancho 'enriquecer' do CrudController.
     *
     * Fica aninhado, e não espalhado na raiz do item, para não haver dúvida
     * sobre o que é coluna do banco e o que é conta feita na hora.
     *
     * @param list<array<string, mixed>> $itens
     * @return list<array<string, mixed>>
     */
    public static function enriquecerLista(array $itens, int $uid): array
    {
        $indices = Controllers\SettingsController::indices($uid);
        foreach ($itens as &$item) {
            $item['calculo'] = self::calcular($item, $indices);
        }
        unset($item);
        return $itens;
    }

    /**
     * O retrato da carteira: totais, quanto ela rende por mês, quanto o Leão
     * levaria hoje e a projeção somada dos próximos meses.
     *
     * @param list<array<string, mixed>> $itens
     * @param array<string, float> $indices
     * @return array<string, mixed>
     */
    public static function resumo(array $itens, array $indices, int $meses = 12, ?string $hoje = null): array
    {
        $t = [
            'aplicado' => 0.0, 'atual' => 0.0, 'rendimento' => 0.0,
            'ir' => 0.0, 'iof' => 0.0, 'liquido' => 0.0,
            'rende_por_mes' => 0.0, 'rende_por_mes_liquido' => 0.0,
        ];
        $porClasse = [];
        $projecao  = [];
        $semData   = 0;

        foreach ($itens as $inv) {
            $c = self::calcular($inv, $indices, $hoje);

            $t['aplicado']   += $c['valor_aplicado'];
            $t['atual']      += (float) ($inv['valor'] ?? 0);
            $t['rendimento'] += $c['rendimento_bruto'];
            $t['ir']         += $c['ir_devido'];
            $t['iof']        += $c['iof_devido'];
            $t['liquido']    += $c['valor_liquido'];
            $t['rende_por_mes']         += $c['rende_por_mes'];
            $t['rende_por_mes_liquido'] += $c['rende_por_mes_liquido'];
            // Só conta quem seria afetado: em produto isento a data de
            // aplicação não muda imposto nenhum, e avisar seria ruído.
            $semData += ($c['sem_data_aplicacao'] && !$c['isento_ir']) ? 1 : 0;

            $classe = $c['classe'];
            $porClasse[$classe] ??= ['classe' => $classe, 'valor' => 0.0, 'rende_por_mes' => 0.0, 'itens' => 0];
            $porClasse[$classe]['valor']         += (float) ($inv['valor'] ?? 0);
            $porClasse[$classe]['rende_por_mes'] += $c['rende_por_mes'];
            $porClasse[$classe]['itens']++;

            foreach (self::projecao($inv, $indices, $meses, $hoje) as $i => $linha) {
                $projecao[$i] ??= ['mes' => $linha['mes'], 'saldo' => 0.0, 'come_cotas' => 0.0, 'liquido' => 0.0];
                $projecao[$i]['saldo']      += $linha['saldo'];
                $projecao[$i]['come_cotas'] += $linha['come_cotas'];
                $projecao[$i]['liquido']    += $linha['liquido'];
            }
        }

        foreach ($t as $k => $v) {
            $t[$k] = round($v, 2);
        }
        foreach ($porClasse as &$c) {
            $c['valor']         = round($c['valor'], 2);
            $c['rende_por_mes'] = round($c['rende_por_mes'], 2);
        }
        unset($c);
        foreach ($projecao as &$p) {
            $p['saldo']      = round($p['saldo'], 2);
            $p['come_cotas'] = round($p['come_cotas'], 2);
            $p['liquido']    = round($p['liquido'], 2);
        }
        unset($p);

        // A taxa média da carteira é ponderada pelo valor, não pela média das
        // taxas: R$ 100 mil a 1% pesam mais que R$ 100 a 2%.
        $t['taxa_mensal_media'] = $t['atual'] > 0 ? round($t['rende_por_mes'] / $t['atual'], 6) : 0.0;
        $t['taxa_mensal_media_liquida'] = $t['atual'] > 0
            ? round($t['rende_por_mes_liquido'] / $t['atual'], 6)
            : 0.0;
        $t['sem_data_aplicacao'] = $semData;

        return [
            'totais'     => $t,
            'por_classe' => array_values($porClasse),
            'projecao'   => array_values($projecao),
        ];
    }

    // ---------------------------------------------------------------- util

    /** @return array<string, mixed> */
    public static function tipo(string $chave): array
    {
        return self::TIPOS[self::normalizarTipo($chave)];
    }

    /**
     * Aceita a chave ("TESOURO_SELIC"), o nome comercial ("Tesouro Selic") e
     * qualquer variação de caixa, acento e pontuação entre os dois.
     *
     * A tolerância existe por causa da planilha: lá o produto é digitado à
     * mão, e "tesouro selic" precisa cair no mesmo lugar que a chave interna.
     */
    public static function normalizarTipo(string $tipo): string
    {
        $t = strtoupper(trim($tipo));
        if (isset(self::TIPOS[$t])) {
            return $t;
        }
        $chave = Valor::chave($tipo);
        if ($chave === '') {
            return 'OUTRO';
        }
        foreach (self::TIPOS as $k => $p) {
            if (Valor::chave($k) === $chave || Valor::chave($p['nome']) === $chave) {
                return $k;
            }
        }
        return 'OUTRO';
    }

    public static function normalizarIndexador(string $indexador): string
    {
        $i = strtoupper(trim($indexador));
        if (isset(self::INDEXADORES[$i])) {
            return $i;
        }
        $chave = Valor::chave($indexador);
        if ($chave === '') {
            return 'NENHUM';
        }
        foreach (self::INDEXADORES as $k => $ix) {
            if (Valor::chave($k) === $chave || Valor::chave($ix['nome']) === $chave) {
                return $k;
            }
        }
        return 'NENHUM';
    }

    /** O nome comercial do produto — o que aparece na planilha e na tela. */
    public static function nomeDoTipo(mixed $tipo): string
    {
        return self::tipo((string) $tipo)['nome'];
    }

    /**
     * O indexador precisa fazer sentido para o produto: Tesouro Selic não é
     * prefixado, e poupança não rende percentual do CDI. Combinação inválida
     * cai no padrão do próprio produto em vez de virar erro — quem digitou
     * "CDB prefixado 110% do CDI" quis dizer alguma coisa, e o padrão é o
     * palpite mais próximo.
     */
    public static function normalizarIndexadorDoTipo(string $tipo, string $indexador): string
    {
        $p = self::tipo($tipo);
        $i = self::normalizarIndexador($indexador);
        return in_array($i, $p['indexadores'], true) ? $i : $p['indexador_padrao'];
    }

    /** "110% do CDI", "IPCA + 6% a.a.", "Selic + 0,05% a.a." */
    public static function rotuloRentabilidade(string $indexador, float $taxa): string
    {
        $n = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');

        return match (self::normalizarIndexador($indexador)) {
            'CDI'       => $n($taxa) . '% do CDI',
            'SELIC'     => $taxa == 0.0 ? 'Selic' : 'Selic + ' . $n($taxa) . '% a.a.',
            'IPCA'      => 'IPCA + ' . $n($taxa) . '% a.a.',
            'PREFIXADO' => $n($taxa) . '% a.a.',
            'POUPANCA'  => 'Regra da poupança',
            default     => '—',
        };
    }

    /** Dias corridos entre a aplicação e hoje, ou null se a data não veio. */
    public static function diasAplicado(mixed $dtAplicacao, ?string $hoje = null): ?int
    {
        if (!is_string($dtAplicacao) || $dtAplicacao === '') {
            return null;
        }
        $inicio = Http::normalizarData(substr($dtAplicacao, 0, 10));
        if ($inicio === null) {
            return null;
        }
        $tz = new \DateTimeZone('UTC');
        $a = new \DateTimeImmutable($inicio, $tz);
        $b = new \DateTimeImmutable($hoje !== null ? $hoje : 'now', $tz);
        // Data futura (erro de digitação) conta como zero, não como negativo:
        // dia negativo cairia numa faixa de imposto que não existe.
        return max(0, (int) $a->diff($b)->format('%r%a'));
    }

    /**
     * O catálogo no formato que a interface consome, já sem a mecânica
     * interna das alíquotas.
     *
     * @return array<string, mixed>
     */
    public static function catalogo(): array
    {
        $tipos = [];
        foreach (self::TIPOS as $chave => $p) {
            $tipos[] = [
                'chave'            => $chave,
                'nome'             => $p['nome'],
                'classe'           => $p['classe'],
                'indexadores'      => $p['indexadores'],
                'indexador_padrao' => $p['indexador_padrao'],
                'taxa_padrao'      => $p['taxa_padrao'],
                'isento_ir'        => $p['ir'] === 'ISENTO',
                'ir'               => $p['ir'],
                'aliquota_fixa'    => $p['aliquota'] ?? null,
                'iof'              => $p['iof'],
                'come_cotas'       => isset($p['come_cotas']),
                'fgc'              => $p['fgc'],
                'nota'             => $p['nota'],
            ];
        }

        $indexadores = [];
        foreach (self::INDEXADORES as $chave => $i) {
            $indexadores[] = ['chave' => $chave] + $i;
        }

        return [
            'tipos'          => $tipos,
            'indexadores'    => $indexadores,
            'tabela_ir'      => self::IR_RENDA_FIXA,
            'tabela_ir_prev' => self::IR_PREVIDENCIA,
        ];
    }
}
