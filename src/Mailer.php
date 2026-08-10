<?php
/**
 * Cliente SMTP mínimo: conecta, autentica e envia uma mensagem em texto puro.
 * Sem biblioteca externa — mesmo espírito do resto do projeto.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Mailer
{
    private const TIMEOUT_SEGUNDOS = 10;

    public static function enviar(string $paraEmail, string $assunto, string $corpo): void
    {
        if (preg_match('/[\r\n]/', $paraEmail) === 1 || preg_match('/[\r\n]/', $assunto) === 1) {
            throw new \InvalidArgumentException('Destinatário ou assunto contém quebra de linha.');
        }
        if (Config::smtpHost() === '') {
            throw new \RuntimeException('SMTP não configurado (defina SMTP_HOST no .env).');
        }

        $host = Config::smtpHost();
        $porta = Config::smtpPort();
        $usarTlsImediato = $porta === 465;

        $esquema = $usarTlsImediato ? 'ssl://' : 'tcp://';
        $contexto = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $conexao = @stream_socket_client(
            "{$esquema}{$host}:{$porta}",
            $codigoErro,
            $mensagemErro,
            self::TIMEOUT_SEGUNDOS,
            STREAM_CLIENT_CONNECT,
            $contexto
        );
        if ($conexao === false) {
            throw new \RuntimeException("Falha ao conectar no SMTP {$host}:{$porta}: {$mensagemErro}");
        }
        stream_set_timeout($conexao, self::TIMEOUT_SEGUNDOS);

        try {
            self::lerResposta($conexao, 220);
            self::comando($conexao, 'EHLO minhascontas.local', 250);

            if (!$usarTlsImediato) {
                self::comando($conexao, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Falha ao negociar TLS com o servidor SMTP.');
                }
                self::comando($conexao, 'EHLO minhascontas.local', 250);
            }

            self::comando($conexao, 'AUTH LOGIN', 334);
            self::comando($conexao, base64_encode(Config::smtpUser()), 334);
            self::comando($conexao, base64_encode(Config::smtpPass()), 235);

            $de = Config::smtpFromEmail();
            self::comando($conexao, "MAIL FROM:<{$de}>", 250);
            self::comando($conexao, "RCPT TO:<{$paraEmail}>", 250);
            self::comando($conexao, 'DATA', 354);

            $cabecalhos = implode("\r\n", [
                'From: ' . self::codificarCabecalho(Config::smtpFromNome()) . " <{$de}>",
                "To: <{$paraEmail}>",
                'Subject: ' . self::codificarCabecalho($assunto),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]);
            // Dot-stuffing: uma linha começando com "." sozinha encerraria a
            // mensagem antes da hora (RFC 5321 §4.5.2).
            $corpoEscapado = preg_replace('/^\./m', '..', $corpo) ?? $corpo;
            self::comando($conexao, "{$cabecalhos}\r\n\r\n{$corpoEscapado}\r\n.", 250);

            self::comando($conexao, 'QUIT', 221);
        } finally {
            fclose($conexao);
        }
    }

    /** @param resource $conexao */
    private static function comando($conexao, string $linha, int $codigoEsperado): void
    {
        fwrite($conexao, $linha . "\r\n");
        self::lerResposta($conexao, $codigoEsperado);
    }

    /** @param resource $conexao */
    private static function lerResposta($conexao, int $codigoEsperado): void
    {
        $ultima = '';
        do {
            $linha = fgets($conexao, 512);
            if ($linha === false) {
                throw new \RuntimeException('Conexão SMTP encerrada inesperadamente.');
            }
            $ultima = $linha;
            // Resposta multi-linha: "250-..." continua, "250 ..." é a última.
        } while (isset($linha[3]) && $linha[3] === '-');

        $codigo = (int) substr($ultima, 0, 3);
        if ($codigo !== $codigoEsperado) {
            throw new \RuntimeException("Resposta SMTP inesperada (esperava {$codigoEsperado}): {$ultima}");
        }
    }

    private static function codificarCabecalho(string $valor): string
    {
        return '=?UTF-8?B?' . base64_encode($valor) . '?=';
    }
}
