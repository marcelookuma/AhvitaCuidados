<?php
declare(strict_types=1);

namespace Cuidados\Support;

/** ULID: 26 caracteres, ordenável por tempo. O app gera o mesmo formato offline. */
final class Ulid
{
    private const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function novo(?int $ms = null): string
    {
        $ms ??= (int)floor(microtime(true) * 1000);
        $tempo = '';
        for ($i = 9; $i >= 0; $i--) {
            $tempo = self::ALFABETO[$ms % 32] . $tempo;
            $ms = intdiv($ms, 32);
        }
        $aleatorio = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $aleatorio .= self::ALFABETO[ord($bytes[$i]) % 32];
        }
        return $tempo . $aleatorio;
    }

    public static function valido(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $v) === 1;
    }

    /** Código curto de convite, sem letras que confundem (0/O, 1/I/L). */
    public static function codigo(int $tamanho = 6): string
    {
        $alf = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $s = '';
        for ($i = 0; $i < $tamanho; $i++) {
            $s .= $alf[random_int(0, strlen($alf) - 1)];
        }
        return $s;
    }
}
