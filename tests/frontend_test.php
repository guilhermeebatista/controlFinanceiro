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

// Ancorado no inicio da linha: Task 3 introduz seletores compostos como
// ".side-brand .ico" e "#tabs button.active .ico", cuja substring ".ico {"
// tambem bateria num regex sem ancora — pegando a regra errada (sem os
// valores de traco) em vez da definicao real da classe .ico.
afirmar((bool) preg_match('/^\.ico\s*\{([^}]*)\}/ms', $css, $mIco), 'style.css define a classe .ico');
$regraIco = $mIco[1] ?? '';

afirmar((bool) preg_match('/stroke:\s*currentColor/', $regraIco),
    '.ico usa stroke: currentColor (icone herda a cor do texto)');
afirmar((bool) preg_match('/fill:\s*none/', $regraIco),
    '.ico usa fill: none');
afirmar((bool) preg_match('/stroke-width:\s*1\.5/', $regraIco),
    '.ico usa stroke-width 1.5');

// ---- Tema: variaveis ----

// As 8 que o Chart.js le via cssVar(). Se alguma sumir, o grafico renderiza
// com cor vazia e o bug so aparece a olho nu.
preg_match_all('/cssVar\("(--[a-z0-9-]+)"\)/', $js, $m);
foreach (array_unique($m[1]) as $var) {
    afirmar((bool) preg_match('/^\s*' . preg_quote($var, '/') . '\s*:/m', $css),
        "variavel {$var}, lida pelo app.js, existe no style.css");
}

// Variaveis do tema antigo, removidas.
foreach (['--glow', '--grad', '--grad-btn', '--primary', '--primary-dark',
          '--primary-soft', '--series-1', '--baseline'] as $morta) {
    afirmar(!str_contains($css, $morta),
        "variavel do tema antigo {$morta} nao aparece mais no style.css");
}

// Variaveis do tema novo.
foreach (['--page', '--surface-1', '--surface-2', '--surface-3', '--border',
          '--border-strong', '--text-primary', '--text-secondary', '--muted',
          '--gold', '--gold-soft', '--gold-dim', '--gold-border', '--on-gold',
          '--good', '--bad', '--radius', '--radius-sm'] as $nova) {
    afirmar((bool) preg_match('/^\s*' . preg_quote($nova, '/') . '\s*:/m', $css),
        "variavel do tema novo {$nova} definida");
}

// ---- Tema: sem residuo roxo/ciano nem glow ----

afirmar(!preg_match('/108,\s*99,\s*255/', $css), 'sem literal roxo #6C63FF em rgba()');
afirmar(!preg_match('/0,\s*212,\s*255/', $css), 'sem literal ciano #00D4FF em rgba()');
afirmar(!preg_match('/#6C63FF|#4f46e5|#a5a0ff|#00D4FF/i', $css), 'sem hex do tema antigo');
afirmar(!str_contains($css, 'body::before'), 'halo radial roxo do fundo removido');
afirmar(!preg_match('/linear-gradient/', $css), 'nenhum gradiente sobrou no CSS');

// A regra do dourado: nada de glow. Sombras difusas so as pretas de
// elevacao (dialog, combo, popover), nunca coloridas.
preg_match_all('/box-shadow:[^;]+;/', $css, $m);
foreach ($m[0] as $sombra) {
    afirmar(!preg_match('/212,\s*175,\s*55/', $sombra),
        'box-shadow sem dourado (regra do dourado racionado): ' . trim($sombra));
}

// ---- index.html sem emoji ----

$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';
afirmar(!preg_match($emoji, $html), 'index.html nao contem nenhum emoji');

// Todo <use href="#i-*"> aponta para um symbol que existe.
preg_match_all('/href="#(i-[a-z-]+)"/', $html, $m);
afirmar(count($m[1]) > 0, 'index.html usa icones do sprite');
foreach (array_unique($m[1]) as $usado) {
    afirmar(in_array($usado, $definidos, true), "icone {$usado} usado no HTML existe no sprite");
}

// Os 9 itens de navegacao tem icone.
preg_match('/<nav id="tabs">(.*?)<\/nav>/s', $html, $nav);
afirmar(isset($nav[1]), 'bloco <nav id="tabs"> encontrado');
afirmar(substr_count($nav[1], '<use href="#i-') === 9, 'os 9 itens do menu lateral tem icone');

// O nome do usuario e um span proprio: app.js escreve nele via textContent
// e nao pode mais carregar o emoji junto.
afirmar((bool) preg_match('/<span id="user-name"><\/span>/', $html),
    '#user-name e um span vazio (icone fica fora dele)');

// ---- app.js sem emoji ----

afirmar(!preg_match($emoji, $js), 'app.js nao contem nenhum emoji');

preg_match_all('/\bico\("([a-z-]+)"\)/', $js, $m);
afirmar(count($m[1]) > 0, 'app.js usa o helper ico()');
foreach (array_unique($m[1]) as $nome) {
    afirmar(in_array('i-' . $nome, $definidos, true), "ico(\"{$nome}\") existe no sprite");
}

// O nome do usuario vai puro no textContent, sem prefixo decorativo.
afirmar(!preg_match('/user-name"\)\.textContent\s*=\s*"[^"]*"\s*\+/', $js),
    '#user-name recebe so o nome, sem prefixo concatenado');

// ---- Acessibilidade e polimento ----

