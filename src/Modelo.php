<?php
/**
 * O modelo de planilha do sistema — layout único de exportação e importação.
 *
 * Este arquivo é a fonte de verdade do formato: o Exporter escreve a partir
 * daqui e o leitor abaixo importa a partir daqui. Mudar um título de coluna
 * num lugar só é impossível por construção.
 *
 * O desenho é deliberadamente simples, ao contrário do modelo antigo
 * ("Controle Financ. Pessoal", que ainda é aceito na importação):
 *
 *   * uma aba por assunto, cada uma com uma tabela só;
 *   * cabeçalho na linha 1, dados a partir da linha 2, começando na coluna A;
 *   * coluna encontrada pelo NOME, não pela posição — reordenar colunas,
 *     apagar as que não interessam ou renomear de leve não quebra nada;
 *   * quase tudo é opcional: cada aba tem um punhado de colunas obrigatórias
 *     (marcadas com *) e o resto o sistema completa.
 */

declare(strict_types=1);

namespace MinhasContas;

use MinhasContas\Xlsx\Reader;
use MinhasContas\Xlsx\Valor;

final class Modelo
{
    public const ARQUIVO_VAZIO = 'modelo-minhas-contas.xlsx';

    public const ABA_INSTRUCOES = 'Instruções';
    public const ABA_PARAMETROS = 'Parâmetros';

    /** Até onde procurar a linha de cabeçalho — cabe um título acima da tabela. */
    private const MAX_LINHAS_CABECALHO = 10;

    /**
     * Parâmetros da aba Parâmetros: chave em settings => rótulo exibido.
     *
     * @var array<string, array{titulo: string, ajuda: string, estilo?: string}>
     */
    public const PARAMETROS = [
        'receita_mensal' => [
            'titulo' => 'Receita mensal',
            'ajuda'  => 'Quanto entra por mês, em média.',
            'estilo' => 'dinheiro',
        ],
        'custo_vida_mensal' => [
            'titulo' => 'Custo de vida mensal',
            'ajuda'  => 'Quanto sai por mês para manter o padrão atual.',
            'estilo' => 'dinheiro',
        ],
        'fator_reserva' => [
            'titulo' => 'Fator da reserva',
            'ajuda'  => 'Quantos meses de custo de vida a reserva de emergência precisa cobrir (ex.: 6).',
        ],
        'taxa_cdi_anual' => [
            'titulo' => 'CDI (% ao ano)',
            'ajuda'  => 'Usado para calcular quanto rendem os investimentos atrelados ao CDI.',
        ],
        'taxa_selic_anual' => [
            'titulo' => 'Selic (% ao ano)',
            'ajuda'  => 'Usada no Tesouro Selic e na regra da poupança.',
        ],
        'taxa_ipca_anual' => [
            'titulo' => 'IPCA (% ao ano)',
            'ajuda'  => 'Usado nos papéis IPCA+.',
        ],
        'taxa_tr_anual' => [
            'titulo' => 'TR (% ao ano)',
            'ajuda'  => 'Entra na conta da poupança.',
        ],
    ];

