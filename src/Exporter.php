<?php
/**
 * Escreve o modelo de planilha — em branco, para preencher, ou com os dados
 * da conta, para servir de backup.
 *
 * As duas saídas vêm da mesma definição em Modelo: o arquivo exportado é
 * exatamente o modelo que a importação sabe ler, então exportar, mexer no
 * Excel e importar de volta é um caminho fechado.
 *
 * As consultas ficam em dados(); o resto trabalha sobre arrays simples. É o
 * que permite testar a planilha inteira sem banco nenhum.
 */

declare(strict_types=1);

namespace MinhasContas;

use MinhasContas\Xlsx\Writer;

final class Exporter
{
    /**
     * Estrutura vazia — o mesmo formato que Modelo::ler() devolve e que
     * ImportController::gravar() consome.
     *
     * @return array<string, mixed>
     */
    public static function vazio(): array
    {
        return [
            'settings'     => [],
            'categories'   => [],
            'transactions' => [],
            'projects'     => [],
            'patrimonio'   => ['investimentos' => [], 'bens' => [], 'dividas' => []],
            'institutions' => [],
            'people'       => [],
        ];
    }

    /**
     * Tudo o que a conta tem, pronto para virar planilha.
     *
     * @return array<string, mixed>
     */
    public static function dados(int $uid): array
    {
        $settings = [];
        foreach (Database::todos('SELECT chave, valor FROM settings WHERE user_id = ?', [$uid]) as $s) {
            $settings[(string) $s['chave']] = (float) $s['valor'];
        }

        return [
            'settings'     => $settings,
            'categories'   => Database::todos(
                'SELECT * FROM categories WHERE user_id = ? ORDER BY tipo DESC, categoria, subcategoria',
                [$uid]
            ),
            'transactions' => Database::todos(
                'SELECT * FROM transactions WHERE user_id = ? ORDER BY dt_venc, id',
                [$uid]
            ),
            'projects'     => Database::todos(
                'SELECT * FROM projects WHERE user_id = ? ORDER BY ano, descricao',
                [$uid]
            ),
            'patrimonio'   => [
                'investimentos' => Database::todos(
                    'SELECT * FROM investments WHERE user_id = ? ORDER BY instituicao, id',
                    [$uid]
                ),
                'bens'    => Database::todos('SELECT * FROM assets WHERE user_id = ? ORDER BY descricao', [$uid]),
                'dividas' => Database::todos('SELECT * FROM debts WHERE user_id = ? ORDER BY descricao', [$uid]),
            ],
            'institutions' => Database::todos('SELECT * FROM institutions WHERE user_id = ? ORDER BY nome', [$uid]),
            'people'       => Database::todos('SELECT * FROM people WHERE user_id = ? ORDER BY nome', [$uid]),
        ];
    }

    /**
     * Monta a planilha.
     *
     * @param array<string, mixed> $dados
     */
    public static function planilha(array $dados, bool $comExemplo = false): Writer
    {
        $w = new Writer();

        // No modelo em branco as instruções vêm primeiro, que é o que a
        // pessoa precisa ler; no arquivo com dados elas vão para o fim, para
        // a planilha abrir já nos lançamentos.
        if ($comExemplo) {
            self::abaInstrucoes($w);
        }

        foreach (Modelo::ABAS as $chave => $aba) {
            self::abaDeDados($w, $aba, self::linhasDe($dados, $chave));
        }
        self::abaParametros($w, $dados['settings'] ?? []);

        if ($comExemplo) {
            self::abaExemplo($w);
        } else {
            self::abaInstrucoes($w);
        }

        return $w;
    }

    /** O modelo em branco: cabeçalhos, instruções e um exemplo preenchido. */
    public static function modeloVazio(): Writer
    {
        return self::planilha(self::vazio(), true);
    }

    /** minhas-contas-2026-08-24.xlsx */
    public static function nomeArquivo(): string
    {
        return 'minhas-contas-' . date('Y-m-d') . '.xlsx';
    }

    // ---------------------------------------------------------- abas de dados

    /**
     * Os itens de uma aba, sabendo que patrimônio mora um nível abaixo.
     *
     * @param array<string, mixed> $dados
     * @return list<array<string, mixed>>
     */
    private static function linhasDe(array $dados, string $chave): array
    {
        if (in_array($chave, ['investimentos', 'bens', 'dividas'], true)) {
            /** @var array<string, list<array<string, mixed>>> $pat */
            $pat = $dados['patrimonio'] ?? [];
            return $pat[$chave] ?? [];
        }
        /** @var list<array<string, mixed>> $itens */
        $itens = $dados[$chave] ?? [];
        return $itens;
    }

