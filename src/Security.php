<?php
/**
 * Defesas transversais do request: origem, CSRF, cookies e freio de força bruta.
 *
 * Aplicadas em public/index.php antes de qualquer controller rodar.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Security
{
    public const COOKIE_SESSAO = 'session';
    public const COOKIE_CSRF   = 'csrf';
    public const HEADER_CSRF   = 'HTTP_X_CSRF_TOKEN';

    /** Métodos que alteram estado — os únicos que exigem CSRF e checagem de origem. */
    private const METODOS_ESCRITA = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public static function alteraEstado(): bool
    {
        return in_array(Http::metodo(), self::METODOS_ESCRITA, true);
    }

    // ------------------------------------------------------------- origem

    /**
     * Primeira barreira de CSRF: request de escrita vindo de outro site é
     * recusado antes de tocar o banco.
     *
     * O navegador envia Origin em toda requisição de escrita e o script da
     * página atacante não consegue forjá-lo. Ausência de Origin (curl, um
     * cliente próprio) passa aqui — mas esbarra no token CSRF logo adiante,
     * que é a defesa que realmente sustenta a proteção.
     */
    public static function verificarOrigem(): void
    {
        if (!self::alteraEstado()) {
            return;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '' || $origin === 'null') {
            return;
        }
        $hostOrigem = parse_url($origin, PHP_URL_HOST);
        $hostAtual  = self::hostAtual();
        if (!is_string($hostOrigem) || strcasecmp($hostOrigem, $hostAtual) !== 0) {
            Http::erro(403, 'Origem não autorizada.');
        }
    }

    private static function hostAtual(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // HTTP_HOST inclui a porta; a comparação com o Origin usa só o host.
        $semPorta = parse_url('http://' . $host, PHP_URL_HOST);
        return is_string($semPorta) ? $semPorta : 'localhost';
    }

    // --------------------------------------------------------------- CSRF

    /**
     * Token CSRF sincronizado com a sessão.
     *
     * Autenticado: o header X-CSRF-Token tem de bater com o csrf_hash gravado
     * na linha da sessão. Não basta reapresentar o cookie — mesmo quem
     * conseguisse plantar um cookie no domínio (subdomínio comprometido) não
     * passaria, porque o valor esperado está no servidor.
     *
     * Anônimo (login/cadastro): não há sessão ainda, então cai no
     * double-submit contra o cookie `csrf`. Protege contra login-CSRF, e a
     * política SameSite=Lax impede que o cookie sequer acompanhe um POST
     * disparado de outro site.
     */
    public static function verificarCsrf(): void
    {
        if (!self::alteraEstado()) {
            return;
        }

        $enviado = $_SERVER[self::HEADER_CSRF] ?? '';
        if (!is_string($enviado) || $enviado === '') {
            Http::erro(403, 'Requisição sem token CSRF. Recarregue a página e tente de novo.');
        }

        $sessao = Auth::sessaoAtual();
        if ($sessao !== null) {
            $esperado = (string) $sessao['csrf_hash'];
            if (!hash_equals($esperado, hash('sha256', $enviado))) {
                Http::erro(403, 'Token CSRF inválido. Recarregue a página e tente de novo.');
            }
            return;
        }

        $cookie = $_COOKIE[self::COOKIE_CSRF] ?? '';
        if (!is_string($cookie) || $cookie === '' || !hash_equals($cookie, $enviado)) {
            Http::erro(403, 'Token CSRF inválido. Recarregue a página e tente de novo.');
        }
    }

    /**
     * Garante que a página sempre tenha um token CSRF disponível para o JS.
     * Este é o único cookie legível por script — é o que o app.js copia para
     * o header. O cookie de sessão continua HttpOnly.
     */
    public static function garantirCookieCsrf(): string
    {
        $atual = $_COOKIE[self::COOKIE_CSRF] ?? '';
        if (is_string($atual) && preg_match('/^[a-f0-9]{64}$/', $atual) === 1) {
            return $atual;
        }
        $token = self::novoToken();
        self::setCookie(self::COOKIE_CSRF, $token, Config::SESSAO_DIAS * 86400, httpOnly: false);
        $_COOKIE[self::COOKIE_CSRF] = $token;
        return $token;
    }

    public static function definirCookieCsrf(string $token): void
    {
        self::setCookie(self::COOKIE_CSRF, $token, Config::SESSAO_DIAS * 86400, httpOnly: false);
        $_COOKIE[self::COOKIE_CSRF] = $token;
    }

    // ------------------------------------------------------------ cookies

    public static function setCookie(string $nome, string $valor, int $vidaSegundos, bool $httpOnly = true): void
    {
        setcookie($nome, $valor, [
            'expires'  => time() + $vidaSegundos,
            'path'     => '/',
            'secure'   => self::httpsAtivo(),
            'httponly' => $httpOnly,
            // Lax: o cookie não acompanha POST vindo de outro site (bloqueia
            // CSRF por formulário), mas sobrevive ao usuário chegar por um link.
            'samesite' => 'Lax',
        ]);
    }

    public static function apagarCookie(string $nome, bool $httpOnly = true): void
    {
        setcookie($nome, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::httpsAtivo(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$nome]);
    }

    /** Marca Secure automaticamente quando servido por HTTPS. */
    private static function httpsAtivo(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // Atrás de proxy TLS o PHP só sabe por este header; confiado apenas
        // para *ativar* o Secure — nunca para desativar.
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function novoToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------- força bruta

    /**
     * Endereço de origem em binário, para a chave do freio de login.
     *
     * X-Forwarded-For é ignorado de propósito: é um header que o cliente
     * escreve, e confiar nele daria a qualquer um uma cota nova de tentativas
     * a cada request. Rodando atrás de um proxy conhecido, resolva o IP real
     * no próprio proxy (mod_remoteip) antes de chegar aqui.
     */
    private static function ipBinario(): string
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $bin = @inet_pton(is_string($ip) ? $ip : '0.0.0.0');
        return $bin === false ? inet_pton('0.0.0.0') : $bin;
    }

    /**
     * Barra o login quando o par (IP, identificador) estourou as tentativas.
     * Sem isso a força bruta é limitada só pela banda do atacante.
     */
    public static function verificarBloqueioLogin(string $identificador): void
    {
        $linha = Database::um(
            'SELECT tentativas, bloqueado_ate FROM login_attempts
              WHERE ip = ? AND identificador = ?',
            [self::ipBinario(), mb_substr($identificador, 0, 190)]
        );
        if ($linha === null || $linha['bloqueado_ate'] === null) {
            return;
        }
        $ate = strtotime((string) $linha['bloqueado_ate'] . ' UTC');
        if ($ate !== false && $ate > time()) {
            $minutos = max(1, (int) ceil(($ate - time()) / 60));
            Http::erro(429, "Muitas tentativas. Tente novamente em {$minutos} minuto(s).");
        }
    }

    public static function registrarFalhaLogin(string $identificador): void
    {
        $bloqueio = Config::LOGIN_BLOQUEIO_SEGUNDOS;
        $limite   = Config::LOGIN_MAX_TENTATIVAS;
        Database::run(
            'INSERT INTO login_attempts (ip, identificador, tentativas)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
               tentativas    = tentativas + 1,
               bloqueado_ate = IF(tentativas + 1 >= ?,
                                  DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                                  bloqueado_ate)',
            [self::ipBinario(), mb_substr($identificador, 0, 190), $limite, $bloqueio]
        );
    }

    public static function limparFalhasLogin(string $identificador): void
    {
        Database::run(
            'DELETE FROM login_attempts WHERE ip = ? AND identificador = ?',
            [self::ipBinario(), mb_substr($identificador, 0, 190)]
        );
    }

    /** Faxina oportunista de registros vencidos (1% dos requests). */
    public static function limpezaPeriodica(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        try {
            Database::run('DELETE FROM sessions WHERE expira_em < UTC_TIMESTAMP()');
            Database::run(
                'DELETE FROM login_attempts
                  WHERE atualizado_em < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                    AND (bloqueado_ate IS NULL OR bloqueado_ate < UTC_TIMESTAMP())'
            );
        } catch (\Throwable) {
            // Faxina é oportunista: falhar aqui não pode derrubar o request.
        }
    }
}
