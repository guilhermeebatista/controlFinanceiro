<?php
/**
 * Painéis analíticos: dashboard, fluxo mensal e resumo de projetos.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Cast;
use MinhasContas\Database;
use MinhasContas\Filters;
use MinhasContas\Http;

final class DashboardController
{
    public static function dashboard(): void
    {
        $uid = Auth::exigirUsuario();
        [$where, $params] = Filters::daRequisicao($uid);
        $sinal = Filters::SINAL;

        $totals = Database::um(
            "SELECT
               COALESCE(SUM(CASE WHEN tipo = 'RECEITA' THEN valor END), 0) AS receitas,
               COALESCE(SUM(CASE WHEN tipo = 'DESPESA' THEN valor END), 0) AS despesas,
               COALESCE(SUM({$sinal}), 0) AS resultado,
               COUNT(*) AS lancamentos
             FROM transactions {$where}",
            $params
        ) ?? ['receitas' => 0, 'despesas' => 0, 'resultado' => 0, 'lancamentos' => 0];

        $byCategoria = Database::todos(
            "SELECT categoria, SUM(valor) AS total FROM transactions
              {$where} AND tipo = 'DESPESA'
              GROUP BY categoria ORDER BY total DESC",
            $params
        );

        $bySubcategoria = Database::todos(
            "SELECT categoria, subcategoria, SUM(valor) AS total FROM transactions
              {$where} AND tipo = 'DESPESA'
              GROUP BY categoria, subcategoria ORDER BY total DESC LIMIT 12",
            $params
        );

        $byPessoa = Database::todos(
            "SELECT COALESCE(pessoa, '(sem pessoa)') AS pessoa, SUM(valor) AS total
               FROM transactions {$where} AND tipo = 'DESPESA'
              GROUP BY COALESCE(pessoa, '(sem pessoa)') ORDER BY total DESC",
            $params
        );

        $byGrupo = Database::todos(
            "SELECT grupo, SUM(valor) AS total FROM transactions
              {$where} AND tipo = 'DESPESA'
              GROUP BY grupo ORDER BY total DESC",
            $params
        );

        // A série mensal ignora o filtro de mês de propósito: o gráfico mostra
        // o ano inteiro mesmo quando os totais acima estão restritos a um mês.
        [$mWhere, $mParams] = Filters::daRequisicao($uid, comMes: false);
        $monthly = Database::todos(
            "SELECT DATE_FORMAT(dt_venc, '%Y-%m') AS mes,
                    COALESCE(SUM(CASE WHEN tipo = 'RECEITA' THEN valor END), 0) AS receitas,
                    COALESCE(SUM(CASE WHEN tipo = 'DESPESA' THEN valor END), 0) AS despesas,
                    COALESCE(SUM({$sinal}), 0) AS resultado
               FROM transactions {$mWhere}
              GROUP BY DATE_FORMAT(dt_venc, '%Y-%m')
              ORDER BY mes",
            $mParams
        );

        $monthly = Cast::linhas($monthly, ['receitas', 'despesas', 'resultado'], [], ['mes']);
        $acumulado = 0.0;
        foreach ($monthly as &$m) {
            $acumulado += $m['resultado'];
            $m['acumulado'] = round($acumulado, 2);
        }
        unset($m);

        Http::json([
            'totals'          => Cast::linha($totals, ['receitas', 'despesas', 'resultado'], ['lancamentos']),
            'by_categoria'    => Cast::linhas($byCategoria, ['total']),
            'by_subcategoria' => Cast::linhas($bySubcategoria, ['total']),
            'by_pessoa'       => Cast::linhas($byPessoa, ['total']),
            'by_grupo'        => Cast::linhas($byGrupo, ['total']),
            'monthly'         => $monthly,
            'years'           => Cast::anos($uid),
        ]);
    }

    public static function fluxo(): void
    {
        $uid = Auth::exigirUsuario();
        // Fluxo é sempre a série completa: ano e mês não entram no filtro.
        [$where, $params] = Filters::transacoes(
            $uid,
            status: Http::queryOpcao('status', Filters::STATUS),
            grupo: Http::queryOpcao('grupo', Filters::GRUPOS),
        );

        $items = Database::todos(
            "SELECT DATE_FORMAT(dt_venc, '%Y-%m') AS mes, grupo, tipo, categoria, subcategoria,
                    SUM(" . Filters::SINAL . ") AS saldo
               FROM transactions {$where}
              GROUP BY DATE_FORMAT(dt_venc, '%Y-%m'), grupo, tipo, categoria, subcategoria
              ORDER BY mes",
            $params
        );

        Http::json(['items' => Cast::linhas($items, ['saldo'], [], ['mes'])]);
    }

    /**
     * Quanto é preciso ter reservado: emergência (fator × custo de vida) mais
     * os projetos agrupados por prazo, confrontado com o total já aplicado.
     */
    public static function resumoProjetos(): void
    {
        $uid = Auth::exigirUsuario();

        $settings = SettingsController::mapa($uid);
        $fator = (float) ($settings['fator_reserva'] ?? 6);
        $custo = (float) ($settings['custo_vida_mensal'] ?? 0);

        $projetos = Cast::linhas(
            Database::todos('SELECT * FROM projects WHERE user_id = ? ORDER BY ano, descricao', [$uid]),
            ['valor'],
            ['id', 'user_id', 'ano']
        );

        $aplicado = (float) Database::valor(
            'SELECT COALESCE(SUM(valor), 0) FROM investments WHERE user_id = ?',
            [$uid]
        );

        $baldes = ['RESERVA' => $custo * $fator, 'CURTO' => 0.0, 'MÉDIO' => 0.0, 'LONGO' => 0.0];
        foreach ($projetos as $p) {
            $prazo = mb_strtoupper((string) ($p['prazo'] ?? ''));
            $chave = str_contains($prazo, 'CURTO') ? 'CURTO'
                : (str_contains($prazo, 'MÉD') ? 'MÉDIO' : 'LONGO');
            $baldes[$chave] += (float) $p['valor'];
        }

        Http::json([
            'fator_reserva'     => $fator,
            'custo_vida_mensal' => $custo,
            'necessidade'       => $baldes,
            'aplicado_total'    => $aplicado,
            'projetos'          => $projetos,
        ]);
    }
}
