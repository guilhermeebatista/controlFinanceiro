<?php
/**
 * Roteador mínimo: casa método + caminho e chama o handler.
 *
 * O único curinga aceito na rota é {id}, que só casa com dígitos. Segmentos
 * de URL nunca viram nome de tabela ou de coluna — quando o caminho seleciona
 * um recurso (/api/projects, /api/assets...), a rota é registrada uma a uma e
 * o nome real da tabela vem de um registro fixo no código.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Router
{
    /** @var list<array{metodo:string, regex:string, handler:callable}> */
    private static array $rotas = [];

    /** @param callable $handler */
    public static function add(string $metodo, string $padrao, callable $handler): void
    {
        $regex = '#^' . preg_replace('/\\\{id\\\}/', '(\d+)', preg_quote($padrao, '#')) . '$#';
        self::$rotas[] = ['metodo' => $metodo, 'regex' => $regex, 'handler' => $handler];
    }

    public static function get(string $p, callable $h): void
    {
        self::add('GET', $p, $h);
    }

    public static function post(string $p, callable $h): void
    {
        self::add('POST', $p, $h);
    }

    public static function put(string $p, callable $h): void
    {
        self::add('PUT', $p, $h);
    }

    public static function delete(string $p, callable $h): void
    {
        self::add('DELETE', $p, $h);
    }

    /** Registra as quatro operações de um recurso CRUD de uma vez. */
    public static function crud(string $caminho, string $recurso): void
    {
        self::get($caminho, static fn() => Controllers\CrudController::listar($recurso));
        self::post($caminho, static fn() => Controllers\CrudController::criar($recurso));
        self::put($caminho . '/{id}', static fn(int $id) => Controllers\CrudController::atualizar($recurso, $id));
        self::delete($caminho . '/{id}', static fn(int $id) => Controllers\CrudController::excluir($recurso, $id));
    }

    public static function despachar(): void
    {
        $metodo   = Http::metodo();
        $caminho  = rtrim(Http::caminho(), '/');
        if ($caminho === '') {
            $caminho = '/';
        }

        $caminhoExiste = false;
        foreach (self::$rotas as $rota) {
            if (preg_match($rota['regex'], $caminho, $m) !== 1) {
                continue;
            }
            $caminhoExiste = true;
            if ($rota['metodo'] !== $metodo) {
                continue;
            }
            $args = array_map('intval', array_slice($m, 1));
            ($rota['handler'])(...$args);
            return;
        }

        // 405 quando o recurso existe mas o verbo não; 404 quando nem existe.
        if ($caminhoExiste) {
            Http::erro(405, 'Método não permitido para este endereço.');
        }
        Http::erro(404, 'Endereço não encontrado.');
    }
}
