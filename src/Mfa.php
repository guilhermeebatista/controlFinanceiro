<?php
/**
 * TOTP (RFC 6238) para MFA: geração/verificação de código, segredo cifrado em
 * repouso e códigos de backup de uso único.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Mfa
{
    private const EMISSOR         = 'MinhasContas';
    private const PASSO_SEGUNDOS  = 30;
    private const DIGITOS         = 6;
    private const JANELA_PASSOS   = 1; // tolera ±1 passo (±30s) de relógio dessincronizado.
    private const BACKUP_QTD      = 10;
    private const BACKUP_TAM      = 10;
    private const BACKUP_ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O/1/I.
    private const BASE32_ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // --------------------------------------------------------------- segredo

    public static function gerarSegredo(): string
    {
        return self::base32Codificar(random_bytes(20));
    }

    public static function uriProvisionamento(string $segredoBase32, string $rotulo): string
    {
        $params = http_build_query([
            'secret'    => $segredoBase32,
            'issuer'    => self::EMISSOR,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITOS,
            'period'    => self::PASSO_SEGUNDOS,
        ]);
        $caminho = rawurlencode(self::EMISSOR . ':' . $rotulo);
        return "otpauth://totp/{$caminho}?{$params}";
    }

    // -------------------------------------------------------------- cifragem

    public static function cifrarSegredo(string $segredoBase32): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = sodium_crypto_secretbox($segredoBase32, $nonce, self::chaveCifragem());
        return base64_encode($nonce . $cifrado);
    }

    public static function decifrarSegredo(string $armazenado): ?string
    {
        $bin = base64_decode($armazenado, true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce   = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $claro = sodium_crypto_secretbox_open($cifrado, $nonce, self::chaveCifragem());
        return $claro === false ? null : $claro;
    }

    private static function chaveCifragem(): string
    {
        $chave = base64_decode(Config::mfaEncryptionKey(), true);
        if ($chave === false || strlen($chave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'MFA_ENCRYPTION_KEY ausente ou com tamanho inválido (esperado 32 bytes em base64).'
            );
        }
        return $chave;
    }

    // ------------------------------------------------------------------ TOTP

    public static function verificarCodigo(?string $segredoBase32, string $codigo): bool
    {
        $codigo = trim($codigo);
        if ($segredoBase32 === null || preg_match('/^\d{' . self::DIGITOS . '}$/', $codigo) !== 1) {
            return false;
        }
        $chaveBin = self::base32Decodificar($segredoBase32);
        if ($chaveBin === null) {
            return false;
        }
        $contadorAtual = intdiv(time(), self::PASSO_SEGUNDOS);
        for ($delta = -self::JANELA_PASSOS; $delta <= self::JANELA_PASSOS; $delta++) {
            $esperado = self::codigoHotp($chaveBin, $contadorAtual + $delta, self::DIGITOS);
            if (hash_equals($esperado, $codigo)) {
                return true;
            }
        }
        return false;
    }

    /** HOTP (RFC 4226) — separado de verificarCodigo() para testar contra os vetores oficiais. */
    public static function codigoHotp(string $chaveBin, int $contador, int $digitos): string
    {
        $bloco = pack('J', $contador); // 8 bytes big-endian.
        $hmac  = hash_hmac('sha1', $bloco, $chaveBin, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binario = ((ord($hmac[$offset]) & 0x7F) << 24)
                 | (ord($hmac[$offset + 1]) << 16)
                 | (ord($hmac[$offset + 2]) << 8)
                 | ord($hmac[$offset + 3]);
        $codigo = $binario % (10 ** $digitos);
        return str_pad((string) $codigo, $digitos, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------- backup codes

    /** @return list<string> */
    public static function gerarCodigosBackup(): array
    {
        $codigos = [];
        $alfabetoTam = strlen(self::BACKUP_ALFABETO);
        for ($i = 0; $i < self::BACKUP_QTD; $i++) {
            $codigo = '';
            for ($j = 0; $j < self::BACKUP_TAM; $j++) {
                $codigo .= self::BACKUP_ALFABETO[random_int(0, $alfabetoTam - 1)];
            }
            $codigos[] = $codigo;
        }
        return $codigos;
    }

    // -------------------------------------------------------------- base32

    public static function base32Codificar(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $grupo = str_pad($grupo, 5, '0', STR_PAD_RIGHT);
            $saida .= self::BASE32_ALFABETO[bindec($grupo)];
        }
        return $saida;
    }

    public static function base32Decodificar(string $valor): ?string
    {
        $valor = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $valor) ?? '');
        if ($valor === '') {
            return null;
        }
        $bits = '';
        foreach (str_split($valor) as $c) {
            $indice = strpos(self::BASE32_ALFABETO, $c);
            if ($indice === false) {
                return null;
            }
            $bits .= str_pad(decbin($indice), 5, '0', STR_PAD_LEFT);
        }
        $bin = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                break; // sobra de padding, não é um byte completo.
            }
            $bin .= chr(bindec($byte));
        }
        return $bin;
    }
}
