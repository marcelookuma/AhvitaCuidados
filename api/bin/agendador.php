<?php
declare(strict_types=1);

/**
 * Tarefas periódicas. Na KingHost: Painel → Agendador de tarefas (cron),
 * a cada 5 minutos:  php /home/<usuario>/privado/bin/agendador.php
 *
 * Sem cron o app continua correto: os painéis do gestor e da empresa
 * também verificam a cobertura quando são abertos.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Cuidados\Modulos\Avisos;
use Cuidados\Support\Migracoes;
use Cuidados\Support\Bd;
use Cuidados\Support\Tempo;

// Depois de cada publicação pelo GitHub, aplica migrações novas do banco.
$migr = Migracoes::aplicar(Migracoes::pasta());
if ($migr) echo date('c') . ' migrações aplicadas: ' . implode(', ', $migr) . "\n";

$n = Avisos::verificarCobertura();

// Outbox: aqui entram push (FCM/APNs/Web Push) e WhatsApp quando forem ligados.
// Por enquanto marca como processado o que tem mais de 7 dias e limpa códigos vencidos.
Bd::exec('UPDATE eventos_saida SET processado_em = :a WHERE processado_em IS NULL AND criado_em < :lim',
    ['a' => Tempo::agoraSql(), 'lim' => Tempo::agora()->modify('-7 days')->format('Y-m-d H:i:s')]);
Bd::exec('DELETE FROM otp_codigos WHERE criado_em < :lim', ['lim' => Tempo::agora()->modify('-2 days')->format('Y-m-d H:i:s')]);
Bd::exec('DELETE FROM sessoes WHERE expira_em < :lim', ['lim' => Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s')]);

echo date('c') . " cobertura: $n alerta(s)\n";
