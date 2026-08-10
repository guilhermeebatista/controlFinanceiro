<?php
/**
 * Autoloader PSR-4 para o namespace MinhasContas\ -> src/.
 *
 * O projeto não usa Composer: são poucas classes e nenhuma dependência
 * externa, então evitar o vendor/ mantém a imagem menor e reduz a superfície
 * de supply chain a zero.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'MinhasContas\\';
    if (!str_starts_with($classe, $prefixo)) {
        return;
    }

    $relativo = substr($classe, strlen($prefixo));
    // Só letras, dígitos, _ e separador de namespace viram caminho — o nome da
    // classe nunca chega a incluir ".." nem barra vinda de fora, mas a
    // checagem deixa isso explícito.
    if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relativo) !== 1) {
        return;
    }

    $arquivo = __DIR__ . '/' . str_replace('\\', '/', $relativo) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});
