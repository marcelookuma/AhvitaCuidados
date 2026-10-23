<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Auth\Sessao;
use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

final class EuControlador extends Controlador
{
    /** GET /eu — quem sou e quais papéis tenho (decide qual app abrir) */
    public function ver(): Resposta
    {
        $u = $this->u();
        $vinculos = Bd::todos(
            "SELECT v.pessoa_id, v.papel, v.atua_como_cuidador, p.apelido
               FROM vinculos v JOIN pessoas_cuidadas p ON p.id = v.pessoa_id
              WHERE v.usuario_id = :u AND v.status = 'ativo' AND p.excluido_em IS NULL
              ORDER BY p.apelido",
            ['u' => $u['id']]
        );
        $empresas = Bd::todos(
            'SELECT e.id, e.nome_fantasia, e.razao_social, m.papel FROM empresa_membros m JOIN empresas e ON e.id = m.empresa_id
              WHERE m.usuario_id = :u AND m.ativo = 1',
            ['u' => $u['id']]
        );
        $redeEmpresas = (int)Bd::valor("SELECT COUNT(*) FROM empresa_cuidadores WHERE usuario_id = :u AND status = 'ativo'", ['u' => $u['id']]);
        $ehCuidador = $redeEmpresas > 0 || count(array_filter($vinculos, fn($v) => $v['papel'] === 'cuidador' || (int)$v['atua_como_cuidador'] === 1)) > 0;
        $acompanha = count(array_filter($vinculos, fn($v) => in_array($v['papel'], ['gestor', 'rede', 'profissional'], true) && (int)$v['atua_como_cuidador'] === 0)) > 0;
        $consentiu = (int)Bd::valor("SELECT COUNT(*) FROM consentimentos WHERE usuario_id = :u AND tipo = 'termos' AND revogado_em IS NULL", ['u' => $u['id']]) > 0;

        return Resposta::json([
            'usuario' => Formata::eu($u),
            'perfil_completo' => $u['nome'] !== null && $u['cpf'] !== null,
            'aceitou_termos' => $consentiu,
            'termos_versao' => Config::get('termos.versao'),
            'papeis' => [
                'cuidador' => $ehCuidador,
                'acompanha' => $acompanha,
                'empresa' => count($empresas) > 0,
            ],
            'pessoas' => array_map(fn($v) => ['id' => $v['pessoa_id'], 'apelido' => $v['apelido'], 'papel' => $v['papel'], 'atua_como_cuidador' => (bool)$v['atua_como_cuidador']], $vinculos),
            'empresas' => array_map(fn($e) => ['id' => $e['id'], 'nome' => $e['nome_fantasia'] ?: $e['razao_social'], 'papel' => $e['papel']], $empresas),
        ]);
    }

    /** PATCH /eu {nome, apelido, cpf, preferencias} */
    public function atualizar(): Resposta
    {
        $d = $this->corpo();
        $v = Valida::de($d);
        $campos = [];
        if (array_key_exists('nome', $d)) $campos['nome'] = $v->texto('nome', true, 120, 3);
        if (array_key_exists('apelido', $d)) $campos['apelido'] = $v->texto('apelido', false, 60);
        if (array_key_exists('cpf', $d)) $campos['cpf'] = $v->cpf('cpf');
        if (array_key_exists('preferencias', $d)) {
            $pref = $v->objeto('preferencias', false, 2000) ?? [];
            $campos['preferencias'] = [
                'fonte' => max(1, min(3, (int)($pref['fonte'] ?? 1))),
                'voz' => (bool)($pref['voz'] ?? false),
                'modo' => in_array($pref['modo'] ?? 'conversa', ['conversa', 'padrao'], true) ? ($pref['modo'] ?? 'conversa') : 'conversa',
            ];
        }
        $v->validar();
        if (isset($campos['cpf']) && $campos['cpf'] !== $this->u()['cpf']) {
            $outro = Bd::valor('SELECT id FROM usuarios WHERE cpf = :c AND id <> :u', ['c' => $campos['cpf'], 'u' => $this->uid()]);
            if ($outro !== null) {
                throw ErroHttp::conflito('cpf_em_uso', 'Este CPF já tem conta. Entre com o celular ou e-mail dessa conta.');
            }
        }
        if ($campos !== []) {
            $campos['atualizado_em'] = Tempo::agoraSql();
            Bd::atualizar('usuarios', $campos, ['id' => $this->uid()]);
            Auditoria::registrar('conta.atualizada', 'usuario', $this->uid(), null, ['campos' => array_keys($campos)]);
        }
        return Resposta::json(Formata::eu(Bd::um('SELECT * FROM usuarios WHERE id = :id', ['id' => $this->uid()])));
    }

