<?php
declare(strict_types=1);

// Só pela linha de comando: nunca pela web (a pasta privada pode estar sob nginx).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Tarefas periódicas pelo cron, se a hospedagem tiver:
 *   php <pasta privada>/bin/agendador.php   (a cada 5 minutos)
 * Sem cron, a API roda as mesmas tarefas sozinha (ver Support/Tarefas.php).
 */
require dirname(__DIR__) . '/src/bootstrap.php';

$r = \Cuidados\Support\Tarefas::executar();
if ($r['migracoes']) echo date('c') . ' migrações aplicadas: ' . implode(', ', $r['migracoes']) . "\n";
echo date('c') . " cobertura: {$r['alertas']} alerta(s)\n";
