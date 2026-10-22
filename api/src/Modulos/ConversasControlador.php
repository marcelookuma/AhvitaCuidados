<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Resposta;
use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Valida;

/** Conversas diretas empresa ↔ cuidador(a) (suporte e combinados comerciais). */
final class ConversasControlador extends Controlador
{
    /** GET /empresas/{id}/conversas */
    public function daEmpresa(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        $lista = Bd::todos("SELECT * FROM conversas WHERE tipo = 'direta' AND empresa_id = :e", ['e' => $e['id']]);
        $saida = [];
        foreach ($lista as $c) {
            $ult = Bd::um('SELECT * FROM mensagens WHERE conversa_id = :c ORDER BY recebido_em DESC LIMIT 1', ['c' => $c['id']]);
            $saida[] = [
                'conversa_id' => $c['id'],
                'com' => Formata::pessoaPublica($c['usuario_id']),
                'ultimo' => $ult ? Formata::mensagem($ult) : null,
                'nao_lidas' => Conversas::naoLidas($c['id'], $this->uid()),
            ];
        }
        usort($saida, fn($a, $b) => strcmp($b['ultimo']['recebido_em'] ?? '', $a['ultimo']['recebido_em'] ?? ''));
        return Resposta::json(['conversas' => $saida]);
    }

    /** GET /conversas/{id}/mensagens?desde= */
    public function mensagens(): Resposta
    {
        $c = $this->conversa();
        $desde = $this->req->q('desde');
        $sql = 'SELECT * FROM mensagens WHERE conversa_id = :c';
        $p = ['c' => $c['id']];
        if ($desde !== null) { $sql .= ' AND recebido_em >= :d'; $p['d'] = Tempo::isoParaSql($desde); }
        $lista = array_reverse(Bd::todos($sql . ' ORDER BY recebido_em DESC LIMIT 150', $p));
        return Resposta::json([
            'itens' => array_map([Formata::class, 'mensagem'], $lista),
            'cursor' => Tempo::sqlParaIso(Tempo::agora()->modify('-2 seconds')->format('Y-m-d H:i:s')),
            'visto_por_outros_ate' => Conversas::vistoPorOutros($c['id'], $this->uid()),
        ]);
    }

    /** POST /conversas/{id}/mensagens {id, tipo, texto?, midia_id?, em} */
    public function enviar(): Resposta
    {
        $c = $this->conversa();
        $d = $this->corpo();
        $v = Valida::de($d);
        $id = $v->ulid('id');
        $tipo = $v->opcao('tipo', ['texto', 'audio', 'foto'], true, 'texto');
        $texto = $v->texto('texto', $tipo === 'texto', 4000);
        $midia = $v->ulid('midia_id', $tipo !== 'texto');
        $v->validar();
        if ($midia !== null) {
            $m = Bd::um('SELECT autor_id, conversa_id FROM midias WHERE id = :id', ['id' => $midia]);
            if ($m === null || $m['autor_id'] !== $this->uid() || $m['conversa_id'] !== $c['id']) throw ErroHttp::validacao(['midia_id' => 'Envie a foto ou o áudio de novo']);
        }
        $msg = Conversas::enviar($c['id'], $this->uid(), (string)$tipo, $texto, $midia, $id, Tempo::horaDoAparelho($d['em'] ?? null));
        return Resposta::json(Formata::mensagem($msg), 201);
    }

    /** POST /conversas/{id}/visto */
    public function visto(): Resposta
    {
        $c = $this->conversa();
        Conversas::marcarVisto($c['id'], $this->uid());
        return Resposta::vazio();
    }

    /** @return array<string,mixed> */
    private function conversa(): array
    {
        $c = Bd::um("SELECT * FROM conversas WHERE id = :id AND tipo = 'direta'", ['id' => $this->req->param('id')]);
        if ($c === null) throw ErroHttp::naoEncontrado('Conversa não encontrada.');
        $ehCuidador = $c['usuario_id'] === $this->uid();
        if (!$ehCuidador && Acesso::membroEmpresa($this->uid(), (string)$c['empresa_id']) === null) {
            throw ErroHttp::naoEncontrado('Conversa não encontrada.');
        }
        return $c;
    }
}
