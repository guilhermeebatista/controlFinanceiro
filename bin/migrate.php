<?php
/**
 * Prepara o banco para a aplicação. Idempotente — roda a cada boot do container.
 *
 *   1. aplica database.sql se o schema ainda não existir;
 *   2. garante a conta de demonstração 'planilha', alimentada por
 *      resources/seed.json;
 *   3. promove o primeiro administrador.
 *
 * Uso:  php bin/migrate.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Config;
use MinhasContas\Controllers\ImportController;
use MinhasContas\Database;
use MinhasContas\Users;

if (PHP_SAPI !== 'cli') {
    exit("Este script só roda pela linha de comando.\n");
}

const PLANILHA_USUARIO = 'planilha';

try {
    aplicarSchema();
    aplicarMigracoes();
    if (Config::seedContaDemo()) {
        $uid = garantirContaPlanilha();
        semearPlanilha($uid);
    }
    Users::garantirPrimeiroAdmin();
    echo "[migrate] banco pronto.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

function schemaExiste(): bool
{
    return Database::um(
        'SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?',
        ['users']
    ) !== null;
}

/**
 * Aplica o database.sql quando o schema ainda não existe.
 *
 * No Docker o próprio container do MySQL já executa o arquivo na primeira
 * subida; isto cobre a instalação manual e o caso de o volume ter sido criado
 * antes de o arquivo existir.
 */
function aplicarSchema(): void
{
    if (schemaExiste()) {
        return;
    }

    $arquivo = \MinhasContas\Config::raiz() . '/database.sql';
    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        throw new RuntimeException("database.sql não encontrado em {$arquivo}");
    }

    echo "[migrate] schema ausente; aplicando database.sql...\n";
    foreach (comandosDo($sql) as $comando) {
        Database::pdo()->exec($comando);
    }
}

/**
 * Aplica migrações incrementais de migrations/*.sql.
 *
 * Numa instalação nova o database.sql (aplicado pelo próprio entrypoint do
 * MySQL antes deste script rodar) já nasce com o schema mais recente — então
 * cada instrução da migração encontra a coluna/tabela já existente. Por isso
 * cada comando roda num try/catch que ignora "já existe" (1050 tabela, 1060
 * coluna, 1061 índice): não há como distinguir de antemão uma instalação nova
 * de um banco que já tem exatamente essa mudança, então a migração precisa
 * ser seguramente reexecutável nos dois casos.
 *
 * Sem Database::transacao() aqui: ALTER/CREATE TABLE fazem commit implícito
 * no MySQL, o que fecha a transação por baixo do PDO e quebra o commit/
 * rollback explícito no fim — DDL nunca foi atômico com o resto de qualquer
 * jeito.
 */
function aplicarMigracoes(): void
{
    Database::run(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            versao      VARCHAR(190) NOT NULL PRIMARY KEY,
            aplicada_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $arquivos = glob(\MinhasContas\Config::raiz() . '/migrations/*.sql') ?: [];
    sort($arquivos);

    $codigosJaExiste = [1050, 1060, 1061]; // tabela, coluna, índice duplicados.

    foreach ($arquivos as $arquivo) {
        $versao = basename($arquivo);
        if (Database::valor('SELECT 1 FROM schema_migrations WHERE versao = ?', [$versao]) !== null) {
            continue;
        }

        echo "[migrate] aplicando migração {$versao}...\n";
        $sql = file_get_contents($arquivo);
        if ($sql === false) {
            throw new RuntimeException("Não foi possível ler {$arquivo}");
        }
        foreach (comandosDo($sql) as $comando) {
            try {
                Database::pdo()->exec($comando);
            } catch (\PDOException $e) {
                if (!in_array((int) ($e->errorInfo[1] ?? 0), $codigosJaExiste, true)) {
                    throw $e;
                }
            }
        }
        Database::run('INSERT INTO schema_migrations (versao) VALUES (?)', [$versao]);
    }
}

/**
 * Quebra o arquivo em comandos.
 *
 * Os comentários saem primeiro porque alguns contêm ';' no texto e
 * atrapalhariam a divisão. CREATE DATABASE e USE são descartados: o banco já
 * existe e o usuário da aplicação não tem (nem deveria ter) privilégio para
 * criá-lo.
 *
 * @return list<string>
 */
function comandosDo(string $sql): array
{
    $linhas = preg_split('/\R/', $sql) ?: [];
    $uteis = array_filter($linhas, static fn(string $l): bool => !str_starts_with(ltrim($l), '--'));

    $comandos = [];
    foreach (explode(';', implode("\n", $uteis)) as $bruto) {
        $c = trim($bruto);
        if ($c === '' || preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $c) === 1) {
            continue;
        }
        $comandos[] = $c;
    }
    return $comandos;
}

/** A conta 'planilha' existe desde a primeira versão; mantida para não quebrar o acesso. */
function garantirContaPlanilha(): int
{
    $linha = Database::um('SELECT id FROM users WHERE usuario = ?', [PLANILHA_USUARIO]);
    if ($linha !== null) {
        return (int) $linha['id'];
    }

    // Senha inicial aleatória: uma senha fixa em código viraria porta aberta
    // em toda instalação. Ela aparece uma única vez no log do primeiro boot e
    // pode ser trocada pelo painel de admin.
    $senha = bin2hex(random_bytes(8));
    $uid = Users::criar(PLANILHA_USUARIO, $senha, nome: 'Planilha', categoriasPadrao: false);

    echo "[migrate] conta '" . PLANILHA_USUARIO . "' criada — senha inicial: {$senha}\n";
    echo "[migrate] troque essa senha no painel de administração.\n";

    return $uid;
}

function semearPlanilha(int $uid): void
{
    $tem = (int) Database::valor('SELECT COUNT(*) FROM transactions WHERE user_id = ?', [$uid]);
    if ($tem > 0) {
        return;
    }

    $arquivo = \MinhasContas\Config::raiz() . '/resources/seed.json';
    if (!is_file($arquivo)) {
        return;
    }
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return;
    }
    $seed = json_decode($conteudo, true);
    if (!is_array($seed)) {
        return;
    }

    // O seed tem o mesmo formato que Importer::parse() devolve, então a mesma
    // rotina de gravação atende os dois casos.
    $resumo = Database::transacao(
        static fn(): array => ImportController::gravar($uid, $seed, false)
    );
    echo "[migrate] seed aplicado: {$resumo['lancamentos']} lançamentos.\n";
}
