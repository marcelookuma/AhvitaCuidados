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
 * Sessão do painel /admin — separada da sessão do app:
 *  - cookie próprio (cuidados_admin), httpOnly, SameSite=Strict, só HTTPS;
 *  - sem Bearer (o painel é só web: um token copiado não abre o painel num script);
 *  - dura no máximo admin.sessao_horas e cai após admin.inatividade_min parada;
 *  - escrita exige o cabeçalho X-Cuidados-Admin e, se o navegador mandar Origin,
 *    que ele seja o próprio endereço do app (anti-CSRF em duas camadas).
 */
final class AdminSessao
{
    public const COOKIE = 'cuidados_admin';
    public const PAPEIS = ['leitura' => 1, 'operador' => 2, 'dono' => 3];

    /** @return array{token:string,id:string} */
    public static function criar(string $adminId, ?string $ip, ?string $dispositivo): array
    {
        $token = bin2hex(random_bytes(32));
        $id = Ulid::novo();
        $agora = Tempo::agora();
        Bd::inserir('admin_sessoes', [
            'id' => $id,
            'admin_id' => $adminId,
            'token_hash' => hash('sha256', $token),
            'ip' => $ip,
            'dispositivo' => $dispositivo !== null ? mb_substr($dispositivo, 0, 160) : null,
            'criado_em' => $agora->format('Y-m-d H:i:s'),
            'ultimo_uso_em' => $agora->format('Y-m-d H:i:s'),
            'expira_em' => $agora->modify('+' . self::horas() . ' hours')->format('Y-m-d H:i:s'),
        ]);
        return ['token' => $token, 'id' => $id];
    }

    public static function cookie(Resposta $r, string $token, bool $apagar = false): void
    {
        $partes = [
            self::COOKIE . '=' . ($apagar ? '' : $token),
            'Path=/',
            'HttpOnly',
            'SameSite=Strict',
            'Max-Age=' . ($apagar ? 0 : self::horas() * 3600),
        ];
        if (!Config::emTeste()) $partes[] = 'Secure';
        $r->cookies[] = implode('; ', $partes);
    }

    /** @return array{admin:array<string,mixed>,sessao_id:string}|null */
    public static function atual(Requisicao $req): ?array
    {
        $token = self::tokenDoCookie($req);
        if ($token === null) return null;

        $s = Bd::um(
            'SELECT s.id AS sessao_id, s.ultimo_uso_em, a.*
               FROM admin_sessoes s JOIN admin_usuarios a ON a.id = s.admin_id
              WHERE s.token_hash = :h AND s.revogada_em IS NULL AND s.expira_em > :agora AND a.ativo = 1',
            ['h' => hash('sha256', $token), 'agora' => Tempo::agoraSql()]
        );
        if ($s === null) return null;

        $parada = Tempo::agora()->modify('-' . max(5, (int)Config::get('admin.inatividade_min', 30)) . ' minutes');
        if (Tempo::utc($s['ultimo_uso_em']) < $parada) {
            self::revogar($s['sessao_id']);
            return null;
        }
        if (!in_array($req->metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if ($req->cabecalho('x-cuidados-admin') === null) {
                throw ErroHttp::proibido('Envio bloqueado por segurança. Atualize a página.');
            }
            $origem = $req->cabecalho('origin');
            if ($origem !== null && !Config::emTeste() && !self::origemPermitida($origem)) {
                throw ErroHttp::proibido('Envio bloqueado por segurança (origem desconhecida).');
            }
        }
        Bd::exec('UPDATE admin_sessoes SET ultimo_uso_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $s['sessao_id']]);
        $sessaoId = $s['sessao_id'];
        unset($s['sessao_id'], $s['ultimo_uso_em']);
        return ['admin' => $s, 'sessao_id' => $sessaoId];
    }

    public static function revogar(string $sessaoId): void
    {
        Bd::exec('UPDATE admin_sessoes SET revogada_em = :a WHERE id = :id AND revogada_em IS NULL', ['a' => Tempo::agoraSql(), 'id' => $sessaoId]);
    }

    public static function revogarTodas(string $adminId): void
    {
        Bd::exec('UPDATE admin_sessoes SET revogada_em = :a WHERE admin_id = :u AND revogada_em IS NULL', ['a' => Tempo::agoraSql(), 'u' => $adminId]);
    }

    /** E-mails da configuração: sempre "dono" (raiz de confiança fica no servidor, não no banco). @return list<string> */
    public static function donosDaConfiguracao(): array
    {
        $lista = Config::get('admin.donos', []);
        if (is_string($lista)) $lista = explode(',', $lista);
        return array_values(array_filter(array_map(fn($e) => mb_strtolower(trim((string)$e)), (array)$lista)));
    }

    public static function nivel(string $papel): int
    {
        return self::PAPEIS[$papel] ?? 0;
    }

    private static function tokenDoCookie(Requisicao $req): ?string
    {
        $c = $_COOKIE[self::COOKIE] ?? null;
        if ($c === null) {
            // Servidor embutido do PHP e alguns proxies: lê do cabeçalho Cookie.
            if (preg_match('/(?:^|;\s*)' . self::COOKIE . '=([0-9a-f]{64})/', (string)$req->cabecalho('cookie'), $m)) $c = $m[1];
        }
        return is_string($c) && preg_match('/^[0-9a-f]{64}$/', $c) ? $c : null;
    }

    private static function origemPermitida(string $origem): bool
    {
        $app = rtrim((string)Config::get('url_app'), '/');
        return $origem === $app || in_array($origem, (array)Config::get('cors.origens', []), true);
    }

    private static function horas(): int
    {
        return max(1, min(24, (int)Config::get('admin.sessao_horas', 8)));
    }
}
