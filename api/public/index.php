<?php
declare(strict_types=1);

/**
 * Porta de entrada da API (fica em www/api/index.php na KingHost).
 * O código e a configuração ficam FORA da pasta pública, em ../../privado
 * (ou no caminho definido em CUIDADOS_PRIVADO).
 */
$privado = getenv('CUIDADOS_PRIVADO') ?: dirname(__DIR__, 2) . '/privado';
if (!is_file($privado . '/src/bootstrap.php')) {
    // Desenvolvimento: api/public/index.php ao lado de api/src
    $privado = dirname(__DIR__);
}
require $privado . '/src/bootstrap.php';

use Cuidados\Http\Kernel;
use Cuidados\Http\Requisicao;

$base = getenv('CUIDADOS_BASE') ?: '/api/v1';
Kernel::atender(Requisicao::doServidor($base))->enviar();
