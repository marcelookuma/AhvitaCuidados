<?php
declare(strict_types=1);

namespace Cuidados\Http;

use Cuidados\Auth\Sessao;
use Cuidados\Rotas;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\LogAcesso;
use Cuidados\Support\Tarefas;

/** Ciclo da requisição: segurança → sessão → rota → resposta JSON. */
final class Kernel
{
    public static function atender(Requisicao $req): Resposta
    {
        if ($req->metodo !== 'OPTIONS') {
            Tarefas::seVencidas(); // hospedagem sem cron: a API cuida das tarefas periódicas
        }
        $inicio = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        LogAcesso::reiniciar();
        $rota = null;
        $r = self::processar($req, $rota);
        self::cabecalhosSeguranca($req, $r);
        LogAcesso::gravar($req, $r->status, $rota, $req->params, $inicio);
        return $r;
    }

    private static function processar(Requisicao $req, ?string &$rota): Resposta
    {
        if ($req->metodo === 'OPTIONS') {
            return new Resposta(204);
        }
        try {
            $roteador = new Roteador();
            Rotas::registrar($roteador);
            [$acao, $params, $publica] = $roteador->resolver($req->metodo, $req->caminho, $rota);
            $req->params = $params;

            // O painel /admin tem sessão própria (Google + lista de administradores):
            // a sessão do app nunca vale lá, e a do painel nunca vale no app.
            $sessao = str_starts_with((string)$rota, '/admin/') ? null : Sessao::atual($req);
            if (!$publica && $sessao === null) {
                throw ErroHttp::naoAutenticado();
            }
            Auditoria::contexto($sessao['usuario']['id'] ?? null, $req->ip);
            LogAcesso::usuario($sessao['usuario']['id'] ?? null, $sessao['sessao_id'] ?? null);

            if (is_array($acao)) {
                [$classe, $metodo] = $acao;
                $ctl = new $classe($req, $sessao['usuario'] ?? null, $sessao['sessao_id'] ?? null);
                return $ctl->$metodo();
            }
            return $acao($req);
        } catch (ErroHttp $e) {
            return Resposta::json(['erro' => ['codigo' => $e->codigo, 'mensagem' => $e->getMessage(), 'campos' => (object)$e->campos]], $e->status);
        } catch (\PDOException $e) {
            error_log('[cuidados] banco: ' . $e->getMessage());
            return Resposta::json(['erro' => ['codigo' => 'banco', 'mensagem' => 'Estamos com instabilidade. Seus registros ficam guardados no celular e vão depois.']], 503);
        } catch (\Throwable $e) {
            error_log('[cuidados] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $msg = Config::emTeste() ? $e->getMessage() : 'Algo deu errado. Tente de novo.';
            return Resposta::json(['erro' => ['codigo' => 'interno', 'mensagem' => $msg]], 500);
        }
    }

    private static function cabecalhosSeguranca(Requisicao $req, Resposta $r): void
    {
        $r->cabecalhos += [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => $r->cabecalhos['Cache-Control'] ?? 'no-store',
        ];
        $origem = $req->cabecalho('origin');
        $comCookie = in_array($origem, (array)Config::get('cors.origens', []), true);
        // App Android (Capacitor, origem https://localhost): entra só com o token Bearer.
        // Sem "Allow-Credentials": o cookie da web nunca vale a partir dessa origem.
        $doApp = in_array($origem, (array)Config::get('cors.origens_app', []), true);
        if ($origem !== null && ($comCookie || $doApp)) {
            $r->cabecalhos['Access-Control-Allow-Origin'] = $origem;
            if ($comCookie) $r->cabecalhos['Access-Control-Allow-Credentials'] = 'true';
            $r->cabecalhos['Access-Control-Allow-Headers'] = $comCookie
                ? 'Content-Type, Authorization, X-Cuidados, X-Cuidados-Admin'
                : 'Content-Type, Authorization, X-Cuidados';
            $r->cabecalhos['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
            $r->cabecalhos['Access-Control-Max-Age'] = '600'; // menos idas ao servidor no 3G/4G
            $r->cabecalhos['Vary'] = 'Origin';
        }
    }
}
