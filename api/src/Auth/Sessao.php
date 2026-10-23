<?php
declare(strict_types=1);

namespace Cuidados\Auth;

use Cuidados\Http\Requisicao;
use Cuidados\Http\Resposta;
use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;

/**
 * Sessão por token opaco (64 hex). Guardamos só o SHA-256.
 *  - Web: cookie httpOnly + SameSite=Lax; escrita exige o cabeçalho X-Cuidados (anti-CSRF).
 *  - App Android/iOS: "Authorization: Bearer <token>" guardado no cofre do aparelho.
 */
final class Sessao
{
    /** @return array{token:string,id:string} */
    public static function criar(string $usuarioId, ?string $dispositivo): array
    {
        $token = bin2hex(random_bytes(32));
        $id = Ulid::novo();
        $agora = Tempo::agora();
        Bd::inserir('sessoes', [
            'id' => $id,
            'usuario_id' => $usuarioId,
            'token_hash' => hash('sha256', $token),
            'dispositivo' => $dispositivo !== null ? mb_substr($dispositivo, 0, 160) : null,
            'criado_em' => $agora->format('Y-m-d H:i:s'),
            'ultimo_uso_em' => $agora->format('Y-m-d H:i:s'),
            'expira_em' => $agora->modify('+' . (int)Config::get('sessao.dias') . ' days')->format('Y-m-d H:i:s'),
        ]);
        return ['token' => $token, 'id' => $id];
    }

    public static function cookie(Resposta $r, string $token, bool $apagar = false): void
    {
        $nome = (string)Config::get('sessao.cookie');
        $seguro = !Config::emTeste();
        $partes = [
            $nome . '=' . ($apagar ? '' : $token),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Max-Age=' . ($apagar ? 0 : (int)Config::get('sessao.dias') * 86400),
        ];
        if ($seguro) $partes[] = 'Secure';
        $r->cookies[] = implode('; ', $partes);
    }

    /** @return array{usuario:array<string,mixed>,sessao_id:string,via_cookie:bool}|null */
    public static function atual(Requisicao $req): ?array
    {
        $token = null;
        $viaCookie = false;
        $auth = $req->cabecalho('authorization');
        if ($auth !== null && preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $auth, $m)) {
            $token = strtolower($m[1]);
        } else {
            $c = $_COOKIE[(string)Config::get('sessao.cookie')] ?? null;
            if (is_string($c) && preg_match('/^[0-9a-f]{64}$/', $c)) {
                $token = $c;
                $viaCookie = true;
            }
        }
        if ($token === null) return null;

        $s = Bd::um(
            'SELECT s.id AS sessao_id, s.ultimo_uso_em, u.*
               FROM sessoes s JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.token_hash = :h AND s.revogada_em IS NULL AND s.expira_em > :agora AND u.excluido_em IS NULL AND u.bloqueado_em IS NULL',
            ['h' => hash('sha256', $token), 'agora' => Tempo::agoraSql()]
        );
        if ($s === null) return null;

        if ($viaCookie && !in_array($req->metodo, ['GET', 'HEAD', 'OPTIONS'], true) && $req->cabecalho('x-cuidados') === null) {
            throw ErroHttp::proibido('Envio bloqueado por segurança. Atualize a página.');
        }
        if (Tempo::utc($s['ultimo_uso_em']) < Tempo::agora()->modify('-5 minutes')) {
            Bd::exec('UPDATE sessoes SET ultimo_uso_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $s['sessao_id']]);
        }
        $sessaoId = $s['sessao_id'];
        unset($s['sessao_id'], $s['ultimo_uso_em']);
        return ['usuario' => $s, 'sessao_id' => $sessaoId, 'via_cookie' => $viaCookie];
    }

    public static function revogar(string $sessaoId): void
    {
        Bd::exec('UPDATE sessoes SET revogada_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $sessaoId]);
    }

    public static function revogarTodas(string $usuarioId): void
    {
        Bd::exec('UPDATE sessoes SET revogada_em = :a WHERE usuario_id = :u AND revogada_em IS NULL', ['a' => Tempo::agoraSql(), 'u' => $usuarioId]);
    }
}
