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
    'i-download', 'i-upload', 'i-sheet', 'i-sun', 'i-moon',
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
    ['index.html', '/id="tema-btn"[^>]*aria-label=/',                     'botao de tema claro/escuro'],
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

// ---- Tema claro/escuro ----

afirmar(is_file($raiz . '/tema.js'), 'existe o static/tema.js');
$temaJs = (string) file_get_contents($raiz . '/tema.js');

// O tema precisa estar aplicado antes do primeiro paint, senao quem usa tema
// claro ve a tela escura piscar. Isso exige o script no <head>, sem defer e
// antes do app.js — e nao da para fazer inline, porque a CSP e script-src 'self'.
$posTema = strpos($html, '/static/tema.js');
$posApp = strpos($html, '/static/app.js');
$fimHead = strpos($html, '</head>');
afirmar($posTema !== false && $fimHead !== false && $posTema < $fimHead,
    'tema.js e carregado dentro do <head>');
afirmar($posTema !== false && $posApp !== false && $posTema < $posApp,
    'tema.js vem antes do app.js');
afirmar(!preg_match('#<script[^>]*src="/static/tema\.js"[^>]*\b(defer|async)\b#', $html),
    'tema.js nao usa defer/async (rodaria tarde demais e a tela piscaria)');
afirmar(!preg_match('/<script(?![^>]*\ssrc=)[^>]*>[^<]*\S/', $html),
    'nenhum <script> inline no HTML (a CSP script-src \'self\' recusaria)');

// A escolha e guardada, mas localStorage estoura em aba anonima de alguns
// navegadores. Tema e conforto: nao pode derrubar o app.
afirmar(substr_count($temaJs, 'catch') >= 2,
    'tema.js protege os acessos a localStorage com try/catch');
afirmar(str_contains($temaJs, 'prefers-color-scheme: light'),
    'tema.js consulta o tema do sistema operacional');
afirmar(str_contains($temaJs, 'colorScheme'),
    'tema.js ajusta color-scheme (barra de rolagem e seletor de data nativos)');
afirmar(str_contains($js, 'window.addEventListener("temamudou"'),
    'app.js reage a troca de tema (o Chart.js le a cor so uma vez, ao desenhar)');

// As duas paletas tem de andar juntas. Um token de cor novo so no escuro fica
// invisivel ou ilegivel no claro, e ninguem percebe ate abrir no outro tema.
afirmar((bool) preg_match('/:root\[data-tema="claro"\]\s*\{(.*?)\n\}/s', $css, $mClaro),
    'style.css define a paleta do tema claro');
$blocoClaro = $mClaro[1] ?? '';

$tokensDe = static function (string $bloco): array {
    preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/m', $bloco, $m);
    return array_unique($m[1]);
};
$tokensEscuro = $tokensDe($blocoRaiz);
$tokensClaro = $tokensDe($blocoClaro);
// --radius* nao sao cor e nao mudam com o tema.
$soDoEscuro = array_diff($tokensEscuro, $tokensClaro, ['--radius', '--radius-sm']);
afirmar($soDoEscuro === [],
    'todo token de cor do tema escuro tem par no claro; faltando: ' . implode(', ', $soDoEscuro));
afirmar(array_diff($tokensClaro, $tokensEscuro) === [],
    'o tema claro nao inventa token que o escuro nao tenha');

// Cor de tema fora dos dois blocos de paleta e um vazamento: rgba branco no
// meio do arquivo some no tema claro. Os scrims pretos (backdrop do dialog e
// do menu) sao intencionais nos dois temas, entao so o branco e proibido.
// Comentario fora: um comentario que MENCIONA a cor proibida nao e um vazamento.
$cssSemPaletas = preg_replace('#/\*.*?\*/#s', '', str_replace([$blocoRaiz, $blocoClaro], '', $css)) ?? '';
afirmar(!preg_match('/rgba\(\s*255\s*,\s*255\s*,\s*255/', $cssSemPaletas),
    'nenhum rgba branco solto fora dos blocos de paleta');
afirmar(!preg_match('/(?<![-\w])#fff\b|(?<![-\w])#ffffff\b/i', $cssSemPaletas),
    'nenhum #fff solto fora dos blocos de paleta');

