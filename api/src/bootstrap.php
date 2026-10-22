<?php
declare(strict_types=1);

/**
 * Inicialização da API. Sem Composer: o autoload abaixo segue PSR-4
 * (namespace Cuidados\ => src/), então dá para migrar para Composer
 * depois sem mudar nenhuma classe.
 */

define('CUIDADOS_SRC', __DIR__);
define('CUIDADOS_RAIZ', dirname(__DIR__));

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'Cuidados\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
        return;
    }
    $relativo = str_replace('\\', '/', substr($classe, strlen($prefixo)));
    $arquivo = CUIDADOS_SRC . '/' . $relativo . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

\Cuidados\Support\Config::carregar();

// Erros do PHP vão para <pasta privada>/logs/erros.log.php (o .php com bloqueio
// no início impede abrir pela web). Sem dados de saúde: só mensagens técnicas.
if (PHP_SAPI !== 'cli' && !getenv('CUIDADOS_SEM_LOG')) {
    // Tenta <pasta privada>/logs e, se o PHP não puder criar, a pasta de mídias.
    foreach ([CUIDADOS_RAIZ . '/logs', (string)\Cuidados\Support\Config::get('midias.pasta')] as $pastaLog) {
        $log = rtrim($pastaLog, '/') . '/erros.log.php';
        if (!is_file($log) && (is_dir($pastaLog) || @mkdir($pastaLog, 0750, true))) {
            @file_put_contents($log, "<?php http_response_code(404); exit; ?>\n");
        }
        if (is_file($log) && is_writable($log)) {
            if (filesize($log) > 2_000_000) @file_put_contents($log, "<?php http_response_code(404); exit; ?>\n");
            ini_set('log_errors', '1');
            ini_set('error_log', $log);
            break;
        }
    }
}