    /**
     * As abas de dados, na ordem em que aparecem na planilha.
     *
     * A chave é o destino do que for lido; 'colunas' descreve cada campo:
     *
     *   campo    nome no banco / no payload da importação
     *   titulo   o que aparece no cabeçalho da planilha
     *   tipo     texto | numero | inteiro | data | opcao
     *   obrig    linha sem esse campo é ignorada (é assim que subtotal e
     *            anotação no meio da tabela são descartados em silêncio)
     *   aliases  outros títulos aceitos na importação
     *   ajuda    a explicação que vai para a aba Instruções
     *
     * @var array<string, array{nome: string, resumo: string, colunas: list<array<string, mixed>>}>
     */
    public const ABAS = [
        'transactions' => [
            'nome'   => 'Lançamentos',
            'resumo' => 'O que entrou e o que saiu. É a aba principal — as outras podem ficar vazias.',
            'colunas' => [
                [
                    'campo' => 'dt_venc', 'titulo' => 'Vencimento', 'tipo' => 'data',
                    'obrig' => true, 'larg' => 14,
                    'aliases' => ['Data', 'Data de vencimento', 'Dt Venc'],
                    'ajuda' => 'DD/MM/AAAA. É a data que posiciona o lançamento no mês.',
                ],
                [
                    'campo' => 'categoria', 'titulo' => 'Categoria', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 120, 'larg' => 22,
                    'ajuda' => 'Ex.: Alimentação. Se ainda não existir, é criada na importação.',
                ],
                [
                    'campo' => 'subcategoria', 'titulo' => 'Subcategoria', 'tipo' => 'texto',
                    'max' => 120, 'larg' => 22,
                    'ajuda' => 'Detalhe dentro da categoria — ex.: Mercado. Pode ficar em branco.',
                ],
                [
                    'campo' => 'valor', 'titulo' => 'Valor', 'tipo' => 'numero',
                    'obrig' => true, 'larg' => 14, 'estilo' => 'dinheiro',
                    'aliases' => ['Valor (R$)'],
                    'ajuda' => 'Sempre positivo: quem decide se entra ou sai é a coluna Tipo.',
                ],
                [
                    'campo' => 'tipo', 'titulo' => 'Tipo', 'tipo' => 'opcao',
                    'opcoes' => Categories::TIPOS, 'larg' => 12,
                    'ajuda' => 'RECEITA ou DESPESA. Em branco, vale o que estiver em Classificações.',
                ],
                [
                    'campo' => 'status', 'titulo' => 'Status', 'tipo' => 'opcao',
                    'opcoes' => Filters::STATUS, 'padrao' => 'Previsto', 'larg' => 13,
                    'aliases' => ['Situação'],
                    'ajuda' => 'Previsto (ainda vai acontecer) ou Realizado (já aconteceu). Em branco, Previsto.',
                ],
                [
                    'campo' => 'instituicao', 'titulo' => 'Instituição', 'tipo' => 'texto',
                    'max' => 120, 'larg' => 20,
                    'aliases' => ['Banco', 'Conta', 'Cartão'],
                    'ajuda' => 'Onde o dinheiro passou: banco, cartão, carteira.',
                ],
                [
                    'campo' => 'pessoa', 'titulo' => 'Pessoa', 'tipo' => 'texto',
                    'max' => 120, 'larg' => 18,
                    'aliases' => ['Responsável'],
                    'ajuda' => 'De quem é o gasto, ou de quem você tem a receber.',
                ],
                [
                    'campo' => 'dt_compra', 'titulo' => 'Data da compra', 'tipo' => 'data',
                    'larg' => 16,
                    'aliases' => ['Dt Compra', 'Compra'],
                    'ajuda' => 'Quando a compra foi feita, se for diferente do vencimento.',
                ],
                [
                    'campo' => 'obs', 'titulo' => 'Observação', 'tipo' => 'texto',
                    'max' => 2000, 'larg' => 34,
                    'aliases' => ['Obs', 'Descrição', 'Detalhe'],
                    'ajuda' => 'Texto livre.',
                ],
            ],
        ],

        'categories' => [
            'nome'   => 'Classificações',
            'resumo' => 'Seu plano de contas. Preencher aqui é opcional: toda categoria usada'
                . ' em Lançamentos é criada sozinha. Serve para definir tipo, grupo e meta.',
            'colunas' => [
                [
                    'campo' => 'categoria', 'titulo' => 'Categoria', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 120, 'larg' => 24,
                    'ajuda' => 'Ex.: Alimentação.',
                ],
                [
                    'campo' => 'subcategoria', 'titulo' => 'Subcategoria', 'tipo' => 'texto',
                    'max' => 120, 'larg' => 24,
                    'ajuda' => 'Ex.: Mercado. Em branco, a categoria vira classificação sozinha.',
                ],
                [
                    'campo' => 'tipo', 'titulo' => 'Tipo', 'tipo' => 'opcao',
                    'opcoes' => Categories::TIPOS, 'padrao' => 'DESPESA', 'larg' => 12,
                    'ajuda' => 'RECEITA ou DESPESA. Em branco, DESPESA.',
                ],
                [
                    'campo' => 'grupo', 'titulo' => 'Grupo', 'tipo' => 'opcao',
                    'opcoes' => Categories::GRUPOS, 'padrao' => 'OPERACIONAL', 'larg' => 20,
                    'ajuda' => 'OPERACIONAL é o dia a dia; NÃO OPERACIONAL é o que sai da rotina.',
                ],
                [
                    'campo' => 'meta_mes', 'titulo' => 'Meta mensal', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 14, 'estilo' => 'dinheiro',
                    'aliases' => ['Meta Mês', 'Meta'],
                    'ajuda' => 'Quanto você pretende gastar (ou receber) por mês nessa classificação.',
                ],
            ],
        ],

        'projects' => [
            'nome'   => 'Projetos',
            'resumo' => 'O que você quer realizar e quanto custa.',
            'colunas' => [
                [
                    'campo' => 'descricao', 'titulo' => 'Descrição', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 255, 'larg' => 34,
                    'aliases' => ['Projeto'],
                    'ajuda' => 'Ex.: Trocar de carro.',
                ],
                [
                    'campo' => 'valor', 'titulo' => 'Valor', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 14, 'estilo' => 'dinheiro',
                    'ajuda' => 'Quanto o projeto custa.',
                ],
                [
                    'campo' => 'ano', 'titulo' => 'Ano', 'tipo' => 'inteiro', 'larg' => 8,
                    'ajuda' => 'Ano em que você pretende realizar.',
                ],
                [
                    'campo' => 'prazo', 'titulo' => 'Prazo', 'tipo' => 'texto',
                    'max' => 40, 'larg' => 18,
                    'ajuda' => 'Texto livre — ex.: Curto, Médio, Longo.',
                ],
            ],
        ],

        'investimentos' => [
            'nome'   => 'Investimentos',
            'resumo' => 'Onde o seu dinheiro está aplicado.',
            'colunas' => [
                [
                    'campo' => 'instituicao', 'titulo' => 'Instituição', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 120, 'larg' => 22,
                    'aliases' => ['Banco', 'Corretora'],
                    'ajuda' => 'Onde o investimento está.',
                ],
                [
                    'campo' => 'ativo', 'titulo' => 'Ativo', 'tipo' => 'texto',
                    'max' => 120, 'larg' => 24,
                    'ajuda' => 'Ex.: Tesouro Selic 2029, CDB do banco X, PETR4.',
                ],
                [
                    'campo' => 'tipo', 'titulo' => 'Produto', 'tipo' => 'texto',
                    'max' => 40, 'larg' => 22, 'saida' => [Investimentos::class, 'nomeDoTipo'],
                    'ajuda' => 'CDB, LCI, LCA, Tesouro Selic, Tesouro IPCA+, Poupança, Fundo DI,'
                        . ' Ações, FII, Previdência... É o que define o imposto.',
                ],
                [
                    'campo' => 'indexador', 'titulo' => 'Indexador', 'tipo' => 'texto',
                    'max' => 20, 'larg' => 16,
                    'aliases' => ['Como rende'],
                    'ajuda' => 'CDI, SELIC, IPCA, PREFIXADO, POUPANCA ou NENHUM.',
                ],
                [
                    'campo' => 'taxa', 'titulo' => 'Taxa', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 12,
                    'aliases' => ['Rentabilidade'],
                    'ajuda' => 'Depende do indexador: 110 (% do CDI), 6 (IPCA + 6% a.a.),'
                        . ' 13,5 (prefixado a.a.), 0,05 (Selic + 0,05% a.a.).',
                ],
                [
                    'campo' => 'valor_aplicado', 'titulo' => 'Valor aplicado', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 16, 'estilo' => 'dinheiro',
                    'aliases' => ['Principal', 'Aplicado'],
                    'ajuda' => 'Quanto entrou. O IR só incide sobre a diferença para o valor atual.',
                ],
                [
                    'campo' => 'valor', 'titulo' => 'Valor', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 14, 'estilo' => 'dinheiro',
                    'aliases' => ['Valor atual', 'Saldo'],
                    'ajuda' => 'Saldo de hoje.',
                ],
                [
                    'campo' => 'dt_aplicacao', 'titulo' => 'Data da aplicação', 'tipo' => 'data',
                    'larg' => 18,
                    'aliases' => ['Aplicação', 'Data de aplicação'],
                    'ajuda' => 'DD/MM/AAAA. É o que define a faixa da tabela regressiva do IR.',
                ],
                [
                    'campo' => 'dt_vencimento', 'titulo' => 'Vencimento', 'tipo' => 'data',
                    'larg' => 16,
                    'ajuda' => 'Opcional, para os papéis que têm prazo.',
                ],
                [
                    'campo' => 'fixa_var', 'titulo' => 'Renda', 'tipo' => 'texto',
                    'max' => 40, 'larg' => 16,
                    'aliases' => ['Fixa/Var', 'Fixa ou variável', 'Renda fixa ou variável'],
                    'ajuda' => 'Fixa ou Variável.',
                ],
                [
                    'campo' => 'prazo_projeto', 'titulo' => 'Prazo / Projeto', 'tipo' => 'texto',
                    'max' => 40, 'larg' => 20,
                    'aliases' => ['Prazo', 'Projeto'],
                    'ajuda' => 'Para quando é esse dinheiro, ou a que projeto ele pertence.',
                ],
            ],
        ],

        'bens' => [
            'nome'   => 'Bens',
            'resumo' => 'O que você tem: imóvel, carro, moto.',
            'colunas' => [
                [
                    'campo' => 'descricao', 'titulo' => 'Bem', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 255, 'larg' => 34,
                    'aliases' => ['Descrição'],
                    'ajuda' => 'Ex.: Apartamento.',
                ],
                [
                    'campo' => 'valor', 'titulo' => 'Valor', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 16, 'estilo' => 'dinheiro',
                    'ajuda' => 'Quanto vale hoje.',
                ],
                [
                    'campo' => 'saldo_devedor', 'titulo' => 'Saldo devedor', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 16, 'estilo' => 'dinheiro',
                    'ajuda' => 'Quanto ainda falta pagar dele. Zero se estiver quitado.',
                ],
            ],
        ],

        'dividas' => [
            'nome'   => 'Dívidas',
            'resumo' => 'O que você deve.',
            'colunas' => [
                [
                    'campo' => 'descricao', 'titulo' => 'Dívida', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 255, 'larg' => 34,
                    'aliases' => ['Descrição'],
                    'ajuda' => 'Ex.: Financiamento do carro.',
                ],
                [
                    'campo' => 'num_parcelas', 'titulo' => 'Nº de parcelas', 'tipo' => 'inteiro',
                    'larg' => 14,
                    'aliases' => ['Parcelas', 'Num Parcelas'],
                    'ajuda' => 'Quantas parcelas ainda faltam.',
                ],
                [
                    'campo' => 'valor_parcela', 'titulo' => 'Valor da parcela', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 17, 'estilo' => 'dinheiro',
                    'ajuda' => 'Quanto custa cada parcela.',
                ],
                [
                    'campo' => 'saldo_devedor', 'titulo' => 'Saldo devedor', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 16, 'estilo' => 'dinheiro',
                    'ajuda' => 'Quanto falta pagar no total.',
                ],
            ],
        ],

        'institutions' => [
            'nome'   => 'Instituições',
            'resumo' => 'Bancos, cartões e carteiras. Opcional: toda instituição citada'
                . ' em Lançamentos é criada sozinha.',
            'colunas' => [
                [
                    'campo' => 'nome', 'titulo' => 'Nome', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 120, 'larg' => 26,
                    'aliases' => ['Instituição'],
                    'ajuda' => 'O mesmo nome que você usa na coluna Instituição dos lançamentos.',
                ],
                [
                    'campo' => 'tipo', 'titulo' => 'Tipo', 'tipo' => 'texto',
                    'max' => 40, 'larg' => 20,
                    'ajuda' => 'Ex.: Conta, Cartão de Crédito, Investimento.',
                ],
                [
                    'campo' => 'saldo_inicial', 'titulo' => 'Saldo inicial', 'tipo' => 'numero',
                    'padrao' => 0.0, 'larg' => 16, 'estilo' => 'dinheiro',
                    'ajuda' => 'Saldo de partida da conta.',
                ],
                [
                    'campo' => 'descricao', 'titulo' => 'Descrição', 'tipo' => 'texto',
                    'max' => 255, 'larg' => 30,
                    'ajuda' => 'Texto livre.',
                ],
            ],
        ],

        'people' => [
            'nome'   => 'Pessoas',
            'resumo' => 'Com quem você divide contas. Opcional: toda pessoa citada em'
                . ' Lançamentos é criada sozinha.',
            'colunas' => [
                [
                    'campo' => 'nome', 'titulo' => 'Nome', 'tipo' => 'texto',
                    'obrig' => true, 'max' => 120, 'larg' => 26,
                    'aliases' => ['Pessoa'],
                    'ajuda' => 'O mesmo nome que você usa na coluna Pessoa dos lançamentos.',
                ],
            ],
        ],
    ];

