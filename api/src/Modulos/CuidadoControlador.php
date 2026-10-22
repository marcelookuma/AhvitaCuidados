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
 * O "coração" do app: a conversa de cada pessoa cuidada, onde registrar é
 * mandar mensagem (versão Conversa do(a) cuidador(a)), e a linha do tempo
 * de quem acompanha. Também remédios e presença (início/fim do serviço).
 */
final class CuidadoControlador extends Controlador
{
    /** Tipos aceitos. Mesma lista em packages/core/src/itens.ts. */
    public const TIPOS = [
        'remedio', 'comida', 'agua', 'banheiro', 'sono', 'banho', 'humor', 'sinais', 'algo',
        'pele', 'andar', 'comportamento', 'consulta', 'saida', 'visita', 'gasto', 'falta',
        'casa', 'aparelhos', 'nota', 'procedimento',
    ];

    // ------------------------------------------------------------------ feed

    /** GET /pessoas/{id}/feed?desde=ISO | ?antes=ISO */
    public function feed(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        $verMsg = Acesso::pode($ctx, 'mensagens');
        $verReg = Acesso::pode($ctx, 'ver_registros');
        if (!$verMsg && !$verReg) throw ErroHttp::proibido();
        $pid = $ctx['pessoa']['id'];
        $uid = $this->uid();
        $cid = Conversas::daPessoa($pid);
        $agora = Tempo::agora();
        $desde = $this->req->q('desde');
        $antes = $this->req->q('antes');
        $limite = 120;

        $itens = [];
        $cond = ''; $p = [];
        if ($desde !== null) { $cond = 'AND recebido_em >= :d'; $p['d'] = Tempo::isoParaSql($desde); }
        elseif ($antes !== null) { $cond = 'AND recebido_em < :d'; $p['d'] = Tempo::isoParaSql($antes); }
        if ($verMsg) {
            $msgs = Bd::todos("SELECT * FROM mensagens WHERE conversa_id = :c $cond ORDER BY recebido_em DESC, id DESC LIMIT $limite", ['c' => $cid] + $p);
            foreach ($msgs as $m) $itens[] = Formata::mensagem($m);
        }
        if ($verReg) {
            $regs = Bd::todos("SELECT * FROM registros WHERE pessoa_id = :pp $cond ORDER BY recebido_em DESC, id DESC LIMIT $limite", ['pp' => $pid] + $p);
            foreach ($regs as $r) $itens[] = Formata::registro($r);
        }
        usort($itens, fn($a, $b) => strcmp($a['em'], $b['em']) ?: strcmp($a['id'], $b['id']));

        $pendencias = [];
        foreach (Bd::todos(
            "SELECT * FROM atendimentos WHERE pessoa_id = :p AND cuidador_id = :u AND
               ((status = 'proposto' AND inicio > :a) OR (status = 'aceito' AND confirmacao IS NULL AND inicio > :a2 AND inicio <= :lim))
             ORDER BY inicio",
            ['p' => $pid, 'u' => $uid, 'a' => $agora->format('Y-m-d H:i:s'), 'a2' => $agora->format('Y-m-d H:i:s'), 'lim' => $agora->modify('+24 hours')->format('Y-m-d H:i:s')]
        ) as $a) {
            $pendencias[] = ['tipo' => $a['status'] === 'proposto' ? 'proposta' : 'confirmar_ida', 'atendimento' => Formata::atendimento($a)];
        }
        $presencas = array_map(fn($pr) => [
            'id' => $pr['id'],
            'cuidador' => Formata::pessoaPublica($pr['cuidador_id']),
            'desde' => Tempo::sqlParaIso($pr['inicio_em']),
            'minha' => $pr['cuidador_id'] === $uid,
        ], Bd::todos('SELECT * FROM presencas WHERE pessoa_id = :p AND fim_em IS NULL', ['p' => $pid]));

        return Resposta::json([
            'itens' => $itens,
            // 2 s de sobreposição: o app remove repetidos pelo id.
            'cursor' => Tempo::sqlParaIso($agora->modify('-2 seconds')->format('Y-m-d H:i:s')),
            'tem_mais' => $antes !== null || $desde === null ? count($itens) >= $limite : false,
            'conversa_id' => $cid,
            'visto_por_outros_ate' => $verMsg ? Conversas::vistoPorOutros($cid, $uid) : null,
            'pendencias' => $pendencias,
            'em_servico' => $presencas,
        ]);
    }

