<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Auth\Canais;
use Cuidados\Auth\Google;
use Cuidados\Auth\Otp;
use Cuidados\Auth\Sessao;
use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\LogAcesso;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

final class AuthControlador extends Controlador
{
    /** GET /auth/canais — quais formas de entrar estão ligadas (google = client_id público, ou null) */
    public function canais(): Resposta
    {
        return Resposta::json(['canais' => Canais::disponiveis(), 'google' => Google::ligado() ? Google::clientId() : null]);
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

        return $this->abrirSessao($usuario, $novo, $disp, 'codigo');
    }

    /**
     * POST /auth/google {credencial, dispositivo?} → {token, usuario, novo}
     * Quem já tem conta com o mesmo e-mail (confirmado pelo Google) entra na mesma
     * conta, e ela passa a ficar vinculada ao Google.
     */
    public function google(): Resposta
    {
        $v = Valida::de($this->corpo());
        $credencial = $v->texto('credencial', true, 4096);
        $disp = $v->texto('dispositivo', false, 160);
        $v->validar();

        $g = Google::verificar((string)$credencial);

        [$usuario, $novo, $vinculou] = Bd::transacao(function () use ($g) {
            $u = Bd::um('SELECT * FROM usuarios WHERE google_sub = :s', ['s' => $g['sub']]);
            if ($u !== null) {
                if ($u['excluido_em'] !== null) throw ErroHttp::proibido('Esta conta foi excluída.');
                return [$u, false, false];
            }
            if ($g['email'] === null || !$g['email_verificado']) {
                throw ErroHttp::validacao(['credencial' => 'E-mail do Google não confirmado'],
                    'Seu e-mail no Google não está confirmado. Entre com celular ou e-mail.');
            }
            $agora = Tempo::agoraSql();
            $u = Bd::um('SELECT * FROM usuarios WHERE email = :e', ['e' => $g['email']]);
            if ($u !== null) {
                if ($u['excluido_em'] !== null) throw ErroHttp::proibido('Esta conta foi excluída.');
                if ($u['google_sub'] !== null) {
                    // O e-mail já está numa conta ligada a OUTRA conta Google.
                    throw ErroHttp::conflito('google_outra_conta', 'Este e-mail já está ligado a outra conta Google. Entre com o código por e-mail.');
                }
                Bd::exec('UPDATE usuarios SET google_sub = :s, atualizado_em = :a WHERE id = :id', ['s' => $g['sub'], 'a' => $agora, 'id' => $u['id']]);
                return [Bd::um('SELECT * FROM usuarios WHERE id = :id', ['id' => $u['id']]), false, true];
            }
            $id = Ulid::novo();
            Bd::inserir('usuarios', [
                'id' => $id,
                'nome' => $g['nome'],
                'email' => $g['email'],
                'google_sub' => $g['sub'],
                'preferencias' => ['fonte' => 1, 'voz' => false, 'modo' => 'conversa'],
                'criado_em' => $agora,
                'atualizado_em' => $agora,
            ]);
            return [Bd::um('SELECT * FROM usuarios WHERE id = :id', ['id' => $id]), true, false];
        });

        if ($vinculou) {
            Auditoria::contexto($usuario['id'], $this->req->ip);
            Auditoria::registrar('conta.google_vinculado', 'usuario', $usuario['id']);
        }
        return $this->abrirSessao($usuario, $novo, $disp, 'google');
    }

    /** @param array<string,mixed> $usuario */
    private function abrirSessao(array $usuario, bool $novo, ?string $disp, string $via): Resposta
    {
        if (($usuario['bloqueado_em'] ?? null) !== null) {
            LogAcesso::usuario($usuario['id'], null);
            throw ErroHttp::proibido('Esta conta está bloqueada. Fale com o suporte do Ahvita Cuidados.');
        }
        $s = Sessao::criar($usuario['id'], $disp ?? $this->req->cabecalho('user-agent'));
        Auditoria::contexto($usuario['id'], $this->req->ip);
        Auditoria::registrar($novo ? 'conta.criada' : 'sessao.entrou', 'usuario', $usuario['id'], null, ['via' => $via]);
        LogAcesso::usuario($usuario['id'], $s['id']);
        LogAcesso::evento($novo ? 'conta_criada' : 'entrou');

        $r = Resposta::json(['token' => $s['token'], 'usuario' => Formata::eu($usuario), 'novo' => $novo]);
        Sessao::cookie($r, $s['token']);
        return $r;
    }

    /** POST /auth/sair */
    public function sair(): Resposta
    {
        if ($this->sessaoId !== null) Sessao::revogar($this->sessaoId);
        LogAcesso::evento('saiu');
        $r = Resposta::vazio();
        Sessao::cookie($r, '', true);
        return $r;
    }
}
