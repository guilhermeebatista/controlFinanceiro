<?php
/**
 * Parâmetros do usuário: receita mensal, custo de vida e fator da reserva.
 */

declare(strict_types=1);

namespace MinhasContas\Controllers;

use MinhasContas\Auth;
use MinhasContas\Database;
use MinhasContas\Http;

final class SettingsController
{
    /** Chaves aceitas. Uma chave fora daqui simplesmente não é gravada. */
    private const CHAVES = ['receita_mensal', 'custo_vida_mensal', 'fator_reserva'];

    private const PADROES = [
        'receita_mensal'    => 0.0,
        'custo_vida_mensal' => 0.0,
        'fator_reserva'     => 6.0,
    ];

    /**
     * @return array<string, float>
     */
    public static function mapa(int $uid): array
    {
        $linhas = Database::todos('SELECT chave, valor FROM settings WHERE user_id = ?', [$uid]);
        $mapa = self::PADROES;
        foreach ($linhas as $l) {
            $chave = (string) $l['chave'];
            if (in_array($chave, self::CHAVES, true)) {
                $mapa[$chave] = (float) $l['valor'];
            }
        }
        return $mapa;
    }

    public static function listar(): void
    {
        Http::json(self::mapa(Auth::exigirUsuario()));
    }

    public static function salvar(): void
    {
        $uid = Auth::exigirUsuario();

        $valores = [
            'receita_mensal'    => Http::numero('receita_mensal'),
            'custo_vida_mensal' => Http::numero('custo_vida_mensal'),
            // Reserva de emergência abaixo de 1 mês ou acima de 120 é erro de
            // digitação; o valor multiplica o custo de vida no resumo.
            'fator_reserva'     => max(0.0, min(120.0, Http::numero('fator_reserva', 6.0))),
        ];

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
