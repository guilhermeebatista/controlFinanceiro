<?php
declare(strict_types=1);

/**
 * Recuperação de senha: propriedades do token.
 *
 * Não sobe servidor HTTP — exercita direto contra o banco as invariantes que
 * decidem se o fluxo é seguro: entropia do token, armazenamento como hash,
 * pedido único por conta, expiração, cascade, e o fato de a senha nova passar
 * pelo mesmo caminho de hash que o login usa.
 *
 * Precisa do banco de pé. Rodar de dentro do container da aplicação:
 *   docker compose exec app php tests/recuperacao_senha_test.php
 *
 * Usa uma conta descartável com e-mail em @exemplo.invalid (TLD reservado pela
 * RFC 2606, nunca entregável) e a remove ao final, inclusive se algo falhar.
 */

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Auth;
use MinhasContas\Database;
use MinhasContas\Security;

function afirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: {$mensagem}\n");
        exit(1);
    }
    echo "ok: {$mensagem}\n";
}

// ---- Entropia do token ----

$t1 = Security::novoToken();
$t2 = Security::novoToken();
afirmar(strlen($t1) === 64, 'token tem 64 caracteres hex (32 bytes = 256 bits)');
afirmar(ctype_xdigit($t1), 'token e hexadecimal');
afirmar($t1 !== $t2, 'dois tokens seguidos sao diferentes');

// ---- O banco guarda hash, nunca o token ----

$hash = hash('sha256', $t1);
afirmar(strlen($hash) === 64, 'SHA-256 cabe em CHAR(64)');
afirmar($hash !== $t1, 'o que vai para o banco nao e o token');

// ---- Contra o banco de verdade ----

$email = 'teste-reset-' . bin2hex(random_bytes(4)) . '@exemplo.invalid';
Database::run(
    "INSERT INTO users (nome, usuario, email, senha_hash, salt, email_verificado_em)
     VALUES ('Teste Reset', ?, ?, ?, '', UTC_TIMESTAMP())",
    [$email, $email, Auth::hashSenha('senha-antiga-123')]
);
$uid = Database::ultimoId();
afirmar($uid > 0, 'conta de teste criada');

try {
    // Pedido valido.
    Database::run(
        'REPLACE INTO password_resets (user_id, token_hash, expira_em)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 MINUTE))',
        [$uid, $hash]
    );
    $achado = Database::um(
        'SELECT user_id FROM password_resets WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
        [$hash]
    );
    afirmar($achado !== null && (int) $achado['user_id'] === $uid,
        'token valido encontra a conta certa');

    // Token diferente nao encontra nada.
    $achadoErrado = Database::um(
        'SELECT user_id FROM password_resets WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
        [hash('sha256', $t2)]
    );
    afirmar($achadoErrado === null, 'token diferente nao encontra nada');

    // Pedir de novo substitui o anterior: um unico pedido ativo por conta.
    $t3 = Security::novoToken();
    Database::run(
        'REPLACE INTO password_resets (user_id, token_hash, expira_em)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 MINUTE))',
        [$uid, hash('sha256', $t3)]
    );
    afirmar((int) Database::valor('SELECT COUNT(*) FROM password_resets WHERE user_id = ?', [$uid]) === 1,
        'pedir de novo substitui o pedido anterior');
    afirmar(Database::um('SELECT user_id FROM password_resets WHERE token_hash = ?', [$hash]) === null,
        'o token anterior deixa de valer');

    // Expirado nao vale.
    Database::run(
        'REPLACE INTO password_resets (user_id, token_hash, expira_em)
         VALUES (?, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE))',
        [$uid, $hash]
    );
    afirmar(Database::um(
        'SELECT user_id FROM password_resets WHERE token_hash = ? AND expira_em > UTC_TIMESTAMP()',
        [$hash]
    ) === null, 'token expirado nao e aceito');

    // A senha nova precisa funcionar no mesmo verificador do login. Gravar
    // senha_hash sem zerar o salt deixaria a conta sem conseguir entrar: o
    // esquema antigo de salt e aposentado na troca.
    Database::run(
        "UPDATE users SET senha_hash = ?, salt = '' WHERE id = ?",
        [Auth::hashSenha('senha-nova-456'), $uid]
    );
    $u = Database::um('SELECT senha_hash, salt FROM users WHERE id = ?', [$uid]);
    afirmar(Auth::verificarSenha('senha-nova-456', (string) $u['senha_hash'], (string) $u['salt']),
        'a senha nova e aceita pelo verificador do login');
    afirmar(!Auth::verificarSenha('senha-antiga-123', (string) $u['senha_hash'], (string) $u['salt']),
        'a senha antiga deixa de valer');

    // Excluir a conta leva o pedido junto (ON DELETE CASCADE).
    Database::run(
        'REPLACE INTO password_resets (user_id, token_hash, expira_em)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 MINUTE))',
        [$uid, $hash]
    );
    Database::run('DELETE FROM users WHERE id = ?', [$uid]);
    afirmar(Database::um('SELECT user_id FROM password_resets WHERE user_id = ?', [$uid]) === null,
        'excluir a conta apaga o pedido pendente (cascade)');
    $uid = 0;
} finally {
    if ($uid > 0) {
        Database::run('DELETE FROM users WHERE id = ?', [$uid]);
    }
}

echo "\nTodos os testes de recuperacao de senha passaram.\n";
