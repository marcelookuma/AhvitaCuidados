<?php
declare(strict_types=1);

// Só pela linha de comando: nunca pela web (a pasta privada pode estar sob nginx).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Cria/atualiza as tabelas (alternativa ao phpMyAdmin).
 *   php bin/instalar.php            → aplica db/*.sql que ainda não foram aplicados
 *   php bin/instalar.php --demo     → também carrega dados de demonstração (nunca em produção)
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Cuidados\Support\Migracoes;

$novas = Migracoes::aplicar(Migracoes::pasta());
echo $novas ? '✓ aplicadas: ' . implode(', ', $novas) . "\n" : "· banco já atualizado\n";

if (in_array('--demo', $argv, true)) {
    require __DIR__ . '/demo.php';
}
echo "Pronto.\n";
