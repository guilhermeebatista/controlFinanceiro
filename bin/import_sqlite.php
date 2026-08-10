<?php
/**
 * Migra os dados da versão SQLite (Python/FastAPI) para o MySQL.
 *
 * Copia contas, parâmetros, classificações, lançamentos, projetos, patrimônio
 * e cadastros, preservando os IDs relativos por usuário.
 *
 * As senhas continuam valendo: os hashes PBKDF2 antigos são copiados junto com
 * o salt, e Auth::verificarSenha reconhece o formato. No primeiro login de cada
 * conta o hash é convertido para bcrypt sem que a pessoa perceba.
 *
 * Uso:
 *   php bin/import_sqlite.php data/financas.db
 *   php bin/import_sqlite.php data/financas.db --dry-run
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Database;

if (PHP_SAPI !== 'cli') {
    exit("Este script só roda pela linha de comando.\n");
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "A extensão pdo_sqlite não está disponível neste PHP.\n");
    exit(1);
}

$origem = $argv[1] ?? '';
$simulacao = in_array('--dry-run', $argv, true);

if ($origem === '' || !is_file($origem)) {
    fwrite(STDERR, "Uso: php bin/import_sqlite.php <caminho/financas.db> [--dry-run]\n");
    exit(1);
}

/** Tabelas de dados do usuário: coluna no SQLite => coluna no MySQL. */
const TABELAS = [
    'settings'      => ['tabela' => 'settings',      'colunas' => ['key' => 'chave', 'value' => 'valor']],
    'categories'    => ['tabela' => 'categories',    'colunas' => ['classificacao', 'grupo', 'tipo', 'categoria', 'subcategoria', 'meta_mes']],
    'transactions'  => ['tabela' => 'transactions',  'colunas' => ['dt_compra', 'dt_venc', 'classificacao', 'valor', 'instituicao', 'pessoa', 'status', 'obs', 'grupo', 'tipo', 'categoria', 'subcategoria']],
    'projects'      => ['tabela' => 'projects',      'colunas' => ['descricao', 'valor', 'ano', 'prazo']],
    'investments'   => ['tabela' => 'investments',   'colunas' => ['instituicao', 'fixa_var', 'prazo_projeto', 'ativo', 'valor']],
    'assets'        => ['tabela' => 'assets',        'colunas' => ['descricao', 'valor', 'saldo_devedor']],
    'debts'         => ['tabela' => 'debts',         'colunas' => ['descricao', 'num_parcelas', 'valor_parcela', 'saldo_devedor']],
    'institutions'  => ['tabela' => 'institutions',  'colunas' => ['nome', 'tipo', 'saldo_inicial', 'descricao']],
    'people'        => ['tabela' => 'people',        'colunas' => ['nome']],
    'ofx_imports'   => ['tabela' => 'ofx_imports',   'colunas' => ['data_extracao', 'banco', 'periodo', 'nome_arquivo']],
    'card_payments' => ['tabela' => 'card_payments', 'colunas' => ['data_pagto', 'cartao', 'periodo', 'valor']],
];

/** Colunas DATE: o SQLite guardava texto livre, o MySQL recusa lixo. */
const COLUNAS_DATA = ['dt_compra', 'dt_venc', 'data_extracao', 'data_pagto'];

