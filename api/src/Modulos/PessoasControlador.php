<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

final class PessoasControlador extends Controlador
{
    public const ARRANJOS = ['somente_eu', 'so_cuidador', 'gestor_cuidador', 'gestor_rede', 'gestor_rede_cuidador'];
    public const SEXOS = ['feminino', 'masculino', 'intersexo', 'nao_informar'];

    /** GET /pessoas — pessoas que acompanho (gestor, rede, profissional, empresa) */
    public function listar(): Resposta
    {
        $uid = $this->uid();
        $linhas = Bd::todos(
            "SELECT p.id FROM vinculos v JOIN pessoas_cuidadas p ON p.id = v.pessoa_id
              WHERE v.usuario_id = :u AND v.status = 'ativo' AND p.excluido_em IS NULL
             UNION
             SELECT p.id FROM empresa_membros m JOIN pessoas_cuidadas p ON p.empresa_id = m.empresa_id
              WHERE m.usuario_id = :u2 AND m.ativo = 1 AND p.excluido_em IS NULL",
            ['u' => $uid, 'u2' => $uid]
        );
        $saida = [];
        foreach ($linhas as $l) {
            $ctx = Acesso::pessoa($this->u(), $l['id']);
            $saida[] = Formata::pessoa($ctx);
        }
        usort($saida, fn($a, $b) => strcmp($a['apelido'], $b['apelido']));
        return Resposta::json(['pessoas' => $saida]);
    }

    /**
     * POST /pessoas — cadastro feito pelo(a) gestor(a) no onboarding.
     * Data de nascimento obrigatória; menor de 18 exige consentimento do responsável legal.
     */
    public function criar(): Resposta
    {
        $d = $this->corpo();
        $v = Valida::de($d);
        $nome = $v->texto('nome_completo', true, 160, 3);
        $apelido = $v->texto('apelido', true, 60);
        $nasc = $v->data('nascimento');
        $sexo = $v->opcao('sexo', self::SEXOS);
        $genero = $v->texto('genero', false, 60);
        $cpf = $v->cpf('cpf', false);
        $relacao = $v->texto('relacao', false, 40) ?? 'Tutor(a)';
        $arranjo = $v->opcao('arranjo', self::ARRANJOS, true, 'somente_eu');
        $ficha = $v->objeto('ficha', false);
        $cons = $v->objeto('consentimentos', true, 500) ?? [];
        $menor = false;
        if ($nasc !== null) {
            if ($nasc > Tempo::local(Tempo::agora())->format('Y-m-d')) $v->erro('nascimento', 'A data não pode ser no futuro');
            elseif ($nasc < '1900-01-01') $v->erro('nascimento', 'Confira o ano');
            else $menor = Tempo::idade($nasc) < 18;
        }
        if (($cons['dados_saude'] ?? false) !== true) {
            $v->erro('consentimentos', 'É preciso autorizar o uso dos dados de saúde para o cuidado');
        }
        if ($menor && ($cons['responsavel_legal'] ?? false) !== true) {
            $v->erro('consentimentos', 'Para menores de 18 anos, confirme que você é o responsável legal');
        }
        $v->validar();

        $uid = $this->uid();
        $id = Ulid::novo();
        Bd::transacao(function () use ($id, $uid, $nome, $apelido, $nasc, $sexo, $genero, $cpf, $arranjo, $ficha, $relacao, $menor) {
            $agora = Tempo::agoraSql();
            Bd::inserir('pessoas_cuidadas', [
                'id' => $id,
                'nome_completo' => $nome,
                'apelido' => $apelido,
                'nascimento' => $nasc,
                'sexo' => $sexo,
                'genero' => $genero,
                'cpf' => $cpf,
                'arranjo' => $arranjo,
                'ficha' => self::fichaLimpa($ficha ?? []),
                'plano' => ['texto' => '', 'importante' => ''],
                'criado_por' => $uid,
                'criado_em' => $agora,
                'atualizado_em' => $agora,
            ]);
            Bd::inserir('vinculos', [
                'id' => Ulid::novo(),
                'pessoa_id' => $id,
                'usuario_id' => $uid,
                'papel' => 'gestor',
                'relacao' => $relacao,
                'permissoes' => Permissoes::padrao('gestor'),
                'criado_em' => $agora,
            ]);
            foreach (array_filter(['dados_saude' => true, 'responsavel_legal' => $menor]) as $tipo => $_) {
                Bd::inserir('consentimentos', [
                    'id' => Ulid::novo(), 'usuario_id' => $uid, 'pessoa_id' => $id, 'tipo' => $tipo,
                    'versao' => (string)Config::get('termos.versao'), 'ip' => $this->req->ip, 'aceito_em' => $agora,
                ]);
            }
            Conversas::sistema(Conversas::daPessoa($id), 'Conversa criada. Tudo o que for registrado aqui fica guardado com dia e hora.');
            Auditoria::registrar('pessoa.criada', 'pessoa', $id, $id, ['arranjo' => $arranjo, 'menor' => $menor]);
        });
        return Resposta::json(Formata::pessoa(Acesso::pessoa($this->u(), $id)), 201);
    }

