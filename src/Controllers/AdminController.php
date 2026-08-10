<?php
/**
 * Painel administrativo: listar contas, redefinir senha, promover/rebaixar e
 * excluir.
 *
 * Toda ação daqui passa por Auth::exigirAdmin(). A checagem é por request e
 * lê is_admin da tabela users (via JOIN na sessão), não de algo gravado no
 * cookie — quem for rebaixado perde o acesso na requisição seguinte.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Users;

final class AdminController
{
    public static function listar(): void
    {
        $admin = Auth::exigirAdmin();

        $linhas = Database::todos(
            'SELECT u.id, u.usuario, u.nome, u.email, u.is_admin, u.criado_em, u.ultimo_login,
                    (SELECT COUNT(*) FROM transactions t WHERE t.user_id = u.id) AS n_lancamentos
               FROM users u
              ORDER BY u.id'
        );

        $items = array_map(static function (array $r) use ($admin): array {
            return [
                'id'            => (int) $r['id'],
                'usuario'       => $r['usuario'],
                'nome'          => $r['nome'] !== null && $r['nome'] !== '' ? $r['nome'] : $r['usuario'],
                'email'         => $r['email'],
                'is_admin'      => (bool) $r['is_admin'],
                'criado_em'     => $r['criado_em'],
                'ultimo_login'  => $r['ultimo_login'],
                'n_lancamentos' => (int) $r['n_lancamentos'],
                'eu'            => (int) $r['id'] === $admin,
            ];
        }, $linhas);

        Http::json(['items' => $items]);
    }

    public static function redefinirSenha(int $uid): void
    {
        Auth::exigirAdmin();
        $nova = AuthController::senhaDoCorpo('senha_nova');
        Users::buscarOuFalhar($uid);

        Database::transacao(static function () use ($uid, $nova): void {
            Database::run(
                "UPDATE users SET senha_hash = ?, salt = '' WHERE id = ?",
                [Auth::hashSenha($nova), $uid]
            );
            // A conta tem senha nova: nenhuma sessão antiga dela continua
            // valendo — inclusive a de quem eventualmente a tenha roubado.
            Database::run('DELETE FROM sessions WHERE user_id = ?', [$uid]);
        });

        Http::json(['ok' => true]);
    }

    public static function definirAdmin(int $uid): void
    {
        Auth::exigirAdmin();
        $virar = Http::booleano('is_admin');
        $u = Users::buscarOuFalhar($uid);

        // Sem esta trava o sistema pode ficar sem nenhum administrador, e daí
        // não há mais como promover ninguém pela interface.
        if (!$virar && (int) $u['is_admin'] === 1 && Users::contarAdmins() <= 1) {
            Http::erro(400, 'Não é possível rebaixar o último administrador');
        }

        Database::run('UPDATE users SET is_admin = ? WHERE id = ?', [$virar ? 1 : 0, $uid]);
        Http::json(['ok' => true, 'is_admin' => $virar]);
    }

    public static function excluir(int $uid): void
    {
        $admin = Auth::exigirAdmin();

        if ($uid === $admin) {
            Http::erro(400, 'Você não pode excluir a própria conta');
        }
        $u = Users::buscarOuFalhar($uid);
        if ((int) $u['is_admin'] === 1 && Users::contarAdmins() <= 1) {
            Http::erro(400, 'Não é possível excluir o último administrador');
        }

        Users::excluir($uid);
        Http::json(['ok' => true]);
    }
}
