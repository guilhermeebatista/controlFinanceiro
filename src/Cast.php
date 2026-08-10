<?php
/**
 * Normalização de tipos na saída JSON.
 *
 * O frontend soma esses campos direto (`items.reduce((a, i) => a + i.valor, 0)`).
 * Se algum chegasse como string, o "+" viraria concatenação e o total do
 * patrimônio sairia como "0100200". O PDO já devolve DOUBLE como float por
 * causa de STRINGIFY_FETCHES=false; isto garante o contrato mesmo que a
 * configuração do driver mude.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Cast
{
    /**
     * @param list<array<string, mixed>> $linhas
     * @param list<string> $floats
     * @param list<string> $ints
     * @param list<string> $strings
     * @return list<array<string, mixed>>
     */
    public static function linhas(array $linhas, array $floats = [], array $ints = [], array $strings = []): array
    {
        foreach ($linhas as &$linha) {
            $linha = self::linha($linha, $floats, $ints, $strings);
        }
        unset($linha);
        return $linhas;
    }

    /**
     * @param array<string, mixed> $linha
     * @param list<string> $floats
     * @param list<string> $ints
     * @param list<string> $strings
     * @return array<string, mixed>
     */
    public static function linha(array $linha, array $floats = [], array $ints = [], array $strings = []): array
    {
        foreach ($floats as $c) {
            if (array_key_exists($c, $linha) && $linha[$c] !== null) {
                $linha[$c] = (float) $linha[$c];
            }
        }
        foreach ($ints as $c) {
            if (array_key_exists($c, $linha) && $linha[$c] !== null) {
                $linha[$c] = (int) $linha[$c];
            }
        }
        foreach ($strings as $c) {
            if (array_key_exists($c, $linha) && $linha[$c] !== null) {
                $linha[$c] = (string) $linha[$c];
            }
        }
        return $linha;
    }

    /**
     * Anos disponíveis no seletor de filtros.
     * Devolvidos como string porque o frontend compara com
     * String(new Date().getFullYear()) para pré-selecionar o ano corrente.
     *
     * @return list<string>
     */
    public static function anos(int $uid): array
    {
        $linhas = Database::todos(
            'SELECT DISTINCT DATE_FORMAT(dt_venc, \'%Y\') AS y
               FROM transactions WHERE user_id = ? ORDER BY y',
            [$uid]
        );
        return array_map(static fn(array $r): string => (string) $r['y'], $linhas);
    }
}