    /**
     * Uma aba de tabela: cabeçalho na linha 1, dados a partir da linha 2.
     *
     * @param array{nome: string, colunas: list<array<string, mixed>>} $aba
     * @param list<array<string, mixed>> $itens
     */
    private static function abaDeDados(Writer $w, array $aba, array $itens): void
    {
        $colunas = $aba['colunas'];
        $w->aba($aba['nome']);

        $titulos  = [];
        $larguras = [];
        foreach ($colunas as $c) {
            $titulos[]  = self::rotulo($c);
            $larguras[] = (float) ($c['larg'] ?? 16);
        }
        $w->cabecalho(1, 1, $titulos, Writer::CABECALHO);
        $w->larguras($larguras);
        $w->congelar(1);
        $w->filtro('A1:' . Writer::letraDaColuna(count($colunas)) . '1');

        $linha = 2;
        foreach ($itens as $item) {
            foreach ($colunas as $i => $c) {
                $campo = (string) $c['campo'];
                $w->set($linha, $i + 1, ...self::celula($item[$campo] ?? null, $c));
            }
            $linha++;
        }
    }

    /**
     * Valor e estilo de uma célula, conforme o tipo declarado no modelo.
     *
     * @param array<string, mixed> $c
     * @return array{0: mixed, 1: int}
     */
    private static function celula(mixed $v, array $c): array
    {
        if ($v === null || $v === '') {
            return [null, Writer::NORMAL];
        }

        // Algumas colunas guardam uma chave interna e mostram outra coisa na
        // planilha: 'TESOURO_SELIC' vira "Tesouro Selic". A leitura de volta
        // aceita as duas formas.
        if (isset($c['saida'])) {
            $v = ($c['saida'])($v);
        }

        return match ((string) $c['tipo']) {
            'data'    => [(string) $v, Writer::DATA],
            'numero'  => [round((float) $v, 2), ($c['estilo'] ?? '') === 'dinheiro' ? Writer::DINHEIRO : Writer::NORMAL],
            'inteiro' => [(int) $v, Writer::NORMAL],
            default   => [(string) $v, Writer::NORMAL],
        };
    }

    /** Título da coluna, com o " *" que marca o que é obrigatório. */
    private static function rotulo(array $c): string
    {
        return (string) $c['titulo'] . (($c['obrig'] ?? false) ? ' *' : '');
    }

    // ----------------------------------------------------------- parâmetros

    /** @param array<string, float> $settings */
    private static function abaParametros(Writer $w, array $settings): void
    {
        $w->aba(Modelo::ABA_PARAMETROS);
        $w->cabecalho(1, 1, ['Parâmetro', 'Valor', 'O que é'], Writer::CABECALHO);
        $w->larguras([26, 16, 74]);
        $w->congelar(1);

        $linha = 2;
        foreach (Modelo::PARAMETROS as $chave => $p) {
            $estilo = ($p['estilo'] ?? '') === 'dinheiro' ? Writer::DINHEIRO : Writer::NORMAL;
            $w->set($linha, 1, $p['titulo'])
              ->set($linha, 2, round((float) ($settings[$chave] ?? 0), 2), $estilo)
              ->set($linha, 3, $p['ajuda']);
            $linha++;
        }
    }

    // ------------------------------------------------------------ instruções

    /**
     * A aba de instruções é gerada a partir da própria definição do modelo —
     * acrescentar uma coluna em Modelo::ABAS documenta a coluna aqui sozinho,
     * sem chance de o texto e o formato divergirem.
     */
    private static function abaInstrucoes(Writer $w): void
    {
        $w->aba(Modelo::ABA_INSTRUCOES);
        $w->larguras([30, 96]);

        $linha = 1;
        $w->set($linha++, 1, 'Modelo Minhas Contas', Writer::TITULO);
        $linha++;

        foreach (self::COMO_USAR as $texto) {
            $w->set($linha++, 1, $texto);
        }
        $linha++;

        foreach (Modelo::ABAS as $aba) {
            $w->set($linha, 1, 'Aba "' . $aba['nome'] . '"', Writer::CABECALHO)
              ->set($linha, 2, $aba['resumo'], Writer::CABECALHO);
            $linha++;
            foreach ($aba['colunas'] as $c) {
                $w->set($linha, 1, self::rotulo($c), Writer::NEGRITO)
                  ->set($linha, 2, (string) ($c['ajuda'] ?? ''));
                $linha++;
            }
            $linha++;
        }

        $w->set($linha, 1, 'Aba "' . Modelo::ABA_PARAMETROS . '"', Writer::CABECALHO)
          ->set($linha, 2, 'Os números que o sistema usa nos cálculos de reserva.', Writer::CABECALHO);
        $linha++;
        foreach (Modelo::PARAMETROS as $p) {
            $w->set($linha, 1, $p['titulo'], Writer::NEGRITO)
              ->set($linha, 2, $p['ajuda']);
            $linha++;
        }
    }

