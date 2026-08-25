<?php
/**
 * Importação da planilha "Controle Financ. Pessoal" para a conta logada.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Categories;
use MinhasContas\Config;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Importer;

final class ImportController
{
    /** Assinatura de um ZIP — todo .xlsx/.xlsm começa com ela. */
    private const MAGIC_ZIP = "PK\x03\x04";

    public static function importar(): void
    {
        $uid = Auth::exigirUsuario();
        $caminho = self::receberArquivo();
        $substituir = filter_var($_POST['replace'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        try {
            $dados = Importer::parse($caminho);
            $resumo = Database::transacao(
                static fn(): array => self::gravar($uid, $dados, $substituir)
            );
        } finally {
            // O arquivo enviado some ao fim do request, com erro ou sem.
            @unlink($caminho);
        }

        Http::json($resumo);
    }

    /**
     * Valida o upload e devolve o caminho de um arquivo temporário fora da
     * árvore pública.
     *
     * O nome original do arquivo é ignorado por completo: nada do que o
     * usuário digitou entra em caminho de arquivo, então não há espaço para
     * path traversal nem para gravar um .php em lugar servível.
     */
    private static function receberArquivo(): string
    {
        $arquivo = $_FILES['file'] ?? null;
        if (!is_array($arquivo) || !isset($arquivo['tmp_name'], $arquivo['error'])) {
            Http::erro(400, 'Nenhum arquivo enviado.');
        }

        if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE) {
            Http::erro(413, 'Arquivo grande demais.');
        }
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            Http::erro(400, 'Falha no envio do arquivo.');
        }

        $tmp = (string) $arquivo['tmp_name'];
        // Garante que o caminho veio mesmo do processamento de upload do PHP,
        // e não de um valor forjado apontando para outro arquivo do servidor.
        if (!is_uploaded_file($tmp)) {
            Http::erro(400, 'Envio inválido.');
        }

        $tamanho = (int) ($arquivo['size'] ?? 0);
        if ($tamanho <= 0) {
            Http::erro(400, 'Arquivo vazio.');
        }
        if ($tamanho > Config::UPLOAD_MAX_BYTES) {
            Http::erro(413, 'Arquivo grande demais (máximo '
                . (int) (Config::UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB).');
        }

        // O tipo declarado pelo navegador não vale nada; o que vale é o
        // conteúdo. Sem ZIP na frente não é uma planilha do Office.
        $inicio = (string) file_get_contents($tmp, false, null, 0, 4);
        if ($inicio !== self::MAGIC_ZIP) {
            Http::erro(400, 'Não foi possível ler o arquivo. Envie um .xlsx ou .xlsm válido.');
        }

        return $tmp;
    }

    /**
     * Grava o conteúdo lido da planilha na conta.
     *
     * Roda inteiro dentro de uma transação: uma planilha que falhe no meio não
     * pode deixar metade dos lançamentos importados — ainda mais quando
     * `replace` já apagou os antigos.
     *
     * @param array<string, mixed> $dados
     * @return array<string, int>
     */
    public static function gravar(int $uid, array $dados, bool $substituir): array
    {
        if ($substituir) {
            // Tudo o que a planilha traz e que não tem chave para casar com o
            // que já existe. Sem isto, reimportar o próprio backup duplicaria
            // projetos e patrimônio a cada tentativa. Classificações,
            // instituições e pessoas ficam: são cadastros com nome único, que
            // a importação atualiza em vez de repetir.
            Database::run('DELETE FROM transactions WHERE user_id = ?', [$uid]);
            Database::run('DELETE FROM projects     WHERE user_id = ?', [$uid]);
            Database::run('DELETE FROM investments  WHERE user_id = ?', [$uid]);
            Database::run('DELETE FROM assets       WHERE user_id = ?', [$uid]);
            Database::run('DELETE FROM debts        WHERE user_id = ?', [$uid]);
        }

        /** @var array<string, float> $settings */
        $settings = $dados['settings'] ?? [];
        foreach (['receita_mensal', 'custo_vida_mensal', 'fator_reserva'] as $chave) {
            $v = (float) ($settings[$chave] ?? 0);
            if ($v != 0.0) {
                Database::run(
                    'INSERT INTO settings (user_id, chave, valor) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                    [$uid, $chave, $v]
                );
            }
        }

        // Índice das classificações da planilha, para completar os lançamentos
        // cuja linha não repete grupo/tipo/categoria.
        $mapa = [];
        $novasCategorias = 0;
        /** @var list<array<string, mixed>> $categorias */
        $categorias = $dados['categories'] ?? [];
        foreach ($categorias as $c) {
            $mapa[(string) $c['classificacao']] = $c;
            $novasCategorias += Categories::inserirSeAusente($uid, [
                'classificacao' => (string) $c['classificacao'],
                'grupo'         => (string) $c['grupo'],
                'tipo'          => (string) $c['tipo'],
                'categoria'     => (string) $c['categoria'],
                'subcategoria'  => $c['subcategoria'] !== null ? (string) $c['subcategoria'] : null,
                'meta_mes'      => (float) ($c['meta_mes'] ?? 0),
            ]);
        }

        $pessoas = [];
        $instituicoes = [];
        $lancamentos = 0;

        /** @var list<array<string, mixed>> $transacoes */
        $transacoes = $dados['transactions'] ?? [];
        foreach ($transacoes as $t) {
            // As chaves opcionais podem faltar: além da planilha, este método
            // também recebe o seed.json da conta de demonstração.
            $t += [
                'dt_compra' => null, 'dt_venc' => null, 'instituicao' => null,
                'pessoa' => null, 'status' => 'Previsto', 'obs' => null,
                'grupo' => null, 'tipo' => null, 'categoria' => null, 'subcategoria' => null,
            ];
            $t['dt_venc'] ??= $t['dt_compra'];
            if ($t['dt_venc'] === null) {
                continue;
            }
            if (!in_array($t['status'], ['Previsto', 'Realizado'], true)) {
                $t['status'] = 'Previsto';
            }

            $classificacao = (string) $t['classificacao'];
            $ref = $mapa[$classificacao] ?? null;

            $grupo     = Categories::normalizarGrupo((string) ($t['grupo'] ?? $ref['grupo'] ?? 'OPERACIONAL'));
            $tipo      = Categories::normalizarTipo((string) ($t['tipo'] ?? $ref['tipo'] ?? 'DESPESA'));
            $categoria = (string) ($t['categoria'] ?? $ref['categoria'] ?? $classificacao);
            $sub       = $t['subcategoria'] ?? $ref['subcategoria'] ?? null;
            $sub       = $sub !== null ? (string) $sub : null;

            // Todo lançamento precisa de uma classificação cadastrada, senão
            // ele aparece na lista mas não abre para edição.
            Categories::inserirSeAusente($uid, [
                'classificacao' => $classificacao,
                'grupo'         => $grupo,
                'tipo'          => $tipo,
                'categoria'     => $categoria,
                'subcategoria'  => $sub,
                'meta_mes'      => 0.0,
            ]);

            Database::run(
                'INSERT INTO transactions
                   (user_id, dt_compra, dt_venc, classificacao, valor, instituicao, pessoa,
                    status, obs, grupo, tipo, categoria, subcategoria)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $uid, $t['dt_compra'], $t['dt_venc'], $classificacao, (float) $t['valor'],
                    $t['instituicao'], $t['pessoa'], $t['status'], $t['obs'],
                    $grupo, $tipo, mb_substr($categoria, 0, 120), $sub,
                ]
            );
            $lancamentos++;

            if ($t['pessoa'] !== null) {
                $pessoas[(string) $t['pessoa']] = true;
            }
            if ($t['instituicao'] !== null) {
                $instituicoes[(string) $t['instituicao']] = true;
            }
        }

        foreach (array_keys($pessoas) as $p) {
            Database::run('INSERT IGNORE INTO people (user_id, nome) VALUES (?, ?)', [$uid, $p]);
        }
        foreach (array_keys($instituicoes) as $i) {
            $ehCartao = in_array(mb_strtolower($i), ['credito', 'crédito'], true);
            Database::run(
                'INSERT IGNORE INTO institutions (user_id, nome, tipo) VALUES (?, ?, ?)',
                [$uid, $i, $ehCartao ? 'Cartão de Crédito' : 'Conta']
            );
        }

        // As abas de cadastro do modelo novo vêm depois das derivadas dos
        // lançamentos: o que a pessoa escreveu explicitamente prevalece sobre
        // o "Conta" que o laço acima chuta.
        /** @var list<array<string, mixed>> $listaPessoas */
        $listaPessoas = $dados['people'] ?? [];
        foreach ($listaPessoas as $p) {
            $nome = (string) ($p['nome'] ?? '');
            if ($nome === '') {
                continue;
            }
            Database::run('INSERT IGNORE INTO people (user_id, nome) VALUES (?, ?)', [$uid, $nome]);
            $pessoas[$nome] = true;
        }

        /** @var list<array<string, mixed>> $listaInstituicoes */
        $listaInstituicoes = $dados['institutions'] ?? [];
        foreach ($listaInstituicoes as $i) {
            $nome = (string) ($i['nome'] ?? '');
            if ($nome === '') {
                continue;
            }
            $i += ['tipo' => null, 'saldo_inicial' => 0.0, 'descricao' => null];
            // COALESCE e não VALUES() direto: coluna em branco na planilha não
            // pode apagar o que já estava cadastrado na conta.
            Database::run(
                'INSERT INTO institutions (user_id, nome, tipo, saldo_inicial, descricao)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   tipo          = COALESCE(VALUES(tipo), tipo),
                   saldo_inicial = VALUES(saldo_inicial),
                   descricao     = COALESCE(VALUES(descricao), descricao)',
                [$uid, $nome, $i['tipo'], (float) $i['saldo_inicial'], $i['descricao']]
            );
            $instituicoes[$nome] = true;
        }

        /** @var list<array<string, mixed>> $projetos */
        $projetos = $dados['projects'] ?? [];
        foreach ($projetos as $p) {
            $p += ['valor' => 0, 'ano' => null, 'prazo' => null];
            Database::run(
                'INSERT INTO projects (user_id, descricao, valor, ano, prazo) VALUES (?, ?, ?, ?, ?)',
                [$uid, $p['descricao'], (float) $p['valor'], $p['ano'], $p['prazo']]
            );
        }

        /** @var array<string, list<array<string, mixed>>> $pat */
        $pat = $dados['patrimonio'] ?? ['investimentos' => [], 'bens' => [], 'dividas' => []];

        foreach ($pat['investimentos'] ?? [] as $inv) {
            $inv += ['fixa_var' => null, 'prazo_projeto' => null, 'ativo' => null, 'valor' => 0];
            Database::run(
                'INSERT INTO investments (user_id, instituicao, fixa_var, prazo_projeto, ativo, valor)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$uid, $inv['instituicao'], $inv['fixa_var'], $inv['prazo_projeto'],
                 $inv['ativo'], (float) $inv['valor']]
            );
        }
        foreach ($pat['bens'] ?? [] as $b) {
            $b += ['valor' => 0, 'saldo_devedor' => 0];
            Database::run(
                'INSERT INTO assets (user_id, descricao, valor, saldo_devedor) VALUES (?, ?, ?, ?)',
                [$uid, $b['descricao'], (float) $b['valor'], (float) $b['saldo_devedor']]
            );
        }
        foreach ($pat['dividas'] ?? [] as $d) {
            $d += ['num_parcelas' => 0, 'valor_parcela' => 0, 'saldo_devedor' => 0];
            Database::run(
                'INSERT INTO debts (user_id, descricao, num_parcelas, valor_parcela, saldo_devedor)
                 VALUES (?, ?, ?, ?, ?)',
                [$uid, $d['descricao'], (int) $d['num_parcelas'],
                 (float) $d['valor_parcela'], (float) $d['saldo_devedor']]
            );
        }

        return [
            'lancamentos'      => $lancamentos,
            'categorias_novas' => $novasCategorias,
            'projetos'         => count($projetos),
            'investimentos'    => count($pat['investimentos'] ?? []),
            'bens'             => count($pat['bens'] ?? []),
            'dividas'          => count($pat['dividas'] ?? []),
            'pessoas'          => count($pessoas),
            'instituicoes'     => count($instituicoes),
        ];
    }
}
