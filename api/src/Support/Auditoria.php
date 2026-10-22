<?php
declare(strict_types=1);

namespace Cuidados\Support;

/**
 * Trilha de auditoria encadeada por hash (sem gatilhos no banco).
 * Cada linha guarda o hash da anterior; alterar o passado quebra a cadeia.
 * Nunca grave dado de saúde aqui: só ação, entidade e o mínimo de contexto.
 */
final class Auditoria
{
    private static ?string $usuario = null;
    private static ?string $ip = null;

    public static function contexto(?string $usuarioId, ?string $ip): void
    {
        self::$usuario = $usuarioId;
        self::$ip = $ip;
    }

    /** @param array<string,mixed> $dados */
    public static function registrar(
        string $acao,
        ?string $entidade = null,
        ?string $entidadeId = null,
        ?string $pessoaId = null,
        array $dados = []
    ): void {
        Bd::transacao(function () use ($acao, $entidade, $entidadeId, $pessoaId, $dados) {
            // Trava a última linha para a cadeia não bifurcar com acessos simultâneos.
            $anterior = Bd::valor('SELECT hash FROM auditoria ORDER BY id DESC LIMIT 1 FOR UPDATE') ?? str_repeat('0', 64);
            $agora = Tempo::agoraSql();
            $json = $dados === [] ? null : self::canonico($dados);
            $hash = self::calcular((string)$anterior, self::$usuario, $acao, $entidade, $entidadeId, $pessoaId, $json, $agora);
            Bd::inserir('auditoria', [
                'usuario_id' => self::$usuario,
                'acao' => $acao,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'pessoa_id' => $pessoaId,
                'dados' => $json,
                'ip' => self::$ip,
                'criado_em' => $agora,
                'hash_anterior' => $anterior,
                'hash' => $hash,
            ]);
        });
    }

    /** @return array{ok:bool,linhas:int,quebra_em:int|null} */
    public static function verificar(): array
    {
        $anterior = str_repeat('0', 64);
        $n = 0;
        foreach (Bd::exec('SELECT * FROM auditoria ORDER BY id') as $l) {
            $n++;
            $dados = $l['dados'] === null ? null : self::canonico((array)json_decode($l['dados'], true));
            $esperado = self::calcular($anterior, $l['usuario_id'], $l['acao'], $l['entidade'], $l['entidade_id'], $l['pessoa_id'], $dados, $l['criado_em']);
            if ($l['hash_anterior'] !== $anterior || $l['hash'] !== $esperado) {
                return ['ok' => false, 'linhas' => $n, 'quebra_em' => (int)$l['id']];
            }
            $anterior = $l['hash'];
        }
        return ['ok' => true, 'linhas' => $n, 'quebra_em' => null];
    }

    /** JSON com chaves ordenadas: o MySQL 8 reordena o JSON guardado, o MariaDB não. */
    private static function canonico(array $d): string
    {
        $ordenar = function ($v) use (&$ordenar) {
            if (!is_array($v)) return is_float($v) ? (string)$v : $v;
            if (!array_is_list($v)) ksort($v);
            return array_map($ordenar, $v);
        };
        return (string)json_encode($ordenar($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function calcular(string $anterior, ?string $u, string $acao, ?string $ent, ?string $entId, ?string $p, ?string $json, string $em): string
    {
        return hash('sha256', implode('|', [$anterior, $u ?? '', $acao, $ent ?? '', $entId ?? '', $p ?? '', $json ?? '', $em]));
    }
}
