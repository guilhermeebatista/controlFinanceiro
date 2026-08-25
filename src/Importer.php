<?php
/**
 * Porta de entrada da importação e leitor do modelo antigo.
 *
 * Dois formatos chegam aqui:
 *
 *   * o modelo do sistema (aba "Lançamentos"), lido por Modelo — é o que a
 *     exportação gera e o que a interface oferece para baixar;
 *   * a planilha "Controle Financ. Pessoal" (aba LANCAMENTO), de onde este
 *     sistema nasceu, lida pelo resto deste arquivo.
 *
 * No modelo antigo as posições de coluna são fixas: o índice 0 é a coluna A,
 * então `col($linha, 1)` é a coluna B. Linhas que não casam com o formato são
 * puladas em silêncio — planilha de usuário tem cabeçalho, subtotal e anotação
 * no meio, e abortar na primeira estranheza tornaria a importação inútil.
 */

declare(strict_types=1);

namespace MinhasContas;

use MinhasContas\Xlsx\Reader;
use MinhasContas\Xlsx\Valor;

final class Importer
{
    /**
     * O formato lido em ImportController::gravar(). O modelo novo ainda traz
     * as chaves 'institutions' e 'people', que o modelo antigo não tem.
     *
     * @return array{
     *   settings: array<string, float>,
     *   categories: list<array<string, mixed>>,
     *   transactions: list<array<string, mixed>>,
     *   projects: list<array<string, mixed>>,
     *   patrimonio: array{investimentos: list<array<string,mixed>>, bens: list<array<string,mixed>>, dividas: list<array<string,mixed>>}
     * }
     */
    public static function parse(string $caminho): array
    {
        $wb = new Reader($caminho);

        // A aba LANCAMENTO, no singular e em caixa alta, é a assinatura do
        // modelo antigo; o modelo novo tem "Lançamentos". A verificação vem
        // primeiro porque o arquivo antigo também tem uma aba PROJETOS, que
        // o modelo novo reconheceria.
        if ($wb->temAba('LANCAMENTO')) {
            return self::lerModeloAntigo($wb);
        }
        if (Modelo::reconhece($wb)) {
            return Modelo::ler($wb);
        }

        Http::erro(400, 'A planilha não tem a aba "Lançamentos". Baixe o modelo em'
            . ' Configuração → Planilha, ou envie a planilha "Controle Financ. Pessoal".');
    }

    /**
     * @return array<string, mixed>
     */
    private static function lerModeloAntigo(Reader $wb): array
    {
        [$categorias, $receitaMensal, $custoVida] = self::lerConfiguracao($wb);
        $transacoes = self::lerLancamentos($wb);
        [$projetos, $fator] = self::lerProjetos($wb);
        $patrimonio = self::lerPatrimonio($wb);

        return [
            'settings' => [
                'receita_mensal'    => $receitaMensal,
                'custo_vida_mensal' => $custoVida,
                'fator_reserva'     => $fator,
            ],
            'categories'   => $categorias,
            'transactions' => $transacoes,
            'projects'     => $projetos,
            'patrimonio'   => $patrimonio,
        ];
    }

    // ------------------------------------------------------- CONFIGURACAO

