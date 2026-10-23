<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Resposta;
use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

/**
 * Aparelhos do app (Android/iOS) que recebem avisos no celular.
 * O registro fica preso à sessão atual: sair da conta desliga os avisos.
 */
final class DispositivosControlador extends Controlador
{
    /** POST /dispositivos {token, plataforma} — idempotente: o app manda de novo a cada abertura. */
    public function registrar(): Resposta
    {
        if ($this->sessaoId === null) throw ErroHttp::naoAutenticado();
        $d = $this->corpo();
        $v = Valida::de($d);
        $token = $v->texto('token', true, 255, 20);
        $plataforma = $v->opcao('plataforma', ['android', 'ios'], true);
        $v->validar();
        if (!preg_match('/^[A-Za-z0-9_:\-.]+$/', (string)$token)) {
            throw ErroHttp::validacao(['token' => 'Token inválido']);
        }
        $agora = Tempo::agoraSql();
        // O mesmo aparelho pode trocar de conta: o token passa a ser só da sessão atual.
        Bd::exec(
            'INSERT INTO dispositivos (id, usuario_id, sessao_id, plataforma, token, criado_em, atualizado_em)
             VALUES (:id, :u, :s, :p, :t, :a, :a2)
             ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), sessao_id = VALUES(sessao_id),
                                     plataforma = VALUES(plataforma), atualizado_em = VALUES(atualizado_em)',
            ['id' => Ulid::novo(), 'u' => $this->uid(), 's' => $this->sessaoId, 'p' => $plataforma, 't' => $token, 'a' => $agora, 'a2' => $agora]
        );
        return Resposta::vazio();
    }

    /** POST /dispositivos/remover {token} — ao sair da conta ou desligar os avisos. */
    public function remover(): Resposta
    {
        $token = (string)($this->corpo()['token'] ?? '');
        if ($token !== '') {
            Bd::exec('DELETE FROM dispositivos WHERE token = :t AND usuario_id = :u', ['t' => $token, 'u' => $this->uid()]);
        }
        return Resposta::vazio();
    }
}
