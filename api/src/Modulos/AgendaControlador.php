<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

/**
 * Agenda do(a) cuidador(a) e atendimentos.
 * Regras de risco trabalhista aplicadas aqui:
 *  - Só o(a) cuidador(a) escreve na própria agenda.
 *  - Todo atendimento é uma PROPOSTA que ele(a) aceita ou recusa, sem consequência.
 *  - Para MEI/autônomo, proposta só dentro da disponibilidade marcada.
 *  - A confirmação de ida serve só para organizar cobertura: sem falta, sem penalidade.
 */
final class AgendaControlador extends Controlador
{
    /** GET /agenda — minha agenda (só eu vejo os bloqueios e o motivo) */
    public function ver(): Resposta
    {
        $uid = $this->uid();
        $agora = Tempo::agoraSql();
        $atendimentos = Bd::todos(
            "SELECT * FROM atendimentos WHERE cuidador_id = :u AND status IN ('proposto','aceito') AND fim > :a ORDER BY inicio LIMIT 60",
            ['u' => $uid, 'a' => $agora]
        );
        $abertas = Bd::todos(
            "SELECT a.* FROM proposta_destinatarios d JOIN atendimentos a ON a.id = d.atendimento_id
              WHERE d.cuidador_id = :u AND d.status = 'enviada' AND a.status = 'proposto' AND a.cuidador_id IS NULL AND a.inicio > :a
              ORDER BY a.inicio",
            ['u' => $uid, 'a' => $agora]
        );
        return Resposta::json([
            'disponibilidade' => array_map(fn($d) => [
                'dia_semana' => (int)$d['dia_semana'], 'inicio' => substr((string)$d['inicio'], 0, 5), 'fim' => substr((string)$d['fim'], 0, 5),
            ], Bd::todos('SELECT * FROM disponibilidades WHERE usuario_id = :u ORDER BY dia_semana, inicio', ['u' => $uid])),
            'bloqueios' => array_map(fn($b) => [
                'id' => $b['id'], 'inicio' => Tempo::sqlParaIso($b['inicio']), 'fim' => Tempo::sqlParaIso($b['fim']), 'motivo' => $b['motivo'],
            ], Bd::todos('SELECT * FROM bloqueios_agenda WHERE usuario_id = :u AND fim > :a ORDER BY inicio', ['u' => $uid, 'a' => $agora])),
            'atendimentos' => array_map([Formata::class, 'atendimento'], $atendimentos),
            'propostas_abertas' => array_map(function ($a) {
                $f = Formata::atendimento($a);
                $e = Bd::um('SELECT nome_fantasia, razao_social FROM empresas WHERE id = :id', ['id' => $a['empresa_id']]);
                $f['empresa'] = $e ? ($e['nome_fantasia'] ?: $e['razao_social']) : null;
                return $f;
            }, $abertas),
        ]);
    }

    /** PUT /agenda/disponibilidade {janelas:[{dia_semana, inicio, fim}]} — substitui tudo */
    public function salvarDisponibilidade(): Resposta
    {
        $v = Valida::de($this->corpo());
        $janelas = $v->lista('janelas', true, 28) ?? [];
        $limpas = [];
        foreach ($janelas as $i => $j) {
            $vj = Valida::de(is_array($j) ? $j : []);
            $dia = $vj->inteiro('dia_semana', true, 0, 6);
            $ini = $vj->hora('inicio');
            $fim = $vj->hora('fim');
            try { $vj->validar(); } catch (ErroHttp) { $v->erro("janelas.$i", 'Horário inválido'); continue; }
            $limpas[] = [$dia, $ini, $fim];
        }
        $v->validar();
        $uid = $this->uid();
        Bd::transacao(function () use ($uid, $limpas) {
            Bd::exec('DELETE FROM disponibilidades WHERE usuario_id = :u', ['u' => $uid]);
            foreach ($limpas as [$dia, $ini, $fim]) {
                Bd::inserir('disponibilidades', ['id' => Ulid::novo(), 'usuario_id' => $uid, 'dia_semana' => $dia, 'inicio' => $ini . ':00', 'fim' => $fim . ':00', 'criado_em' => Tempo::agoraSql()]);
            }
        });
        Auditoria::registrar('agenda.disponibilidade', 'usuario', $uid, null, ['janelas' => count($limpas)]);
        return $this->ver();
    }

