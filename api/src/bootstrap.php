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