    /** GET /pessoas/{id} */
    public function ver(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        return Resposta::json(Formata::pessoa($ctx));
    }

    /** PATCH /pessoas/{id} — cadastro e ficha (administrar) e plano de cuidado (plano_editar) */
    public function atualizar(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        $d = $this->corpo();
        $v = Valida::de($d);
        $campos = [];
        $cadastro = ['nome_completo', 'apelido', 'nascimento', 'sexo', 'genero', 'cpf', 'arranjo', 'ficha', 'empresa_permissoes'];
        if (array_intersect(array_keys($d), $cadastro) !== []) Acesso::exigir($ctx, 'administrar');
        if (array_key_exists('nome_completo', $d)) $campos['nome_completo'] = $v->texto('nome_completo', true, 160, 3);
        if (array_key_exists('apelido', $d)) $campos['apelido'] = $v->texto('apelido', true, 60);
        if (array_key_exists('nascimento', $d)) $campos['nascimento'] = $v->data('nascimento');
        if (array_key_exists('sexo', $d)) $campos['sexo'] = $v->opcao('sexo', self::SEXOS);
        if (array_key_exists('genero', $d)) $campos['genero'] = $v->texto('genero', false, 60);
        if (array_key_exists('cpf', $d)) $campos['cpf'] = $v->cpf('cpf', false);
        if (array_key_exists('arranjo', $d)) $campos['arranjo'] = $v->opcao('arranjo', self::ARRANJOS);
        if (array_key_exists('ficha', $d)) $campos['ficha'] = self::fichaLimpa($v->objeto('ficha', true) ?? []);
        if (array_key_exists('empresa_permissoes', $d)) {
            $campos['empresa_permissoes'] = ClientesControlador::limpar($d['empresa_permissoes']);
        }
        if (array_key_exists('plano', $d)) {
            Acesso::exigir($ctx, 'plano_editar');
            $pl = $v->objeto('plano', true) ?? [];
            $campos['plano'] = [
                'texto' => mb_substr(trim((string)($pl['texto'] ?? '')), 0, 4000),
                'importante' => mb_substr(trim((string)($pl['importante'] ?? '')), 0, 200),
                'cuidados' => array_values(array_slice(array_map(
                    fn($c) => ['titulo' => mb_substr(trim((string)($c['titulo'] ?? '')), 0, 80), 'como' => mb_substr(trim((string)($c['como'] ?? '')), 0, 400)],
                    array_filter((array)($pl['cuidados'] ?? []), 'is_array')
                ), 0, 30)),
                'agua_meta_copos' => max(0, min(20, (int)($pl['agua_meta_copos'] ?? 0))),
            ];
        }
        $v->validar();
        if ($campos === []) return Resposta::json(Formata::pessoa($ctx));
        $campos['atualizado_em'] = Tempo::agoraSql();
        Bd::atualizar('pessoas_cuidadas', $campos, ['id' => $ctx['pessoa']['id']]);
        Auditoria::registrar('pessoa.atualizada', 'pessoa', $ctx['pessoa']['id'], $ctx['pessoa']['id'], ['campos' => array_keys($campos)]);
        if (isset($campos['plano'])) {
            Conversas::sistema(Conversas::daPessoa($ctx['pessoa']['id']), '📋 O plano de cuidados foi atualizado. Veja em "Como cuidar".');
        }
        return Resposta::json(Formata::pessoa(Acesso::pessoa($this->u(), $ctx['pessoa']['id'])));
    }

