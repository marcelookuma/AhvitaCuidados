<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use DateTimeImmutable;

/**
 * A agenda pertence ao(à) cuidador(a).
 *  - MEI/autônomo (inclusive quando vem por empresa como prestador(a)):
 *    proposta só dentro da disponibilidade que ele(a) marcou, e sempre com aceite.
 *  - CLT: a empresa organiza a escala, mas ainda checamos choque de horário. (termos-ok)
 * A tela do gestor consulta GET /pessoas/{id}/cuidadores/{c}/disponivel antes de enviar.
 */
final class RegrasAgenda
{
    public const MODALIDADES_AUTONOMAS = ['mei', 'autonomo', 'empresa'];

    public static function validarProposta(string $cuidadorId, ?string $modalidade, string $inicioSql, string $fimSql, ?string $ignorarId = null): void
    {
        $ini = Tempo::utc($inicioSql);
        $fim = Tempo::utc($fimSql);
        if ($fim <= $ini) {
            throw ErroHttp::validacao(['fim' => 'O fim precisa ser depois do início']);
        }
        if ($fim->getTimestamp() - $ini->getTimestamp() > 24 * 3600) {
            throw ErroHttp::validacao(['fim' => 'Um atendimento pode ter até 24 horas']);
        }
        if ($ini < Tempo::agora()) {
            throw ErroHttp::validacao(['inicio' => 'Escolha um horário no futuro']);
        }
        if ($modalidade === null || in_array($modalidade, self::MODALIDADES_AUTONOMAS, true)) {
            if (!self::dentroDaDisponibilidade($cuidadorId, $ini, $fim)) {
                throw ErroHttp::conflito('fora_da_agenda', 'Fora da agenda do(a) cuidador(a). Proponha um horário em que ele(a) atende.');
            }
        }
        if (self::temBloqueio($cuidadorId, $inicioSql, $fimSql)) {
            throw ErroHttp::conflito('agenda_bloqueada', 'O(a) cuidador(a) bloqueou esse período na agenda.');
        }
        if (self::temChoque($cuidadorId, $inicioSql, $fimSql, $ignorarId)) {
            throw ErroHttp::conflito('choque', 'O(a) cuidador(a) já tem atendimento combinado nesse horário.');
        }
    }

    public static function dentroDaDisponibilidade(string $cuidadorId, DateTimeImmutable $iniUtc, DateTimeImmutable $fimUtc): bool
    {
        $fuso = Tempo::fuso();
        $ini = $iniUtc->setTimezone($fuso);
        $fim = $fimUtc->setTimezone($fuso);
        $disp = Bd::todos('SELECT dia_semana, inicio, fim FROM disponibilidades WHERE usuario_id = :u', ['u' => $cuidadorId]);
        // Janelas que começam no dia do início ou no dia anterior (as que viram a noite).
        foreach ([$ini->modify('-1 day'), $ini] as $dia) {
            $dow = (int)$dia->format('w');
            foreach ($disp as $d) {
                if ((int)$d['dia_semana'] !== $dow) continue;
                $jIni = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $d['inicio'], $fuso);
                $jFim = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $d['fim'], $fuso);
                if ($jFim <= $jIni) $jFim = $jFim->modify('+1 day');
                if ($ini >= $jIni && $fim <= $jFim) return true;
            }
        }
        return false;
    }

    private static function temBloqueio(string $cuidadorId, string $ini, string $fim): bool
    {
        return (int)Bd::valor(
            'SELECT COUNT(*) FROM bloqueios_agenda WHERE usuario_id = :u AND inicio < :f AND fim > :i',
            ['u' => $cuidadorId, 'i' => $ini, 'f' => $fim]
        ) > 0;
    }

    private static function temChoque(string $cuidadorId, string $ini, string $fim, ?string $ignorarId): bool
    {
        return (int)Bd::valor(
            "SELECT COUNT(*) FROM atendimentos
              WHERE cuidador_id = :u AND status = 'aceito' AND inicio < :f AND fim > :i AND id <> :ign",
            ['u' => $cuidadorId, 'i' => $ini, 'f' => $fim, 'ign' => $ignorarId ?? '']
        ) > 0;
    }
}
