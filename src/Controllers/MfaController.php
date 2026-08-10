<?php
/**
 * MFA (TOTP): pendência entre senha e sessão real, cadastro do autenticador e
 * verificação do código (ou de um código de backup) no login.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Config;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Mfa;
use MinhasContas\Security;

final class MfaController
{
    private const COOKIE_PENDENTE = 'mfa_pending';

    /**
     * Chamado por AuthController::login() após senha e e-mail conferidos.
     * @return array<string, mixed>
     */
    public static function iniciarPendencia(int $uid): array
    {
        $modo = self::mfaAtivo($uid) ? 'verify' : 'setup';

        $token = Security::novoToken();
        Database::run('DELETE FROM mfa_pending WHERE user_id = ?', [$uid]);
        Database::run(
            'INSERT INTO mfa_pending (token_hash, user_id, modo, expira_em) VALUES (?, ?, ?, ?)',
            [
                hash('sha256', $token),
                $uid,
                $modo,
                gmdate('Y-m-d H:i:s', time() + Config::MFA_PENDENTE_TTL_SEGUNDOS),
            ]
        );
        Security::setCookie(self::COOKIE_PENDENTE, $token, Config::MFA_PENDENTE_TTL_SEGUNDOS);

        return ['status' => $modo === 'setup' ? 'mfa_setup_required' : 'mfa_required'];
    }

    private static function mfaAtivo(int $uid): bool
    {
        return Database::valor('SELECT mfa_ativado_em FROM users WHERE id = ?', [$uid]) !== null;
    }

    /** @return array{user_id:int, modo:string} */
    private static function exigirPendencia(string $modoEsperado): array
    {
        $token = $_COOKIE[self::COOKIE_PENDENTE] ?? '';
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            Http::erro(401, 'Sessão de login expirada. Entre novamente.');
        }
        $linha = Database::um(
            'SELECT user_id, modo FROM mfa_pending WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
        if ($linha === null || $linha['modo'] !== $modoEsperado) {
            Http::erro(401, 'Sessão de login expirada. Entre novamente.');
        }
        return ['user_id' => (int) $linha['user_id'], 'modo' => (string) $linha['modo']];
    }

    private static function encerrarPendencia(int $uid): void
    {
        Database::run('DELETE FROM mfa_pending WHERE user_id = ?', [$uid]);
        Security::apagarCookie(self::COOKIE_PENDENTE);
    }

    public static function setupIniciar(): void
    {
        $p = self::exigirPendencia('setup');
        $uid = $p['user_id'];

        $u = Database::um('SELECT email, usuario FROM users WHERE id = ?', [$uid]);
        if ($u === null) {
            Http::erro(401, 'Sessão de login expirada. Entre novamente.');
        }

        $segredo = Mfa::gerarSegredo();
        Database::run('UPDATE users SET mfa_secret_cifrado = ? WHERE id = ?', [Mfa::cifrarSegredo($segredo), $uid]);

        $rotulo = (string) ($u['email'] ?? $u['usuario']);
        Http::json([
            'segredo'     => $segredo,
            'otpauth_uri' => Mfa::uriProvisionamento($segredo, $rotulo),
        ]);
    }

    public static function setupConfirmar(): void
    {
        $p = self::exigirPendencia('setup');
        $uid = $p['user_id'];
        $codigo = Http::texto('codigo', 10);

        Security::verificarBloqueioLogin("mfa:{$uid}");

        $cifrado = Database::valor('SELECT mfa_secret_cifrado FROM users WHERE id = ?', [$uid]);
        $segredo = is_string($cifrado) && $cifrado !== '' ? Mfa::decifrarSegredo($cifrado) : null;

        if (!Mfa::verificarCodigo($segredo, $codigo)) {
            Security::registrarFalhaLogin("mfa:{$uid}");
            Http::erro(401, 'Código inválido.');
        }
        Security::limparFalhasLogin("mfa:{$uid}");

        $codigosBackup = Mfa::gerarCodigosBackup();
        Database::transacao(static function () use ($uid, $codigosBackup): void {
            Database::run('UPDATE users SET mfa_ativado_em = UTC_TIMESTAMP(), ultimo_login = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
            Database::run('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$uid]);
            foreach ($codigosBackup as $c) {
                Database::run('INSERT INTO mfa_backup_codes (user_id, codigo_hash) VALUES (?, ?)', [$uid, hash('sha256', $c)]);
            }
        });

        self::encerrarPendencia($uid);
        Auth::criarSessao($uid);

        Http::json(array_merge(self::dadosUsuario($uid), ['codigos_backup' => $codigosBackup]));
    }

    public static function verificar(): void
    {
        $p = self::exigirPendencia('verify');
        $uid = $p['user_id'];
        $codigo = Http::texto('codigo', 10);

        Security::verificarBloqueioLogin("mfa:{$uid}");

        $cifrado = Database::valor('SELECT mfa_secret_cifrado FROM users WHERE id = ?', [$uid]);
        $segredo = is_string($cifrado) && $cifrado !== '' ? Mfa::decifrarSegredo($cifrado) : null;

        $usouBackup = false;
        if (!Mfa::verificarCodigo($segredo, $codigo)) {
            if (!self::consumirCodigoBackup($uid, $codigo)) {
                Security::registrarFalhaLogin("mfa:{$uid}");
                Http::erro(401, 'Código inválido.');
            }
            $usouBackup = true;
        }
        Security::limparFalhasLogin("mfa:{$uid}");

        Database::run('UPDATE users SET ultimo_login = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
        self::encerrarPendencia($uid);
        Auth::criarSessao($uid);

        Http::json(array_merge(self::dadosUsuario($uid), ['usou_codigo_backup' => $usouBackup]));
    }

    private static function consumirCodigoBackup(int $uid, string $codigo): bool
    {
        $hash = hash('sha256', strtoupper(trim($codigo)));
        $linha = Database::um(
            'SELECT id FROM mfa_backup_codes WHERE user_id = ? AND codigo_hash = ? AND usado_em IS NULL',
            [$uid, $hash]
        );
        if ($linha === null) {
            return false;
        }
        Database::run('UPDATE mfa_backup_codes SET usado_em = UTC_TIMESTAMP() WHERE id = ?', [$linha['id']]);
        return true;
    }

    public static function regenerarCodigosBackup(): void
    {
        $uid = Auth::exigirUsuario();
        $codigo = Http::texto('codigo', 10);

        Security::verificarBloqueioLogin("mfa:{$uid}");
        $cifrado = Database::valor('SELECT mfa_secret_cifrado FROM users WHERE id = ?', [$uid]);
        $segredo = is_string($cifrado) && $cifrado !== '' ? Mfa::decifrarSegredo($cifrado) : null;
        if (!Mfa::verificarCodigo($segredo, $codigo)) {
            Security::registrarFalhaLogin("mfa:{$uid}");
            Http::erro(401, 'Código inválido.');
        }
        Security::limparFalhasLogin("mfa:{$uid}");

        $codigosBackup = Mfa::gerarCodigosBackup();
        Database::transacao(static function () use ($uid, $codigosBackup): void {
            Database::run('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$uid]);
            foreach ($codigosBackup as $c) {
                Database::run('INSERT INTO mfa_backup_codes (user_id, codigo_hash) VALUES (?, ?)', [$uid, hash('sha256', $c)]);
            }
        });

        Http::json(['codigos_backup' => $codigosBackup]);
    }

    /** @return array<string, mixed> */
    private static function dadosUsuario(int $uid): array
    {
        $u = Database::um('SELECT nome, usuario, email, is_admin FROM users WHERE id = ?', [$uid]);
        return [
            'nome'     => $u['nome'] !== null && $u['nome'] !== '' ? $u['nome'] : $u['usuario'],
            'email'    => $u['email'],
            'is_admin' => (bool) $u['is_admin'],
        ];
    }
}
