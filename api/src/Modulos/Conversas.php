<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Support\Bd;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;

/** Conversas no estilo WhatsApp: mensagens + leituras (✓✓ "viu"). */
final class Conversas
{
    public static function daPessoa(string $pessoaId): string
    {
        $id = Bd::valor('SELECT id FROM conversas WHERE pessoa_id = :p', ['p' => $pessoaId]);
        if ($id !== null) return (string)$id;
        $id = Ulid::novo();
        Bd::inserir('conversas', ['id' => $id, 'tipo' => 'cuidado', 'pessoa_id' => $pessoaId, 'criado_em' => Tempo::agoraSql()], true);
        return (string)Bd::valor('SELECT id FROM conversas WHERE pessoa_id = :p', ['p' => $pessoaId]);
    }

    public static function direta(string $empresaId, string $cuidadorId): string
    {
        $id = Bd::valor('SELECT id FROM conversas WHERE empresa_id = :e AND usuario_id = :u', ['e' => $empresaId, 'u' => $cuidadorId]);
        if ($id !== null) return (string)$id;
        Bd::inserir('conversas', ['id' => Ulid::novo(), 'tipo' => 'direta', 'empresa_id' => $empresaId, 'usuario_id' => $cuidadorId, 'criado_em' => Tempo::agoraSql()], true);
        return (string)Bd::valor('SELECT id FROM conversas WHERE empresa_id = :e AND usuario_id = :u', ['e' => $empresaId, 'u' => $cuidadorId]);
    }

    /**
     * Idempotente: o aparelho manda o mesmo id ao reenviar após ficar sem internet.
     * @return array<string,mixed>
     */
    public static function enviar(string $conversaId, ?string $autorId, string $tipo, ?string $texto, ?string $midiaId = null, ?string $id = null, ?string $criadoEm = null): array
    {
        $id ??= Ulid::novo();
        $agora = Tempo::agoraSql();
        $nova = Bd::inserir('mensagens', [
            'id' => $id,
            'conversa_id' => $conversaId,
            'autor_id' => $autorId,
            'tipo' => $tipo,
            'texto' => $texto,
            'midia_id' => $midiaId,
            'criado_em' => $criadoEm ?? $agora,
            'recebido_em' => $agora,
        ], true) > 0;
        $m = Bd::um('SELECT * FROM mensagens WHERE id = :id AND conversa_id = :c', ['id' => $id, 'c' => $conversaId]);
        if ($m === null) {
            throw \Cuidados\Support\ErroHttp::conflito('id_em_uso', 'Envio duplicado. Atualize a conversa.');
        }
        if ($autorId !== null) {
            self::marcarVisto($conversaId, $autorId);
            // Aviso no celular para os outros participantes (só na primeira vez; reenvio do aparelho não repete).
            if ($nova && $tipo !== 'sistema') {
                Avisos::evento('mensagem', null, ['conversa_id' => $conversaId, 'autor_id' => $autorId]);
            }
        }
        return $m;
    }

    public static function sistema(string $conversaId, string $texto): void
    {
        self::enviar($conversaId, null, 'sistema', $texto);
    }

    public static function marcarVisto(string $conversaId, string $usuarioId, ?string $ate = null): void
    {
        $ate ??= Tempo::agoraSql();
        Bd::exec(
            'INSERT INTO conversa_leituras (conversa_id, usuario_id, visto_ate) VALUES (:c, :u, :a)
             ON DUPLICATE KEY UPDATE visto_ate = GREATEST(visto_ate, VALUES(visto_ate))',
            ['c' => $conversaId, 'u' => $usuarioId, 'a' => $ate]
        );
    }

    /** Até quando "os outros" viram — base do ✓✓ azul. */
    public static function vistoPorOutros(string $conversaId, string $usuarioId): ?string
    {
        $v = Bd::valor('SELECT MAX(visto_ate) FROM conversa_leituras WHERE conversa_id = :c AND usuario_id <> :u', ['c' => $conversaId, 'u' => $usuarioId]);
        return $v === null ? null : Tempo::sqlParaIso((string)$v);
    }

    public static function naoLidas(string $conversaId, string $usuarioId): int
    {
        $visto = Bd::valor('SELECT visto_ate FROM conversa_leituras WHERE conversa_id = :c AND usuario_id = :u', ['c' => $conversaId, 'u' => $usuarioId]);
        return (int)Bd::valor(
            'SELECT COUNT(*) FROM mensagens WHERE conversa_id = :c AND (autor_id IS NULL OR autor_id <> :u) AND recebido_em > :v',
            ['c' => $conversaId, 'u' => $usuarioId, 'v' => $visto ?? '1970-01-01 00:00:00']
        );
    }
}
