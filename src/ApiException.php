<?php
/**
 * Erro previsto de API — vira uma resposta {"detail": "..."} com o status HTTP
 * informado. O frontend (app.js) lê exatamente esse campo para exibir a mensagem.
 *
 * Só entra aqui texto escrito por nós, seguro para mostrar ao usuário. Falhas
 * inesperadas (PDOException, TypeError...) não usam esta classe: viram 500
 * genérico e vão para o log, sem vazar detalhes de banco ou de caminho de arquivo.
 */

declare(strict_types=1);

namespace MinhasContas;

final class ApiException extends \RuntimeException
{
    public function __construct(private int $status, string $detalhe)
    {
        parent::__construct($detalhe);
    }

    public function status(): int
    {
        return $this->status;
    }
}
