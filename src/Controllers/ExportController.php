<?php
/**
 * Download das planilhas: o modelo em branco e os dados da conta.
 *
 * As duas saem no mesmo layout — o de src/Modelo.php —, que é o mesmo que
 * /api/import lê de volta.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Exporter;
use MinhasContas\Http;
use MinhasContas\Modelo;
use MinhasContas\Xlsx\Writer;

final class ExportController
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Modelo em branco, com instruções e exemplo. */
    public static function modelo(): void
    {
        Auth::exigirUsuario();
        self::enviar(Exporter::modeloVazio(), Modelo::ARQUIVO_VAZIO);
    }

    /** Os dados da conta no modelo — backup e reimportável. */
    public static function dados(): void
    {
        $uid = Auth::exigirUsuario();
        self::enviar(Exporter::planilha(Exporter::dados($uid)), Exporter::nomeArquivo());
    }

    /**
     * O .xlsx é montado por ZipArchive, que só escreve em arquivo — daí o
     * temporário. Ele fica em sys_get_temp_dir(), fora da árvore servida pela
     * web, e some no finally mesmo se o envio falhar no meio.
     */
    private static function enviar(Writer $planilha, string $nome): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'minhas-contas-');
        if ($tmp === false) {
            Http::erro(500, 'Não foi possível gerar a planilha.');
        }

        try {
            $planilha->salvar($tmp);
            Http::download($tmp, $nome, self::MIME);
        } finally {
            @unlink($tmp);
        }
    }
}
