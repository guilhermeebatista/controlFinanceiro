<?php
/**
 * CRUD das abas simples (patrimônio, projetos, cadastros e registro).
 *
 * A versão anterior montava `INSERT INTO {table} ({cols})` a partir das chaves
 * do JSON recebido — quem controlasse o payload escolhia colunas. Aqui existe
 * um registro fechado: tabela, ordenação e cada campo com tipo e limite estão
 * declarados em RECURSOS, e o SQL é gerado só a partir dele. Nome de tabela ou
 * de coluna nunca vem da requisição; o corpo enviado só fornece VALORES, e
 * apenas para os campos declarados — o resto é descartado em silêncio.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Cast;
use MinhasContas\Database;
use MinhasContas\Http;
use MinhasContas\Investimentos;
use PDOException;

final class CrudController
{
    /**
     * Tipos aceitos em 'campos':
     *   texto:N       obrigatório, no máximo N caracteres
     *   texto?:N      opcional (vazio vira NULL)
     *   numero        float arredondado em 2 casas
     *   inteiro?      inteiro opcional
     *   data?         data ISO opcional
     *
     * Dois ganchos opcionais, ambos declarados aqui e nunca vindos da
     * requisição:
     *   normalizar    ajusta os valores gravados quando um campo depende de
     *                 outro (o indexador precisa fazer sentido para o tipo)
     *   enriquecer    acrescenta campos calculados na listagem, que existem
     *                 na resposta mas não no banco
     *
     * @var array<string, array{tabela:string, ordem:string, rotulo:string, campos:array<string,string>, unico?:string, normalizar?:callable, enriquecer?:callable}>
     */
    private const RECURSOS = [
        'projects' => [
            'tabela' => 'projects',
            'ordem'  => 'ano, descricao',
            'rotulo' => 'Projeto',
            'campos' => [
                'descricao' => 'texto:255',
                'valor'     => 'numero',
                'ano'       => 'inteiro?',
                'prazo'     => 'texto?:40',
            ],
        ],
        'investments' => [
            'tabela' => 'investments',
            'ordem'  => 'instituicao, id',
            'rotulo' => 'Investimento',
            'campos' => [
                'instituicao'   => 'texto:120',
                'fixa_var'      => 'texto?:40',
                'prazo_projeto' => 'texto?:40',
                'ativo'         => 'texto?:120',
                'valor'         => 'numero',
                // Produto, rentabilidade e datas: é o que permite calcular
                // imposto e rendimento mensal (ver src/Investimentos.php).
                'tipo'           => 'texto?:40',
                'indexador'      => 'texto?:20',
                'taxa'           => 'numero',
                'valor_aplicado' => 'numero',
                'dt_aplicacao'   => 'data?',
                'dt_vencimento'  => 'data?',
            ],
            'normalizar' => [Investimentos::class, 'normalizarCampos'],
            'enriquecer' => [Investimentos::class, 'enriquecerLista'],
        ],
        'assets' => [
            'tabela' => 'assets',
            'ordem'  => 'descricao, id',
            'rotulo' => 'Bem',
            'campos' => [
                'descricao'     => 'texto:255',
                'valor'         => 'numero',
                'saldo_devedor' => 'numero',
            ],
        ],
        'debts' => [
            'tabela' => 'debts',
            'ordem'  => 'descricao, id',
            'rotulo' => 'Dívida',
            'campos' => [
                'descricao'     => 'texto:255',
                'num_parcelas'  => 'inteiro?',
                'valor_parcela' => 'numero',
                'saldo_devedor' => 'numero',
            ],
        ],
        'institutions' => [
            'tabela' => 'institutions',
            'ordem'  => 'nome',
            'rotulo' => 'Instituição',
            'unico'  => 'Já existe uma instituição com esse nome.',
            'campos' => [
                'nome'          => 'texto:120',
                'tipo'          => 'texto?:40',
                'saldo_inicial' => 'numero',
                'descricao'     => 'texto?:255',
            ],
        ],
        'people' => [
            'tabela' => 'people',
            'ordem'  => 'nome',
            'rotulo' => 'Pessoa',
            'unico'  => 'Já existe uma pessoa com esse nome.',
            'campos' => [
                'nome' => 'texto:120',
            ],
        ],
        'ofx' => [
            'tabela' => 'ofx_imports',
            'ordem'  => 'id DESC',
            'rotulo' => 'Arquivo OFX',
            'campos' => [
                'data_extracao' => 'data?',
                'banco'         => 'texto?:120',
                'periodo'       => 'texto?:60',
                'nome_arquivo'  => 'texto?:255',
            ],
        ],
        'card-payments' => [
            'tabela' => 'card_payments',
            'ordem'  => 'id DESC',
            'rotulo' => 'Pagamento de cartão',
            'campos' => [
                'data_pagto' => 'data?',
                'cartao'     => 'texto?:120',
                'periodo'    => 'texto?:60',
                'valor'      => 'numero',
            ],
        ],
    ];

    public static function listar(string $recurso): void
    {
        $uid = Auth::exigirUsuario();
        $r = self::recurso($recurso);

        $itens = Database::todos(
            "SELECT * FROM {$r['tabela']} WHERE user_id = ? ORDER BY {$r['ordem']}",
            [$uid]
        );

        [$floats, $ints] = self::colunasNumericas($r['campos']);
        $itens = Cast::linhas($itens, $floats, [...$ints, 'id', 'user_id']);

        // Depois do Cast: os campos calculados trabalham em cima de float, e
        // não da string que o driver poderia devolver.
        if (isset($r['enriquecer'])) {
            $itens = ($r['enriquecer'])($itens, $uid);
        }

        Http::json(['items' => $itens]);
    }

    public static function criar(string $recurso): void
    {
        $uid = Auth::exigirUsuario();
        $r = self::recurso($recurso);
        $valores = self::lerCampos($r);

        $colunas = array_keys($valores);
        $sql = sprintf(
            'INSERT INTO %s (user_id, %s) VALUES (%s)',
            $r['tabela'],
            implode(', ', $colunas),
            Database::placeholders(count($colunas) + 1)
        );

        try {
            Database::run($sql, [$uid, ...array_values($valores)]);
        } catch (PDOException $e) {
            self::traduzirDuplicidade($e, $r);
            throw $e;
        }

        Http::json(['id' => Database::ultimoId()]);
    }

    public static function atualizar(string $recurso, int $id): void
    {
        $uid = Auth::exigirUsuario();
        $r = self::recurso($recurso);
        $valores = self::lerCampos($r);

        $sets = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($valores)));
        $sql = "UPDATE {$r['tabela']} SET {$sets} WHERE id = ? AND user_id = ?";

        try {
            $stmt = Database::run($sql, [...array_values($valores), $id, $uid]);
        } catch (PDOException $e) {
            self::traduzirDuplicidade($e, $r);
            throw $e;
        }

        // rowCount conta linhas casadas (MYSQL_ATTR_FOUND_ROWS), então zero
        // aqui significa que o id não pertence a esta conta — não que o PUT
        // repetiu os mesmos valores.
        if ($stmt->rowCount() === 0) {
            Http::erro(404, 'Registro não encontrado');
        }
        Http::json(['ok' => true]);
    }

    public static function excluir(string $recurso, int $id): void
    {
        $uid = Auth::exigirUsuario();
        $r = self::recurso($recurso);

        $stmt = Database::run(
            "DELETE FROM {$r['tabela']} WHERE id = ? AND user_id = ?",
            [$id, $uid]
        );
        if ($stmt->rowCount() === 0) {
            Http::erro(404, 'Registro não encontrado');
        }
        Http::json(['ok' => true]);
    }

    // ---------------------------------------------------------------- util

    /**
     * @return array{tabela:string, ordem:string, rotulo:string, campos:array<string,string>, unico?:string}
     */
    private static function recurso(string $chave): array
    {
        // As chaves vêm das rotas registradas em public/index.php, não da URL;
        // esta checagem é a rede de segurança contra um registro incompleto.
        if (!isset(self::RECURSOS[$chave])) {
            Http::erro(404, 'Recurso desconhecido.');
        }
        return self::RECURSOS[$chave];
    }

    /**
     * Lê do corpo apenas os campos declarados, com o tipo declarado, e passa
     * o resultado pelo normalizador do recurso, se houver.
     *
     * @param array{campos:array<string,string>, normalizar?:callable} $r
     * @return array<string, mixed>
     */
    private static function lerCampos(array $r): array
    {
        $valores = [];
        foreach ($r['campos'] as $nome => $spec) {
            $valores[$nome] = match (true) {
                $spec === 'numero'             => Http::numero($nome),
                $spec === 'inteiro?'           => Http::inteiroOuNulo($nome, -1_000_000_000, 1_000_000_000),
                $spec === 'data?'              => Http::dataOuNulo($nome),
                str_starts_with($spec, 'texto?:') => Http::textoOuNulo($nome, (int) substr($spec, 7)),
                str_starts_with($spec, 'texto:')  => Http::texto($nome, (int) substr($spec, 6)),
                default => throw new \LogicException("Spec de campo desconhecida: {$spec}"),
            };
        }

        return isset($r['normalizar']) ? ($r['normalizar'])($valores) : $valores;
    }

    /**
     * @param array<string, string> $campos
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function colunasNumericas(array $campos): array
    {
        $floats = $ints = [];
        foreach ($campos as $nome => $spec) {
            if ($spec === 'numero') {
                $floats[] = $nome;
            } elseif ($spec === 'inteiro?') {
                $ints[] = $nome;
            }
        }
        return [$floats, $ints];
    }

    /** @param array{unico?:string, ...} $r */
    private static function traduzirDuplicidade(PDOException $e, array $r): void
    {
        if ($e->getCode() === '23000' && isset($r['unico'])) {
            Http::erro(400, $r['unico']);
        }
    }
}
