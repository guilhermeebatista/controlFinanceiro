<?php
/**
 * Autenticação: hash de senha, sessões e resolução do usuário do request.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Auth
{
    /** Iterações do PBKDF2 usado pela versão Python — só para verificar hashes herdados. */
    private const PBKDF2_ITERACOES = 200_000;

    /** @var array<string, mixed>|null Linha de sessions+users do request atual. */
    private static ?array $sessao = null;
    private static bool $sessaoResolvida = false;

    // ------------------------------------------------------------- senhas

    /**
     * bcrypt via password_hash: salt aleatório por senha e fator de custo
     * embutidos no próprio hash. Substitui o PBKDF2 escrito à mão da versão
     * anterior — menos código nosso no caminho crítico e reidratação
     * automática quando o custo subir.
     */
    public static function hashSenha(string $senha): string
    {
        $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => Config::BCRYPT_COST]);
        if ($hash === false || $hash === '') {
            throw new \RuntimeException('Falha ao gerar o hash da senha.');
        }
        return $hash;
    }

    /**
     * Confere a senha aceitando os dois formatos.
     *
     * `salt` preenchido identifica uma conta migrada do SQLite, cujo hash é
     * PBKDF2-HMAC-SHA256 em hex. As contas continuam funcionando com a senha
     * de sempre; no primeiro login o registro é convertido para bcrypt
     * (ver reidratarSeNecessario).
     *
     * Comparação com hash_equals para não vazar por tempo onde os hashes
     * começam a divergir.
     */
    public static function verificarSenha(string $senha, string $hashArmazenado, string $salt): bool
    {
        if ($salt !== '') {
            if (preg_match('/^[a-f0-9]+$/i', $salt) !== 1 || strlen($salt) % 2 !== 0) {
                return false;
            }
            $saltBin = hex2bin($salt);
            if ($saltBin === false) {
                return false;
            }
            $calculado = hash_pbkdf2('sha256', $senha, $saltBin, self::PBKDF2_ITERACOES);
            return hash_equals($hashArmazenado, $calculado);
        }
        return password_verify($senha, $hashArmazenado);
    }

    /**
     * Converte hash legado para bcrypt, ou reaplica bcrypt quando o custo
     * configurado subiu. Roda logo após um login bem-sucedido, então a senha
     * em claro ainda está disponível e o usuário não percebe nada.
     */
    public static function reidratarSeNecessario(int $userId, string $senha, string $hash, string $salt): void
    {
        $precisa = $salt !== ''
            || password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => Config::BCRYPT_COST]);
        if (!$precisa) {
            return;
        }
        Database::run(
            "UPDATE users SET senha_hash = ?, salt = '' WHERE id = ?",
            [self::hashSenha($senha), $userId]
        );
    }

    // ------------------------------------------------------------ sessões

    /**
     * Abre uma sessão e devolve o token CSRF que o frontend vai reapresentar.
     *
     * No banco ficam apenas os SHA-256 do token de sessão e do token CSRF: um
     * dump do banco não permite montar um cookie válido. Como os tokens têm
     * 256 bits de entropia, hash simples basta — não há espaço de busca para
     * força bruta que justifique KDF aqui.
     */
    public static function criarSessao(int $userId): string
    {
        $token = Security::novoToken();
        $csrf  = Security::novoToken();

        Database::run(
            'INSERT INTO sessions (token_hash, user_id, csrf_hash, expira_em)
             VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY))',
            [hash('sha256', $token), $userId, hash('sha256', $csrf), Config::SESSAO_DIAS]
        );

        Security::setCookie(Security::COOKIE_SESSAO, $token, Config::SESSAO_DIAS * 86400);
        Security::definirCookieCsrf($csrf);

        // O request que acabou de logar já enxerga a sessão nova.
        self::$sessaoResolvida = false;
        self::$sessao = null;
        $_COOKIE[Security::COOKIE_SESSAO] = $token;

        return $csrf;
    }

    /**
     * Sessão do request atual (ou null). Uma consulta por request, memoizada.
     *
     * @return array<string, mixed>|null
     */
    public static function sessaoAtual(): ?array
    {
        if (self::$sessaoResolvida) {
            return self::$sessao;
        }
        self::$sessaoResolvida = true;

        $token = $_COOKIE[Security::COOKIE_SESSAO] ?? '';
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return self::$sessao = null;
        }

        self::$sessao = Database::um(
            'SELECT s.token_hash, s.user_id, s.csrf_hash, u.is_admin
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.expira_em > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
        return self::$sessao;
    }

    public static function encerrarSessaoAtual(): void
    {
        $sessao = self::sessaoAtual();
        if ($sessao !== null) {
            Database::run('DELETE FROM sessions WHERE token_hash = ?', [$sessao['token_hash']]);
        }
        Security::apagarCookie(Security::COOKIE_SESSAO);
        self::$sessao = null;
        self::$sessaoResolvida = true;
    }

    /** Derruba as outras sessões do usuário, preservando a atual. */
    public static function encerrarOutrasSessoes(int $userId): void
    {
        $sessao = self::sessaoAtual();
        if ($sessao === null) {
            Database::run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
            return;
        }
        Database::run(
            'DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?',
            [$userId, $sessao['token_hash']]
        );
    }

    // --------------------------------------------------------- porteiros

    /** ID do usuário autenticado, ou 401. */
    public static function exigirUsuario(): int
    {
        $sessao = self::sessaoAtual();
        if ($sessao === null) {
            Http::erro(401, 'Não autenticado');
        }
        return (int) $sessao['user_id'];
    }

    /**
     * ID do usuário autenticado que também é administrador, ou 403.
     * O is_admin vem do JOIN em users, então refletir uma promoção/rebaixamento
     * não depende de a sessão ser recriada.
     */
    public static function exigirAdmin(): int
    {
        $sessao = self::sessaoAtual();
        if ($sessao === null) {
            Http::erro(401, 'Não autenticado');
        }
        if ((int) $sessao['is_admin'] !== 1) {
            Http::erro(403, 'Acesso restrito a administradores');
        }
        return (int) $sessao['user_id'];
    }
}
