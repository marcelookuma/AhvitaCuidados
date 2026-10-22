<?php
declare(strict_types=1);

namespace Cuidados\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Datas: banco em UTC; regras de agenda em hora local (America/Sao_Paulo). */
final class Tempo
{
    private static ?DateTimeImmutable $fixo = null;

    /** Só para testes: congela "agora". */
    public static function fixar(?DateTimeImmutable $t): void
    {
        self::$fixo = $t;
    }

    public static function agora(): DateTimeImmutable
    {
        return self::$fixo ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function agoraSql(): string
    {
        return self::agora()->format('Y-m-d H:i:s');
    }

    public static function fuso(): DateTimeZone
    {
        return new DateTimeZone((string)Config::get('fuso', 'America/Sao_Paulo'));
    }

    /** Converte ISO 8601 (com fuso) para DATETIME UTC. */
    public static function isoParaSql(string $iso): string
    {
        $d = new DateTimeImmutable($iso);
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function sqlParaIso(?string $sql): ?string
    {
        if ($sql === null) return null;
        return (new DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    public static function utc(string $sql): DateTimeImmutable
    {
        return new DateTimeImmutable($sql, new DateTimeZone('UTC'));
    }

    public static function local(DateTimeImmutable $d): DateTimeImmutable
    {
        return $d->setTimezone(self::fuso());
    }

    /**
     * Hora do aparelho aceitável: não pode estar no futuro (+5 min de folga)
     * nem mais de 7 dias no passado (fila offline longa).
     */
    public static function horaDoAparelho(?string $iso): string
    {
        $agora = self::agora();
        if ($iso === null || $iso === '') return $agora->format('Y-m-d H:i:s');
        try {
            $d = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw ErroHttp::validacao(['ocorrido_em' => 'Data inválida']);
        }
        if ($d > $agora->modify('+5 minutes')) $d = $agora;
        if ($d < $agora->modify('-7 days')) {
            throw ErroHttp::validacao(['ocorrido_em' => 'Registro muito antigo para envio automático']);
        }
        return $d->format('Y-m-d H:i:s');
    }

    /** Início e fim do dia local de hoje, em DATETIME UTC. @return array{0:string,1:string} */
    public static function diaLocalEmUtc(?DateTimeImmutable $em = null): array
    {
        $local = self::local($em ?? self::agora())->setTime(0, 0);
        $utc = new DateTimeZone('UTC');
        return [$local->setTimezone($utc)->format('Y-m-d H:i:s'), $local->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    public static function idade(string $nascimento, ?DateTimeImmutable $em = null): int
    {
        $em ??= self::local(self::agora());
        return (int)(new DateTimeImmutable($nascimento, self::fuso()))->diff($em)->y;
    }
}
