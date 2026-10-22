<?php
declare(strict_types=1);

namespace Cuidados\Support;

use PDO;
use PDOStatement;

/**
 * Acesso ao MySQL/MariaDB via PDO, com consultas sempre parametrizadas.
 * Repositórios usam este wrapper; trocar de banco (ex.: PostgreSQL na fase
 * de crescimento) fica concentrado aqui e nos SQLs dos módulos.
 */
final class Bd
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('db.host'),
                (int)Config::get('db.porta'),
                Config::get('db.nome')
            );
            self::$pdo = new PDO($dsn, (string)Config::get('db.usuario'), (string)Config::get('db.senha'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5, // banco fora do ar não pode travar o site
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    /** @param array<string|int,mixed> $p */
    public static function exec(string $sql, array $p = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($p as $k => $v) {
            $nome = is_int($k) ? $k + 1 : ':' . ltrim((string)$k, ':');
            $tipo = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($nome, is_bool($v) ? (int)$v : $v, $tipo);
        }
        $st->execute();
        return $st;
    }

    /** @param array<string|int,mixed> $p @return array<string,mixed>|null */
    public static function um(string $sql, array $p = []): ?array
    {
        $linha = self::exec($sql, $p)->fetch();
        return $linha === false ? null : $linha;
    }

    /** @param array<string|int,mixed> $p @return list<array<string,mixed>> */
    public static function todos(string $sql, array $p = []): array
    {
        return self::exec($sql, $p)->fetchAll();
    }

    /** @param array<string|int,mixed> $p */
    public static function valor(string $sql, array $p = []): mixed
    {
        $v = self::exec($sql, $p)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $dados */
    public static function inserir(string $tabela, array $dados, bool $ignorar = false): int
    {
        $cols = array_keys($dados);
        $sql = sprintf(
            'INSERT %sINTO %s (%s) VALUES (%s)',
            $ignorar ? 'IGNORE ' : '',
            $tabela,
            implode(', ', $cols),
            implode(', ', array_map(fn($c) => ':' . $c, $cols))
        );
        return self::exec($sql, self::normalizar($dados))->rowCount();
    }

    /**
     * @param array<string,mixed> $dados
     * @param array<string,mixed> $onde
     */
    public static function atualizar(string $tabela, array $dados, array $onde): int
    {
        $sets = implode(', ', array_map(fn($c) => "$c = :s_$c", array_keys($dados)));
        $conds = implode(' AND ', array_map(fn($c) => "$c = :w_$c", array_keys($onde)));
        $p = [];
        foreach (self::normalizar($dados) as $k => $v) $p["s_$k"] = $v;
        foreach ($onde as $k => $v) $p["w_$k"] = $v;
        return self::exec("UPDATE $tabela SET $sets WHERE $conds", $p)->rowCount();
    }

    /** @template T @param callable():T $fn @return T */
    public static function transacao(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param list<mixed> $valores @return array{0:string,1:array<string,mixed>} */
    public static function lista(string $prefixo, array $valores): array
    {
        if ($valores === []) {
            return ['NULL', []];
        }
        $p = [];
        foreach (array_values($valores) as $i => $v) $p["{$prefixo}{$i}"] = $v;
        return [implode(', ', array_map(fn($k) => ':' . $k, array_keys($p))), $p];
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function normalizar(array $d): array
    {
        foreach ($d as $k => $v) {
            if (is_array($v)) {
                $d[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $d;
    }
}
