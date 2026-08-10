<?php
/**
 * Plano de contas — operações compartilhadas entre cadastro, importação e
 * lançamentos.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Categories
{
    public const GRUPOS = ['OPERACIONAL', 'NÃO OPERACIONAL'];
    public const TIPOS  = ['RECEITA', 'DESPESA'];

    /**
     * Insere a classificação se ela ainda não existir para o usuário.
     * Devolve 1 quando criou, 0 quando já havia.
     *
     * INSERT IGNORE apoiado na UNIQUE (user_id, classificacao): a unicidade é
     * decidida pelo banco, não por um SELECT seguido de INSERT que duas
     * requisições simultâneas conseguiriam furar.
     *
     * @param array{classificacao:string, grupo:string, tipo:string, categoria:string, subcategoria:?string, meta_mes:float} $c
     */
    public static function inserirSeAusente(int $uid, array $c): int
    {
        $stmt = Database::run(
            'INSERT IGNORE INTO categories
               (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $uid,
                mb_substr($c['classificacao'], 0, 190),
                self::normalizarGrupo($c['grupo']),
                self::normalizarTipo($c['tipo']),
                mb_substr($c['categoria'], 0, 120),
                $c['subcategoria'] !== null ? mb_substr($c['subcategoria'], 0, 120) : null,
                $c['meta_mes'],
            ]
        );
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    /**
     * A classificação escolhida no lançamento tem de existir no plano de contas
     * do próprio usuário — é ela que define grupo/tipo/categoria gravados na
     * transação. O filtro por user_id aqui também impede usar a classificação
     * de outra conta.
     *
     * @return array<string, mixed>
     */
    public static function exigir(int $uid, string $classificacao): array
    {
        $cat = Database::um(
            'SELECT * FROM categories WHERE user_id = ? AND classificacao = ?',
            [$uid, $classificacao]
        );
        if ($cat === null) {
            Http::erro(400, 'Classificação não cadastrada: ' . $classificacao);
        }
        return $cat;
    }

    /** Nome canônico da classificação: "CATEGORIA - Subcategoria". */
    public static function montarClassificacao(string $categoria, ?string $subcategoria): string
    {
        $rotulo = ($subcategoria !== null && $subcategoria !== '') ? $subcategoria : $categoria;
        return mb_substr(mb_strtoupper($categoria) . ' - ' . $rotulo, 0, 190);
    }

    public static function normalizarGrupo(string $grupo): string
    {
        $g = mb_strtoupper(trim($grupo));
        return in_array($g, self::GRUPOS, true) ? $g : 'OPERACIONAL';
    }

    public static function normalizarTipo(string $tipo): string
    {
        $t = mb_strtoupper(trim($tipo));
        return in_array($t, self::TIPOS, true) ? $t : 'DESPESA';
    }
}