    /**
     * A planilha enviada está neste modelo?
     *
     * Basta uma das abas próprias: quem só quer mandar lançamentos apaga o
     * resto do arquivo, e isso continua sendo o modelo novo.
     */
    public static function reconhece(Reader $wb): bool
    {
        foreach ([self::ABAS['transactions']['nome'], self::ABAS['categories']['nome'], self::ABA_PARAMETROS] as $nome) {
            if (self::acharAba($wb, $nome) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lê a planilha inteira no formato que ImportController::gravar consome.
     *
     * @return array{
     *   settings: array<string, float>,
     *   categories: list<array<string, mixed>>,
     *   transactions: list<array<string, mixed>>,
     *   projects: list<array<string, mixed>>,
     *   patrimonio: array{investimentos: list<array<string,mixed>>, bens: list<array<string,mixed>>, dividas: list<array<string,mixed>>},
     *   institutions: list<array<string, mixed>>,
     *   people: list<array<string, mixed>>
     * }
     */
    public static function ler(Reader $wb): array
    {
        $lidas = [];
        foreach (self::ABAS as $chave => $aba) {
            $lidas[$chave] = self::lerAba($wb, $aba);
        }

        return [
            'settings'     => self::lerParametros($wb),
            'categories'   => array_map(self::completarClassificacao(...), $lidas['categories']),
            'transactions' => array_map(self::completarClassificacao(...), $lidas['transactions']),
            'projects'     => $lidas['projects'],
            'patrimonio'   => [
                'investimentos' => array_map(self::completarInvestimento(...), $lidas['investimentos']),
                'bens'          => $lidas['bens'],
                'dividas'       => $lidas['dividas'],
            ],
            'institutions' => $lidas['institutions'],
            'people'       => $lidas['people'],
        ];
    }

    // ---------------------------------------------------------------- abas

    /**
     * Acha a aba pelo nome normalizado — "Lançamentos", "LANCAMENTOS" e
     * "lancamentos" são a mesma aba. O Google Sheets, ao exportar, às vezes
     * devolve o nome sem acento.
     */
    private static function acharAba(Reader $wb, string $nome): ?string
    {
        $alvo = Valor::chave($nome);
        foreach ($wb->nomesDasAbas() as $existente) {
            if (Valor::chave($existente) === $alvo) {
                return $existente;
            }
        }
        return null;
    }

    /**
     * @param array{nome: string, colunas: list<array<string, mixed>>} $aba
     * @return list<array<string, mixed>>
     */
    private static function lerAba(Reader $wb, array $aba): array
    {
        $nome = self::acharAba($wb, $aba['nome']);
        if ($nome === null) {
            return [];
        }

        $mapa  = null;
        $itens = [];
        foreach ($wb->linhas($nome) as $numero => $linha) {
            if ($mapa === null) {
                $mapa = self::mapearCabecalho($linha, $aba['colunas']);
                if ($mapa === null && $numero >= self::MAX_LINHAS_CABECALHO) {
                    // Sem cabeçalho reconhecível nas primeiras linhas: a aba
                    // tem outro conteúdo. Ler o resto só produziria lixo.
                    break;
                }
                continue;
            }
            $item = self::lerLinha($linha, $aba['colunas'], $mapa);
            if ($item !== null) {
                $itens[] = $item;
            }
        }

        return $itens;
    }

    /**
     * Casa os títulos da linha com as colunas do modelo.
     *
     * Devolve campo => índice da coluna, ou null quando a linha não é o
     * cabeçalho — o que também acontece de propósito quando falta uma coluna
     * obrigatória: sem ela não há tabela para ler.
     *
     * @param list<mixed> $linha
     * @param list<array<string, mixed>> $colunas
     * @return array<string, int>|null
     */
    private static function mapearCabecalho(array $linha, array $colunas): ?array
    {
        $porTitulo = [];
        foreach ($linha as $i => $bruto) {
            $t = Valor::texto($bruto, 120);
            if ($t === null) {
                continue;
            }
            $chave = Valor::chave($t);
            // A primeira ocorrência vence: cabeçalho com título repetido não
            // faz a segunda coluna roubar o campo da primeira.
            if ($chave !== '' && !isset($porTitulo[$chave])) {
                $porTitulo[$chave] = $i;
            }
        }
        if ($porTitulo === []) {
            return null;
        }

        // Duas passadas: o título oficial de toda coluna é resolvido antes de
        // qualquer alias. Senão o alias "Descrição" de uma coluna tomaria a
        // coluna que é mesmo o "Descrição" oficial de outra.
        $mapa = [];
        foreach ([false, true] as $usarAliases) {
            foreach ($colunas as $c) {
                $campo = (string) $c['campo'];
                if (isset($mapa[$campo])) {
                    continue;
                }
                /** @var list<string> $rotulos */
                $rotulos = $usarAliases ? ($c['aliases'] ?? []) : [(string) $c['titulo']];
                foreach ($rotulos as $rotulo) {
                    $i = $porTitulo[Valor::chave($rotulo)] ?? null;
                    if ($i !== null && !in_array($i, $mapa, true)) {
                        $mapa[$campo] = $i;
                        break;
                    }
                }
            }
        }

        foreach ($colunas as $c) {
            if (($c['obrig'] ?? false) && !isset($mapa[(string) $c['campo']])) {
                return null;
            }
        }
        // Uma coluna reconhecida basta para a aba Pessoas, que só tem uma.
        return count($mapa) >= min(2, count($colunas)) ? $mapa : null;
    }

    /**
     * @param list<mixed> $linha
     * @param list<array<string, mixed>> $colunas
     * @param array<string, int> $mapa
     * @return array<string, mixed>|null
     */
    private static function lerLinha(array $linha, array $colunas, array $mapa): ?array
    {
        $vazia = true;
        foreach ($mapa as $i) {
            if (Valor::texto($linha[$i] ?? null, 8) !== null) {
                $vazia = false;
                break;
            }
        }
        if ($vazia) {
            return null;
        }

        $item = [];
        foreach ($colunas as $c) {
            $campo = (string) $c['campo'];
            $i     = $mapa[$campo] ?? null;
            $bruto = $i === null ? null : ($linha[$i] ?? null);
            $v     = self::converter($bruto, $c);

            // Linha sem um campo obrigatório é subtotal, anotação ou sobra de
            // rodapé — descartada sem alarde, como no modelo antigo.
            if ($v === null && ($c['obrig'] ?? false)) {
                return null;
            }
            $item[$campo] = $v;
        }

        return $item;
    }

    /** @param array<string, mixed> $c */
    private static function converter(mixed $bruto, array $c): mixed
    {
        $padrao = $c['padrao'] ?? null;

        return match ((string) $c['tipo']) {
            'data'    => Valor::data($bruto),
            'numero'  => Valor::numero($bruto) ?? $padrao,
            'inteiro' => Valor::inteiro($bruto) ?? $padrao,
            'opcao'   => Valor::opcao($bruto, $c['opcoes'], is_string($padrao) ? $padrao : null),
            default   => Valor::texto($bruto, (int) ($c['max'] ?? 190)),
        };
    }

    /**
     * A classificação é derivada, nunca digitada: é sempre
     * "CATEGORIA - Subcategoria", montada pela mesma função que a tela de
     * cadastro usa. Uma coluna a menos para preencher e nenhuma chance de a
     * planilha e o cadastro divergirem.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function completarClassificacao(array $item): array
    {
        $sub = $item['subcategoria'] ?? null;
        $item['classificacao'] = Categories::montarClassificacao(
            (string) $item['categoria'],
            $sub !== null ? (string) $sub : null
        );
        return $item;
    }

    /**
     * O produto e o indexador viram as chaves internas ainda na leitura.
     *
     * A planilha mostra "Tesouro Selic" e aceita também "TESOURO_SELIC" ou
     * "tesouro selic"; daqui para dentro existe uma forma só. É a mesma ideia
     * da classificação derivada dos lançamentos.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function completarInvestimento(array $item): array
    {
        $tipo = Investimentos::normalizarTipo((string) ($item['tipo'] ?? ''));
        $item['tipo']      = $tipo;
        $item['indexador'] = Investimentos::normalizarIndexadorDoTipo(
            $tipo,
            (string) ($item['indexador'] ?? '')
        );
        return $item;
    }

    // ---------------------------------------------------------- parâmetros

    /**
     * Aba Parâmetros: uma linha por parâmetro, rótulo numa coluna e valor na
     * outra. Lida pelo rótulo, então a ordem das linhas não importa.
     *
     * @return array<string, float>
     */
    private static function lerParametros(Reader $wb): array
    {
        $settings = [];
        $nome = self::acharAba($wb, self::ABA_PARAMETROS);
        if ($nome === null) {
            return $settings;
        }

        $porRotulo = [];
        foreach (self::PARAMETROS as $chave => $p) {
            $porRotulo[Valor::chave($p['titulo'])] = $chave;
        }

        foreach ($wb->linhas($nome) as $linha) {
            $rotulo = null;
            foreach ($linha as $bruto) {
                $t = Valor::texto($bruto, 120);
                if ($rotulo === null) {
                    // Primeira célula com texto é o rótulo...
                    if ($t !== null && isset($porRotulo[Valor::chave($t)])) {
                        $rotulo = $porRotulo[Valor::chave($t)];
                    }
                    continue;
                }
                // ...e o primeiro número depois dela é o valor.
                $n = Valor::numero($bruto);
                if ($n !== null) {
                    $settings[$rotulo] = $n;
                    break;
                }
            }
        }

        return $settings;
    }
}
