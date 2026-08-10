<?php
/**
 * Exporta os dados de um usuário para uma planilha reimportável via /api/import.
 *
 * Gera um .xlsx no layout do modelo "Controle Financ. Pessoal" — as mesmas
 * abas e colunas que src/Importer.php lê —, para servir de backup.
 *
 * Uso:
 *   php bin/export_backup.php <email-ou-usuario> <destino.xlsx>
 *
 * No Docker:
 *   docker compose exec app php bin/export_backup.php fulano@exemplo.com /tmp/backup.xlsx
 *   docker compose cp app:/tmp/backup.xlsx ./backup.xlsx
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use MinhasContas\Database;
use MinhasContas\Xlsx\Writer;

if (PHP_SAPI !== 'cli') {
    exit("Este script só roda pela linha de comando.\n");
}

$identificador = $argv[1] ?? '';
$destino = $argv[2] ?? '';

if ($identificador === '' || $destino === '') {
    fwrite(STDERR, "Uso: php bin/export_backup.php <email-ou-usuario> <destino.xlsx>\n");
    exit(1);
}

try {
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
    $uid = (int) $u['id'];

    $settings = [];
    foreach (Database::todos('SELECT chave, valor FROM settings WHERE user_id = ?', [$uid]) as $s) {
        $settings[(string) $s['chave']] = (float) $s['valor'];
    }

    $cats   = Database::todos('SELECT * FROM categories WHERE user_id = ? ORDER BY tipo, categoria, subcategoria', [$uid]);
    $txs    = Database::todos('SELECT * FROM transactions WHERE user_id = ? ORDER BY dt_venc, id', [$uid]);
    $projs  = Database::todos('SELECT * FROM projects WHERE user_id = ? ORDER BY ano', [$uid]);
    $invs   = Database::todos('SELECT * FROM investments WHERE user_id = ?', [$uid]);
    $assets = Database::todos('SELECT * FROM assets WHERE user_id = ?', [$uid]);
    $debts  = Database::todos('SELECT * FROM debts WHERE user_id = ?', [$uid]);
    $insts  = Database::todos('SELECT * FROM institutions WHERE user_id = ? ORDER BY nome', [$uid]);
    $people = Database::todos('SELECT * FROM people WHERE user_id = ? ORDER BY nome', [$uid]);

    $w = new Writer();

    // ---------- CONFIGURACAO ----------
    // Importer: parâmetros em F1:G3; classificações a partir da linha 5
    // (col B=classificacao, C=grupo, D=tipo, E=categoria, F=subcategoria, G=meta).
    $w->aba('CONFIGURACAO');
    $w->ref('F1', 'Receita Mensal')->ref('G1', $settings['receita_mensal'] ?? 0.0);
    $w->ref('F2', 'Custo de Vida Mensal')->ref('G2', $settings['custo_vida_mensal'] ?? 0.0);
    $w->cabecalho(4, 2, ['Classificacao', 'Grupo', 'Tipo', 'Categoria', 'Subcategoria', 'Meta Mês']);
    $linha = 5;
    foreach ($cats as $c) {
        $w->set($linha, 2, $c['classificacao'])
          ->set($linha, 3, $c['grupo'])
          ->set($linha, 4, $c['tipo'])
          ->set($linha, 5, $c['categoria'])
          ->set($linha, 6, $c['subcategoria'])
          ->set($linha, 7, (float) $c['meta_mes']);
        $linha++;
    }

    // ---------- LANCAMENTO ----------
    // Importer: dados a partir da linha 4, colunas B..M.
    $w->aba('LANCAMENTO');
    $w->cabecalho(3, 2, [
        'Dt Compra', 'Dt Venc', 'Classificacao', 'Valor', 'Instituicao',
        'Pessoa', 'Status', 'Obs', 'Grupo', 'Tipo', 'Categoria', 'Subcategoria',
    ]);
    $linha = 4;
    foreach ($txs as $t) {
        $w->set($linha, 2, $t['dt_compra'], Writer::DATA)
          ->set($linha, 3, $t['dt_venc'], Writer::DATA)
          ->set($linha, 4, $t['classificacao'])
          ->set($linha, 5, round((float) $t['valor'], 2))
          ->set($linha, 6, $t['instituicao'])
          ->set($linha, 7, $t['pessoa'])
          ->set($linha, 8, $t['status'])
          ->set($linha, 9, $t['obs'])
          ->set($linha, 10, $t['grupo'])
          ->set($linha, 11, $t['tipo'])
          ->set($linha, 12, $t['categoria'])
          ->set($linha, 13, $t['subcategoria']);
        $linha++;
    }

    // ---------- PROJETOS ----------
    // Importer: fator_reserva em I1; dados a partir da linha 2.
    $w->aba('PROJETOS');
    $w->ref('I1', $settings['fator_reserva'] ?? 6.0);
    $w->cabecalho(1, 2, ['Descricao', 'Valor', 'Ano', 'Prazo']);
    $linha = 2;
    foreach ($projs as $p) {
        $w->set($linha, 2, $p['descricao'])
          ->set($linha, 3, (float) $p['valor'])
          ->set($linha, 4, $p['ano'] !== null ? (int) $p['ano'] : null)
          ->set($linha, 5, $p['prazo']);
        $linha++;
    }

    // ---------- PATRIMONIO ----------
    // Importer: dados a partir da linha 3; investimentos B:F, bens H:J, dívidas M:P.
    $w->aba('PATRIMONIO');
    $w->cabecalho(2, 2, ['Instituicao', 'Fixa/Var', 'Prazo/Projeto', 'Ativo', 'Valor']);
    $w->cabecalho(2, 8, ['Bem', 'Valor', 'Saldo Devedor']);
    $w->cabecalho(2, 13, ['Dívida', 'Nº Parcelas', 'Valor Parcela', 'Saldo Devedor']);

    $linha = 3;
    foreach ($invs as $i) {
        $w->set($linha, 2, $i['instituicao'])
          ->set($linha, 3, $i['fixa_var'])
          ->set($linha, 4, $i['prazo_projeto'])
          ->set($linha, 5, $i['ativo'])
          ->set($linha, 6, (float) $i['valor']);
        $linha++;
    }
    $linha = 3;
    foreach ($assets as $a) {
        $w->set($linha, 8, $a['descricao'])
          ->set($linha, 9, (float) $a['valor'])
          ->set($linha, 10, (float) $a['saldo_devedor']);
        $linha++;
    }
    $linha = 3;
    foreach ($debts as $d) {
        $w->set($linha, 13, $d['descricao'])
          ->set($linha, 14, (int) $d['num_parcelas'])
          ->set($linha, 15, (float) $d['valor_parcela'])
          ->set($linha, 16, (float) $d['saldo_devedor']);
        $linha++;
    }

    // ---------- CADASTROS (referência; não é reimportada diretamente) ----------
    $w->aba('CADASTROS');
    $w->ref('A1', 'Instituições (nome / tipo / saldo inicial / descrição)', Writer::NEGRITO);
    $linha = 2;
    foreach ($insts as $i) {
        $w->set($linha, 1, $i['nome'])
          ->set($linha, 2, $i['tipo'])
          ->set($linha, 3, (float) $i['saldo_inicial'])
          ->set($linha, 4, $i['descricao']);
        $linha++;
    }
    $linha += 2;
    $w->set($linha, 1, 'Pessoas', Writer::NEGRITO);
    $linha++;
    foreach ($people as $p) {
        $w->set($linha, 1, $p['nome']);
        $linha++;
    }

    $w->salvar($destino);

    printf(
        "OK -> %s | lançamentos=%d categorias=%d projetos=%d instituições=%d pessoas=%d\n",
        $destino,
        count($txs),
        count($cats),
        count($projs),
        count($insts),
        count($people)
    );
}