    /** POST /eu/termos — aceite dos termos de uso e da política de privacidade */
    public function aceitarTermos(): Resposta
    {
        Bd::inserir('consentimentos', [
            'id' => Ulid::novo(),
            'usuario_id' => $this->uid(),
            'tipo' => 'termos',
            'versao' => (string)Config::get('termos.versao'),
            'ip' => $this->req->ip,
            'aceito_em' => Tempo::agoraSql(),
        ]);
        Auditoria::registrar('termos.aceitos', 'usuario', $this->uid());
        return Resposta::vazio();
    }

    /**
     * GET /eu/inicio — lista de conversas do(a) cuidador(a), estilo WhatsApp.
     * Cada pessoa cuidada é uma conversa; conversas com empresas vêm depois.
     */
    public function inicio(): Resposta
    {
        $uid = $this->uid();
        $agora = Tempo::agora();
        $pessoas = Bd::todos(
            "SELECT p.id, p.apelido, p.sexo, v.papel
               FROM vinculos v JOIN pessoas_cuidadas p ON p.id = v.pessoa_id
              WHERE v.usuario_id = :u AND v.status = 'ativo' AND p.excluido_em IS NULL
                AND (v.papel = 'cuidador' OR v.atua_como_cuidador = 1)",
            ['u' => $uid]
        );
        $conversas = [];
        foreach ($pessoas as $p) {
            $cid = Conversas::daPessoa($p['id']);
            $ultMsg = Bd::um('SELECT * FROM mensagens WHERE conversa_id = :c ORDER BY recebido_em DESC, id DESC LIMIT 1', ['c' => $cid]);
            $ultReg = Bd::um('SELECT * FROM registros WHERE pessoa_id = :p AND anulado_por IS NULL ORDER BY recebido_em DESC LIMIT 1', ['p' => $p['id']]);
            $ultimo = null;
            if ($ultMsg && (!$ultReg || $ultMsg['recebido_em'] >= $ultReg['recebido_em'])) $ultimo = Formata::mensagem($ultMsg);
            elseif ($ultReg) $ultimo = Formata::registro($ultReg);
            $emServico = Bd::um('SELECT id, inicio_em FROM presencas WHERE pessoa_id = :p AND cuidador_id = :u AND fim_em IS NULL ORDER BY inicio_em DESC LIMIT 1', ['p' => $p['id'], 'u' => $uid]);
            $proximo = Bd::um(
                "SELECT * FROM atendimentos WHERE pessoa_id = :p AND cuidador_id = :u AND status = 'aceito' AND fim > :a ORDER BY inicio LIMIT 1",
                ['p' => $p['id'], 'u' => $uid, 'a' => $agora->format('Y-m-d H:i:s')]
            );
            $pendencias = (int)Bd::valor(
                "SELECT COUNT(*) FROM atendimentos WHERE pessoa_id = :p AND cuidador_id = :u AND
                   ((status = 'proposto') OR (status = 'aceito' AND confirmacao IS NULL AND inicio > :a AND inicio <= :lim))",
                ['p' => $p['id'], 'u' => $uid, 'a' => $agora->format('Y-m-d H:i:s'), 'lim' => $agora->modify('+24 hours')->format('Y-m-d H:i:s')]
            );
            $conversas[] = [
                'tipo' => 'cuidado',
                'pessoa_id' => $p['id'],
                'conversa_id' => $cid,
                'nome' => $p['apelido'],
                'sexo' => $p['sexo'],
                'ultimo' => $ultimo,
                'nao_lidas' => Conversas::naoLidas($cid, $uid) + $pendencias,
                'em_servico_desde' => $emServico ? Tempo::sqlParaIso($emServico['inicio_em']) : null,
                'presenca_id' => $emServico['id'] ?? null,
                'proximo_atendimento' => $proximo ? Formata::atendimento($proximo) : null,
            ];
        }
        foreach (Bd::todos(
            "SELECT c.id, e.nome_fantasia, e.razao_social FROM conversas c JOIN empresas e ON e.id = c.empresa_id
              WHERE c.tipo = 'direta' AND c.usuario_id = :u",
            ['u' => $uid]
        ) as $c) {
            $ult = Bd::um('SELECT * FROM mensagens WHERE conversa_id = :c ORDER BY recebido_em DESC LIMIT 1', ['c' => $c['id']]);
            $conversas[] = [
                'tipo' => 'direta',
                'conversa_id' => $c['id'],
                'nome' => $c['nome_fantasia'] ?: $c['razao_social'],
                'ultimo' => $ult ? Formata::mensagem($ult) : null,
                'nao_lidas' => Conversas::naoLidas($c['id'], $uid),
            ];
        }
        usort($conversas, fn($a, $b) => strcmp($b['ultimo']['recebido_em'] ?? '', $a['ultimo']['recebido_em'] ?? ''));

        $propostasEmpresa = (int)Bd::valor(
            "SELECT COUNT(*) FROM proposta_destinatarios d JOIN atendimentos a ON a.id = d.atendimento_id
              WHERE d.cuidador_id = :u AND d.status = 'enviada' AND a.status = 'proposto' AND a.inicio > :a",
            ['u' => $uid, 'a' => $agora->format('Y-m-d H:i:s')]
        );
        return Resposta::json(['conversas' => $conversas, 'propostas_abertas' => $propostasEmpresa]);
    }