    /** @var list<string> */
    private const COMO_USAR = [
        'Preencha o que quiser e importe em Configuração → Planilha. Nada aqui é obrigatório'
            . ' além das colunas marcadas com *.',
        '1. Cabeçalho na linha 1, dados a partir da linha 2. Não apague a linha do cabeçalho.',
        '2. As colunas são encontradas pelo nome, não pela posição: pode reordenar as colunas'
            . ' e apagar as que não usa.',
        '3. Aba que você não for usar pode ficar vazia ou ser apagada — só a aba Lançamentos'
            . ' costuma ser indispensável.',
        '4. Datas em DD/MM/AAAA. Valores sempre positivos: quem diz se entra ou sai é a coluna Tipo.',
        '5. Categoria e subcategoria não cadastradas são criadas na importação, assim como'
            . ' pessoas e instituições citadas nos lançamentos.',
        '6. Linha sem as colunas obrigatórias é ignorada em silêncio — dá para deixar subtotal'
            . ' e anotação no meio da tabela sem estragar a importação.',
        '7. Na hora de importar, "substituir" apaga os lançamentos, os projetos e o patrimônio'
            . ' da conta antes de gravar. Sem isso, o conteúdo é somado ao que já existe.',
    ];

    // -------------------------------------------------------------- exemplo

    /**
     * Um punhado de lançamentos preenchidos, para copiar e colar.
     *
     * Fica numa aba própria, com um nome que o importador não reconhece: o
     * exemplo nunca entra na conta de ninguém por engano.
     */
    private static function abaExemplo(Writer $w): void
    {
        $colunas = Modelo::ABAS['transactions']['colunas'];
        $hoje    = new \DateTimeImmutable('now');

        $exemplos = [
            [
                'dt_venc' => $hoje->format('Y-m-05'), 'categoria' => 'Renda Principal',
                'subcategoria' => 'Salário', 'valor' => 6500.0, 'tipo' => 'RECEITA',
                'status' => 'Realizado', 'instituicao' => 'Banco do Brasil',
                'obs' => 'Salário do mês',
            ],
            [
                'dt_venc' => $hoje->format('Y-m-10'), 'categoria' => 'Moradia',
                'subcategoria' => 'Aluguel', 'valor' => 1800.0, 'tipo' => 'DESPESA',
                'status' => 'Realizado', 'instituicao' => 'Banco do Brasil',
            ],
            [
                'dt_venc' => $hoje->format('Y-m-15'), 'categoria' => 'Alimentação',
                'subcategoria' => 'Mercado', 'valor' => 432.9, 'tipo' => 'DESPESA',
                'status' => 'Realizado', 'instituicao' => 'Nubank', 'pessoa' => 'Eu',
                'dt_compra' => $hoje->format('Y-m-12'), 'obs' => 'Compra do mês',
            ],
            [
                'dt_venc' => $hoje->format('Y-m-20'), 'categoria' => 'Transporte',
                'subcategoria' => 'Combustível', 'valor' => 250.0, 'tipo' => 'DESPESA',
                'status' => 'Previsto', 'instituicao' => 'Nubank',
            ],
        ];

        $w->aba('Exemplo (não importado)');
        $titulos  = [];
        $larguras = [];
        foreach ($colunas as $c) {
            $titulos[]  = self::rotulo($c);
            $larguras[] = (float) ($c['larg'] ?? 16);
        }
        $w->cabecalho(1, 1, $titulos, Writer::CABECALHO);
        $w->larguras($larguras);
        $w->congelar(1);

        $linha = 2;
        foreach ($exemplos as $item) {
            foreach ($colunas as $i => $c) {
                $w->set($linha, $i + 1, ...self::celula($item[(string) $c['campo']] ?? null, $c));
            }
            $linha++;
        }

        $linha++;
        $w->set($linha, 1, 'Esta aba é só demonstração: o nome dela não é reconhecido na'
            . ' importação, então nada daqui entra na sua conta.', Writer::NEGRITO);
    }
}
