<?php
/**
 * Entrada e saída HTTP: leitura do corpo JSON, coerção/validação dos campos e
 * emissão das respostas.
 *
 * Nenhum controller lê $_GET/$_POST direto — tudo passa pelos helpers daqui,
 * que devolvem o tipo declarado (int, float, string limitada, opção de lista)
 * ou estouram 400. É o ponto onde a entrada deixa de ser "o que o cliente
 * mandou" e vira um valor com formato conhecido.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Http
{
    /** @var array<string, mixed>|null Corpo JSON já decodificado. */
    private static ?array $corpo = null;

    // ---------------------------------------------------------------- saída

    /** @param array<string, mixed>|list<mixed> $dados */
    public static function json(array $dados, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /** Atalho para abortar o request com uma mensagem exibível. */
    public static function erro(int $status, string $detalhe): never
    {
        throw new ApiException($status, $detalhe);
    }

    /**
     * Cabeçalhos de segurança aplicados a toda resposta.
     *
     * A CSP é a defesa de fundo contra XSS: mesmo que algum dado do usuário
     * escape da escapagem no JS, o navegador não executa script inline nem
     * carrega script de origem externa. 'self' cobre /static/app.js e o
     * Chart.js embarcado; o style-src precisa de 'unsafe-inline' porque o
     * app.js monta estilos inline em tiles e gráficos.
     */
    public static function cabecalhosSeguranca(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header(
            "Content-Security-Policy: default-src 'none'; "
            . "script-src 'self'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data:; "
            . "connect-src 'self'; "
            . "form-action 'self'; "
            . "base-uri 'none'; "
            . "frame-ancestors 'none'"
        );
        header_remove('X-Powered-By');
    }

    // ---------------------------------------------------------------- entrada

    public static function metodo(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function caminho(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $caminho = parse_url($uri, PHP_URL_PATH);
        return is_string($caminho) ? rawurldecode($caminho) : '/';
    }

    /**
     * Corpo JSON da requisição.
     *
     * Recusa o que não for um objeto JSON: um array, um escalar ou lixo
     * viraria acesso a índice inexistente lá na frente.
     *
     * @return array<string, mixed>
     */
    public static function corpo(): array
    {
        if (self::$corpo !== null) {
            return self::$corpo;
        }
        $bruto = file_get_contents('php://input');
        if ($bruto === false || $bruto === '') {
            return self::$corpo = [];
        }
        if (strlen($bruto) > 2 * 1024 * 1024) {
            self::erro(413, 'Corpo da requisição grande demais.');
        }
        $dados = json_decode($bruto, true);
        if (!is_array($dados) || array_is_list($dados)) {
            self::erro(400, 'Corpo da requisição precisa ser um objeto JSON.');
        }
        /** @var array<string, mixed> $dados */
        return self::$corpo = $dados;
    }

    // ------------------------------------------------- campos do corpo JSON

    public static function texto(string $campo, int $max = 255, bool $obrigatorio = true, string $padrao = ''): string
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($obrigatorio) {
                self::erro(400, "Campo obrigatório: {$campo}");
            }
            return $padrao;
        }
        if (!is_string($v) && !is_numeric($v)) {
            self::erro(400, "Campo inválido: {$campo}");
        }
        return self::limitar((string) $v, $max, $campo);
    }

    /** Texto opcional que vira NULL quando vazio (colunas anuláveis). */
    public static function textoOuNulo(string $campo, int $max = 255): ?string
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v) && !is_numeric($v)) {
            self::erro(400, "Campo inválido: {$campo}");
        }
        $t = trim((string) $v);
        return $t === '' ? null : self::limitar($t, $max, $campo);
    }

    public static function numero(string $campo, float $padrao = 0.0): float
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null || $v === '') {
            return $padrao;
        }
        if (!is_numeric($v)) {
            self::erro(400, "Campo numérico inválido: {$campo}");
        }
        $n = (float) $v;
        if (!is_finite($n)) {
            self::erro(400, "Campo numérico inválido: {$campo}");
        }
        // Teto defensivo: mantém os totais dentro da precisão do double e
        // impede que um valor absurdo envenene os gráficos.
        if (abs($n) > 1e12) {
            self::erro(400, "Valor fora da faixa aceita: {$campo}");
        }
        return round($n, 2);
    }

    public static function inteiro(string $campo, int $padrao = 0, ?int $min = null, ?int $max = null): int
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null || $v === '') {
            $n = $padrao;
        } else {
            if (!is_numeric($v)) {
                self::erro(400, "Campo inteiro inválido: {$campo}");
            }
            $n = (int) $v;
        }
        if ($min !== null && $n < $min) {
            $n = $min;
        }
        if ($max !== null && $n > $max) {
            $n = $max;
        }
        return $n;
    }

    public static function inteiroOuNulo(string $campo, int $min, int $max): ?int
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            self::erro(400, "Campo inteiro inválido: {$campo}");
        }
        $n = (int) $v;
        return ($n < $min || $n > $max) ? null : $n;
    }

    public static function booleano(string $campo, bool $padrao = false): bool
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null) {
            return $padrao;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $padrao;
    }

    /**
     * Campo que só aceita valores de uma lista fechada.
     * Usado em status, tipo e grupo — colunas com domínio conhecido.
     *
     * @param list<string> $permitidos
     */
    public static function opcao(string $campo, array $permitidos, string $padrao): string
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null || $v === '') {
            return $padrao;
        }
        if (!is_string($v) || !in_array($v, $permitidos, true)) {
            self::erro(400, "Valor não permitido em {$campo}: " . self::amostra($v));
        }
        return $v;
    }

    /** Data ISO (YYYY-MM-DD) obrigatória, validada como data real. */
    public static function data(string $campo): string
    {
        $v = self::corpo()[$campo] ?? null;
        $d = is_string($v) ? self::normalizarData($v) : null;
        if ($d === null) {
            self::erro(400, "Data inválida em {$campo} (use AAAA-MM-DD).");
        }
        return $d;
    }

    public static function dataOuNulo(string $campo): ?string
    {
        $v = self::corpo()[$campo] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v)) {
            self::erro(400, "Data inválida em {$campo}.");
        }
        $d = self::normalizarData($v);
        if ($d === null) {
            self::erro(400, "Data inválida em {$campo} (use AAAA-MM-DD).");
        }
        return $d;
    }

    /**
     * Lista de IDs para as operações em lote.
     *
     * Devolve inteiros positivos, sem repetição e com teto de quantidade — o
     * número deles define quantos "?" a query vai ter.
     *
     * @return list<int>
     */
    public static function listaIds(string $campo, int $max = 5000): array
    {
        $v = self::corpo()[$campo] ?? null;
        if (!is_array($v)) {
            self::erro(400, "Campo {$campo} precisa ser uma lista de IDs.");
        }
        if (count($v) > $max) {
            self::erro(400, "Máximo de {$max} itens por operação.");
        }
        $ids = [];
        foreach ($v as $item) {
            if (!is_int($item) && !(is_string($item) && ctype_digit($item))) {
                self::erro(400, 'Lista de IDs inválida.');
            }
            $n = (int) $item;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        return array_values(array_map('intval', array_keys($ids)));
    }

    // ------------------------------------------------- parâmetros de query

    public static function queryTexto(string $campo, int $max = 120): ?string
    {
        $v = $_GET[$campo] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    public static function queryInteiro(string $campo, ?int $min = null, ?int $max = null): ?int
    {
        $v = $_GET[$campo] ?? null;
        if (!is_string($v) || trim($v) === '' || !is_numeric($v)) {
            return null;
        }
        $n = (int) $v;
        if ($min !== null && $n < $min) {
            return null;
        }
        if ($max !== null && $n > $max) {
            return $max;
        }
        return $n;
    }

    /**
     * Parâmetro de query restrito a uma lista fechada.
     * Diferente de opcao(): valor fora da lista é ignorado (vira null) em vez
     * de derrubar o request — filtros da UI não devem quebrar a tela.
     *
     * @param list<string> $permitidos
     */
    public static function queryOpcao(string $campo, array $permitidos): ?string
    {
        $v = self::queryTexto($campo);
        return ($v !== null && in_array($v, $permitidos, true)) ? $v : null;
    }

    // ---------------------------------------------------------------- util

    /** Corta o texto no limite da coluna e recusa bytes de controle. */
    private static function limitar(string $v, int $max, string $campo): string
    {
        $v = trim($v);
        // \x00 truncaria a string em várias camadas C; os demais controles não
        // têm uso legítimo nestes campos.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            self::erro(400, "Caracteres de controle não são aceitos em {$campo}.");
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            self::erro(400, "Codificação inválida em {$campo}.");
        }
        return mb_substr($v, 0, $max);
    }

    /** Data real, não só "parece uma data": 2026-02-31 é recusada. */
    public static function normalizarData(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1) {
            return null;
        }
        [, $a, $mes, $dia] = $m;
        if (!checkdate((int) $mes, (int) $dia, (int) $a)) {
            return null;
        }
        // Faixa defensiva: fora disso é erro de digitação, e DATE do MySQL
        // aceitaria calado.
        if ((int) $a < 1900 || (int) $a > 2200) {
            return null;
        }
        return $v;
    }

    /** Trecho curto e seguro do valor recusado, para a mensagem de erro. */
    private static function amostra(mixed $v): string
    {
        $s = is_scalar($v) ? (string) $v : gettype($v);
        $s = preg_replace('/[^\P{C}]/u', '', $s) ?? '';
        return mb_substr($s, 0, 40);
    }
}