    /** POST /pessoas/{id}/mensagens {id, tipo, texto?, midia_id?, em} */
    public function enviarMensagem(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'mensagens');
        $m = $this->mensagemDoCorpo(Conversas::daPessoa($ctx['pessoa']['id']), $ctx['pessoa']['id']);
        return Resposta::json(Formata::mensagem($m), 201);
    }

    /** POST /pessoas/{id}/visto {ate?} */
    public function visto(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        $ate = $this->corpo()['ate'] ?? null;
        Conversas::marcarVisto(Conversas::daPessoa($ctx['pessoa']['id']), $this->uid(), is_string($ate) ? Tempo::horaDoAparelho($ate) : null);
        return Resposta::vazio();
    }

    // ------------------------------------------------------------- registros

    /** POST /pessoas/{id}/registros — idempotente pelo id gerado no aparelho */
    public function registrar(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'registrar');
        $pid = $ctx['pessoa']['id'];
        $d = $this->corpo();
        $v = Valida::de($d);
        $id = $v->ulid('id');
        $tipo = $v->opcao('tipo', self::TIPOS);
        $rotulo = $v->texto('rotulo', true, 80);
        $detalhe = $v->texto('detalhe', false, 255);
        $dados = $v->objeto('dados', false, 2000) ?? [];
        $alerta = $v->booleano('alerta');
        $remedioId = $v->ulid('remedio_id', false);
        $midiaId = $v->ulid('midia_id', false);
        $v->validar();
        $em = Tempo::horaDoAparelho(is_string($d['em'] ?? null) ? $d['em'] : null);

        if ($tipo === 'procedimento') {
            // Procedimentos técnicos só por profissional de enfermagem (Lei 7.498/86).
            $esp = mb_strtolower((string)($ctx['vinculo']['especialidade'] ?? ''));
            if ($ctx['papel'] !== 'profissional' || !str_contains($esp, 'enferm')) {
                throw ErroHttp::proibido('Procedimentos de enfermagem só podem ser registrados pela enfermagem.');
            }
        }
        if ($remedioId !== null && Bd::valor('SELECT id FROM remedios WHERE id = :r AND pessoa_id = :p', ['r' => $remedioId, 'p' => $pid]) === null) {
            throw ErroHttp::validacao(['remedio_id' => 'Remédio não encontrado']);
        }
        if ($midiaId !== null) $this->exigirMidiaPropria($midiaId, $pid);
        $dados = self::limparDados((string)$tipo, $dados);
        $alerta = $alerta || self::alertaAutomatico((string)$tipo, $dados);

        $existente = Bd::um('SELECT * FROM registros WHERE id = :id', ['id' => $id]);
        if ($existente !== null) {
            if ($existente['pessoa_id'] !== $pid || $existente['autor_id'] !== $this->uid()) throw ErroHttp::conflito('id_em_uso', 'Envio duplicado.');
            return Resposta::json(Formata::registro($existente));
        }
        $presenca = Bd::valor('SELECT id FROM presencas WHERE pessoa_id = :p AND cuidador_id = :u AND fim_em IS NULL LIMIT 1', ['p' => $pid, 'u' => $this->uid()]);
        Bd::transacao(function () use ($id, $pid, $tipo, $rotulo, $detalhe, $dados, $alerta, $remedioId, $midiaId, $em, $presenca, $ctx) {
            Bd::inserir('registros', [
                'id' => $id,
                'pessoa_id' => $pid,
                'autor_id' => $this->uid(),
                'presenca_id' => $presenca,
                'tipo' => $tipo,
                'rotulo' => $rotulo,
                'detalhe' => $detalhe,
                'dados' => $dados === [] ? null : $dados,
                'alerta' => (int)$alerta,
                'remedio_id' => $remedioId,
                'midia_id' => $midiaId,
                'ocorrido_em' => $em,
                'recebido_em' => Tempo::agoraSql(),
            ], true);
            $autor = Formata::pessoaPublica($this->uid())['nome'];
            if ($tipo === 'remedio' && in_array($dados['situacao'] ?? '', ['recusou', 'nao_dei'], true)) {
                Avisos::alerta($pid, 'remedio_recusado', $id, "$autor: remédio não tomado — $rotulo" . ($detalhe ? " ($detalhe)" : ''), $ctx['pessoa']['empresa_id']);
            } elseif ($alerta) {
                Avisos::alerta($pid, 'registro', $id, "$autor registrou: $rotulo" . ($detalhe ? " — $detalhe" : ''));
            }
        });
        return Resposta::json(Formata::registro(Bd::um('SELECT * FROM registros WHERE id = :id', ['id' => $id])), 201);
    }

    /** POST /registros/{id}/anular {id, motivo} — corrige sem apagar o histórico */
    public function anular(): Resposta
    {
        $orig = Bd::um('SELECT * FROM registros WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($orig === null) throw ErroHttp::naoEncontrado();
        $ctx = Acesso::pessoa($this->u(), $orig['pessoa_id']);
        Acesso::exigir($ctx, 'registrar');
        if ($orig['autor_id'] !== $this->uid() && !Acesso::pode($ctx, 'administrar')) {
            throw ErroHttp::proibido('Só quem registrou pode desfazer.');
        }
        if ($orig['anulado_por'] !== null) return Resposta::vazio();
        $v = Valida::de($this->corpo());
        $novo = $v->ulid('id', false) ?? Ulid::novo();
        $motivo = $v->texto('motivo', false, 160) ?? 'Registro desfeito';
        $v->validar();
        Bd::transacao(function () use ($orig, $novo, $motivo) {
            $agora = Tempo::agoraSql();
            Bd::inserir('registros', [
                'id' => $novo, 'pessoa_id' => $orig['pessoa_id'], 'autor_id' => $this->uid(), 'tipo' => 'nota',
                'rotulo' => 'Correção', 'detalhe' => mb_substr($motivo . ': ' . $orig['rotulo'], 0, 255),
                'anula_id' => $orig['id'], 'ocorrido_em' => $agora, 'recebido_em' => $agora,
            ], true);
            Bd::exec('UPDATE registros SET anulado_por = :n WHERE id = :id AND anulado_por IS NULL', ['n' => $novo, 'id' => $orig['id']]);
            Bd::exec('UPDATE alertas SET resolvido_em = :a, resolvido_por = :u WHERE referencia_id = :r AND resolvido_em IS NULL',
                ['a' => $agora, 'u' => $this->uid(), 'r' => $orig['id']]);
            Auditoria::registrar('registro.anulado', 'registro', $orig['id'], $orig['pessoa_id']);
        });
        return Resposta::vazio();
    }

    /** GET /pessoas/{id}/registros?tipo=&de=&ate=&antes= — linha do tempo com filtros */
    public function linhaDoTempo(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'ver_registros');
        $sql = 'SELECT * FROM registros WHERE pessoa_id = :p';
        $par = ['p' => $ctx['pessoa']['id']];
        $tipos = array_values(array_intersect(explode(',', (string)$this->req->q('tipo', '')), self::TIPOS));
        if ($tipos !== []) {
            [$in, $pin] = Bd::lista('t', $tipos);
            $sql .= " AND tipo IN ($in)";
            $par += $pin;
        }
        if ($this->req->q('alertas') === '1') $sql .= ' AND alerta = 1';
        if (($de = $this->req->q('de')) !== null) { $sql .= ' AND ocorrido_em >= :de'; $par['de'] = Tempo::isoParaSql($de); }
        if (($ate = $this->req->q('ate')) !== null) { $sql .= ' AND ocorrido_em < :ate'; $par['ate'] = Tempo::isoParaSql($ate); }
        if (($antes = $this->req->q('antes')) !== null) { $sql .= ' AND ocorrido_em < :antes'; $par['antes'] = Tempo::isoParaSql($antes); }
        $sql .= ' ORDER BY ocorrido_em DESC, id DESC LIMIT 100';
        $regs = array_map([Formata::class, 'registro'], Bd::todos($sql, $par));
        $presencas = [];
        if ($this->req->q('presencas') === '1') {
            // Registro de presença: só início e fim. Sem totais de horas, atrasos ou ranking.
            $presencas = array_map(fn($p) => [
                'id' => $p['id'], 'cuidador' => Formata::pessoaPublica($p['cuidador_id']),
                'inicio' => Tempo::sqlParaIso($p['inicio_em']), 'fim' => Tempo::sqlParaIso($p['fim_em']),
            ], Bd::todos('SELECT * FROM presencas WHERE pessoa_id = :p ORDER BY inicio_em DESC LIMIT 60', ['p' => $ctx['pessoa']['id']]));
        }
        return Resposta::json(['registros' => $regs, 'presencas' => $presencas, 'tem_mais' => count($regs) === 100]);
    }

    // --------------------------------------------------------------- remédios

    /** GET /pessoas/{id}/remedios */
    public function remedios(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'remedios_ver');
        $lista = Bd::todos('SELECT * FROM remedios WHERE pessoa_id = :p ORDER BY ativo DESC, nome', ['p' => $ctx['pessoa']['id']]);
        return Resposta::json(['remedios' => array_map([Formata::class, 'remedio'], $lista)]);
    }

    /** POST /pessoas/{id}/remedios {nome, dose, como_dar, horarios[]} */
    public function criarRemedio(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'remedios_editar');
        $dados = $this->dadosRemedio(true);
        $id = Ulid::novo();
        $agora = Tempo::agoraSql();
        Bd::inserir('remedios', $dados + [
            'id' => $id, 'pessoa_id' => $ctx['pessoa']['id'], 'ativo' => 1,
            'criado_por' => $this->uid(), 'criado_em' => $agora, 'atualizado_em' => $agora,
        ]);
        Auditoria::registrar('remedio.criado', 'remedio', $id, $ctx['pessoa']['id']);
        Conversas::sistema(Conversas::daPessoa($ctx['pessoa']['id']), "💊 Remédio novo nos lembretes: {$dados['nome']}" . ($dados['dose'] ? " {$dados['dose']}" : '') . '.');
        return Resposta::json(Formata::remedio(Bd::um('SELECT * FROM remedios WHERE id = :id', ['id' => $id])), 201);
    }

    /** PATCH /remedios/{id} {nome?, dose?, como_dar?, horarios?, ativo?} */
    public function atualizarRemedio(): Resposta
    {
        $r = Bd::um('SELECT * FROM remedios WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($r === null) throw ErroHttp::naoEncontrado();
        $ctx = Acesso::pessoa($this->u(), $r['pessoa_id']);
        Acesso::exigir($ctx, 'remedios_editar');
        $dados = $this->dadosRemedio(false);
        if (array_key_exists('ativo', $this->corpo())) $dados['ativo'] = (int)Valida::de($this->corpo())->booleano('ativo');
        if ($dados !== []) {
            $dados['atualizado_em'] = Tempo::agoraSql();
            Bd::atualizar('remedios', $dados, ['id' => $r['id']]);
            Auditoria::registrar('remedio.atualizado', 'remedio', $r['id'], $r['pessoa_id'], ['campos' => array_keys($dados)]);
            if (isset($dados['ativo']) && $dados['ativo'] === 0) {
                Conversas::sistema(Conversas::daPessoa($r['pessoa_id']), "💊 {$r['nome']} foi suspenso. Não dar mais.");
            }
        }
        return Resposta::json(Formata::remedio(Bd::um('SELECT * FROM remedios WHERE id = :id', ['id' => $r['id']])));
    }

    // --------------------------------------------------------------- presença

    /** POST /pessoas/{id}/presencas {id, em, atendimento_id?} — "Iniciar serviço" */
    public function iniciarServico(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        if (!$ctx['cuidador']) throw ErroHttp::proibido('Só o(a) cuidador(a) inicia o serviço.');
        $pid = $ctx['pessoa']['id'];
        $uid = $this->uid();
        $v = Valida::de($this->corpo());
        $id = $v->ulid('id');
        $atId = $v->ulid('atendimento_id', false);
        $v->validar();
        $em = Tempo::horaDoAparelho($this->corpo()['em'] ?? null);

        $aberta = Bd::um('SELECT * FROM presencas WHERE pessoa_id = :p AND cuidador_id = :u AND fim_em IS NULL', ['p' => $pid, 'u' => $uid]);
        if ($aberta !== null) {
            return Resposta::json(['id' => $aberta['id'], 'desde' => Tempo::sqlParaIso($aberta['inicio_em'])]);
        }
        if ($atId === null) {
            // Liga ao atendimento combinado de hoje, se houver.
            $atId = Bd::valor(
                "SELECT id FROM atendimentos WHERE pessoa_id = :p AND cuidador_id = :u AND status = 'aceito' AND inicio <= :lim AND fim >= :em ORDER BY inicio LIMIT 1",
                ['p' => $pid, 'u' => $uid, 'lim' => Tempo::utc($em)->modify('+3 hours')->format('Y-m-d H:i:s'), 'em' => $em]
            );
        }
        Bd::inserir('presencas', [
            'id' => $id, 'pessoa_id' => $pid, 'cuidador_id' => $uid, 'atendimento_id' => $atId,
            'inicio_em' => $em, 'criado_em' => Tempo::agoraSql(),
        ], true);
        $hora = Tempo::local(Tempo::utc($em))->format('H:i');
        Conversas::sistema(Conversas::daPessoa($pid), "▶️ Serviço iniciado às $hora.");
        return Resposta::json(['id' => $id, 'desde' => Tempo::sqlParaIso($em)], 201);
    }

    /** POST /presencas/{id}/finalizar {em, recado?} — "Finalizar" com resumo automático */
    public function finalizarServico(): Resposta
    {
        $pr = Bd::um('SELECT * FROM presencas WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($pr === null || $pr['cuidador_id'] !== $this->uid()) throw ErroHttp::naoEncontrado();
        if ($pr['fim_em'] !== null) return Resposta::json(['resumo' => json_decode((string)$pr['resumo'], true)]);
        $em = Tempo::horaDoAparelho($this->corpo()['em'] ?? null);
        if ($em < $pr['inicio_em']) $em = $pr['inicio_em'];
        $recado = Valida::de($this->corpo())->texto('recado', false, 1000);

        $contagem = [];
        foreach (Bd::todos(
            'SELECT tipo, rotulo FROM registros WHERE pessoa_id = :p AND autor_id = :u AND anulado_por IS NULL AND ocorrido_em >= :i AND ocorrido_em <= :f',
            ['p' => $pr['pessoa_id'], 'u' => $this->uid(), 'i' => $pr['inicio_em'], 'f' => $em]
        ) as $r) {
            $contagem[$r['tipo']] = ($contagem[$r['tipo']] ?? 0) + 1;
        }
        $resumo = ['registros' => $contagem, 'recado' => $recado];
        Bd::transacao(function () use ($pr, $em, $resumo, $recado, $contagem) {
            Bd::atualizar('presencas', ['fim_em' => $em, 'resumo' => $resumo], ['id' => $pr['id']]);
            $cid = Conversas::daPessoa($pr['pessoa_id']);
            if ($recado) Conversas::enviar($cid, $this->uid(), 'texto', '📝 Recado: ' . $recado);
            $partes = [];
            foreach ($contagem as $t => $n) $partes[] = ItensResumo::rotulo($t, $n);
            $hora = Tempo::local(Tempo::utc($em))->format('H:i');
            Conversas::sistema($cid, "✅ Serviço finalizado às $hora." . ($partes ? ' Resumo: ' . implode(', ', $partes) . '.' : ''));
        });
        return Resposta::json(['resumo' => $resumo]);
    }

    // ---------------------------------------------------------------- apoio

    /** @return array<string,mixed> */
    private function mensagemDoCorpo(string $conversaId, ?string $pessoaId): array
    {
        $d = $this->corpo();
        $v = Valida::de($d);
        $id = $v->ulid('id');
        $tipo = $v->opcao('tipo', ['texto', 'audio', 'foto'], true, 'texto');
        $texto = $v->texto('texto', $tipo === 'texto', 4000);
        $midia = $v->ulid('midia_id', $tipo !== 'texto');
        $v->validar();
        if ($midia !== null) $this->exigirMidiaPropria($midia, $pessoaId, $conversaId);
        return Conversas::enviar($conversaId, $this->uid(), (string)$tipo, $texto, $midia, $id, Tempo::horaDoAparelho($d['em'] ?? null));
    }

    private function exigirMidiaPropria(string $midiaId, ?string $pessoaId, ?string $conversaId = null): void
    {
        $m = Bd::um('SELECT autor_id, pessoa_id, conversa_id FROM midias WHERE id = :id AND excluido_em IS NULL', ['id' => $midiaId]);
        if ($m === null || $m['autor_id'] !== $this->uid() || ($pessoaId !== null && $m['pessoa_id'] !== $pessoaId && $m['conversa_id'] !== $conversaId)) {
            throw ErroHttp::validacao(['midia_id' => 'Foto ou áudio não encontrado. Envie de novo']);
        }
    }

    /** @return array<string,mixed> */
    private function dadosRemedio(bool $obrigatorio): array
    {
        $d = $this->corpo();
        $v = Valida::de($d);
        $s = [];
        if ($obrigatorio || array_key_exists('nome', $d)) $s['nome'] = $v->texto('nome', true, 120);
        if ($obrigatorio || array_key_exists('dose', $d)) $s['dose'] = $v->texto('dose', false, 80);
        if ($obrigatorio || array_key_exists('como_dar', $d)) $s['como_dar'] = $v->texto('como_dar', false, 255);
        if ($obrigatorio || array_key_exists('horarios', $d)) {
            $h = $v->lista('horarios', true, 12) ?? [];
            $ok = [];
            foreach ($h as $x) {
                if (!is_string($x) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $x)) { $v->erro('horarios', 'Use horários como 08:00'); break; }
                $ok[] = $x;
            }
            sort($ok);
            $s['horarios'] = array_values(array_unique($ok));
        }
        $v->validar();
        return $s;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function limparDados(string $tipo, array $d): array
    {
        $num = fn($k, $min, $max) => isset($d[$k]) && is_numeric($d[$k]) && $d[$k] >= $min && $d[$k] <= $max ? $d[$k] + 0 : null;
        $saida = match ($tipo) {
            'remedio' => ['situacao' => in_array($d['situacao'] ?? '', ['dado', 'recusou', 'nao_dei'], true) ? $d['situacao'] : 'dado'],
            'agua' => ['copos' => (int)($num('copos', 0, 10) ?? 1)],
            'humor' => ['nivel' => $num('nivel', 1, 5)],
            'sinais' => [
                'temperatura' => $num('temperatura', 30, 45),
                'pressao_sist' => $num('pressao_sist', 50, 260),
                'pressao_diast' => $num('pressao_diast', 30, 160),
                'glicemia' => $num('glicemia', 10, 700),
                'saturacao' => $num('saturacao', 50, 100),
            ],
            'gasto' => ['valor_centavos' => $num('valor_centavos', 0, 10_000_000)],
            default => array_map(fn($x) => is_scalar($x) ? mb_substr((string)$x, 0, 120) : null, array_slice($d, 0, 10, true)),
        };
        return array_filter($saida, fn($x) => $x !== null);
    }

    /** Sinais fora do comum avisam o(a) gestor(a) mesmo que o app não tenha marcado. */
    private static function alertaAutomatico(string $tipo, array $d): bool
    {
        if ($tipo === 'algo') return true;
        if ($tipo !== 'sinais') return false;
        return (($d['temperatura'] ?? 0) >= 37.8)
            || (($d['pressao_sist'] ?? 120) >= 140 || ($d['pressao_sist'] ?? 120) <= 90)
            || (($d['pressao_diast'] ?? 80) >= 90 && isset($d['pressao_diast']))
            || (isset($d['glicemia']) && ($d['glicemia'] < 70 || $d['glicemia'] > 250))
            || (isset($d['saturacao']) && $d['saturacao'] < 92);
    }
}