    /** POST /agenda/bloqueios {inicio, fim, motivo?} */
    public function bloquear(): Resposta
    {
        $v = Valida::de($this->corpo());
        $ini = $v->dataHora('inicio');
        $fim = $v->dataHora('fim');
        $motivo = $v->texto('motivo', false, 120);
        $v->validar();
        if ($fim <= $ini) throw ErroHttp::validacao(['fim' => 'O fim precisa ser depois do início']);
        $id = Ulid::novo();
        Bd::inserir('bloqueios_agenda', ['id' => $id, 'usuario_id' => $this->uid(), 'inicio' => $ini, 'fim' => $fim, 'motivo' => $motivo, 'criado_em' => Tempo::agoraSql()]);
        return Resposta::json(['id' => $id], 201);
    }

    /** DELETE /agenda/bloqueios/{id} */
    public function desbloquear(): Resposta
    {
        Bd::exec('DELETE FROM bloqueios_agenda WHERE id = :id AND usuario_id = :u', ['id' => $this->req->param('id'), 'u' => $this->uid()]);
        return Resposta::vazio();
    }

    // ------------------------------------------------------------ atendimentos

    /** GET /pessoas/{id}/cuidadores/{cuidador}/disponivel?inicio&fim — a tela do gestor avisa antes de enviar */
    public function checarDisponivel(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'atendimentos');
        $vin = $this->vinculoCuidador($ctx['pessoa']['id'], $this->req->param('cuidador'));
        try {
            RegrasAgenda::validarProposta($vin['usuario_id'], $vin['modalidade'], Tempo::isoParaSql((string)$this->req->q('inicio')), Tempo::isoParaSql((string)$this->req->q('fim')));
            return Resposta::json(['disponivel' => true]);
        } catch (ErroHttp $e) {
            return Resposta::json(['disponivel' => false, 'motivo' => $e->getMessage()]);
        }
    }

    /** POST /pessoas/{id}/atendimentos {cuidador_id, inicio, fim, valor_centavos?, observacao?} — proposta do(a) gestor(a) */
    public function propor(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'atendimentos');
        $v = Valida::de($this->corpo());
        $cuidador = $v->ulid('cuidador_id');
        $ini = $v->dataHora('inicio');
        $fim = $v->dataHora('fim');
        $valor = $v->inteiro('valor_centavos', false, 0, 10_000_000);
        $obs = $v->texto('observacao', false, 255);
        $v->validar();
        $vin = $this->vinculoCuidador($ctx['pessoa']['id'], (string)$cuidador);
        RegrasAgenda::validarProposta($vin['usuario_id'], $vin['modalidade'], (string)$ini, (string)$fim);

        $id = Ulid::novo();
        Bd::inserir('atendimentos', [
            'id' => $id, 'pessoa_id' => $ctx['pessoa']['id'], 'cuidador_id' => $vin['usuario_id'], 'solicitante_id' => $this->uid(),
            'empresa_id' => $ctx['papel'] === 'empresa' ? $ctx['empresa_id'] : null,
            'origem' => $ctx['papel'] === 'empresa' ? 'empresa' : 'gestor',
            'inicio' => $ini, 'fim' => $fim, 'valor_centavos' => $valor, 'observacao' => $obs,
            'prazo_confirmacao_min' => (int)$vin['prazo_confirmacao_min'], 'criado_em' => Tempo::agoraSql(),
        ]);
        Avisos::evento('proposta', $vin['usuario_id'], ['atendimento_id' => $id]);
        Auditoria::registrar('atendimento.proposto', 'atendimento', $id, $ctx['pessoa']['id']);
        return Resposta::json(Formata::atendimento(Bd::um('SELECT * FROM atendimentos WHERE id = :id', ['id' => $id])), 201);
    }

    /** GET /pessoas/{id}/atendimentos */
    public function listar(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        if (!Acesso::pode($ctx, 'atendimentos') && !$ctx['cuidador']) throw ErroHttp::proibido();
        $sql = 'SELECT * FROM atendimentos WHERE pessoa_id = :p AND fim > :a';
        $par = ['p' => $ctx['pessoa']['id'], 'a' => Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s')];
        if (!Acesso::pode($ctx, 'atendimentos')) { $sql .= ' AND cuidador_id = :u'; $par['u'] = $this->uid(); }
        $lista = Bd::todos($sql . ' ORDER BY inicio DESC LIMIT 100', $par);
        return Resposta::json(['atendimentos' => array_map([Formata::class, 'atendimento'], $lista)]);
    }

    /**
     * POST /atendimentos/{id}/responder {aceitar: bool, motivo?}
     * Proposta aberta da empresa: o primeiro aceite fica (UPDATE condicional atômico).
     */
    public function responder(): Resposta
    {
        $a = $this->atendimento();
        $uid = $this->uid();
        $v = Valida::de($this->corpo());
        $aceitar = $v->booleano('aceitar');
        $motivo = $v->texto('motivo', false, 160);
        $v->validar();
        if ($a['status'] !== 'proposto') throw ErroHttp::conflito('ja_respondido', $a['status'] === 'aceito' ? 'Este atendimento já foi aceito.' : 'Esta proposta não está mais aberta.');
        if (Tempo::utc($a['inicio']) <= Tempo::agora()) throw ErroHttp::conflito('passou', 'O horário desta proposta já passou.');
        $aberta = $a['cuidador_id'] === null;
        if ($aberta) {
            $dest = Bd::um('SELECT * FROM proposta_destinatarios WHERE atendimento_id = :a AND cuidador_id = :u', ['a' => $a['id'], 'u' => $uid]);
            if ($dest === null) throw ErroHttp::naoEncontrado();
        } elseif ($a['cuidador_id'] !== $uid) {
            throw ErroHttp::naoEncontrado();
        }
        $agora = Tempo::agoraSql();

        if (!$aceitar) {
            if ($aberta) {
                Bd::exec("UPDATE proposta_destinatarios SET status = 'recusada', respondido_em = :a WHERE atendimento_id = :at AND cuidador_id = :u",
                    ['a' => $agora, 'at' => $a['id'], 'u' => $uid]);
            } else {
                Bd::atualizar('atendimentos', ['status' => 'recusado', 'respondido_em' => $agora, 'motivo_recusa' => $motivo], ['id' => $a['id']]);
                $nome = Formata::pessoaPublica($uid)['nome'];
                Avisos::alerta($a['pessoa_id'], 'proposta_recusada', $a['id'], "$nome não poderá atender em " . $this->quando($a) . '.' . ($motivo ? " Motivo: $motivo" : ''), $a['empresa_id']);
            }
            return Resposta::json(['status' => 'recusado']);
        }

        $modalidade = $aberta
            ? Bd::valor("SELECT modalidade FROM empresa_cuidadores WHERE empresa_id = :e AND usuario_id = :u AND status = 'ativo'", ['e' => $a['empresa_id'], 'u' => $uid])
            : Bd::valor("SELECT modalidade FROM vinculos WHERE pessoa_id = :p AND usuario_id = :u AND status = 'ativo'", ['p' => $a['pessoa_id'], 'u' => $uid]);
        // No aceite, checa só choque com outros atendimentos e bloqueios: quem aceita decide sobre a própria agenda.
        RegrasAgenda::validarProposta($uid, 'clt', $a['inicio'], $a['fim'], $a['id']);

        Bd::transacao(function () use ($a, $uid, $agora, $aberta, $modalidade) {
            if ($aberta) {
                $ganhou = Bd::exec(
                    "UPDATE atendimentos SET cuidador_id = :u, status = 'aceito', respondido_em = :a WHERE id = :id AND status = 'proposto' AND cuidador_id IS NULL",
                    ['u' => $uid, 'a' => $agora, 'id' => $a['id']]
                )->rowCount();
                if ($ganhou === 0) throw ErroHttp::conflito('ja_aceito', 'Outra pessoa aceitou antes. Fica para a próxima!');
                Bd::exec("UPDATE proposta_destinatarios SET status = IF(cuidador_id = :u, 'aceita', 'encerrada'), respondido_em = :a WHERE atendimento_id = :at",
                    ['u' => $uid, 'a' => $agora, 'at' => $a['id']]);
                // Ganha acesso à pessoa como cuidador(a) via empresa (o gestor vê e pode encerrar).
                $ja = Bd::valor("SELECT id FROM vinculos WHERE pessoa_id = :p AND usuario_id = :u AND status = 'ativo'", ['p' => $a['pessoa_id'], 'u' => $uid]);
                if ($ja === null) {
                    Bd::inserir('vinculos', [
                        'id' => Ulid::novo(), 'pessoa_id' => $a['pessoa_id'], 'usuario_id' => $uid, 'papel' => 'cuidador',
                        'modalidade' => 'empresa', 'empresa_id' => $a['empresa_id'], 'permissoes' => Permissoes::padrao('cuidador'),
                        'prazo_confirmacao_min' => (int)$a['prazo_confirmacao_min'], 'criado_em' => $agora,
                    ]);
                    $nome = Formata::pessoaPublica($uid)['nome'];
                    Conversas::sistema(Conversas::daPessoa($a['pessoa_id']), "👋 $nome entrou na conversa (cuidador(a) pela empresa).");
                }
            } else {
                Bd::atualizar('atendimentos', ['status' => 'aceito', 'respondido_em' => $agora], ['id' => $a['id']]);
            }
            $nome = Formata::pessoaPublica($uid)['nome'];
            Conversas::sistema(Conversas::daPessoa($a['pessoa_id']), "📅 $nome aceitou o atendimento de " . $this->quando($a) . '.');
            Auditoria::registrar('atendimento.aceito', 'atendimento', $a['id'], $a['pessoa_id']);
        });
        return Resposta::json(Formata::atendimento(Bd::um('SELECT * FROM atendimentos WHERE id = :id', ['id' => $a['id']])));
    }

    /** POST /atendimentos/{id}/confirmar {vou: bool, motivo?} — "Vou" / "Não vou" */
    public function confirmar(): Resposta
    {
        $a = $this->atendimento();
        if ($a['cuidador_id'] !== $this->uid()) throw ErroHttp::naoEncontrado();
        if ($a['status'] !== 'aceito') throw ErroHttp::conflito('nao_combinado', 'Este atendimento não está combinado.');
        $v = Valida::de($this->corpo());
        $vou = $v->booleano('vou');
        $motivo = $v->texto('motivo', false, 160);
        $v->validar();
        $agora = Tempo::agoraSql();
        Bd::atualizar('atendimentos', ['confirmacao' => $vou ? 'vou' : 'nao_vou', 'confirmacao_em' => $agora, 'confirmacao_motivo' => $motivo], ['id' => $a['id']]);
        $nome = Formata::pessoaPublica($this->uid())['nome'];
        $cid = Conversas::daPessoa($a['pessoa_id']);
        if ($vou) {
            Conversas::sistema($cid, "✅ $nome confirmou que vai em " . $this->quando($a) . '.');
            Bd::exec("UPDATE alertas SET resolvido_em = :a WHERE tipo = 'sem_confirmacao' AND referencia_id = :r AND resolvido_em IS NULL", ['a' => $agora, 'r' => $a['id']]);
        } else {
            Conversas::sistema($cid, "🙏 $nome avisou que não vai em " . $this->quando($a) . '.');
            Avisos::alerta($a['pessoa_id'], 'nao_vou', $a['id'], "$nome avisou que não vai em " . $this->quando($a) . '.' . ($motivo ? " Motivo: $motivo" : '') . ' Organize a cobertura.', $a['empresa_id']);
        }
        return Resposta::json(Formata::atendimento(Bd::um('SELECT * FROM atendimentos WHERE id = :id', ['id' => $a['id']])));
    }

    /** POST /atendimentos/{id}/cancelar — quem propôs ou quem administra */
    public function cancelar(): Resposta
    {
        $a = $this->atendimento();
        $pode = $a['solicitante_id'] === $this->uid() || $a['cuidador_id'] === $this->uid();
        if (!$pode) {
            if ($a['empresa_id'] !== null && Acesso::membroEmpresa($this->uid(), $a['empresa_id']) !== null) $pode = true;
            else Acesso::exigir(Acesso::pessoa($this->u(), $a['pessoa_id']), 'atendimentos');
        }
        if (!in_array($a['status'], ['proposto', 'aceito'], true)) return Resposta::vazio();
        Bd::atualizar('atendimentos', ['status' => 'cancelado', 'respondido_em' => Tempo::agoraSql()], ['id' => $a['id']]);
        Conversas::sistema(Conversas::daPessoa($a['pessoa_id']), '📅 O atendimento de ' . $this->quando($a) . ' foi cancelado.');
        Auditoria::registrar('atendimento.cancelado', 'atendimento', $a['id'], $a['pessoa_id']);
        return Resposta::vazio();
    }

    /** @return array<string,mixed> */
    private function vinculoCuidador(string $pessoaId, string $cuidadorId): array
    {
        $vin = Bd::um(
            "SELECT * FROM vinculos WHERE pessoa_id = :p AND usuario_id = :u AND status = 'ativo' AND (papel = 'cuidador' OR atua_como_cuidador = 1)",
            ['p' => $pessoaId, 'u' => $cuidadorId]
        );
        if ($vin === null) throw ErroHttp::validacao(['cuidador_id' => 'Escolha um(a) cuidador(a) desta pessoa']);
        return $vin;
    }

    /** @return array<string,mixed> */
    private function atendimento(): array
    {
        $a = Bd::um('SELECT * FROM atendimentos WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($a === null) throw ErroHttp::naoEncontrado('Atendimento não encontrado.');
        return $a;
    }

    /** @param array<string,mixed> $a */
    private function quando(array $a): string
    {
        $dias = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
        $i = Tempo::local(Tempo::utc($a['inicio']));
        $f = Tempo::local(Tempo::utc($a['fim']));
        return $dias[(int)$i->format('w')] . ', ' . $i->format('d/m') . ', das ' . $i->format('H\hi') . ' às ' . $f->format('H\hi');
    }
}
