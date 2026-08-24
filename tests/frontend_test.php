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

afirmar((bool) preg_match('/<svg[^>]*id="sprite"[^>]*\shidden/', $html),
    'sprite esta oculto (nao ocupa espaco no layout)');

// ---- Helper ico() ----

afirmar(str_contains($js, 'const ico ='), 'app.js define o helper ico()');

// ---- Classe .ico ----
// O <use> clona o <symbol> numa shadow tree cujo pai e o proprio <use>, entao
// atributos de apresentacao no <svg id="sprite"> nunca chegam ao clone. Os
// valores de traco por isso precisam estar na regra .ico como CSS herdavel,
// que atravessa a shadow tree a partir do site de uso (nao do de definicao).

afirmar((bool) preg_match('/\.ico\s*\{([^}]*)\}/s', $css, $mIco), 'style.css define a classe .ico');
$regraIco = $mIco[1] ?? '';

afirmar((bool) preg_match('/stroke:\s*currentColor/', $regraIco),
    '.ico usa stroke: currentColor (icone herda a cor do texto)');
afirmar((bool) preg_match('/fill:\s*none/', $regraIco),
    '.ico usa fill: none');
afirmar((bool) preg_match('/stroke-width:\s*1\.5/', $regraIco),
    '.ico usa stroke-width 1.5');

echo "\nTodos os testes de frontend passaram.\n";
