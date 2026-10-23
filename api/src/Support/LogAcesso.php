<?php
declare(strict_types=1);

namespace Cuidados\Support;

use Cuidados\Http\Requisicao;

/**
 * Log de acessos e usos de todos os perfis (e da equipe do painel /admin).
 *
 * O que vira uma linha em log_acessos:
 *   - entrar, sair e tentativas recusadas;
 *   - toda escrita (POST/PUT/PATCH/DELETE);
 *   - todo erro (status >= 400) em rota existente;
 *   - leituras sensíveis (exportação de dados);
 *   - tudo o que a equipe faz no painel /admin, inclusive leituras.
 * Leituras de rotina (a conversa consulta a cada 5 s) só somam um contador por
 * dia em uso_diario — sem horário, para não virar medição de tempo de ninguém.
 *
 * Nunca grava corpo da requisição, parâmetros de busca nem dado de saúde:
 * só quem, quando, de onde, qual rota (padrão) e o resultado.
 * Uma falha aqui nunca derruba a requisição.
 */
final class LogAcesso
{
    /** Leituras que sempre geram linha própria. */
    private const LEITURAS_SENSIVEIS = ['/eu/exportar', '/pessoas/{id}/exportar'];
    private const ROTAS_ENTRADA = ['/auth/entrar', '/auth/google', '/admin/entrar'];

    /** @var array<string,?string> */
    private static array $ctx = [];

    public static function reiniciar(): void
    {
        self::$ctx = ['ator' => 'anonimo', 'usuario_id' => null, 'admin_id' => null, 'sessao_id' => null,
            'perfil' => null, 'pessoa_id' => null, 'empresa_id' => null, 'evento' => null];
    }

    public static function usuario(?string $usuarioId, ?string $sessaoId): void
    {
        if ($usuarioId === null) return;
        self::$ctx['ator'] = 'usuario';
        self::$ctx['usuario_id'] = $usuarioId;
        self::$ctx['sessao_id'] = $sessaoId;
    }

    public static function admin(string $adminId, ?string $sessaoId): void
    {
        self::$ctx['ator'] = 'admin';
        self::$ctx['admin_id'] = $adminId;
        self::$ctx['sessao_id'] = $sessaoId;
        self::$ctx['perfil'] = 'admin';
    }

    /** Perfil em que a pessoa agiu nesta requisição (vem da autorização central). Vale o primeiro. */
    public static function perfil(string $perfil, ?string $pessoaId = null, ?string $empresaId = null): void
    {
        self::$ctx['perfil'] ??= $perfil;
        self::$ctx['pessoa_id'] ??= $pessoaId;
        self::$ctx['empresa_id'] ??= $empresaId;
    }

    public static function evento(string $evento): void
    {
        self::$ctx['evento'] = $evento;
    }

    /** @param array<string,string> $params */
    public static function gravar(Requisicao $req, int $status, ?string $rota, array $params, float $inicio): void
    {
        if ($req->metodo === 'OPTIONS' || !Config::configurado()) return;
        if (self::$ctx === []) self::reiniciar();
        try {
            $c = self::$ctx;
            if ($rota === null && $c['ator'] === 'anonimo') return; // robôs batendo em endereços que não existem
            if ($rota !== null && str_starts_with($rota, '/agenda')) self::perfil('cuidador');
            $c = self::$ctx;

            $individual = $c['ator'] === 'admin'
                || ($rota !== null && str_starts_with($rota, '/admin'))
                || !in_array($req->metodo, ['GET', 'HEAD'], true)
                || $status >= 400
                || in_array($rota, self::LEITURAS_SENSIVEIS, true);

            if (!$individual) {
                if ($c['usuario_id'] === null) return;
                $dia = Tempo::local(Tempo::agora())->format('Y-m-d');
                Bd::exec(
                    'INSERT INTO uso_diario (dia, usuario_id, perfil, rota, contexto_id, requisicoes)
                     VALUES (:d, :u, :p, :r, :c, 1)
                     ON DUPLICATE KEY UPDATE requisicoes = requisicoes + 1',
                    ['d' => $dia, 'u' => $c['usuario_id'], 'p' => $c['perfil'] ?? '', 'r' => (string)$rota,
                        'c' => $c['pessoa_id'] ?? $c['empresa_id'] ?? '']
                );
                return;
            }

            $evento = $c['evento'];
            if ($evento === null && in_array($rota, self::ROTAS_ENTRADA, true) && $status >= 400) {
                $evento = $rota === '/admin/entrar' ? 'admin.entrada_recusada' : 'entrada_recusada';
            }
            $recurso = $params['id'] ?? $params['codigo'] ?? null;
            if (isset($params['codigo'])) $recurso = substr($params['codigo'], 0, 2) . '…'; // código de convite não vai inteiro
            self::inserir([
                'ator' => $c['ator'],
                'usuario_id' => $c['usuario_id'],
                'admin_id' => $c['admin_id'],
                'sessao_id' => $c['sessao_id'],
                'perfil' => $c['perfil'],
                'evento' => $evento ?? 'requisicao',
                'metodo' => $req->metodo,
                'rota' => $rota !== null ? mb_substr($rota, 0, 80) : mb_substr($req->caminho, 0, 80),
                'recurso_id' => $recurso !== null ? mb_substr($recurso, 0, 40) : null,
                'pessoa_id' => $c['pessoa_id'],
                'empresa_id' => $c['empresa_id'],
                'status' => $status,
                'duracao_ms' => (int)max(0, round((microtime(true) - $inicio) * 1000)),
                'ip' => $req->ip,
                'cliente' => $req->cabecalho('authorization') !== null ? 'app' : 'web',
                'dispositivo' => self::dispositivo($req),
            ]);
        } catch (\Throwable $e) {
            error_log('[cuidados] log de acessos: ' . $e->getMessage());
        } finally {
            self::reiniciar();
        }
    }

    /** @param array<string,mixed> $linha */
    public static function inserir(array $linha): void
    {
        Bd::inserir('log_acessos', $linha + ['criado_em' => Tempo::agoraSql()]);
    }

    public static function dispositivo(Requisicao $req): ?string
    {
        $ua = $req->cabecalho('user-agent');
        return $ua === null ? null : mb_substr($ua, 0, 160);
    }

    /** Apaga o que passou do prazo de guarda (nunca menos de 6 meses). */
    public static function limpar(): void
    {
        $dias = max(183, (int)Config::get('logs.dias', 365));
        $lim = Tempo::agora()->modify("-$dias days");
        Bd::exec('DELETE FROM log_acessos WHERE criado_em < :l', ['l' => $lim->format('Y-m-d H:i:s')]);
        Bd::exec('DELETE FROM uso_diario WHERE dia < :d', ['d' => $lim->format('Y-m-d')]);
        Bd::exec('DELETE FROM admin_sessoes WHERE expira_em < :l', ['l' => Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s')]);
    }
}
