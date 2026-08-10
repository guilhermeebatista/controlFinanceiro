<?php
/**
 * Classificações (plano de contas) da aba Configuração.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Cast;
use MinhasContas\Categories;
use MinhasContas\Database;
use MinhasContas\Http;
use PDOException;

final class CategoryController
{
    public static function listar(): void
    {
        $uid = Auth::exigirUsuario();
        $itens = Database::todos(
            'SELECT * FROM categories WHERE user_id = ?
              ORDER BY tipo, categoria, subcategoria',
            [$uid]
        );
        Http::json(['items' => Cast::linhas($itens, ['meta_mes'], ['id', 'user_id'])]);
    }

    public static function criar(): void
    {
        $uid = Auth::exigirUsuario();
        $c = self::lerCorpo();

        try {
            Database::run(
                'INSERT INTO categories
                   (user_id, classificacao, grupo, tipo, categoria, subcategoria, meta_mes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $uid, $c['classificacao'], $c['grupo'], $c['tipo'],
                    $c['categoria'], $c['subcategoria'], $c['meta_mes'],
                ]
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Http::erro(400, 'Classificação já existe');
            }
            throw $e;
        }

        Http::json(['id' => Database::ultimoId(), 'classificacao' => $c['classificacao']]);
    }

    /**
     * Renomear a classificação precisa arrastar os lançamentos junto: eles
     * guardam classificacao/grupo/tipo/categoria desnormalizados e ficariam
     * órfãos, sumindo do fluxo e do dashboard.
     */
    public static function atualizar(int $id): void
    {
        $uid = Auth::exigirUsuario();

        $antiga = Database::um(
            'SELECT * FROM categories WHERE id = ? AND user_id = ?',
            [$id, $uid]
        );
        if ($antiga === null) {
            Http::erro(404, 'Categoria não encontrada');
        }

        $c = self::lerCorpo();

        Database::transacao(static function () use ($uid, $id, $c, $antiga): void {
            try {
                Database::run(
                    'UPDATE categories
                        SET classificacao = ?, grupo = ?, tipo = ?, categoria = ?,
                            subcategoria = ?, meta_mes = ?
                      WHERE id = ? AND user_id = ?',
                    [
                        $c['classificacao'], $c['grupo'], $c['tipo'], $c['categoria'],
                        $c['subcategoria'], $c['meta_mes'], $id, $uid,
                    ]
                );
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    Http::erro(400, 'Já existe outra classificação com esse nome');
                }
                throw $e;
            }

            Database::run(
                'UPDATE transactions
                    SET classificacao = ?, grupo = ?, tipo = ?, categoria = ?, subcategoria = ?
                  WHERE classificacao = ? AND user_id = ?',
                [
                    $c['classificacao'], $c['grupo'], $c['tipo'], $c['categoria'],
                    $c['subcategoria'], $antiga['classificacao'], $uid,
                ]
            );
        });

        Http::json(['ok' => true]);
    }

    public static function excluir(int $id): void
    {
        $uid = Auth::exigirUsuario();

        $cat = Database::um('SELECT * FROM categories WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($cat === null) {
            Http::erro(404, 'Categoria não encontrada');
        }

        // Apagar uma classificação em uso deixaria lançamentos apontando para
        // algo inexistente — e a edição deles passaria a falhar.
        $usada = (int) Database::valor(
            'SELECT COUNT(*) FROM transactions WHERE classificacao = ? AND user_id = ?',
            [$cat['classificacao'], $uid]
        );
        if ($usada > 0) {
            Http::erro(400, "Categoria em uso por {$usada} lançamento(s)");
        }

        Database::run('DELETE FROM categories WHERE id = ? AND user_id = ?', [$id, $uid]);
        Http::json(['ok' => true]);
    }

    /**
     * @return array{classificacao:string, grupo:string, tipo:string, categoria:string, subcategoria:?string, meta_mes:float}
     */
    private static function lerCorpo(): array
    {
        $categoria    = Http::texto('categoria', 120);
        $subcategoria = Http::textoOuNulo('subcategoria', 120);

        return [
            // Derivada, nunca recebida: o cliente não escolhe a chave de negócio.
            'classificacao' => Categories::montarClassificacao($categoria, $subcategoria),
            'grupo'         => Http::opcao('grupo', Categories::GRUPOS, 'OPERACIONAL'),
            'tipo'          => Http::opcao('tipo', Categories::TIPOS, 'DESPESA'),
            'categoria'     => $categoria,
            'subcategoria'  => $subcategoria,
            'meta_mes'      => Http::numero('meta_mes'),
        ];
    }
}