// Todo botao cujo conteudo visivel e so um icone precisa de rotulo
// acessivel. Deteccao por lista explicita em vez de regex generica: os
// botoes so-icone sao conhecidos e finitos, e um regex que tenta casar
// "<button> cujo unico filho e um svg" gera falso positivo em botao com
// icone + texto.
$soIcone = [
    ['app.js', '/data-edit(?:="\$\{[a-z]+\.id\}")?[^>]*aria-label=/', 'botao de editar linha'],
    ['app.js', '/data-del="\$\{[a-z]+\.id\}"[^>]*aria-label=/',        'botao de excluir linha'],
    ['app.js', '/data-pw="[^"]*"[^>]*aria-label=/',                       'botao de redefinir senha'],
    ['app.js', '/data-mfa="[^"]*"[^>]*aria-label=/',                      'botao de resetar MFA'],
    ['app.js', '/data-flag="[^"]*"[^>]*aria-label=/',                     'botao de promover/rebaixar'],
    ['index.html', '/id="menu-toggle"[^>]*aria-label=/',                  'botao hamburguer'],
];
foreach ($soIcone as [$arquivo, $padrao, $descricao]) {
    $conteudo = $arquivo === 'app.js' ? $js : $html;
    afirmar((bool) preg_match($padrao, $conteudo),
        "{$descricao} ({$arquivo}) tem aria-label");
}

// E nenhum botao pode ter ficado com o icone como unico conteudo sem rotulo:
// varre os data-* de acao no app.js e exige aria-label em todos.
preg_match_all('/<button\b[^>]*data-(?:edit|del|pw|mfa|flag)\b[^>]*>/', $js, $m);
afirmar(count($m[0]) >= 8, 'encontrou os botoes de acao gerados pelo JS');
foreach ($m[0] as $tag) {
    afirmar(str_contains($tag, 'aria-label='),
        'botao de acao com aria-label: ' . trim(substr($tag, 0, 60)));
}

// Anel de foco visivel — hoje so inputs reagiam ao foco.
// Ancorado no inicio da linha (mesmo motivo do .ico, ja corrigido tres
// vezes neste arquivo): ":focus-visible" e substring de
// "select:focus-visible", entao um regex sem ancora podia casar dentro do
// seletor composto errado dependendo da ordem das regras no arquivo.
afirmar((bool) preg_match('/^:focus-visible\s*\{([^}]*)\}/m', $css, $mFoco),
    'style.css define anel de :focus-visible');
afirmar((bool) preg_match('/outline:\s*2px solid var\(--gold\)/', $mFoco[1] ?? ''),
    'anel de foco usa --gold');

// Especificidade: "select:focus, input:focus, textarea:focus" tem
// especificidade (0,1,1) e vencia o ":focus-visible" generico acima, que e
// (0,1,0) — o "outline: none" daquela regra apagava o anel exatamente nos
// elementos de formulario que mais precisam dele (bug real, achado em
// revisao manual no navegador: outlineStyle computava "none" num <select>
// mesmo com :focus-visible === true). Precisa de uma regra dedicada
// "select:focus-visible, input:focus-visible, textarea:focus-visible" cuja
// especificidade (0,2,1) vence a regra antiga. Isto NAO e so grep de texto:
// assert que essa regra de override existe e realmente usa --gold, para
// nao voltar a passar so por acaso de ordenacao.
afirmar((bool) preg_match(
    '/^select:focus-visible,\s*input:focus-visible,\s*textarea:focus-visible\s*\{([^}]*)\}/m',
    $css, $mFocoForm
), 'style.css define :focus-visible dedicado para select/input/textarea (override de especificidade)');
$regraFocoForm = $mFocoForm[1] ?? '';
afirmar((bool) preg_match('/outline:\s*2px solid var\(--gold\)/', $regraFocoForm),
    ':focus-visible de formulario usa --gold');

// Numeros nao mudam de largura a cada atualizacao.
// Ancorado no inicio da linha: sem isso, o regex bateria em
// ".tile-value.pos {" ou ".tile-value.neg {" (regras seguintes) em vez da
// definicao real de ".tile-value".
afirmar((bool) preg_match('/^\.tile-value\s*\{[^}]*tabular-nums[^}]*\}/m', $css),
    '.tile-value usa tabular-nums');

// ---- Contraste: --muted sobre --surface-1 (WCAG AA, texto pequeno >= 4.5:1) ----
// --muted e usado em .muted (12px) para texto de apoio real. Achado na
// revisao da Task 2: o valor antigo (#6E6E76) so alcancava 3.70:1 contra
// --surface-1 (#121213) — abaixo do minimo de 4.5:1 para texto < 18px/14pt
// bold. As cores sao extraidas do bloco :root em vez de hardcoded aqui, para
// que uma futura alteracao da paleta seja pega por este teste em vez de
// passar batido.

function luminanciaRelativa(string $hex): float
{
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
    $linear = function (int $canal): float {
        $s = $canal / 255;
        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
}

function razaoContraste(string $hexA, string $hexB): float
{
    $lA = luminanciaRelativa($hexA);
    $lB = luminanciaRelativa($hexB);
    [$lMax, $lMin] = $lA >= $lB ? [$lA, $lB] : [$lB, $lA];
    return ($lMax + 0.05) / ($lMin + 0.05);
}

afirmar((bool) preg_match('/:root\s*\{(.*?)\n\}/s', $css, $mRaiz), 'style.css define bloco :root');
$blocoRaiz = $mRaiz[1] ?? '';

afirmar((bool) preg_match('/--muted:\s*(#[0-9a-fA-F]{6})/', $blocoRaiz, $mMuted),
    ':root define --muted como hex');
afirmar((bool) preg_match('/--surface-1:\s*(#[0-9a-fA-F]{6})/', $blocoRaiz, $mSurface1),
    ':root define --surface-1 como hex');

$razaoMuted = razaoContraste($mMuted[1], $mSurface1[1]);
afirmar($razaoMuted >= 4.5,
    "--muted sobre --surface-1 atinge 4.5:1 (WCAG AA para texto pequeno); calculado: {$razaoMuted}");

echo "\nTodos os testes de frontend passaram.\n";