try {
    $sqlite = new PDO('sqlite:' . $origem, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $usuarios = $sqlite->query('SELECT * FROM users ORDER BY id')->fetchAll();
    echo 'Encontradas ' . count($usuarios) . " conta(s) na origem.\n\n";

    foreach ($usuarios as $u) {
        migrarUsuario($sqlite, $u, $simulacao);
    }

    if ($simulacao) {
        echo "\n(--dry-run: nada foi gravado)\n";
    } else {
        \MinhasContas\Users::garantirPrimeiroAdmin();
        echo "\nMigração concluída.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

/** @param array<string, mixed> $u */
function migrarUsuario(PDO $sqlite, array $u, bool $simulacao): void
{
    $usuario = (string) $u['usuario'];
    $idAntigo = (int) $u['id'];

    $existente = Database::um('SELECT id FROM users WHERE usuario = ?', [$usuario]);
    if ($existente !== null) {
        echo "· {$usuario}: já existe no MySQL (id {$existente['id']}) — pulando.\n";
        return;
    }

    if ($simulacao) {
        echo "· {$usuario}: seria criada.\n";
        contarOrigem($sqlite, $idAntigo);
        return;
    }

    Database::transacao(static function () use ($sqlite, $u, $usuario, $idAntigo): void {
        Database::run(
            'INSERT INTO users (usuario, nome, email, senha_hash, salt, is_admin, ultimo_login, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, UTC_TIMESTAMP()))',
            [
                $usuario,
                $u['nome'] ?? null,
                $u['email'] ?? null,
                (string) $u['senha_hash'],
                (string) ($u['salt'] ?? ''),
                (int) ($u['is_admin'] ?? 0),
                $u['ultimo_login'] ?? null,
                $u['criado_em'] ?? null,
            ]
        );
        $novoId = Database::ultimoId();

        $total = 0;
        foreach (TABELAS as $tabelaOrigem => $spec) {
            $total += copiarTabela($sqlite, $tabelaOrigem, $spec, $idAntigo, $novoId);
        }
        echo "· {$usuario}: criado como id {$novoId} ({$total} registros copiados).\n";
    });
}

/**
 * @param array{tabela: string, colunas: array<int|string, string>} $spec
 */
function copiarTabela(PDO $sqlite, string $tabelaOrigem, array $spec, int $idAntigo, int $novoId): int
{
    // Nomes de tabela e coluna vêm da constante TABELAS, escrita neste arquivo
    // — nada aqui é derivado de entrada externa.
    $mapa = [];
    foreach ($spec['colunas'] as $de => $para) {
        $mapa[is_int($de) ? $para : $de] = $para;
    }

    $colunasOrigem = array_keys($mapa);
    $selecao = implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', $colunasOrigem));

    try {
        $stmt = $sqlite->prepare("SELECT {$selecao} FROM {$tabelaOrigem} WHERE user_id = ?");
        $stmt->execute([$idAntigo]);
    } catch (PDOException) {
        return 0;   // tabela ausente num banco mais antigo
    }

    $colunasDestino = array_values($mapa);
    $sql = sprintf(
        'INSERT INTO %s (user_id, %s) VALUES (%s)',
        $spec['tabela'],
        implode(', ', $colunasDestino),
        Database::placeholders(count($colunasDestino) + 1)
    );
    // settings tem PK (user_id, chave); reexecutar não pode explodir.
    if ($spec['tabela'] === 'settings') {
        $sql .= ' ON DUPLICATE KEY UPDATE valor = VALUES(valor)';
    }

    $n = 0;
    while (($linha = $stmt->fetch()) !== false) {
        $valores = [$novoId];
        foreach ($colunasOrigem as $c) {
            $valores[] = in_array($c, COLUNAS_DATA, true)
                ? normalizarData($linha[$c] ?? null)
                : ($linha[$c] ?? null);
        }
        try {
            Database::run($sql, $valores);
            $n++;
        } catch (PDOException $e) {
            // Uma linha inconsistente (data impossível, nome duplicado em
            // people) não pode abortar a migração inteira.
            fwrite(STDERR, "  ! {$tabelaOrigem}: linha ignorada — {$e->getMessage()}\n");
        }
    }
    return $n;
}

function normalizarData(mixed $v): ?string
{
    if (!is_string($v) || trim($v) === '') {
        return null;
    }
    return \MinhasContas\Http::normalizarData(substr(trim($v), 0, 10));
}

function contarOrigem(PDO $sqlite, int $idAntigo): void
{
    foreach (TABELAS as $tabela => $_) {
        try {
            $stmt = $sqlite->prepare("SELECT COUNT(*) FROM {$tabela} WHERE user_id = ?");
            $stmt->execute([$idAntigo]);
            $n = (int) $stmt->fetchColumn();
        } catch (PDOException) {
            continue;
        }
        if ($n > 0) {
            echo "    {$tabela}: {$n}\n";
        }
    }
}
