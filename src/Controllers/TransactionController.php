<?php
/**
 * Lançamentos: listagem com filtros, CRUD, parcelamento e operações em lote.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Cast;
use MinhasContas\Categories;
use MinhasContas\Database;
use MinhasContas\Filters;
use MinhasContas\Http;

final class TransactionController
{
    /** Campos que a alteração em lote pode tocar. Fora desta lista, 400. */
    private const CAMPOS_LOTE = ['status', 'pessoa', 'instituicao', 'classificacao', 'dt_venc'];

    private const FLOATS = ['valor', 'saldo'];
    private const INTS   = ['id', 'user_id'];

    // ------------------------------------------------------------- leitura

    public static function listar(): void
    {
        $uid = Auth::exigirUsuario();
        [$where, $params] = Filters::daRequisicao($uid);

        $q = Http::queryTexto('q');
        if ($q !== null) {
            $where .= ' AND (classificacao LIKE ? OR obs LIKE ? OR pessoa LIKE ?)';
            $alvo = '%' . self::escaparLike($q) . '%';
            array_push($params, $alvo, $alvo, $alvo);
        }

        $total = (int) Database::valor("SELECT COUNT(*) FROM transactions {$where}", $params);

        // LIMIT/OFFSET não aceitam placeholder com prepare nativo do MySQL.
        // São os únicos valores interpolados no SQL do sistema e ambos passam
        // por (int) com faixa fixa antes — não há como sobrar sintaxe.
        $limit  = Http::queryInteiro('limit', 1, 5000) ?? 500;
        $offset = max(0, Http::queryInteiro('offset', 0, 1_000_000) ?? 0);

        $items = Database::todos(
            'SELECT *, ' . Filters::SINAL . " AS saldo FROM transactions {$where}
              ORDER BY dt_venc DESC, id DESC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        Http::json([
            'total' => $total,
            'items' => Cast::linhas($items, self::FLOATS, self::INTS),
            'years' => Cast::anos($uid),
        ]);
    }

    public static function balancoPorPessoa(): void
    {
        $uid = Auth::exigirUsuario();
        [$where, $params] = Filters::daRequisicao($uid);

        $itens = Database::todos(
            "SELECT COALESCE(pessoa, '(sem pessoa)') AS pessoa,
                    COALESCE(SUM(CASE WHEN tipo = 'DESPESA' THEN valor END), 0) AS a_pagar,
                    COALESCE(SUM(CASE WHEN tipo = 'RECEITA' THEN valor END), 0) AS a_receber,
                    COUNT(*) AS lancamentos
               FROM transactions {$where}
              GROUP BY COALESCE(pessoa, '(sem pessoa)')
              ORDER BY a_pagar DESC, a_receber DESC",
            $params
        );

        $itens = Cast::linhas($itens, ['a_pagar', 'a_receber'], ['lancamentos']);
        $totalPagar = $totalReceber = 0.0;
        foreach ($itens as &$i) {
            $i['saldo'] = round($i['a_receber'] - $i['a_pagar'], 2);
            $totalPagar   += $i['a_pagar'];
            $totalReceber += $i['a_receber'];
        }
        unset($i);

        Http::json([
            'itens'  => $itens,
            'totais' => [
                'a_pagar'   => round($totalPagar, 2),
                'a_receber' => round($totalReceber, 2),
                'saldo'     => round($totalReceber - $totalPagar, 2),
            ],
            'years'  => Cast::anos($uid),
        ]);
    }

    // ------------------------------------------------------------- escrita

    /**
     * Cria o lançamento — ou a série de parcelas, uma por mês.
     *
     * Quando o valor informado é o total, ele é dividido igualmente e a última
     * parcela absorve a sobra do arredondamento, de modo que a soma feche
     * exatamente com o total digitado.
     */
    public static function criar(): void
    {
        $uid = Auth::exigirUsuario();
        $t   = self::lerCorpo($uid);

        $n = Http::inteiro('parcelas', 1, 1, 360);
        $valorEhTotal = Http::booleano('valor_total');

        if ($valorEhTotal && $n > 1) {
            $base = round($t['valor'] / $n, 2);
            $valores = array_fill(0, $n - 1, $base);
            $valores[] = round($t['valor'] - $base * ($n - 1), 2);
        } else {
            $valores = array_fill(0, $n, $t['valor']);
        }

        $primeiroId = Database::transacao(static function () use ($uid, $t, $n, $valores): int {
            $primeiro = 0;
            for ($k = 0; $k < $n; $k++) {
                $venc = $n > 1 ? self::somarMeses($t['dt_venc'], $k) : $t['dt_venc'];
                $obs  = $t['obs'];
                if ($n > 1) {
                    $marca = '(' . ($k + 1) . '/' . $n . ')';
                    $obs = ($obs !== null && $obs !== '') ? $obs . ' ' . $marca : $marca;
                }
                Database::run(
                    'INSERT INTO transactions
                       (user_id, dt_compra, dt_venc, classificacao, valor, instituicao, pessoa,
                        status, obs, grupo, tipo, categoria, subcategoria)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $uid, $t['dt_compra'], $venc, $t['classificacao'], $valores[$k],
                        $t['instituicao'], $t['pessoa'], $t['status'], $obs,
                        $t['grupo'], $t['tipo'], $t['categoria'], $t['subcategoria'],
                    ]
                );
                if ($primeiro === 0) {
                    $primeiro = Database::ultimoId();
                }
            }
            if ($t['pessoa'] !== null) {
                self::registrarPessoa($uid, $t['pessoa']);
            }
            return $primeiro;
        });

        Http::json(['id' => $primeiroId, 'parcelas' => $n]);
    }

    public static function atualizar(int $id): void
    {
        $uid = Auth::exigirUsuario();
        $t   = self::lerCorpo($uid);

        // O `AND user_id = ?` é o que impede editar o lançamento de outra
        // conta: sem ele, um id adivinhado bastaria (IDOR).
        $stmt = Database::run(
            'UPDATE transactions
                SET dt_compra = ?, dt_venc = ?, classificacao = ?, valor = ?, instituicao = ?,
                    pessoa = ?, status = ?, obs = ?, grupo = ?, tipo = ?, categoria = ?, subcategoria = ?
              WHERE id = ? AND user_id = ?',
            [
                $t['dt_compra'], $t['dt_venc'], $t['classificacao'], $t['valor'], $t['instituicao'],
                $t['pessoa'], $t['status'], $t['obs'], $t['grupo'], $t['tipo'],
                $t['categoria'], $t['subcategoria'], $id, $uid,
            ]
        );

        // rowCount conta linhas casadas (MYSQL_ATTR_FOUND_ROWS), então zero
        // aqui só acontece quando o id não é desta conta.
        if ($stmt->rowCount() === 0) {
            Http::erro(404, 'Lançamento não encontrado');
        }
        if ($t['pessoa'] !== null) {
            self::registrarPessoa($uid, $t['pessoa']);
        }
        Http::json(['ok' => true]);
    }

    public static function excluir(int $id): void
    {
        $uid  = Auth::exigirUsuario();
        $stmt = Database::run('DELETE FROM transactions WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($stmt->rowCount() === 0) {
            Http::erro(404, 'Lançamento não encontrado');
        }
        Http::json(['ok' => true]);
    }

    public static function excluirEmLote(): void
    {
        $uid = Auth::exigirUsuario();
        $ids = Http::listaIds('ids');
        if ($ids === []) {
            Http::json(['deleted' => 0]);
            return;
        }

        $marks = Database::placeholders(count($ids));
        $stmt  = Database::run(
            "DELETE FROM transactions WHERE user_id = ? AND id IN ({$marks})",
            [$uid, ...$ids]
        );
        Http::json(['deleted' => $stmt->rowCount()]);
    }

    /**
     * Altera um campo em vários lançamentos de uma vez.
     *
     * `field` vem do cliente e vira nome de coluna — o ponto clássico de
     * injeção por identificador. A lista CAMPOS_LOTE resolve: o valor recebido
     * só é usado para *escolher* uma das cinco strings SQL escritas aqui;
     * nada do que chega é concatenado.
     */
    public static function atualizarEmLote(): void
    {
        $uid   = Auth::exigirUsuario();
        $campo = Http::corpo()['field'] ?? '';

        if (!is_string($campo) || !in_array($campo, self::CAMPOS_LOTE, true)) {
            Http::erro(400, 'Campo não permitido para alteração em lote');
        }

        $ids = Http::listaIds('ids');
        if ($ids === []) {
            Http::json(['updated' => 0]);
            return;
        }
        $marks = Database::placeholders(count($ids));

        if ($campo === 'classificacao') {
            $classificacao = Http::texto('value', 190);
            $cat = Categories::exigir($uid, $classificacao);
            // Reclassificar tem de arrastar grupo/tipo/categoria junto, senão
            // o lançamento fica com a classificação nova e a categoria antiga.
            $stmt = Database::run(
                "UPDATE transactions
                    SET classificacao = ?, grupo = ?, tipo = ?, categoria = ?, subcategoria = ?
                  WHERE user_id = ? AND id IN ({$marks})",
                [
                    $classificacao, $cat['grupo'], $cat['tipo'], $cat['categoria'],
                    $cat['subcategoria'], $uid, ...$ids,
                ]
            );
            Http::json(['updated' => $stmt->rowCount()]);
            return;
        }

        [$sql, $valor] = match ($campo) {
            'status' => [
                'UPDATE transactions SET status = ? WHERE user_id = ? AND id IN (' . $marks . ')',
                Http::opcao('value', Filters::STATUS, 'Previsto'),
            ],
            'pessoa' => [
                'UPDATE transactions SET pessoa = ? WHERE user_id = ? AND id IN (' . $marks . ')',
                Http::textoOuNulo('value', 120),
            ],
            'instituicao' => [
                'UPDATE transactions SET instituicao = ? WHERE user_id = ? AND id IN (' . $marks . ')',
                Http::textoOuNulo('value', 120),
            ],
            'dt_venc' => [
                'UPDATE transactions SET dt_venc = ? WHERE user_id = ? AND id IN (' . $marks . ')',
                self::dataVencimentoObrigatoria(),
            ],
        };

        $stmt = Database::run($sql, [$valor, $uid, ...$ids]);

        if ($campo === 'pessoa' && is_string($valor) && $valor !== '') {
            self::registrarPessoa($uid, $valor);
        }
        Http::json(['updated' => $stmt->rowCount()]);
    }

    // ---------------------------------------------------------------- util

    /**
     * Lê e valida o corpo de um lançamento, resolvendo grupo/tipo/categoria a
     * partir do plano de contas do próprio usuário.
     *
     * @return array<string, mixed>
     */
    private static function lerCorpo(int $uid): array
    {
        $classificacao = Http::texto('classificacao', 190);
        $cat = Categories::exigir($uid, $classificacao);

        return [
            'dt_compra'     => Http::dataOuNulo('dt_compra'),
            'dt_venc'       => Http::data('dt_venc'),
            'classificacao' => $classificacao,
            'valor'         => Http::numero('valor'),
            'instituicao'   => Http::textoOuNulo('instituicao', 120),
            'pessoa'        => Http::textoOuNulo('pessoa', 120),
            'status'        => Http::opcao('status', Filters::STATUS, 'Previsto'),
            'obs'           => Http::textoOuNulo('obs', 2000),
            // Não vêm do cliente: são copiados da categoria cadastrada, então
            // o cliente não consegue gravar um tipo que mude o sinal do saldo.
            'grupo'         => $cat['grupo'],
            'tipo'          => $cat['tipo'],
            'categoria'     => $cat['categoria'],
            'subcategoria'  => $cat['subcategoria'],
        ];
    }

    private static function dataVencimentoObrigatoria(): string
    {
        $v = Http::corpo()['value'] ?? null;
        if (!is_string($v) || trim($v) === '') {
            Http::erro(400, 'Data de vencimento não pode ficar vazia');
        }
        $d = Http::normalizarData($v);
        if ($d === null) {
            Http::erro(400, 'Data de vencimento inválida (use AAAA-MM-DD).');
        }
        return $d;
    }

    /** Mantém o cadastro de pessoas em dia sem duplicar (UNIQUE user_id+nome). */
    public static function registrarPessoa(int $uid, string $nome): void
    {
        Database::run(
            'INSERT IGNORE INTO people (user_id, nome) VALUES (?, ?)',
            [$uid, mb_substr($nome, 0, 120)]
        );
    }

    /**
     * Soma k meses a 'YYYY-MM-DD', encolhendo o dia quando o mês de destino é
     * mais curto: 31/01 + 1 mês vira 28/02, não 03/03.
     */
    public static function somarMeses(string $iso, int $k): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $iso));
        $total = ($m - 1) + $k;
        $y2 = $y + intdiv($total, 12);
        $m2 = $total % 12 + 1;
        $ultimoDia = (int) date('t', (int) mktime(0, 0, 0, $m2, 1, $y2));
        return sprintf('%04d-%02d-%02d', $y2, $m2, min($d, $ultimoDia));
    }

    /**
     * Neutraliza os curingas do LIKE no texto buscado.
     * Sem isso, procurar por "50%" casaria com tudo que começa com "50", e um
     * termo só de "%" varreria a tabela inteira.
     */
    private static function escaparLike(string $termo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo);
    }
}
