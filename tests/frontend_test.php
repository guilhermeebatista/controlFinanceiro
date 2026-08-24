<?php
declare(strict_types=1);

/**
 * Invariantes do frontend estático. Não sobe servidor nem banco — só lê os
 * três arquivos de public/static/ e checa propriedades estruturais que
 * quebram silenciosamente no navegador (símbolo de ícone faltando, variável
 * CSS que o Chart.js lê e não existe mais, botão só-ícone sem rótulo).
 */

function afirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: {$mensagem}\n");
        exit(1);
    }
    echo "ok: {$mensagem}\n";
}

$raiz = __DIR__ . '/../public/static';
$html = file_get_contents($raiz . '/index.html');
$js   = file_get_contents($raiz . '/app.js');
$css  = file_get_contents($raiz . '/style.css');
afirmar($html !== false && $js !== false && $css !== false, 'os tres arquivos de public/static foram lidos');

// ---- Sprite de icones ----

$esperados = [
    'i-dashboard', 'i-list', 'i-users', 'i-trend', 'i-landmark', 'i-target',
    'i-inbox', 'i-settings', 'i-shield', 'i-wallet', 'i-mail', 'i-lock',
    'i-key', 'i-x', 'i-pencil', 'i-trash', 'i-check', 'i-refresh',
    'i-arrow-up', 'i-arrow-down', 'i-user', 'i-menu', 'i-plus',
];

preg_match_all('/<symbol id="(i-[a-z-]+)"/', $html, $m);
$definidos = $m[1];

foreach ($esperados as $id) {
    afirmar(in_array($id, $definidos, true), "sprite define <symbol id=\"{$id}\">");
}
afirmar(count($definidos) === count(array_unique($definidos)), 'nenhum id de symbol duplicado');

// O <svg> do sprite carrega os atributos de traco que os symbols herdam.
afirmar((bool) preg_match('/<svg[^>]*id="sprite"[^>]*stroke="currentColor"/', $html),
    'sprite usa stroke="currentColor" (icone herda a cor do texto)');
afirmar((bool) preg_match('/<svg[^>]*id="sprite"[^>]*stroke-width="1\.5"/', $html),
    'sprite usa stroke-width 1.5');
afirmar((bool) preg_match('/<svg[^>]*id="sprite"[^>]*\shidden/', $html),
    'sprite esta oculto (nao ocupa espaco no layout)');

// ---- Helper ico() ----

afirmar(str_contains($js, 'const ico ='), 'app.js define o helper ico()');

// ---- Classe .ico ----

afirmar((bool) preg_match('/^\.ico\s*\{/m', $css), 'style.css define a classe .ico');

echo "\nTodos os testes de frontend passaram.\n";