    /**
     * @return array{0: list<array<string, mixed>>, 1: float, 2: float}
     */
    private static function lerConfiguracao(Reader $wb): array
    {
        $categorias = [];
        $vistas = [];
        $receita = 0.0;
        $custo   = 0.0;

        if (!$wb->temAba('CONFIGURACAO')) {
            return [$categorias, $receita, $custo];
        }

        // $numero é o número REAL da linha na planilha, vindo do atributo r=""
        // do XML. Contar iterações aqui daria errado: linha totalmente vazia
        // não é gravada no arquivo, então o leitor não a emite e um contador
        // próprio ficaria defasado — descartando a primeira classificação.
        foreach ($wb->linhas('CONFIGURACAO') as $numero => $linha) {
            // Parâmetros ficam em F1:G3, ao lado da tabela de classificações.
            if ($numero <= 3) {
                $rotulo = self::texto(self::col($linha, 5));
                $valor  = self::col($linha, 6);
                if ($rotulo !== null && self::numerico($valor)) {
                    if (str_contains($rotulo, 'Receita Mensal')) {
                        $receita = (float) $valor;
                    }
                    if (str_contains($rotulo, 'Custo de Vida')) {
                        $custo = (float) $valor;
                    }
                }
            }
            if ($numero < 5) {
                continue;
            }

            $classificacao = self::texto(self::col($linha, 1));
            $categoria     = self::texto(self::col($linha, 4));
            $tipo          = self::texto(self::col($linha, 3));

            // " - " separa categoria de subcategoria; sem isso a linha é
            // cabeçalho ou anotação, não uma classificação.
            if ($classificacao === null || $categoria === null || !str_contains($classificacao, ' - ')) {
                continue;
            }
            if ($tipo === null || !in_array($tipo, Categories::TIPOS, true)) {
                continue;
            }
            if (isset($vistas[$classificacao])) {
                continue;
            }
            $vistas[$classificacao] = true;

            $meta = self::col($linha, 6);
            $categorias[] = [
                'classificacao' => $classificacao,
                'grupo'         => self::texto(self::col($linha, 2)) ?? 'OPERACIONAL',
                'tipo'          => $tipo,
                'categoria'     => $categoria,
                'subcategoria'  => self::texto(self::col($linha, 5)),
                'meta_mes'      => self::numerico($meta) ? (float) $meta : 0.0,
            ];
        }

        return [$categorias, $receita, $custo];
    }

    // --------------------------------------------------------- LANCAMENTO

    /**
     * @return list<array<string, mixed>>
     */
    private static function lerLancamentos(Reader $wb): array
    {
        $transacoes = [];

        foreach ($wb->linhas('LANCAMENTO') as $numero => $linha) {
            if ($numero < 4) {
                continue;
            }
            $descricao = self::texto(self::col($linha, 3));
            $valor     = self::col($linha, 4);
            if ($descricao === null || !self::numerico($valor)) {
                continue;
            }

            $dtCompra = self::data(self::col($linha, 1));
            $dtVenc   = self::data(self::col($linha, 2)) ?? $dtCompra;
            if ($dtVenc === null) {
                // dt_venc é NOT NULL — sem data não há como posicionar o
                // lançamento em nenhum mês.
                continue;
            }

            $transacoes[] = [
                'dt_compra'     => $dtCompra,
                'dt_venc'       => $dtVenc,
                'classificacao' => mb_substr($descricao, 0, 190),
                'valor'         => round((float) $valor, 2),
                'instituicao'   => self::texto(self::col($linha, 5), 120),
                'pessoa'        => self::texto(self::col($linha, 6), 120),
                'status'        => self::status(self::texto(self::col($linha, 7))),
                'obs'           => self::texto(self::col($linha, 8), 2000),
                'grupo'         => self::texto(self::col($linha, 9), 60),
                'tipo'          => self::texto(self::col($linha, 10), 20),
                'categoria'     => self::texto(self::col($linha, 11), 120),
                'subcategoria'  => self::texto(self::col($linha, 12), 120),
            ];
        }

        return $transacoes;
    }

    // ----------------------------------------------------------- PROJETOS

