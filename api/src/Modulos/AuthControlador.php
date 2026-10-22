<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Auth\Canais;
use Cuidados\Auth\Otp;
use Cuidados\Auth\Sessao;
use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

final class AuthControlador extends Controlador
{
    /** GET /auth/canais — quais formas de receber o código estão ligadas */
    public function canais(): Resposta
    {
        return Resposta::json(['canais' => Canais::disponiveis()]);
    }

    /** POST /auth/codigo {destino, canal?} */
    public function codigo(): Resposta
    {
        $v = Valida::de($this->corpo());
        $destino = $v->texto('destino', true, 190);
        $canal = $v->opcao('canal', ['whatsapp', 'sms', 'email'], false);
        $v->validar();
        return Resposta::json(Otp::solicitar((string)$destino, $canal, $this->req->ip));
    }

    /** POST /auth/entrar {destino, codigo, dispositivo?} → {token, usuario, novo} */
    public function entrar(): Resposta
    {
        $v = Valida::de($this->corpo());
        $destinoBruto = $v->texto('destino', true, 190);
        $codigo = $v->texto('codigo', true, 10);
        $disp = $v->texto('dispositivo', false, 160);
        $v->validar();

        $destino = Otp::verificar((string)$destinoBruto, (string)$codigo);
        $campo = str_contains($destino, '@') ? 'email' : 'telefone';

        [$usuario, $novo] = Bd::transacao(function () use ($campo, $destino) {
            $u = Bd::um("SELECT * FROM usuarios WHERE $campo = :d", ['d' => $destino]);
            if ($u !== null && $u['excluido_em'] !== null) {
                throw ErroHttp::proibido('Esta conta foi excluída.');
            }
            if ($u !== null) return [$u, false];
            $agora = Tempo::agoraSql();
            $id = Ulid::novo();
            Bd::inserir('usuarios', [
                'id' => $id,
                $campo => $destino,
                'preferencias' => ['fonte' => 1, 'voz' => false, 'modo' => 'conversa'],
                'criado_em' => $agora,
                'atualizado_em' => $agora,
            ]);
            return [Bd::um('SELECT * FROM usuarios WHERE id = :id', ['id' => $id]), true];
        });

        $s = Sessao::criar($usuario['id'], $disp ?? $this->req->cabecalho('user-agent'));
        Auditoria::contexto($usuario['id'], $this->req->ip);
        Auditoria::registrar($novo ? 'conta.criada' : 'sessao.entrou', 'usuario', $usuario['id']);

        $r = Resposta::json(['token' => $s['token'], 'usuario' => Formata::eu($usuario), 'novo' => $novo]);
        Sessao::cookie($r, $s['token']);
        return $r;
    }

    /** POST /auth/sair */
    public function sair(): Resposta
    {
        if ($this->sessaoId !== null) Sessao::revogar($this->sessaoId);
        $r = Resposta::vazio();
        Sessao::cookie($r, '', true);
        return $r;
    }
}