// O tema claro passa nos mesmos criterios de contraste do escuro. Sem isto o
// dourado #D4AF37, que brilha no preto, daria 1.9:1 sobre branco.
$corDo = static function (string $bloco, string $token): string {
    afirmar((bool) preg_match('/' . preg_quote($token, '/') . ':\s*(#[0-9a-fA-F]{6})/', $bloco, $m),
        "a paleta define {$token} como hex");
    return $m[1];
};
$pares = [
    ['--muted', '--surface-1', 4.5, 'texto secundario sobre card'],
    ['--text-secondary', '--surface-1', 4.5, 'texto de apoio sobre card'],
    ['--text-primary', '--surface-1', 7.0, 'texto principal sobre card'],
    ['--on-gold', '--gold', 4.5, 'texto do botao primario'],
    ['--good', '--surface-1', 4.5, 'valor positivo sobre card'],
    ['--bad', '--surface-1', 4.5, 'valor negativo sobre card'],
    ['--gold', '--page', 3.0, 'dourado sobre o fundo da pagina'],
];
foreach ([['escuro', $blocoRaiz], ['claro', $blocoClaro]] as [$nome, $bloco]) {
    foreach ($pares as [$fg, $bg, $min, $descricao]) {
        $r = razaoContraste($corDo($bloco, $fg), $corDo($bloco, $bg));
        afirmar($r >= $min, sprintf(
            'tema %s: %s (%s sobre %s) atinge %.1f:1; calculado %.2f:1',
            $nome, $descricao, $fg, $bg, $min, $r
        ));
    }
}

// ---- Planilha: baixar e importar ----
// Os dois downloads sao <a href> e nao fetch(): sem JS eles continuam
// funcionando, e a CSP (default-src 'none') nao atrapalha uma navegacao.
// Se alguem trocar por um botao, o arquivo para de baixar em silencio.
foreach (['/api/export/modelo' => 'modelo em branco', '/api/export' => 'dados da conta'] as $rota => $oque) {
    afirmar((bool) preg_match('/<a[^>]*class="btn"[^>]*href="' . preg_quote($rota, '/') . '"[^>]*download/', $html),
        "link de download do {$oque} aponta para {$rota}");
}
afirmar((bool) preg_match('/^a\.btn\s*\{([^}]*)\}/m', $css, $mLinkBtn),
    'style.css tem a regra a.btn (o link precisa dela para parecer botao)');
afirmar(str_contains($mLinkBtn[1] ?? '', 'text-decoration: none'),
    'a.btn tira o sublinhado do link');

afirmar((bool) preg_match('/id="imp-file"[^>]*accept="\.xlsx,\.xlsm"/', $html),
    'o campo de importacao aceita .xlsx e .xlsm');

// ---- Investimentos: carteira, impostos e indices ----

foreach (['p-carteira', 'p-carteira-nota', 'p-projecao', 'p-meses'] as $id) {
    afirmar(str_contains($html, 'id="' . $id . '"'), "a aba Patrimonio tem #{$id}");
}
foreach (['taxa_cdi_anual', 'taxa_selic_anual', 'taxa_ipca_anual', 'taxa_tr_anual'] as $campo) {
    afirmar((bool) preg_match('/<input[^>]*name="' . $campo . '"/', $html),
        "o formulario de indices tem o campo {$campo}");
}
afirmar(str_contains($html, 'id="idx-form"'), 'existe o formulario de indices de mercado');

// A regra de imposto mora em src/Investimentos.php e em lugar nenhum mais.
// Se uma aliquota aparecer no JS, existem duas fontes de verdade e uma delas
// vai ficar para tras na proxima mudanca de lei.
foreach (['0.225', '0.175', '22,5%', '17,5%'] as $aliquota) {
    afirmar(!str_contains($js, $aliquota),
        "app.js nao tem a aliquota {$aliquota} embutida (a tabela de IR e do backend)");
}
afirmar(str_contains($js, 'const pct ='), 'app.js define o helper pct() para formatar fracao como %');
afirmar(str_contains($js, '/api/investments/summary'), 'app.js busca o resumo calculado da carteira');

// ---- A aba ativa sobrevive ao F5 ----
// Ela mora no hash da URL. Se alguem voltar a fixar o dashboard na entrada,
// recarregar a pagina joga a pessoa para fora da aba em que estava.
afirmar(str_contains($js, 'window.addEventListener("hashchange"'),
    'app.js reage ao hashchange (F5 e botao voltar abrem a aba certa)');
afirmar(str_contains($js, 'const abaDaUrl ='), 'app.js le a aba ativa da URL');
afirmar((bool) preg_match('/await abrirAba\(abaDaUrl\(\)\)/', $js),
    'a entrada no app abre a aba que estava na URL, nao uma aba fixa');
afirmar(!preg_match('/dataset\.tab === "dashboard"/', $js),
    'nenhum trecho forca a aba dashboard na entrada');
// O nome vem da URL e vira seletor ("#tab-" + nome): sem validacao, "#admin"
// abriria para quem nao e administrador.
afirmar(str_contains($js, 'function abaValida'),
    'app.js valida o nome da aba vindo da URL antes de usa-lo como seletor');

afirmar((bool) preg_match('/^\.hint\s*\{/m', $css),
    'style.css define .hint (a linha de ajuda dos campos do formulario)');
afirmar((bool) preg_match('/^\.inline-label\s*\{/m', $css),
    'style.css define .inline-label (o seletor de meses dentro do titulo do card)');

echo "\nTodos os testes de frontend passaram.\n";