    /** GET /eu/exportar — portabilidade (LGPD art. 18) dos dados da própria conta */
    public function exportar(): Resposta
    {
        $uid = $this->uid();
        $dados = [
            'gerado_em' => Tempo::sqlParaIso(Tempo::agoraSql()),
            'conta' => Formata::eu($this->u()),
            'vinculos' => array_map([Formata::class, 'vinculo'], Bd::todos('SELECT * FROM vinculos WHERE usuario_id = :u', ['u' => $uid])),
            'agenda' => Bd::todos('SELECT dia_semana, inicio, fim FROM disponibilidades WHERE usuario_id = :u', ['u' => $uid]),
            'atendimentos' => array_map([Formata::class, 'atendimento'], Bd::todos('SELECT * FROM atendimentos WHERE cuidador_id = :u OR solicitante_id = :u2', ['u' => $uid, 'u2' => $uid])),
            'presencas' => Bd::todos('SELECT pessoa_id, inicio_em, fim_em FROM presencas WHERE cuidador_id = :u', ['u' => $uid]),
            'registros_que_fiz' => array_map([Formata::class, 'registro'], Bd::todos('SELECT * FROM registros WHERE autor_id = :u', ['u' => $uid])),
            'consentimentos' => Bd::todos('SELECT tipo, versao, aceito_em, revogado_em, pessoa_id FROM consentimentos WHERE usuario_id = :u', ['u' => $uid]),
        ];
        Auditoria::registrar('conta.exportada', 'usuario', $uid);
        $r = Resposta::json($dados);
        $r->cabecalhos['Content-Disposition'] = 'attachment; filename="meus-dados-cuidados.json"';
        return $r;
    }

    /** POST /eu/excluir {confirmar:"EXCLUIR"} */
    public function excluir(): Resposta
    {
        if (($this->corpo()['confirmar'] ?? '') !== 'EXCLUIR') {
            throw ErroHttp::validacao(['confirmar' => 'Digite EXCLUIR para confirmar']);
        }
        self::excluirConta($this->uid());
        $r = Resposta::vazio();
        Sessao::cookie($r, '', true);
        return $r;
    }

    /**
     * Exclusão (anonimização) de uma conta. Usada pela própria pessoa (POST /eu/excluir)
     * e pelo painel /admin quando o titular pede pelo suporte (LGPD, art. 18).
     * @param array<string,mixed> $dadosAuditoria
     */
    public static function excluirConta(string $uid, array $dadosAuditoria = []): void
    {
        $soEu = Bd::todos(
            "SELECT v.pessoa_id FROM vinculos v WHERE v.usuario_id = :u AND v.papel = 'gestor' AND v.status = 'ativo'
               AND (SELECT COUNT(*) FROM vinculos v2 WHERE v2.pessoa_id = v.pessoa_id AND v2.papel = 'gestor' AND v2.status = 'ativo') = 1",
            ['u' => $uid]
        );
        if ($soEu !== []) {
            throw ErroHttp::conflito('unico_gestor', 'Você é o(a) único(a) gestor(a) de uma pessoa cuidada. Passe a gestão para alguém ou exclua os dados dela antes.');
        }
        Bd::transacao(function () use ($uid, $dadosAuditoria) {
            $agora = Tempo::agoraSql();
            Bd::exec("UPDATE vinculos SET status = 'encerrado', encerrado_em = :a WHERE usuario_id = :u AND status = 'ativo'", ['a' => $agora, 'u' => $uid]);
            Bd::exec("UPDATE empresa_cuidadores SET status = 'encerrado', encerrado_em = :a WHERE usuario_id = :u", ['a' => $agora, 'u' => $uid]);
            Bd::exec('UPDATE empresa_membros SET ativo = 0 WHERE usuario_id = :u', ['u' => $uid]);
            Bd::exec('DELETE FROM disponibilidades WHERE usuario_id = :u', ['u' => $uid]);
            Bd::exec('DELETE FROM bloqueios_agenda WHERE usuario_id = :u', ['u' => $uid]);
            Bd::exec(
                "UPDATE usuarios SET nome = 'Conta excluída', apelido = NULL, cpf = NULL, telefone = NULL, email = NULL, google_sub = NULL,
                        preferencias = NULL, excluido_em = :a, atualizado_em = :a2 WHERE id = :u",
                ['a' => $agora, 'a2' => $agora, 'u' => $uid]
            );
            Sessao::revogarTodas($uid);
            Auditoria::registrar('conta.excluida', 'usuario', $uid, null, $dadosAuditoria);
        });
    }
}
