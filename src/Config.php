<?php
/**
 * Configuração da aplicação — lida exclusivamente de variáveis de ambiente.
 *
 * Nenhuma credencial fica em arquivo versionado: em desenvolvimento vêm do
 * docker-compose.yml, em produção do gerenciador de segredos do host.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Config
{
    /** Vida do cookie de sessão. */
    public const SESSAO_DIAS = 30;

    /** Tamanho máximo aceito no upload da planilha (bytes). */
    public const UPLOAD_MAX_BYTES = 12 * 1024 * 1024;

    /** Teto de descompressão do .xlsx — trava contra "zip bomb". */
    public const XLSX_MAX_DESCOMPRIMIDO = 120 * 1024 * 1024;

    /** Tentativas de login erradas antes do bloqueio temporário. */
    public const LOGIN_MAX_TENTATIVAS = 8;

    /** Duração do bloqueio após estourar o limite (segundos). */
    public const LOGIN_BLOQUEIO_SEGUNDOS = 900;

    /** Custo do bcrypt. Sobe com o hardware; 12 ≈ 250ms num servidor atual. */
    public const BCRYPT_COST = 12;

    /**
     * Conta promovida a administrador na primeira inicialização, contanto que
     * ainda não exista nenhum admin. A condição importa: sem ela um admin
     * rebaixado pelo painel voltaria a ser admin no próximo restart.
     */
    public static function adminEmail(): string
    {
        return self::env('ADMIN_EMAIL', 'guilherme.bsb2014mix@gmail.com');
    }

    public static function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::env('DB_HOST', 'mysql'),
            self::env('DB_PORT', '3306'),
            self::env('DB_NAME', 'minhas_contas'),
        );
    }

    public static function dbUser(): string
    {
        return self::env('DB_USER', 'contas');
    }

    public static function dbPass(): string
    {
        return self::env('DB_PASS', '');
    }

    /** Em produção os erros viram 500 genérico; em dev aparecem na resposta. */
    public static function debug(): bool
    {
        return self::env('APP_ENV', 'production') === 'development';
    }

    /** Raiz do projeto (um nível acima de src/). */
    public static function raiz(): string
    {
        return \dirname(__DIR__);
    }

    public static function env(string $nome, string $padrao = ''): string
    {
        $v = getenv($nome);
        if ($v === false || $v === '') {
            return $padrao;
        }
        return $v;
    }
}
