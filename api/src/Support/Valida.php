<?php
declare(strict_types=1);

namespace Cuidados\Support;

/**
 * Validação de entrada. Junta os erros por campo para a tela mostrar
 * cada um junto do seu campo.
 */
final class Valida
{
    /** @var array<string,string> */
    private array $erros = [];

    /** @param array<string,mixed> $dados */
    public function __construct(private array $dados) {}

    /** @param array<string,mixed> $dados */
    public static function de(array $dados): self
    {
        return new self($dados);
    }

    public function texto(string $campo, bool $obrigatorio = true, int $max = 255, int $min = 1): ?string
    {
        $v = $this->dados[$campo] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($obrigatorio) $this->erros[$campo] = 'Preencha este campo';
            return null;
        }
        if (!is_string($v) && !is_numeric($v)) {
            $this->erros[$campo] = 'Valor inválido';
            return null;
        }
        $v = trim((string)$v);
        if (mb_strlen($v) > $max) $this->erros[$campo] = "Use até $max caracteres";
        if (mb_strlen($v) < $min) $this->erros[$campo] = "Use pelo menos $min caracteres";
        return $v;
    }

    /** @param list<string> $opcoes */
    public function opcao(string $campo, array $opcoes, bool $obrigatorio = true, ?string $padrao = null): ?string
    {
        $v = $this->dados[$campo] ?? $padrao;
        if ($v === null || $v === '') {
            if ($obrigatorio) $this->erros[$campo] = 'Escolha uma opção';
            return null;
        }
        if (!in_array($v, $opcoes, true)) {
            $this->erros[$campo] = 'Opção inválida';
            return null;
        }
        return $v;
    }

    public function data(string $campo, bool $obrigatorio = true): ?string
    {
        $v = $this->texto($campo, $obrigatorio, 10);
        if ($v === null) return null;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->erros[$campo] = 'Data inválida';
            return null;
        }
        return $v;
    }

    public function dataHora(string $campo, bool $obrigatorio = true): ?string
    {
        $v = $this->texto($campo, $obrigatorio, 40);
        if ($v === null) return null;
        try {
            return Tempo::isoParaSql($v);
        } catch (\Exception) {
            $this->erros[$campo] = 'Data e hora inválidas';
            return null;
        }
    }

    public function hora(string $campo, bool $obrigatorio = true): ?string
    {
        $v = $this->texto($campo, $obrigatorio, 5);
        if ($v === null) return null;
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            $this->erros[$campo] = 'Hora inválida';
            return null;
        }
        return $v;
    }

    public function inteiro(string $campo, bool $obrigatorio = true, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $v = $this->dados[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($obrigatorio) $this->erros[$campo] = 'Preencha este campo';
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            $this->erros[$campo] = 'Use um número inteiro';
            return null;
        }
        $v = (int)$v;
        if ($v < $min || $v > $max) {
            $this->erros[$campo] = "Use um valor entre $min e $max";
            return null;
        }
        return $v;
    }

    public function booleano(string $campo, bool $padrao = false): bool
    {
        $v = $this->dados[$campo] ?? $padrao;
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    public function cpf(string $campo, bool $obrigatorio = true): ?string
    {
        $v = $this->texto($campo, $obrigatorio, 20);
        if ($v === null) return null;
        $d = preg_replace('/\D/', '', $v) ?? '';
        if (!self::cpfValido($d)) {
            $this->erros[$campo] = 'CPF inválido';
            return null;
        }
        return $d;
    }

    public function cnpj(string $campo): ?string
    {
        $v = $this->texto($campo, true, 20);
        if ($v === null) return null;
        $d = preg_replace('/\D/', '', $v) ?? '';
        if (!self::cnpjValido($d)) {
            $this->erros[$campo] = 'CNPJ inválido';
            return null;
        }
        return $d;
    }

    public function ulid(string $campo, bool $obrigatorio = true): ?string
    {
        $v = $this->dados[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($obrigatorio) $this->erros[$campo] = 'Identificador ausente';
            return null;
        }
        if (!Ulid::valido($v)) {
            $this->erros[$campo] = 'Identificador inválido';
            return null;
        }
        return $v;
    }

    /** @return array<mixed>|null */
    public function lista(string $campo, bool $obrigatorio = true, int $max = 50): ?array
    {
        $v = $this->dados[$campo] ?? null;
        if ($v === null) {
            if ($obrigatorio) $this->erros[$campo] = 'Preencha este campo';
            return null;
        }
        if (!is_array($v) || !array_is_list($v) || count($v) > $max) {
            $this->erros[$campo] = 'Lista inválida';
            return null;
        }
        return $v;
    }

    /** @return array<string,mixed>|null */
    public function objeto(string $campo, bool $obrigatorio = false, int $maxBytes = 20000): ?array
    {
        $v = $this->dados[$campo] ?? null;
        if ($v === null) {
            if ($obrigatorio) $this->erros[$campo] = 'Preencha este campo';
            return null;
        }
        if (!is_array($v) || (array_is_list($v) && $v !== [])) {
            $this->erros[$campo] = 'Formato inválido';
            return null;
        }
        if (strlen((string)json_encode($v)) > $maxBytes) {
            $this->erros[$campo] = 'Conteúdo muito grande';
            return null;
        }
        return $v;
    }

    public function erro(string $campo, string $msg): void
    {
        $this->erros[$campo] = $msg;
    }

    public function validar(): void
    {
        if ($this->erros !== []) {
            throw ErroHttp::validacao($this->erros);
        }
    }

    public static function cpfValido(string $d): bool
    {
        if (!preg_match('/^\d{11}$/', $d) || preg_match('/^(\d)\1{10}$/', $d)) return false;
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) $s += (int)$d[$i] * ($t + 1 - $i);
            $dv = ((10 * $s) % 11) % 10;
            if ((int)$d[$t] !== $dv) return false;
        }
        return true;
    }

    public static function cnpjValido(string $d): bool
    {
        if (!preg_match('/^\d{14}$/', $d) || preg_match('/^(\d)\1{13}$/', $d)) return false;
        $pesos = [[5,4,3,2,9,8,7,6,5,4,3,2], [6,5,4,3,2,9,8,7,6,5,4,3,2]];
        foreach ([12, 13] as $n => $pos) {
            $s = 0;
            foreach ($pesos[$n] as $i => $p) $s += (int)$d[$i] * $p;
            $r = $s % 11;
            if ((int)$d[$pos] !== ($r < 2 ? 0 : 11 - $r)) return false;
        }
        return true;
    }

    /** Normaliza telefone brasileiro para E.164 (+55…). */
    public static function telefone(string $v): ?string
    {
        $d = preg_replace('/\D/', '', $v) ?? '';
        if (str_starts_with($d, '55') && strlen($d) >= 12) $d = substr($d, 2);
        if (strlen($d) < 10 || strlen($d) > 11) return null;
        return '+55' . $d;
    }

    public static function email(string $v): ?string
    {
        $v = mb_strtolower(trim($v));
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null;
    }
}
