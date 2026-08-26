<?php
/**
 * Ciclo de vida das contas: criação com plano de contas inicial, exclusão e
 * promoção do primeiro administrador.
 */

declare(strict_types=1);

namespace MinhasContas;

final class Users
{
    /**
     * Os padrões saem de SettingsController: assim uma conta nova nasce com
     * exatamente os mesmos valores que a tela de parâmetros mostraria, e
     * acrescentar um parâmetro é mexer num lugar só.
     */
    private const PADROES_SETTINGS = Controllers\SettingsController::PARAMETROS;

    /**
     * Cria a conta com parâmetros zerados e o plano de contas padrão, para a
     * pessoa entrar num painel utilizável em vez de uma tela vazia.
     */
    public static function criar(
        string $usuario,
        string $senha,
        ?string $nome = null,
        ?string $email = null,
        bool $categoriasPadrao = true,
    ): int {
        return Database::transacao(static function () use ($usuario, $senha, $nome, $email, $categoriasPadrao): int {
            Database::run(
                'INSERT INTO users (usuario, nome, email, senha_hash) VALUES (?, ?, ?, ?)',
                [$usuario, $nome, $email, Auth::hashSenha($senha)]
            );
            $uid = Database::ultimoId();

            foreach (self::PADROES_SETTINGS as $chave => $p) {
                Database::run(
                    'INSERT INTO settings (user_id, chave, valor) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                    [$uid, $chave, $p['padrao']]
                );
            }

            if ($categoriasPadrao) {
                self::semearCategoriasPadrao($uid);
            }
            return $uid;
        });
    }

    public static function semearCategoriasPadrao(int $uid): int
    {
        $arquivo = Config::raiz() . '/resources/default_categories.json';
        if (!is_file($arquivo)) {
            return 0;
        }
        $conteudo = file_get_contents($arquivo);
        if ($conteudo === false) {
            return 0;
        }
        $cats = json_decode($conteudo, true);
        if (!is_array($cats)) {
            return 0;
        }

        $n = 0;
        foreach ($cats as $c) {
            if (!is_array($c) || !isset($c['classificacao'], $c['categoria'])) {
                continue;
            }
            $n += Categories::inserirSeAusente($uid, [
                'classificacao' => (string) $c['classificacao'],
                'grupo'         => (string) ($c['grupo'] ?? 'OPERACIONAL'),
                'tipo'          => (string) ($c['tipo'] ?? 'DESPESA'),
                'categoria'     => (string) $c['categoria'],
                'subcategoria'  => isset($c['subcategoria']) ? (string) $c['subcategoria'] : null,
                'meta_mes'      => (float) ($c['meta_mes'] ?? 0),
            ]);
        }
        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buscarOuFalhar(int $uid): array
    {
        $u = Database::um('SELECT * FROM users WHERE id = ?', [$uid]);
        if ($u === null) {
            Http::erro(404, 'Usuário não encontrado');
        }
        return $u;
    }

    public static function contarAdmins(): int
    {
        return (int) Database::valor('SELECT COUNT(*) FROM users WHERE is_admin = 1');
    }

    /**
     * As FKs são ON DELETE CASCADE, então apagar a linha de users leva junto
     * lançamentos, categorias, projetos, patrimônio e sessões. O banco é a
     * garantia — não uma lista de tabelas na aplicação que alguém esqueceria
     * de atualizar ao criar uma tabela nova.
     */
    public static function excluir(int $uid): void
    {
        Database::run('DELETE FROM users WHERE id = ?', [$uid]);
    }

    /**
     * Promove o e-mail configurado enquanto não existir nenhum admin.
     * A condição importa: sem ela, um admin rebaixado pelo painel voltaria a
     * ser admin no próximo restart.
     */
    public static function garantirPrimeiroAdmin(): void
    {
        if (self::contarAdmins() > 0) {
            return;
        }
        $alvo = mb_strtolower(Config::adminEmail());
        // Sem ADMIN_EMAIL configurado não há a quem promover. Sair aqui evita
        // que a comparação com string vazia case com uma conta cujo e-mail ou
        // usuário também seja vazio — o que daria admin a quem não devia.
        if ($alvo === '') {
            return;
        }
        Database::run(
            'UPDATE users SET is_admin = 1 WHERE LOWER(email) = ? OR LOWER(usuario) = ?',
            [$alvo, $alvo]
        );
    }
}
