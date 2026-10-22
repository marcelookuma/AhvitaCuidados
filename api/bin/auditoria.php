<?php
declare(strict_types=1);

// Só pela linha de comando: nunca pela web (a pasta privada pode estar sob nginx).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Confere se a trilha de auditoria está íntegra: php bin/auditoria.php */
require dirname(__DIR__) . '/src/bootstrap.php';

$r = \Cuidados\Support\Auditoria::verificar();
echo $r['ok']
    ? "✓ Auditoria íntegra ({$r['linhas']} linhas)\n"
    : "✗ Cadeia quebrada na linha {$r['quebra_em']}\n";
exit($r['ok'] ? 0 : 1);
