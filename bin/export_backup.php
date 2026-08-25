<?php
/**
 * Exporta os dados de um usuário para uma planilha reimportável via /api/import.
 *
 * Gera o mesmo arquivo que o botão "Meus dados de hoje" em Configuração →
 * Planilha: o modelo do sistema (src/Modelo.php), que a própria importação
 * lê de volta.
 *
 * Uso:
 *   php bin/export_backup.php <email-ou-usuario> [destino.xlsx]
 *
 * No Docker:
 *   docker compose exec app php bin/export_backup.php fulano@exemplo.com /tmp/backup.xlsx
 *   docker compose cp app:/tmp/backup.xlsx ./backup.xlsx
 *
 * Sem <destino>, gera um modelo em branco:
 *   php bin/export_backup.php --modelo /tmp/modelo.xlsx
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Database;
use MinhasContas\Exporter;
use MinhasContas\Modelo;

if (PHP_SAPI !== 'cli') {
    exit("Este script só roda pela linha de comando.\n");
}

$identificador = $argv[1] ?? '';
$destino = $argv[2] ?? '';

if ($identificador === '') {
    fwrite(STDERR, "Uso: php bin/export_backup.php <email-ou-usuario> <destino.xlsx>\n");
    fwrite(STDERR, "     php bin/export_backup.php --modelo <destino.xlsx>\n");
    exit(1);
}

try {
    if ($identificador === '--modelo') {
        $caminho = $destino !== '' ? $destino : Modelo::ARQUIVO_VAZIO;
        Exporter::modeloVazio()->salvar($caminho);
        echo "OK -> {$caminho} (modelo em branco)\n";
        exit(0);
    }
    if ($destino === '') {
        fwrite(STDERR, "Falta o destino: php bin/export_backup.php <email-ou-usuario> <destino.xlsx>\n");
        exit(1);
    }
    exportar($identificador, $destino);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

function exportar(string $identificador, string $destino): void
{
    $ident = mb_strtolower($identificador);
    $u = Database::um(
        'SELECT id FROM users WHERE LOWER(email) = ? OR LOWER(usuario) = ?',
        [$ident, $ident]
    );
    if ($u === null) {
        throw new RuntimeException("Usuário não encontrado: {$identificador}");
    }

    $dados = Exporter::dados((int) $u['id']);
    Exporter::planilha($dados)->salvar($destino);

    $pat = $dados['patrimonio'];
    printf(
        "OK -> %s | lançamentos=%d classificações=%d projetos=%d investimentos=%d bens=%d dívidas=%d"
        . " instituições=%d pessoas=%d\n",
        $destino,
        count($dados['transactions']),
        count($dados['categories']),
        count($dados['projects']),
        count($pat['investimentos']),
        count($pat['bens']),
        count($pat['dividas']),
        count($dados['institutions']),
        count($dados['people'])
    );
}
