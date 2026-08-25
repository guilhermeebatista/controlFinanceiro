<?php
/**
 * Parâmetros do usuário: renda e custo de vida, fator da reserva e os índices
 * de mercado usados nos cálculos de investimento.
 *
 * Os índices ficam aqui, e não no código, por dois motivos: mudam a cada
 * reunião do Copom e o sistema roda sem acesso à internet — não há de onde
 * buscá-los sozinho. O padrão é um ponto de partida para a conta não sair
 * zerada; quem usa atualiza quando a taxa muda.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Database;
use MinhasContas\Http;

final class SettingsController
{
    /**
     * Os parâmetros aceitos. Uma chave fora daqui simplesmente não é gravada.
     *
     * Os limites são defensivos, contra erro de digitação: fator de reserva
     * de 600 meses ou CDI de 1400% seriam aceitos calados pelo banco e
     * envenenariam todos os gráficos.
     *
     * @var array<string, array{padrao: float, min: float, max: float}>
     */
    public const PARAMETROS = [
        'receita_mensal'    => ['padrao' => 0.0,   'min' => 0.0,   'max' => 1.0e12],
        'custo_vida_mensal' => ['padrao' => 0.0,   'min' => 0.0,   'max' => 1.0e12],
        'fator_reserva'     => ['padrao' => 6.0,   'min' => 0.0,   'max' => 120.0],
        // Índices em % ao ano.
        'taxa_cdi_anual'    => ['padrao' => 14.90, 'min' => 0.0,   'max' => 100.0],
        'taxa_selic_anual'  => ['padrao' => 15.00, 'min' => 0.0,   'max' => 100.0],
        // IPCA aceita negativo: deflação acontece.
        'taxa_ipca_anual'   => ['padrao' => 4.50,  'min' => -20.0, 'max' => 100.0],
        'taxa_tr_anual'     => ['padrao' => 1.00,  'min' => 0.0,   'max' => 50.0],
    ];

    /**
     * @return array<string, float>
     */
    public static function mapa(int $uid): array
    {
        $mapa = array_map(static fn(array $p): float => $p['padrao'], self::PARAMETROS);

        foreach (Database::todos('SELECT chave, valor FROM settings WHERE user_id = ?', [$uid]) as $l) {
            $chave = (string) $l['chave'];
            if (isset(self::PARAMETROS[$chave])) {
                $mapa[$chave] = (float) $l['valor'];
            }
        }
        return $mapa;
    }

    /**
     * Os índices como fração ao ano (0.149), que é o formato com que
     * Investimentos trabalha — a interface fala em porcentagem, a conta não.
     *
     * @return array<string, float>
     */
    public static function indices(int $uid): array
    {
        $m = self::mapa($uid);
        return [
            'cdi'   => $m['taxa_cdi_anual']   / 100,
            'selic' => $m['taxa_selic_anual'] / 100,
            'ipca'  => $m['taxa_ipca_anual']  / 100,
            'tr'    => $m['taxa_tr_anual']    / 100,
        ];
    }

    public static function listar(): void
    {
        Http::json(self::mapa(Auth::exigirUsuario()));
    }

    public static function salvar(): void
    {
        $uid = Auth::exigirUsuario();

        // Só grava o que veio no corpo. São dois formulários na tela — os
        // parâmetros da conta e os índices de mercado —, e salvar um deles
        // não pode zerar o outro por ausência de campo.
        $corpo = Http::corpo();
        $atual = self::mapa($uid);

        $valores = [];
        foreach (self::PARAMETROS as $chave => $p) {
            if (!array_key_exists($chave, $corpo)) {
                continue;
            }
            $valores[$chave] = max($p['min'], min($p['max'], Http::numero($chave, $atual[$chave])));
        }

        Database::transacao(static function () use ($uid, $valores): void {
            foreach ($valores as $chave => $valor) {
                Database::run(
                    'INSERT INTO settings (user_id, chave, valor) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                    [$uid, $chave, $valor]
                );
            }
        });

        Http::json(['ok' => true]);
    }
}
