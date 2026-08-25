<?php
/**
 * O que o CRUD genérico não dá conta: o retrato da carteira e o catálogo de
 * produtos.
 *
 * O CRUD de /api/investments continua em CrudController — aqui ficam só as
 * duas leituras calculadas.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Investimentos;

final class InvestmentController
{
    /** Teto da projeção: 10 anos de meses. Além disso o gráfico vira ruído. */
    private const MAX_MESES = 120;

    /**
     * Totais da carteira, quebra por classe e projeção mês a mês.
     *
     * Vem junto o catálogo de produtos e os índices em vigor: é uma
     * requisição só para a aba Patrimônio montar tanto os números quanto o
     * formulário de cadastro.
     */
    public static function resumo(): void
    {
        $uid = Auth::exigirUsuario();
        $meses = Http::queryInteiro('meses', 1, self::MAX_MESES) ?? 12;

        $itens = Database::todos(
            'SELECT * FROM investments WHERE user_id = ? ORDER BY instituicao, id',
            [$uid]
        );

        $indices = SettingsController::indices($uid);
        $resumo  = Investimentos::resumo($itens, $indices, $meses);

        Http::json($resumo + [
            'indices'  => $indices,
            'catalogo' => Investimentos::catalogo(),
        ]);
    }
}