    /** GET /pessoas/{id}/painel — resumo do dia para quem acompanha */
    public function painel(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        $pid = $ctx['pessoa']['id'];
        Avisos::verificarCobertura();
        [$iniDia, $fimDia] = Tempo::diaLocalEmUtc();
        $agoraSql = Tempo::agoraSql();

        $saida = [
            'pessoa' => Formata::pessoa($ctx),
            'alertas' => [],
            'em_servico' => [],
            'hoje' => null,
            'proximos_atendimentos' => [],
        ];
        if (Acesso::pode($ctx, 'ver_registros') || Acesso::pode($ctx, 'atendimentos')) {
            $saida['alertas'] = array_map([Formata::class, 'alerta'], Bd::todos(
                'SELECT * FROM alertas WHERE pessoa_id = :p AND resolvido_em IS NULL ORDER BY criado_em DESC LIMIT 30', ['p' => $pid]
            ));
            if (!Acesso::pode($ctx, 'ver_registros')) {
                // Empresa sem acesso aos registros vê só alertas de organização (cobertura), não de saúde.
                $saida['alertas'] = array_values(array_filter($saida['alertas'], fn($a) => in_array($a['tipo'], ['sem_confirmacao', 'nao_vou', 'proposta_recusada'], true)));
            }
            // ⚖️ Empresa não vê presença (quem está em serviço e desde quando).
            $saida['em_servico'] = $ctx['papel'] === 'empresa' ? [] : array_map(fn($p) => [
                'presenca_id' => $p['id'],
                'cuidador' => Formata::pessoaPublica($p['cuidador_id']),
                'desde' => Tempo::sqlParaIso($p['inicio_em']),
            ], Bd::todos('SELECT * FROM presencas WHERE pessoa_id = :p AND fim_em IS NULL', ['p' => $pid]));
            $saida['proximos_atendimentos'] = array_map([Formata::class, 'atendimento'], Bd::todos(
                "SELECT * FROM atendimentos WHERE pessoa_id = :p AND status IN ('proposto','aceito') AND fim > :a ORDER BY inicio LIMIT 5",
                ['p' => $pid, 'a' => $agoraSql]
            ));
        }
        if (Acesso::pode($ctx, 'ver_registros')) {
            $regs = Bd::todos(
                'SELECT tipo, rotulo, detalhe, dados, ocorrido_em FROM registros
                  WHERE pessoa_id = :p AND anulado_por IS NULL AND ocorrido_em >= :i AND ocorrido_em < :f ORDER BY ocorrido_em',
                ['p' => $pid, 'i' => $iniDia, 'f' => $fimDia]
            );
            $copos = 0; $refeicoes = 0; $dados = 0; $recusas = 0; $humor = null;
            foreach ($regs as $r) {
                $dj = json_decode((string)($r['dados'] ?? '{}'), true) ?: [];
                match ($r['tipo']) {
                    'agua' => $copos += (int)($dj['copos'] ?? 0),
                    'comida' => $refeicoes++,
                    'remedio' => ($dj['situacao'] ?? '') === 'dado' ? $dados++ : $recusas++,
                    'humor' => $humor = $r['detalhe'],
                    default => null,
                };
            }
            $previstos = 0;
            foreach (Bd::todos('SELECT horarios FROM remedios WHERE pessoa_id = :p AND ativo = 1', ['p' => $pid]) as $rm) {
                $previstos += count(json_decode((string)$rm['horarios'], true) ?: []);
            }
            $plano = json_decode((string)($ctx['pessoa']['plano'] ?? '{}'), true) ?: [];
            $saida['hoje'] = [
                'registros' => count($regs),
                'agua_copos' => $copos,
                'agua_meta' => (int)($plano['agua_meta_copos'] ?? 0) ?: null,
                'refeicoes' => $refeicoes,
                'remedios_dados' => $dados,
                'remedios_nao_dados' => $recusas,
                'remedios_previstos' => $previstos,
                'humor' => $humor,
            ];
        }
        return Resposta::json($saida);
    }