    /**
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private static function lerProjetos(Reader $wb): array
    {
        $projetos = [];
        $fator = 6.0;

        if (!$wb->temAba('PROJETOS')) {
            return [$projetos, $fator];
        }

        // Pelo número real da linha, não por contagem de iterações — ver o
        // comentário em lerConfiguracao().
        foreach ($wb->linhas('PROJETOS') as $numero => $linha) {
            if ($numero === 1 && self::numerico(self::col($linha, 8))) {
                $fator = (float) self::col($linha, 8);
            }
            if ($numero < 2) {
                continue;
            }

            $descricao = self::texto(self::col($linha, 1), 255);
            $valor     = self::col($linha, 2);
            if ($descricao === null || !self::numerico($valor)) {
                continue;
            }

            $ano = self::col($linha, 3);
            $projetos[] = [
                'descricao' => $descricao,
                'valor'     => (float) $valor,
                'ano'       => self::numerico($ano) ? (int) $ano : null,
                'prazo'     => self::texto(self::col($linha, 4), 40),
            ];
        }

        return [$projetos, $fator];
    }

    // --------------------------------------------------------- PATRIMONIO

    /**
     * As três tabelas ficam lado a lado na mesma aba, então uma única
     * varredura alimenta as três: investimentos em B:F, bens em H:J e
     * dívidas em M:P.
     *
     * @return array{investimentos: list<array<string,mixed>>, bens: list<array<string,mixed>>, dividas: list<array<string,mixed>>}
     */
    private static function lerPatrimonio(Reader $wb): array
    {
        $pat = ['investimentos' => [], 'bens' => [], 'dividas' => []];
        if (!$wb->temAba('PATRIMONIO')) {
            return $pat;
        }

        foreach ($wb->linhas('PATRIMONIO') as $numero => $linha) {
            if ($numero < 3) {
                continue;
            }

            $inst = self::texto(self::col($linha, 1), 120);
            if ($inst !== null && self::numerico(self::col($linha, 5))) {
                $pat['investimentos'][] = [
                    'instituicao'   => $inst,
                    'fixa_var'      => self::texto(self::col($linha, 2), 40),
                    'prazo_projeto' => self::texto(self::col($linha, 3), 40),
                    'ativo'         => self::texto(self::col($linha, 4), 120),
                    'valor'         => (float) self::col($linha, 5),
                ];
            }

            $bem = self::texto(self::col($linha, 7), 255);
            if ($bem !== null && self::numerico(self::col($linha, 8))) {
                $pat['bens'][] = [
                    'descricao'     => $bem,
                    'valor'         => (float) self::col($linha, 8),
                    'saldo_devedor' => self::numero(self::col($linha, 9)),
                ];
            }

            $divida = self::texto(self::col($linha, 12), 255);
            $temNumero = self::numerico(self::col($linha, 13))
                || self::numerico(self::col($linha, 14))
                || self::numerico(self::col($linha, 15));
            if ($divida !== null && $temNumero) {
                $pat['dividas'][] = [
                    'descricao'     => $divida,
                    'num_parcelas'  => (int) self::numero(self::col($linha, 13)),
                    'valor_parcela' => self::numero(self::col($linha, 14)),
                    'saldo_devedor' => self::numero(self::col($linha, 15)),
                ];
            }
        }

        return $pat;
    }

    // --------------------------------------------------------------- util

    /** @param list<mixed> $linha */
    private static function col(array $linha, int $i): mixed
    {
        return $linha[$i] ?? null;
    }

    // A coerção de célula é a mesma dos dois modelos e mora em Xlsx\Valor;
    // aqui ficam só os apelidos que este arquivo usa desde sempre.

    /** Texto aparado, ou null quando a célula está vazia. */
    private static function texto(mixed $v, int $max = 190): ?string
    {
        return Valor::texto($v, $max);
    }

    /**
     * Célula numérica de verdade. Continua estrito de propósito: no modelo
     * antigo é este teste que separa lançamento de linha de subtotal.
     */
    private static function numerico(mixed $v): bool
    {
        return Valor::ehNumero($v);
    }

    private static function numero(mixed $v): float
    {
        return Valor::numeroOuZero($v);
    }

    private static function data(mixed $v): ?string
    {
        return Valor::data($v);
    }

    private static function status(?string $v): string
    {
        return in_array($v, Filters::STATUS, true) ? $v : 'Previsto';
    }
}
