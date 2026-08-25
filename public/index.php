<?php
/**
 * Front controller — o único arquivo PHP alcançável pelo navegador.
 *
 * O DocumentRoot do Apache é public/. Todo o código da aplicação vive em
 * src/, um nível acima: mesmo que uma configuração do servidor pare de
 * interpretar PHP, não há como baixar o fonte dos controllers nem um arquivo
 * de configuração pela URL.
 */

declare(strict_types=1);

use MinhasContas\ApiException;
use MinhasContas\Auth;
use MinhasContas\Config;
use MinhasContas\Controllers\AdminController;
use MinhasContas\Controllers\AuthController;
use MinhasContas\Controllers\CategoryController;
use MinhasContas\Controllers\DashboardController;
use MinhasContas\Controllers\ExportController;
use MinhasContas\Controllers\ImportController;
use MinhasContas\Controllers\InvestmentController;
use MinhasContas\Controllers\MfaController;
use MinhasContas\Controllers\SettingsController;
use MinhasContas\Controllers\TransactionController;
use MinhasContas\Http;
use MinhasContas\Router;
use MinhasContas\Security;

require __DIR__ . '/../src/autoload.php';

// Erro nunca vai para a resposta: viraria vazamento de caminho de arquivo,
// versão de biblioteca e estrutura de query. Vai para o log do container.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

Http::cabecalhosSeguranca();

try {
    Security::verificarOrigem();
    // Resolve a sessão antes do CSRF: o token esperado está gravado nela.
    Auth::sessaoAtual();
    Security::verificarCsrf();
    Security::garantirCookieCsrf();
    Security::limpezaPeriodica();

    registrarRotas();
    Router::despachar();
} catch (ApiException $e) {
    Http::json(['detail' => $e->getMessage()], $e->status());
} catch (Throwable $e) {
    error_log(sprintf(
        '[minhas-contas] %s: %s em %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    Http::json(
        ['detail' => Config::debug()
            ? $e::class . ': ' . $e->getMessage()
            : 'Erro interno. Tente novamente.'],
        500
    );
}

function registrarRotas(): void
{
    // ---------------------------------------------------------- interface
    Router::get('/', servirIndex(...));

    // --------------------------------------------------------------- auth
    Router::post('/api/auth/register', AuthController::registrar(...));
    Router::post('/api/auth/verify-email', AuthController::verificarEmail(...));
    Router::post('/api/auth/resend-verification', AuthController::reenviarVerificacao(...));
    Router::post('/api/auth/login', AuthController::login(...));
    Router::post('/api/auth/mfa/setup/start', MfaController::setupIniciar(...));
    Router::post('/api/auth/mfa/setup/confirm', MfaController::setupConfirmar(...));
    Router::post('/api/auth/mfa/verify', MfaController::verificar(...));
    Router::post('/api/auth/mfa/backup-codes/regenerate', MfaController::regenerarCodigosBackup(...));
    Router::post('/api/auth/logout', AuthController::logout(...));
    Router::get('/api/auth/me', AuthController::eu(...));
    Router::put('/api/auth/profile', AuthController::atualizarPerfil(...));
    Router::put('/api/auth/password', AuthController::trocarSenha(...));

    // -------------------------------------------------------------- admin
    Router::get('/api/admin/users', AdminController::listar(...));
    Router::post('/api/admin/users/{id}/password', AdminController::redefinirSenha(...));
    Router::post('/api/admin/users/{id}/admin', AdminController::definirAdmin(...));
    Router::post('/api/admin/users/{id}/mfa/reset', AdminController::resetarMfa(...));
    Router::delete('/api/admin/users/{id}', AdminController::excluir(...));

    // -------------------------------------------------------- lançamentos
    Router::get('/api/transactions', TransactionController::listar(...));
    Router::post('/api/transactions', TransactionController::criar(...));
    Router::post('/api/transactions/bulk-delete', TransactionController::excluirEmLote(...));
    Router::post('/api/transactions/bulk-update', TransactionController::atualizarEmLote(...));
    Router::put('/api/transactions/{id}', TransactionController::atualizar(...));
    Router::delete('/api/transactions/{id}', TransactionController::excluir(...));
    Router::get('/api/pessoas-balanco', TransactionController::balancoPorPessoa(...));

    // ------------------------------------------------------------ painéis
    Router::get('/api/dashboard', DashboardController::dashboard(...));
    Router::get('/api/fluxo', DashboardController::fluxo(...));
    // Antes de /api/projects/{id}: senão "summary" tentaria casar com o id.
    Router::get('/api/projects/summary', DashboardController::resumoProjetos(...));
    // Carteira: totais, imposto e projeção. O CRUD de investimentos continua
    // no registro genérico, mais abaixo.
    Router::get('/api/investments/summary', InvestmentController::resumo(...));

    // ---------------------------------------------------- classificações
    Router::get('/api/categories', CategoryController::listar(...));
    Router::post('/api/categories', CategoryController::criar(...));
    Router::put('/api/categories/{id}', CategoryController::atualizar(...));
    Router::delete('/api/categories/{id}', CategoryController::excluir(...));

    // -------------------------------------------------------- parâmetros
    Router::get('/api/settings', SettingsController::listar(...));
    Router::put('/api/settings', SettingsController::salvar(...));

    // ------------------------------------------- importação e exportação
    Router::post('/api/import', ImportController::importar(...));
    Router::get('/api/export/modelo', ExportController::modelo(...));
    Router::get('/api/export', ExportController::dados(...));

    // ------------------------------------------------------- CRUD simples
    // O segundo argumento é a chave do registro em CrudController; o nome real
    // da tabela nunca vem da URL.
    Router::crud('/api/projects', 'projects');
    Router::crud('/api/investments', 'investments');
    Router::crud('/api/assets', 'assets');
    Router::crud('/api/debts', 'debts');
    Router::crud('/api/institutions', 'institutions');
    Router::crud('/api/people', 'people');
    Router::crud('/api/ofx', 'ofx');
    Router::crud('/api/card-payments', 'card-payments');
}

/**
 * Serve o index com ?v=<mtime> no CSS e no JS.
 *
 * Sem isso o navegador pode continuar usando um style.css antigo mesmo com o
 * HTML novo — a URL não mudou, então ele nem pergunta ao servidor. Com o mtime
 * na query, cada alteração de arquivo vira uma URL nova.
 */
function servirIndex(): void
{
    $estatico = __DIR__ . '/static';
    $html = file_get_contents($estatico . '/index.html');
    if ($html === false) {
        Http::erro(500, 'Interface indisponível.');
    }

    $versionados = ['/static/style.css', '/static/app.js', '/static/tema.js'];
    $v = 0;
    foreach ($versionados as $p) {
        $arquivo = $estatico . substr($p, strlen('/static'));
        $mtime = @filemtime($arquivo);
        if ($mtime !== false) {
            $v = max($v, $mtime);
        }
    }
    foreach ($versionados as $p) {
        $html = str_replace($p, $p . '?v=' . $v, $html);
    }

    header('Content-Type: text/html; charset=utf-8');
    // Obriga o navegador a revalidar a cada carregamento: sem isso ele guarda
    // o index por heurística própria e continua exibindo a interface antiga
    // depois de um deploy. Com ETag, a revalidação custa um 304.
    header('Cache-Control: no-cache');
    echo $html;
}
