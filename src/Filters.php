<?php
/**
 * Montagem do WHERE compartilhado por lançamentos, dashboard, fluxo e balanço
 * por pessoa.
 *
 * O SQL é construído só a partir de fragmentos literais escritos aqui; o que
 * vem do cliente entra exclusivamente como valor em $params. Nenhum nome de
 * coluna, operador ou trecho de condição é derivado da requisição.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Filters
{
    /** Lançamento de DESPESA entra negativo no saldo; RECEITA, positivo. */
    public const SINAL = "CASE WHEN tipo = 'DESPESA' THEN -valor ELSE valor END";

    public const STATUS   = ['Previsto', 'Realizado'];
    public const TIPOS    = ['RECEITA', 'DESPESA'];
    public const GRUPOS   = ['OPERACIONAL', 'NÃO OPERACIONAL'];

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public static function transacoes(
        int $uid,
        ?int $year = null,
        ?int $month = null,
        ?string $status = null,
        ?string $categoria = null,
        ?string $pessoa = null,
        ?string $tipo = null,
        ?string $grupo = null,
    ): array {
        $where  = ['user_id = ?'];
        $params = [$uid];

        if ($year !== null) {
            $where[]  = 'YEAR(dt_venc) = ?';
            $params[] = $year;
        }
        if ($month !== null) {
            $where[]  = 'MONTH(dt_venc) = ?';
            $params[] = $month;
        }
        if ($status !== null && $status !== 'Todos') {
            $where[]  = 'status = ?';
            $params[] = $status;
        }
        if ($categoria !== null) {
            $where[]  = 'categoria = ?';
            $params[] = $categoria;
        }
        if ($pessoa !== null) {
            $where[]  = 'pessoa = ?';
            $params[] = $pessoa;
        }
        if ($tipo !== null) {
            $where[]  = 'tipo = ?';
            $params[] = $tipo;
        }
        if ($grupo !== null) {
            $where[]  = 'grupo = ?';
            $params[] = $grupo;
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Filtros lidos da query string, já restritos aos domínios válidos.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function daRequisicao(int $uid, bool $comMes = true): array
    {
        return self::transacoes(
            $uid,
            Http::queryInteiro('year', 1900, 2200),
            $comMes ? Http::queryInteiro('month', 1, 12) : null,
            Http::queryOpcao('status', self::STATUS),
            Http::queryTexto('categoria'),
            Http::queryTexto('pessoa'),
            Http::queryOpcao('tipo', self::TIPOS),
            Http::queryOpcao('grupo', self::GRUPOS),
        );
    }
}
