<?php
/**
 * Cadastro, login, perfil e troca de senha.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Security;
use MinhasContas\Users;
use PDOException;

final class AuthController
{
    /** Mínimo para senhas novas. Não se aplica à verificação — contas antigas continuam entrando. */
    private const SENHA_MIN = 8;

    /**
     * bcrypt só considera os primeiros 72 bytes. Aceitar mais seria mentir
     * sobre a força da senha: "senha…" + 200 caracteres teria o mesmo hash
     * que os 72 primeiros. Melhor recusar explicitamente.
     */
    private const SENHA_MAX_BYTES = 72;

    public static function registrar(): void
    {
        $nome  = Http::texto('nome', 190);
        $email = mb_strtolower(Http::texto('email', 190));
        $senha = self::senhaDoCorpo('senha');

        if (mb_strlen($nome) < 2) {
            Http::erro(400, 'Informe seu nome');
        }
        self::validarEmail($email);

        $existe = Database::um('SELECT 1 FROM users WHERE email = ? OR usuario = ?', [$email, $email]);
        if ($existe !== null) {
            Http::erro(400, 'Já existe uma conta com esse e-mail');
        }

        try {
            $uid = Users::criar($email, $senha, nome: $nome, email: $email);
        } catch (PDOException $e) {
            // A UNIQUE do banco é quem decide de fato: duas requisições
            // simultâneas com o mesmo e-mail passariam pelo SELECT acima.
            if ($e->getCode() === '23000') {
                Http::erro(400, 'Já existe uma conta com esse e-mail');
            }
            throw $e;
        }

        Auth::criarSessao($uid);
        Http::json(['nome' => $nome, 'email' => $email, 'is_admin' => false]);
    }

    public static function login(): void
    {
        $ident = mb_strtolower(Http::texto('email', 190));
        $senha = Http::texto('senha', 200);

        Security::verificarBloqueioLogin($ident);

        $u = Database::um(
            'SELECT * FROM users WHERE LOWER(email) = ? OR LOWER(usuario) = ?',
            [$ident, $ident]
        );

        $ok = $u !== null && Auth::verificarSenha($senha, (string) $u['senha_hash'], (string) $u['salt']);

        if (!$ok) {
            Security::registrarFalhaLogin($ident);
            // Mensagem única para usuário inexistente e senha errada: dizer
            // qual dos dois falhou entregaria uma lista de e-mails cadastrados.
            Http::erro(401, 'E-mail ou senha inválidos');
        }

        /** @var array<string, mixed> $u */
        $uid = (int) $u['id'];
        Security::limparFalhasLogin($ident);
        Auth::reidratarSeNecessario($uid, $senha, (string) $u['senha_hash'], (string) $u['salt']);

        Database::run('UPDATE users SET ultimo_login = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
        Auth::criarSessao($uid);

        Http::json([
            'nome'     => $u['nome'] !== null && $u['nome'] !== '' ? $u['nome'] : $u['usuario'],
            'email'    => $u['email'],
            'is_admin' => (bool) $u['is_admin'],
        ]);
    }

    public static function logout(): void
    {
        Auth::encerrarSessaoAtual();
        Http::json(['ok' => true]);
    }

    public static function eu(): void
    {
        $uid = Auth::exigirUsuario();
        $u = Database::um('SELECT usuario, nome, email, is_admin FROM users WHERE id = ?', [$uid]);
        if ($u === null) {
            Http::erro(401, 'Sessão inválida');
        }
        Http::json([
            'usuario'  => $u['usuario'],
            'nome'     => $u['nome'] !== null && $u['nome'] !== '' ? $u['nome'] : $u['usuario'],
            'email'    => $u['email'],
            'is_admin' => (bool) $u['is_admin'],
        ]);
    }

    public static function atualizarPerfil(): void
    {
        $uid   = Auth::exigirUsuario();
        $nome  = Http::texto('nome', 190);
        $email = mb_strtolower(Http::texto('email', 190));

        if (mb_strlen($nome) < 2) {
            Http::erro(400, 'Informe seu nome');
        }
        self::validarEmail($email);

        $conflito = Database::um(
            'SELECT 1 FROM users WHERE (LOWER(email) = ? OR LOWER(usuario) = ?) AND id <> ?',
            [$email, $email, $uid]
        );
        if ($conflito !== null) {
            Http::erro(400, 'Já existe uma conta com esse e-mail');
        }

        $u = Users::buscarOuFalhar($uid);
        // Contas criadas pelo cadastro têm usuario == e-mail, e o login casa
        // nas duas colunas; então o usuario precisa acompanhar, senão o e-mail
        // antigo continuaria entrando. Contas com handle próprio (ex.:
        // 'planilha') mantêm o handle.
        $usuarioAtual = (string) ($u['usuario'] ?? '');
        $novoUsuario  = self::pareceEmail($usuarioAtual) ? $email : $usuarioAtual;

        try {
            Database::run(
                'UPDATE users SET nome = ?, email = ?, usuario = ? WHERE id = ?',
                [$nome, $email, $novoUsuario, $uid]
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Http::erro(400, 'Já existe uma conta com esse e-mail');
            }
            throw $e;
        }

        Http::json(['nome' => $nome, 'email' => $email, 'usuario' => $novoUsuario]);
    }

    public static function trocarSenha(): void
    {
        $uid   = Auth::exigirUsuario();
        $atual = Http::texto('senha_atual', 200);
        $nova  = self::senhaDoCorpo('senha_nova');

        $u = Users::buscarOuFalhar($uid);
        if (!Auth::verificarSenha($atual, (string) $u['senha_hash'], (string) $u['salt'])) {
            Http::erro(400, 'Senha atual incorreta');
        }

        Database::run(
            "UPDATE users SET senha_hash = ?, salt = '' WHERE id = ?",
            [Auth::hashSenha($nova), $uid]
        );
        // Quem tiver a senha antiga guardada em outro dispositivo sai.
        Auth::encerrarOutrasSessoes($uid);

        Http::json(['ok' => true]);
    }

    // ---------------------------------------------------------------- util

    public static function senhaDoCorpo(string $campo): string
    {
        $v = Http::corpo()[$campo] ?? null;
        if (!is_string($v) || $v === '') {
            Http::erro(400, 'Informe a senha');
        }
        return self::validarSenha($v);
    }

    public static function validarSenha(string $senha): string
    {
        if (mb_strlen($senha) < self::SENHA_MIN) {
            Http::erro(400, 'A senha precisa ter ao menos ' . self::SENHA_MIN . ' caracteres');
        }
        if (strlen($senha) > self::SENHA_MAX_BYTES) {
            Http::erro(400, 'A senha pode ter no máximo ' . self::SENHA_MAX_BYTES . ' caracteres');
        }
        if (str_contains($senha, "\0")) {
            Http::erro(400, 'A senha contém caracteres inválidos');
        }
        return $senha;
    }

    private static function validarEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !self::pareceEmail($email)) {
            Http::erro(400, 'E-mail inválido');
        }
    }

    private static function pareceEmail(string $v): bool
    {
        return preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $v) === 1;
    }
}