    /** POST /alertas/{id}/resolver */
    public function resolverAlerta(): Resposta
    {
        $a = Bd::um('SELECT * FROM alertas WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($a === null) throw ErroHttp::naoEncontrado();
        $ctx = Acesso::pessoa($this->u(), $a['pessoa_id']);
        if (!Acesso::pode($ctx, 'ver_registros') && !Acesso::pode($ctx, 'atendimentos')) throw ErroHttp::proibido();
        Bd::exec('UPDATE alertas SET resolvido_em = :a, resolvido_por = :u WHERE id = :id AND resolvido_em IS NULL',
            ['a' => Tempo::agoraSql(), 'u' => $this->uid(), 'id' => $a['id']]);
        return Resposta::vazio();
    }

    /** GET /pessoas/{id}/vinculos — quem participa do cuidado */
    public function vinculos(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        $todos = Bd::todos("SELECT * FROM vinculos WHERE pessoa_id = :p ORDER BY status, papel, criado_em", ['p' => $ctx['pessoa']['id']]);
        if (!Acesso::pode($ctx, 'administrar')) {
            // Quem não administra vê só os nomes e papéis dos ativos (para saber com quem conversa).
            $todos = array_values(array_filter($todos, fn($v) => $v['status'] === 'ativo'));
            return Resposta::json(['vinculos' => array_map(fn($v) => [
                'id' => $v['id'], 'usuario' => Formata::pessoaPublica($v['usuario_id']), 'papel' => $v['papel'], 'relacao' => $v['relacao'],
            ], $todos)]);
        }
        $convites = Bd::todos(
            'SELECT id, codigo, papel, dados, destino_nome, expira_em FROM convites
              WHERE pessoa_id = :p AND aceito_em IS NULL AND revogado_em IS NULL AND expira_em > :a',
            ['p' => $ctx['pessoa']['id'], 'a' => Tempo::agoraSql()]
        );
        $empresa = null;
        if ($ctx['pessoa']['empresa_id'] !== null) {
            $e = Bd::um('SELECT id, nome_fantasia, razao_social FROM empresas WHERE id = :id', ['id' => $ctx['pessoa']['empresa_id']]);
            $empresa = ['id' => $e['id'], 'nome' => $e['nome_fantasia'] ?: $e['razao_social'],
                'permissoes' => json_decode((string)($ctx['pessoa']['empresa_permissoes'] ?? '[]'), true) ?: []];
        }
        return Resposta::json([
            'vinculos' => array_map([Formata::class, 'vinculo'], $todos),
            'convites_pendentes' => array_map(fn($c) => [
                'id' => $c['id'], 'codigo' => $c['codigo'], 'papel' => $c['papel'], 'nome' => $c['destino_nome'],
                'expira_em' => Tempo::sqlParaIso($c['expira_em']), 'dados' => json_decode((string)$c['dados'], true),
            ], $convites),
            'empresa' => $empresa,
        ]);
    }

    /** POST /pessoas/{id}/empresa/desvincular — o(a) gestor(a) encerra a relação com a empresa */
    public function desvincularEmpresa(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'administrar');
        $empresaId = $ctx['pessoa']['empresa_id'];
        Bd::atualizar('pessoas_cuidadas', ['empresa_id' => null, 'empresa_permissoes' => null, 'atualizado_em' => Tempo::agoraSql()], ['id' => $ctx['pessoa']['id']]);
        Bd::exec("UPDATE consentimentos SET revogado_em = :a WHERE pessoa_id = :p AND tipo = 'compartilhar_empresa' AND revogado_em IS NULL",
            ['a' => Tempo::agoraSql(), 'p' => $ctx['pessoa']['id']]);
        if ($empresaId !== null) {
            $e = Bd::um('SELECT nome_fantasia, razao_social FROM empresas WHERE id = :id', ['id' => $empresaId]);
            if ($e !== null) Conversas::sistema(Conversas::daPessoa((string)$ctx['pessoa']['id']), '🏢 A empresa ' . ($e['nome_fantasia'] ?: $e['razao_social']) . ' deixou de acompanhar o cuidado.');
        }
        Auditoria::registrar('empresa.desvinculada', 'pessoa', $ctx['pessoa']['id'], $ctx['pessoa']['id'], ['empresa' => $empresaId]);
        return Resposta::vazio();
    }

