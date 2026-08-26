<?php
/**
 * Cadastro, login, perfil e troca de senha.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Config;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Mailer;
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

        self::enviarCodigoVerificacao($uid, $email);
        Http::json([
            'email'    => $email,
            'mensagem' => 'Conta criada. Enviamos um código de 6 dígitos para seu e-mail — digite-o para ativar a conta.',
        ]);
    }

    /**
     * Gera um código de 6 dígitos, substitui qualquer pendência anterior da
     * conta e envia por e-mail. Só o SHA-256 do código fica no banco.
     *
     * Falha de envio (SMTP fora do ar, credencial errada) não pode virar 500:
     * quem chama isso já criou a conta com sucesso antes deste ponto, e um
     * 500 aqui a deixaria travada — sem código e sem poder recadastrar
     * (o e-mail já existe). O código fica salvo; "reenviar código" tenta nele
     * de novo depois que o SMTP voltar.
     */
    private static function enviarCodigoVerificacao(int $uid, string $email): void
    {
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::run(
            'INSERT INTO email_verifications (user_id, codigo_hash, expira_em) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE codigo_hash = VALUES(codigo_hash), criado_em = CURRENT_TIMESTAMP, expira_em = VALUES(expira_em)',
            [$uid, hash('sha256', $codigo), gmdate('Y-m-d H:i:s', time() + Config::EMAIL_VERIFICACAO_TTL_SEGUNDOS)]
        );
        try {
            Mailer::enviar(
                $email,
                'Código de ativação — Minhas Contas',
                "Seu código de ativação é: {$codigo}\n\n"
                . "Digite-o na tela de ativação para confirmar seu e-mail. O código expira em 15 minutos.\n\n"
                . 'Se você não criou esta conta, ignore este e-mail.'
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[minhas-contas] Falha ao enviar código de verificação para %s: %s',
                $email,
                $e->getMessage()
            ));
        }
    }

    /**
     * Confirma o código enviado no cadastro e, se bater, já cria a sessão —
     * a pessoa acabou de provar a senha (cadastro) e a posse do e-mail
     * (código) nos últimos minutos, não há motivo para pedir login de novo.
     */
    public static function verificarEmail(): void
    {
        $email  = mb_strtolower(Http::texto('email', 190));
        $codigo = Http::texto('codigo', 10);

        $identificador = "email_verify:{$email}";
        Security::verificarBloqueioLogin($identificador);

        $u = Database::um(
            'SELECT * FROM users WHERE LOWER(email) = ? AND email_verificado_em IS NULL',
            [$email]
        );
        $pendencia = $u !== null
            ? Database::um(
                'SELECT codigo_hash FROM email_verifications WHERE user_id = ? AND expira_em > UTC_TIMESTAMP()',
                [(int) $u['id']]
            )
            : null;

        $ok = $pendencia !== null && hash_equals((string) $pendencia['codigo_hash'], hash('sha256', $codigo));
        if (!$ok) {
            Security::registrarFalhaLogin($identificador);
            Http::erro(400, 'Código inválido ou expirado.');
        }

        /** @var array<string, mixed> $u */
        $uid = (int) $u['id'];
        Security::limparFalhasLogin($identificador);

        Database::transacao(static function () use ($uid): void {
            Database::run('UPDATE users SET email_verificado_em = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
            Database::run('DELETE FROM email_verifications WHERE user_id = ?', [$uid]);
        });

        Auth::criarSessao($uid);
        Http::json([
            'nome'     => $u['nome'] !== null && $u['nome'] !== '' ? $u['nome'] : $u['usuario'],
            'email'    => $u['email'],
            'is_admin' => (bool) $u['is_admin'],
        ]);
    }

    /** Resposta sempre genérica: não revela se a conta existe ou já foi ativada. */
    public static function reenviarVerificacao(): void
    {
        $email = mb_strtolower(Http::texto('email', 190));
        self::validarEmail($email);

        $u = Database::um(
            'SELECT id FROM users WHERE LOWER(email) = ? AND email_verificado_em IS NULL',
            [$email]
        );
        if ($u !== null) {
            $uid = (int) $u['id'];
            $recente = Database::valor(
                'SELECT 1 FROM email_verifications WHERE user_id = ? AND criado_em > ?',
                [$uid, gmdate('Y-m-d H:i:s', time() - Config::TOKEN_REENVIO_COOLDOWN_SEGUNDOS)]
            );
            if ($recente === null) {
                self::enviarCodigoVerificacao($uid, $email);
            }
        }

        Http::json(['mensagem' => 'Se esse e-mail existir e a conta ainda não tiver sido ativada, reenviamos o código.']);
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

        // Contas sem e-mail (ex.: 'planilha') nunca passaram pelo cadastro
        // com código, então não têm o que confirmar.
        if ($u['email'] !== null && $u['email_verificado_em'] === null) {
            Http::erro(403, 'Confirme seu e-mail antes de entrar. Verifique sua caixa de entrada ou peça um novo código.');
        }

        // ultimo_login só é atualizado quando a sessão real é de fato criada
        // (dentro do MfaController), depois do código MFA confirmado.
        Http::json(\MinhasContas\Controllers\MfaController::iniciarPendencia($uid));
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

    /** Minutos de validade do token de redefinição. */
    private const RESET_VALIDADE_MIN = 60;

    /**
     * Pede a redefinição de senha.
     *
     * Resposta sempre genérica, igual a reenviarVerificacao(): responder
     * diferente para e-mail existente e inexistente transformaria este
     * endpoint num oráculo de "quem tem conta aqui" — e num app de finanças
     * isso já é informação sensível por si só.
     */
    public static function esqueciSenha(): void
    {
        $email = mb_strtolower(Http::texto('email', 190));

        // O freio existe contra usar o endpoint como canhão de e-mail contra
        // um terceiro, não contra adivinhação — não há o que adivinhar aqui.
        $identificador = "senha_reset:{$email}";
        Security::verificarBloqueioLogin($identificador);
        Security::registrarFalhaLogin($identificador);

        $u = Database::um(
            'SELECT id, email FROM users
              WHERE LOWER(email) = ? AND email_verificado_em IS NOT NULL',
            [$email]
        );

        if ($u !== null) {
            $token = Security::novoToken();
            Database::run(
                'REPLACE INTO password_resets (user_id, token_hash, expira_em)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))',
                [(int) $u['id'], hash('sha256', $token), self::RESET_VALIDADE_MIN]
            );

            $base = Config::appUrl();
            $link = $base !== '' ? "{$base}/?redefinir=" . rawurlencode($token) : '';
            $corpo = "Olá,\n\n"
                . "Recebemos um pedido para redefinir a senha da sua conta no Minhas Contas.\n\n"
                . ($link !== '' ? "Abra este link para escolher uma senha nova:\n{$link}\n\n" : '')
                . "Se preferir, cole este código na tela de redefinição:\n{$token}\n\n"
                . 'Ele vale por ' . self::RESET_VALIDADE_MIN . " minutos e só pode ser usado uma vez.\n\n"
                . "Se não foi você quem pediu, ignore esta mensagem: sua senha continua a mesma.\n";

            // Falha de SMTP não pode virar erro para quem pediu — senão a
            // resposta deixa de ser genérica e volta a revelar quem tem conta.
            try {
                Mailer::enviar((string) $u['email'], 'Redefinir sua senha — Minhas Contas', $corpo);
            } catch (\Throwable $e) {
                error_log('[senha_reset] falha ao enviar e-mail: ' . $e->getMessage());
            }
        }

        Http::json(['status' => 'ok']);
    }

    /**
     * Consome o token e grava a senha nova.
     *
     * A consulta é por token_hash sozinho, sem user_id, porque quem chega aqui
     * ainda não se identificou. Isso só é seguro porque o token tem 256 bits —
     * ver o comentário de password_resets em database.sql, que explica por que
     * a regra oposta vale para o código de 6 dígitos da verificação de e-mail.
     */
    public static function redefinirSenha(): void
    {
        $token = Http::texto('token', 128);
        $nova  = self::validarSenha(self::senhaDoCorpo('senha'));

        $pedido = Database::um(
            'SELECT user_id FROM password_resets
              WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
        if ($pedido === null) {
            Http::erro(400, 'Link inválido ou expirado. Peça a redefinição de novo.');
        }

        /** @var array<string, mixed> $pedido */
        $uid = (int) $pedido['user_id'];

        Database::transacao(static function () use ($uid, $nova): void {
            // salt = '' aposenta o esquema antigo, igual a trocarSenha() e ao
            // painel de admin. Gravar só senha_hash deixaria a conta sem login.
            Database::run(
                "UPDATE users SET senha_hash = ?, salt = '' WHERE id = ?",
                [Auth::hashSenha($nova), $uid]
            );
            // Uso único.
            Database::run('DELETE FROM password_resets WHERE user_id = ?', [$uid]);
            // Quem redefine a senha pode estar reagindo a um acesso indevido:
            // derrubar todas as sessões corta o invasor que ainda esteja logado.
            Database::run('DELETE FROM sessions WHERE user_id = ?', [$uid]);
        });

        Http::json(['status' => 'ok']);
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
