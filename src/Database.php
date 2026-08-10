<?php
/**
 * Conexão PDO com MySQL e helpers de consulta.
 *
 * Toda query do sistema passa por aqui, e todos os métodos recebem os valores
 * separados do SQL ($params) — nunca concatenados na string. Ver security_review.md.
 */

declare(strict_types=1);

namespace MinhasContas;

use PDO;
use PDOException;
use PDOStatement;

final class Database
{
    private static ?PDO $pdo = null;

    /**
     * O ponto mais importante desta classe são as três primeiras opções:
     *
     *  - EMULATE_PREPARES = false força prepares nativos do servidor. Com
     *    emulação (o padrão do PDO/MySQL) o driver monta a query em PHP e
     *    escapa os valores no cliente; qualquer descuido de charset volta a
     *    abrir espaço para injeção. Desligado, SQL e dados viajam em pacotes
     *    separados e o servidor nunca reinterpreta o valor como sintaxe.
     *  - ERRMODE_EXCEPTION: erro de SQL vira exceção. Sem isso um prepare que
     *    falha retorna false e a execução continua como se nada tivesse
     *    acontecido.
     *  - STRINGIFY_FETCHES = false: INT/DOUBLE voltam como int/float do PHP,
     *    não como string. O frontend soma esses campos direto.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $opcoes = [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            // Faz rowCount() de um UPDATE contar as linhas QUE CASARAM, não só
            // as que mudaram de valor. Duas consequências: "3 lançamentos
            // alterados" não vira "2" quando um já estava no valor novo, e
            // rowCount() === 0 passa a significar exatamente "não existe" —
            // que é como as rotas distinguem 404 de alteração sem efeito.
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ];

        // O container do MySQL costuma demorar alguns segundos a mais que o do
        // PHP para aceitar conexões; sem a espera o primeiro request do deploy
        // morreria com "connection refused".
        $ultimoErro = null;
        for ($tentativa = 0; $tentativa < 15; $tentativa++) {
            try {
                self::$pdo = new PDO(Config::dsn(), Config::dbUser(), Config::dbPass(), $opcoes);
                break;
            } catch (PDOException $e) {
                $ultimoErro = $e;
                usleep(600_000);
            }
        }

        if (!self::$pdo instanceof PDO) {
            throw $ultimoErro ?? new PDOException('Não foi possível conectar ao banco.');
        }

        // Tudo em UTC: CURRENT_TIMESTAMP, criado_em e ultimo_login ficam
        // consistentes independentemente do fuso do host.
        // STRICT_ALL_TABLES faz o MySQL recusar dados que não cabem na coluna
        // em vez de truncar em silêncio.
        self::$pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return self::$pdo;
    }

    /** @param array<int|string, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function todos(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $linhas */
        $linhas = self::run($sql, $params)->fetchAll();
        return $linhas;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function um(string $sql, array $params = []): ?array
    {
        $linha = self::run($sql, $params)->fetch();
        return $linha === false ? null : $linha;
    }

    /** @param array<int|string, mixed> $params */
    public static function valor(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function ultimoId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Executa $fn dentro de uma transação, com rollback em qualquer exceção.
     * Usado onde uma operação toca várias tabelas (importação, exclusão de
     * conta, lançamento parcelado) e um estado pela metade seria pior que o erro.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function transacao(callable $fn): mixed
    {
        $pdo = self::pdo();
        // Uma transação já aberta (chamada aninhada) participa da externa.
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $resultado = $fn();
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Monta a lista de placeholders de um `IN (...)`.
     *
     * Os VALORES continuam vindo por bind; o que se constrói aqui é só a
     * quantidade de "?" — derivada de count(), nunca do conteúdo enviado.
     */
    public static function placeholders(int $quantidade): string
    {
        return implode(',', array_fill(0, max($quantidade, 1), '?'));
    }
}