    /** GET /pessoas/{id}/exportar — cópia de todos os dados (portabilidade) */
    public function exportar(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'administrar');
        $pid = $ctx['pessoa']['id'];
        $cid = Conversas::daPessoa($pid);
        $dados = [
            'gerado_em' => Tempo::sqlParaIso(Tempo::agoraSql()),
            'pessoa' => Formata::pessoa($ctx),
            'vinculos' => array_map([Formata::class, 'vinculo'], Bd::todos('SELECT * FROM vinculos WHERE pessoa_id = :p', ['p' => $pid])),
            'remedios' => array_map([Formata::class, 'remedio'], Bd::todos('SELECT * FROM remedios WHERE pessoa_id = :p', ['p' => $pid])),
            'registros' => array_map([Formata::class, 'registro'], Bd::todos('SELECT * FROM registros WHERE pessoa_id = :p ORDER BY ocorrido_em', ['p' => $pid])),
            'mensagens' => array_map([Formata::class, 'mensagem'], Bd::todos('SELECT * FROM mensagens WHERE conversa_id = :c ORDER BY recebido_em', ['c' => $cid])),
            'atendimentos' => array_map([Formata::class, 'atendimento'], Bd::todos('SELECT * FROM atendimentos WHERE pessoa_id = :p', ['p' => $pid])),
            'presencas' => Bd::todos('SELECT cuidador_id, inicio_em, fim_em FROM presencas WHERE pessoa_id = :p', ['p' => $pid]),
            'consentimentos' => Bd::todos('SELECT usuario_id, tipo, versao, aceito_em, revogado_em FROM consentimentos WHERE pessoa_id = :p', ['p' => $pid]),
        ];
        Auditoria::registrar('pessoa.exportada', 'pessoa', $pid, $pid);
        $r = Resposta::json($dados);
        $r->cabecalhos['Content-Disposition'] = 'attachment; filename="dados-' . $pid . '.json"';
        return $r;
    }

    /**
     * POST /pessoas/{id}/excluir {confirmar: "<apelido>"}
     * Apaga dados de saúde, mensagens e mídias. Mantém só o registro de presença
     * (quem, quando), sem dado de saúde, como comprovação dos serviços prestados.
     * ⚠ Prazo de guarda a validar com o jurídico.
     */
    public function excluir(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'administrar');
        $p = $ctx['pessoa'];
        if (mb_strtolower(trim((string)($this->corpo()['confirmar'] ?? ''))) !== mb_strtolower($p['apelido'])) {
            throw ErroHttp::validacao(['confirmar' => 'Digite o nome "' . $p['apelido'] . '" para confirmar']);
        }
        $pid = $p['id'];
        $arquivos = [];
        Bd::transacao(function () use ($pid, &$arquivos) {
            $agora = Tempo::agoraSql();
            $cid = Conversas::daPessoa($pid);
            foreach (Bd::todos('SELECT caminho FROM midias WHERE pessoa_id = :p OR conversa_id = :c', ['p' => $pid, 'c' => $cid]) as $m) {
                $arquivos[] = $m['caminho'];
            }
            Bd::exec('DELETE FROM midias WHERE pessoa_id = :p OR conversa_id = :c', ['p' => $pid, 'c' => $cid]);
            Bd::exec('DELETE FROM registros WHERE pessoa_id = :p', ['p' => $pid]);
            Bd::exec('DELETE FROM mensagens WHERE conversa_id = :c', ['c' => $cid]);
            Bd::exec('DELETE FROM conversa_leituras WHERE conversa_id = :c', ['c' => $cid]);
            Bd::exec('DELETE FROM remedios WHERE pessoa_id = :p', ['p' => $pid]);
            Bd::exec('DELETE FROM alertas WHERE pessoa_id = :p', ['p' => $pid]);
            Bd::exec('UPDATE presencas SET resumo = NULL WHERE pessoa_id = :p', ['p' => $pid]);
            Bd::exec("UPDATE atendimentos SET status = 'cancelado', observacao = NULL WHERE pessoa_id = :p AND status IN ('proposto','aceito') AND inicio > :a", ['p' => $pid, 'a' => $agora]);
            Bd::exec("UPDATE vinculos SET status = 'encerrado', encerrado_em = :a WHERE pessoa_id = :p AND status = 'ativo'", ['a' => $agora, 'p' => $pid]);
            Bd::exec('UPDATE convites SET revogado_em = :a WHERE pessoa_id = :p AND aceito_em IS NULL', ['a' => $agora, 'p' => $pid]);
            Bd::exec(
                "UPDATE pessoas_cuidadas SET nome_completo = 'Pessoa excluída', apelido = 'Pessoa excluída', cpf = NULL, genero = NULL,
                        ficha = NULL, plano = NULL, empresa_permissoes = NULL, excluido_em = :a, atualizado_em = :a2 WHERE id = :p",
                ['a' => $agora, 'a2' => $agora, 'p' => $pid]
            );
            Auditoria::registrar('pessoa.excluida', 'pessoa', $pid, $pid);
        });
        $base = (string)Config::get('midias.pasta');
        foreach ($arquivos as $a) @unlink($base . '/' . $a);
        return Resposta::vazio();
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function fichaLimpa(array $f): array
    {
        $txt = fn($k, $max = 400) => mb_substr(trim((string)($f[$k] ?? '')), 0, $max);
        $contatos = [];
        foreach (array_slice((array)($f['contatos'] ?? []), 0, 5) as $c) {
            if (!is_array($c)) continue;
            $tel = \Cuidados\Support\Valida::telefone((string)($c['telefone'] ?? ''));
            if ($tel === null) continue;
            $contatos[] = ['nome' => mb_substr(trim((string)($c['nome'] ?? '')), 0, 80), 'telefone' => $tel];
        }
        return [
            'alergias' => $txt('alergias'),
            'condicoes' => $txt('condicoes'),
            'convenio' => $txt('convenio', 120),
            'endereco' => $txt('endereco', 200),
            'observacoes' => $txt('observacoes'),
            'contatos' => $contatos,
        ];
    }
}
