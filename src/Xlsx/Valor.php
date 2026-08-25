<?php
/**
 * Coerção dos valores brutos que o Reader devolve.
 *
 * Uma célula chega como int, float, string ou null — e o que a pessoa digitou
 * raramente é o que o banco espera. É aqui que "R$ 1.234,56" vira 1234.56 e
 * "09/07/2026" vira '2026-07-09'.
 *
 * A tolerância é intencional: o modelo de planilha é preenchido à mão, em
 * Excel, LibreOffice ou Google Sheets, cada um com um palpite diferente sobre
 * o que é número e o que é texto. Recusar a célula por causa do formato
 * transformaria a importação numa caça ao erro invisível.
 */

declare(strict_types=1);

namespace MinhasContas\Xlsx;

use MinhasContas\Http;

final class Valor
{
    /**
     * Acentos que somem na normalização de rótulos. Feito por tabela e não
     * por iconv //TRANSLIT: o resultado do iconv depende do locale instalado
     * no container, e "Instituição" viraria "Institui?ao" em algumas imagens.
     */
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    /**
     * Chave de comparação de rótulos: minúsculas, sem acento e sem nada que
     * não seja letra ou dígito.
     *
     * É o que faz "Instituição", "INSTITUICAO" e "Instituição *" caírem todos
     * em "instituicao" — a coluna é encontrada pelo nome, não pela posição,
     * então reordenar as colunas ou renomear levemente uma delas não quebra a
     * importação.
     */
    public static function chave(string $v): string
    {
        $v = mb_strtolower(trim($v), 'UTF-8');
        $v = strtr($v, self::ACENTOS);
        return preg_replace('/[^a-z0-9]+/', '', $v) ?? '';
    }

    /** Texto aparado e seguro para o banco, ou null quando a célula está vazia. */
    public static function texto(mixed $v, int $max = 190): ?string
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        if (is_float($v) && $v === floor($v) && abs($v) < 1e15) {
            // Um número de conta digitado numa coluna de texto chega como
            // float; sem isto viraria "12345678.0".
            $v = (int) $v;
        }
        $t = trim((string) $v);
        if ($t === '') {
            return null;
        }
        // A planilha vem de fonte externa: normaliza para UTF-8 válido antes
        // de chegar ao banco, que roda em STRICT e recusaria bytes inválidos.
        if (!mb_check_encoding($t, 'UTF-8')) {
            $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
        }
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t) ?? $t;
        $t = trim($t);
        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    /** Célula numérica de verdade — não "parece um número". */
    public static function ehNumero(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && !is_bool($v);
    }

    /**
     * Número tolerante: aceita a célula numérica e também o texto que a
     * pessoa digitou ("R$ 1.234,56", "1,234.56", "(80,00)" para negativo).
     */
    public static function numero(mixed $v): ?float
    {
        if (self::ehNumero($v)) {
            return (float) $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if ($s === '') {
            return null;
        }

        // Contabilidade escreve negativo entre parênteses.
        $negativo = str_starts_with($s, '(') && str_ends_with($s, ')');

        $s = preg_replace('/[^0-9,.\-]/', '', $s) ?? '';
        if ($s === '' || preg_match('/\d/', $s) !== 1) {
            return null;
        }
        $negativo = $negativo || str_starts_with($s, '-');
        $s = str_replace('-', '', $s);

        $temVirgula = str_contains($s, ',');
        $temPonto   = str_contains($s, '.');

        if ($temVirgula && $temPonto) {
            // O separador que aparece por último é o decimal: "1.234,56" é
            // pt-BR e "1,234.56" é en-US.
            $s = strrpos($s, ',') > strrpos($s, '.')
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        } elseif ($temVirgula) {
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s) === 1) {
            // Só pontos, todos separando grupos de três: milhar, não decimal.
            $s = str_replace('.', '', $s);
        }

        if (!is_numeric($s)) {
            return null;
        }
        $n = (float) $s;
        return $negativo ? -$n : $n;
    }

    /** Número para as colunas em que célula vazia significa zero. */
    public static function numeroOuZero(mixed $v): float
    {
        return self::numero($v) ?? 0.0;
    }

    public static function inteiro(mixed $v): ?int
    {
        $n = self::numero($v);
        return $n === null ? null : (int) round($n);
    }

    /**
     * Data em 'YYYY-MM-DD'.
     *
     * O Reader já devolve ISO quando a célula tem formato de data. O resto é
     * o que a pessoa digitou numa coluna formatada como texto — quase sempre
     * DD/MM/AAAA — ou o serial cru do Excel, quando o formato de data se
     * perdeu no caminho.
     */
    public static function data(mixed $v): ?string
    {
        if (self::ehNumero($v)) {
            return self::serial((float) $v);
        }
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if ($s === '') {
            return null;
        }
        // Datas com hora ("2026-07-09 00:00:00") saem assim de alguns
        // exportadores; só a parte da data interessa.
        $s = preg_split('/[ T]/', $s)[0] ?? $s;

        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $s, $m) === 1) {
            $ano = (int) $m[3];
            if ($ano < 100) {
                // "26" é 2026, não 1926: a planilha é de finanças pessoais.
                $ano += $ano <= 69 ? 2000 : 1900;
            }
            $s = sprintf('%04d-%02d-%02d', $ano, (int) $m[2], (int) $m[1]);
        }

        return Http::normalizarData($s);
    }

    /**
     * Serial do Excel -> 'YYYY-MM-DD'. A base é 30/12/1899 porque o Excel
     * trata 1900 como bissexto; o deslocamento de dois dias cancela o erro.
     */
    private static function serial(float $serial): ?string
    {
        $dias = (int) floor($serial);
        // Faixa deliberadamente estreita (1950–2119): fora dela é bem mais
        // provável que a coluna guarde um valor qualquer do que uma data.
        if ($dias < 18_264 || $dias > 80_000) {
            return null;
        }
        $data = (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))
            ->modify("+{$dias} days");
        return $data === false ? null : $data->format('Y-m-d');
    }

    /**
     * Casa a célula com uma das opções aceitas, ignorando caixa e acento
     * ("despesa", "DESPESA" e "Despesa" são a mesma coisa).
     *
     * @param list<string> $permitidos
     */
    public static function opcao(mixed $v, array $permitidos, ?string $padrao = null): ?string
    {
        $t = self::texto($v, 60);
        if ($t === null) {
            return $padrao;
        }
        $chave = self::chave($t);
        foreach ($permitidos as $p) {
            if (self::chave($p) === $chave) {
                return $p;
            }
        }
        return $padrao;
    }
}
