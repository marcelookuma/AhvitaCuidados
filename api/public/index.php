<?php
declare(strict_types=1);

/**
 * Porta de entrada da API (fica em <pasta pública>/api/index.php na KingHost).
 * O código e a configuração ficam FORA da área pública. O caminho até eles vem,
 * nesta ordem, de: variável CUIDADOS_PRIVADO; privado.php (gerado no empacotamento);
 * ../../privado (padrão); ou, em desenvolvimento, a pasta api/ ao lado.
 */
$privado = getenv('CUIDADOS_PRIVADO') ?: '';
if ($privado === '' && is_file(__DIR__ . '/privado.php')) {
    $privado = __DIR__ . '/' . (require __DIR__ . '/privado.php');
}
if ($privado === '' || !is_file($privado . '/src/bootstrap.php')) {
    $privado = dirname(__DIR__, 2) . '/privado';
}
if (!is_file($privado . '/src/bootstrap.php')) {
    $privado = dirname(__DIR__); // desenvolvimento: api/public/index.php ao lado de api/src
}
if (!is_file($privado . '/src/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"erro":{"codigo":"instalacao","mensagem":"Pasta privada da API não encontrada. Confira KINGHOST_PASTA_PRIVADO."}}';
    exit;
}
require $privado . '/src/bootstrap.php';

use Cuidados\Http\Kernel;
use Cuidados\Http\Requisicao;

$base = getenv('CUIDADOS_BASE') ?: '/api/v1';
Kernel::atender(Requisicao::doServidor($base))->enviar();
