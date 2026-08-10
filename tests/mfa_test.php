<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Mfa;

function afirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: {$mensagem}\n");
        exit(1);
    }
    echo "ok: {$mensagem}\n";
}

// Vetores oficiais do RFC 6238 Apêndice B (segredo ASCII "12345678901234567890",
// HOTP de 8 dígitos, passo de 30s a partir de T0=0).
$segredoRfc = '12345678901234567890';
afirmar(Mfa::codigoHotp($segredoRfc, 1, 8) === '94287082', 'RFC 6238 T=59 (contador=1)');
afirmar(Mfa::codigoHotp($segredoRfc, 37037036, 8) === '07081804', 'RFC 6238 T=1111111109');
afirmar(Mfa::codigoHotp($segredoRfc, 37037037, 8) === '14050471', 'RFC 6238 T=1111111111');

// Base32: round-trip com segredo aleatório.
for ($i = 0; $i < 20; $i++) {
    $bin = random_bytes(20);
    $codificado = Mfa::base32Codificar($bin);
    afirmar(Mfa::base32Decodificar($codificado) === $bin, "base32 round-trip #{$i}");
}

// Cifragem do segredo: round-trip com uma chave de teste.
putenv('MFA_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
$segredo = Mfa::gerarSegredo();
afirmar(strlen($segredo) === 32, 'segredo gerado tem 32 caracteres base32 (20 bytes)');
$cifrado = Mfa::cifrarSegredo($segredo);
afirmar(Mfa::decifrarSegredo($cifrado) === $segredo, 'cifragem round-trip');
afirmar(Mfa::decifrarSegredo('lixo-invalido') === null, 'decifrar valor inválido devolve null');

// Códigos de backup: quantidade, unicidade e alfabeto sem ambiguidade.
$codigos = Mfa::gerarCodigosBackup();
afirmar(count($codigos) === 10, 'gera 10 códigos de backup');
afirmar(count(array_unique($codigos)) === 10, 'códigos de backup são únicos');
foreach ($codigos as $c) {
    afirmar(preg_match('/^[A-HJ-NP-Z2-9]{10}$/', $c) === 1, "código de backup bem formado: {$c}");
}

// TOTP: um código gerado para o passo atual precisa validar contra o mesmo segredo.
$contadorAtual = intdiv(time(), 30);
$codigoAtual = Mfa::codigoHotp(Mfa::base32Decodificar($segredo), $contadorAtual, 6);
afirmar(Mfa::verificarCodigo($segredo, $codigoAtual) === true, 'código TOTP do passo atual é aceito');
afirmar(Mfa::verificarCodigo($segredo, 'abcdef') === false, 'código não numérico é rejeitado');
afirmar(Mfa::verificarCodigo(null, $codigoAtual) === false, 'segredo nulo é rejeitado');

echo "Todos os testes de Mfa passaram.\n";
