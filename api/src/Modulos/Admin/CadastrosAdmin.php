<?php
declare(strict_types=1);

namespace Cuidados\Modulos\Admin;

use Cuidados\Auth\Sessao;
use Cuidados\Http\Resposta;
use Cuidados\Modulos\EuControlador;
use Cuidados\Modulos\Permissoes;
use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\LogAcesso;
use Cuidados\Support\Tempo;
use Cuidados\Support\Valida;

/**
 * Usuários, pessoas cuidadas, empresas, convites e vínculos.
 * Mostra cadastro, vínculos e contagens — nunca registros, mensagens, ficha,
 * plano, remédios, fotos ou áudios (minimização, LGPD art. 6º, III).
 */
final class CadastrosAdmin extends AdminBase
{
    // ------------------------------------------------------------------ usuários

    /** GET /admin/usuarios?q=&papel=&status=&pagina= */
    public function usuarios(): Resposta
    {
        $this->adm();
        [$pag, $pp, $off] = $this->paginacao();
        $onde = ['1 = 1'];
        $p = [];
        if (($q = $this->busca()) !== null) {
            $onde[] = '(u.nome LIKE :q1 OR u.apelido LIKE :q2 OR u.email LIKE :q3 OR u.telefone LIKE :q4 OR u.cpf LIKE :q5 OR u.id = :qid)';
            $digitos = preg_replace('/\D/', '', (string)$this->req->q('q'));
            $p += ['q1' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $digitos !== '' ? '%' . $digitos . '%' : $q, 'q5' => $digitos !== '' ? '%' . $digitos . '%' : '-', 'qid' => trim((string)$this->req->q('q'))];
        }
        $status = $this->req->q('status', 'ativo');
        $onde[] = match ($status) {
            'bloqueado' => 'u.bloqueado_em IS NOT NULL AND u.excluido_em IS NULL',
            'excluido' => 'u.excluido_em IS NOT NULL',
            'todos' => '1 = 1',
            default => 'u.excluido_em IS NULL',
        };
        $papel = $this->req->q('papel');
        if (in_array($papel, ['gestor', 'cuidador', 'rede', 'profissional'], true)) {
            $onde[] = "EXISTS (SELECT 1 FROM vinculos v WHERE v.usuario_id = u.id AND v.status = 'ativo' AND v.papel = :papel)";
            $p['papel'] = $papel;
        } elseif ($papel === 'empresa') {
            $onde[] = 'EXISTS (SELECT 1 FROM empresa_membros m WHERE m.usuario_id = u.id AND m.ativo = 1)';
        } elseif ($papel === 'cuidador_empresa') {
            $onde[] = "EXISTS (SELECT 1 FROM empresa_cuidadores c WHERE c.usuario_id = u.id AND c.status = 'ativo')";
        } elseif ($papel === 'sem_papel') {
            $onde[] = "NOT EXISTS (SELECT 1 FROM vinculos v WHERE v.usuario_id = u.id AND v.status = 'ativo')
                       AND NOT EXISTS (SELECT 1 FROM empresa_membros m WHERE m.usuario_id = u.id AND m.ativo = 1)
                       AND NOT EXISTS (SELECT 1 FROM empresa_cuidadores c WHERE c.usuario_id = u.id AND c.status = 'ativo')";
        }
        $w = implode(' AND ', $onde);
        $total = (int)Bd::valor("SELECT COUNT(*) FROM usuarios u WHERE $w", $p);
        $linhas = Bd::todos(
            "SELECT u.*,
                    (SELECT GROUP_CONCAT(DISTINCT v.papel) FROM vinculos v WHERE v.usuario_id = u.id AND v.status = 'ativo') AS papeis,
                    (SELECT COUNT(*) FROM empresa_membros m WHERE m.usuario_id = u.id AND m.ativo = 1) AS empresas_membro,
                    (SELECT COUNT(*) FROM empresa_cuidadores c WHERE c.usuario_id = u.id AND c.status = 'ativo') AS empresas_cuidador,
                    (SELECT MAX(s.ultimo_uso_em) FROM sessoes s WHERE s.usuario_id = u.id) AS ultimo_acesso
               FROM usuarios u WHERE $w ORDER BY u.criado_em DESC LIMIT $pp OFFSET $off",
            $p
        );
        return Resposta::json(['itens' => array_map([self::class, 'usuarioResumo'], $linhas), 'total' => $total, 'pagina' => $pag, 'por_pagina' => $pp]);
    }

    /** GET /admin/usuarios/{id} */
    public function usuario(): Resposta
    {
        $this->adm();
        $u = $this->achar('usuarios', $this->req->param('id'));
        $id = (string)$u['id'];
        $agora = Tempo::agoraSql();

        $vinculos = Bd::todos(
            "SELECT v.*, p.apelido AS pessoa_apelido, p.excluido_em AS pessoa_excluida
               FROM vinculos v JOIN pessoas_cuidadas p ON p.id = v.pessoa_id
              WHERE v.usuario_id = :u ORDER BY v.status, v.criado_em DESC",
            ['u' => $id]
        );
        $empresas = Bd::todos(
            "SELECT e.id, COALESCE(e.nome_fantasia, e.razao_social) AS nome, 'membro' AS como, m.papel AS detalhe, IF(m.ativo = 1, 'ativo', 'encerrado') AS status
               FROM empresa_membros m JOIN empresas e ON e.id = m.empresa_id WHERE m.usuario_id = :u1
             UNION ALL
             SELECT e.id, COALESCE(e.nome_fantasia, e.razao_social), 'cuidador', c.modalidade, c.status
               FROM empresa_cuidadores c JOIN empresas e ON e.id = c.empresa_id WHERE c.usuario_id = :u2",
            ['u1' => $id, 'u2' => $id]
        );
        $sessoes = Bd::todos(
            'SELECT id, dispositivo, criado_em, ultimo_uso_em, expira_em FROM sessoes
              WHERE usuario_id = :u AND revogada_em IS NULL AND expira_em > :a ORDER BY ultimo_uso_em DESC LIMIT 20',
            ['u' => $id, 'a' => $agora]
        );
        $consent = Bd::todos(
            'SELECT c.tipo, c.versao, c.aceito_em, c.revogado_em, p.apelido AS pessoa
               FROM consentimentos c LEFT JOIN pessoas_cuidadas p ON p.id = c.pessoa_id
              WHERE c.usuario_id = :u ORDER BY c.aceito_em DESC LIMIT 30',
            ['u' => $id]
        );
        $uso = Bd::todos(
            "SELECT perfil, SUM(requisicoes) AS leituras, COUNT(DISTINCT dia) AS dias FROM uso_diario
              WHERE usuario_id = :u AND dia >= :d GROUP BY perfil",
            ['u' => $id, 'd' => Tempo::local(Tempo::agora())->modify('-29 days')->format('Y-m-d')]
        );

        return Resposta::json([
            'usuario' => self::usuarioResumo($u + ['papeis' => null, 'empresas_membro' => 0, 'empresas_cuidador' => 0, 'ultimo_acesso' => $sessoes[0]['ultimo_uso_em'] ?? null])
                + ['bloqueio_motivo' => $u['bloqueio_motivo'], 'atualizado_em' => self::iso($u['atualizado_em'])],
            'vinculos' => array_map(fn($v) => [
                'id' => $v['id'], 'pessoa_id' => $v['pessoa_id'], 'pessoa' => $v['pessoa_excluida'] ? 'Pessoa excluída' : $v['pessoa_apelido'],
                'papel' => $v['papel'], 'relacao' => $v['relacao'], 'modalidade' => $v['modalidade'], 'especialidade' => $v['especialidade'],
                'atua_como_cuidador' => (int)$v['atua_como_cuidador'] === 1, 'status' => $v['status'],
                'permissoes' => json_decode((string)$v['permissoes'], true) ?: [],
                'criado_em' => self::iso($v['criado_em']), 'encerrado_em' => self::iso($v['encerrado_em']),
            ], $vinculos),
            'empresas' => $empresas,
            'sessoes' => array_map(fn($s) => ['id' => $s['id'], 'dispositivo' => $s['dispositivo'], 'criado_em' => self::iso($s['criado_em']),
                'ultimo_uso_em' => self::iso($s['ultimo_uso_em']), 'expira_em' => self::iso($s['expira_em'])], $sessoes),
            'consentimentos' => array_map(fn($c) => ['tipo' => $c['tipo'], 'versao' => $c['versao'], 'pessoa' => $c['pessoa'],
                'aceito_em' => self::iso($c['aceito_em']), 'revogado_em' => self::iso($c['revogado_em'])], $consent),
            'uso_30d' => array_map(fn($l) => ['perfil' => $l['perfil'] ?: null, 'leituras' => (int)$l['leituras'], 'dias' => (int)$l['dias']], $uso),
        ]);
    }

    /** PATCH /admin/usuarios/{id} {nome?, email?, telefone?, motivo} — correção de cadastro a pedido do titular */
    public function corrigirUsuario(): Resposta
    {
        $this->adm('operador');
        $u = $this->achar('usuarios', $this->req->param('id'), true);
        $motivo = $this->motivo();
        $c = $this->corpo();
        $mud = [];
        $erros = [];
        if (array_key_exists('nome', $c)) {
            $n = trim((string)$c['nome']);
            if ($n === '' || mb_strlen($n) > 120) $erros['nome'] = 'Nome inválido'; else $mud['nome'] = $n;
        }
        if (array_key_exists('email', $c)) {
            if ($c['email'] === null || trim((string)$c['email']) === '') $mud['email'] = null;
            elseif (($e = Valida::email((string)$c['email'])) === null) $erros['email'] = 'E-mail inválido';
            else $mud['email'] = $e;
        }
        if (array_key_exists('telefone', $c)) {
            if ($c['telefone'] === null || trim((string)$c['telefone']) === '') $mud['telefone'] = null;
            elseif (($t = Valida::telefone((string)$c['telefone'])) === null) $erros['telefone'] = 'Celular inválido';
            else $mud['telefone'] = $t;
        }
        if ($erros !== []) throw ErroHttp::validacao($erros);
        if ($mud === []) throw ErroHttp::validacao([], 'Nada para mudar.');
        $novoEmail = array_key_exists('email', $mud) ? $mud['email'] : $u['email'];
        $novoTel = array_key_exists('telefone', $mud) ? $mud['telefone'] : $u['telefone'];
        if ($novoEmail === null && $novoTel === null && $u['google_sub'] === null) {
            throw ErroHttp::conflito('sem_acesso', 'A conta ficaria sem forma de entrar (sem celular, e-mail ou Google).');
        }
        foreach (['email', 'telefone'] as $campo) {
            if (($mud[$campo] ?? null) !== null && Bd::um("SELECT id FROM usuarios WHERE $campo = :v AND id <> :id", ['v' => $mud[$campo], 'id' => $u['id']]) !== null) {
                throw ErroHttp::conflito($campo . '_em_uso', 'Este ' . ($campo === 'email' ? 'e-mail' : 'celular') . ' já está em outra conta.');
            }
        }
        Bd::atualizar('usuarios', $mud + ['atualizado_em' => Tempo::agoraSql()], ['id' => $u['id']]);
        // Na auditoria só os nomes dos campos (não o dado novo): o dado está no cadastro.
        $this->auditar('usuario_corrigido', 'usuario', (string)$u['id'], null, ['campos' => array_keys($mud), 'motivo' => $motivo]);
        if ($novoEmail !== $u['email'] || $novoTel !== $u['telefone']) {
            Sessao::revogarTodas((string)$u['id']); // mudou a forma de entrar: entra de novo
        }
        return Resposta::json(['ok' => true]);
    }

    /** POST /admin/usuarios/{id}/bloquear {motivo} */
    public function bloquear(): Resposta
    {
        $this->adm('operador');
        $u = $this->achar('usuarios', $this->req->param('id'), true);
        $motivo = $this->motivo();
        if ($u['bloqueado_em'] !== null) throw ErroHttp::conflito('ja_bloqueado', 'A conta já está bloqueada.');
        Bd::transacao(function () use ($u, $motivo) {
            Bd::exec('UPDATE usuarios SET bloqueado_em = :a, bloqueio_motivo = :m, atualizado_em = :a2 WHERE id = :id',
                ['a' => Tempo::agoraSql(), 'm' => $motivo, 'a2' => Tempo::agoraSql(), 'id' => $u['id']]);
            Sessao::revogarTodas((string)$u['id']);
            $this->auditar('usuario_bloqueado', 'usuario', (string)$u['id'], null, ['motivo' => $motivo]);
        });
        return Resposta::json(['ok' => true]);
    }

    /** POST /admin/usuarios/{id}/desbloquear {motivo} */
    public function desbloquear(): Resposta
    {
        $this->adm('operador');
        $u = $this->achar('usuarios', $this->req->param('id'), true);
        $motivo = $this->motivo();
        if ($u['bloqueado_em'] === null) throw ErroHttp::conflito('nao_bloqueado', 'A conta não está bloqueada.');
        Bd::exec('UPDATE usuarios SET bloqueado_em = NULL, bloqueio_motivo = NULL, atualizado_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $u['id']]);
        $this->auditar('usuario_desbloqueado', 'usuario', (string)$u['id'], null, ['motivo' => $motivo]);
        return Resposta::json(['ok' => true]);
    }

    /** POST /admin/usuarios/{id}/encerrar-sessoes {motivo} — ex.: celular perdido ou roubado */
    public function encerrarSessoes(): Resposta
    {
        $this->adm('operador');
        $u = $this->achar('usuarios', $this->req->param('id'), true);
        $motivo = $this->motivo();
        $n = (int)Bd::valor('SELECT COUNT(*) FROM sessoes WHERE usuario_id = :u AND revogada_em IS NULL AND expira_em > :a', ['u' => $u['id'], 'a' => Tempo::agoraSql()]);
        Sessao::revogarTodas((string)$u['id']);
        $this->auditar('sessoes_encerradas', 'usuario', (string)$u['id'], null, ['sessoes' => $n, 'motivo' => $motivo]);
        return Resposta::json(['encerradas' => $n]);
    }

    /** POST /admin/usuarios/{id}/desvincular-google {motivo} */
    public function desvincularGoogle(): Resposta
    {
        $this->adm('operador');
        $u = $this->achar('usuarios', $this->req->param('id'), true);
        $motivo = $this->motivo();
        if ($u['google_sub'] === null) throw ErroHttp::conflito('sem_google', 'A conta não está ligada ao Google.');
        if ($u['email'] === null && $u['telefone'] === null) throw ErroHttp::conflito('sem_acesso', 'Sem Google a conta ficaria sem forma de entrar. Cadastre um celular ou e-mail antes.');
        Bd::exec('UPDATE usuarios SET google_sub = NULL, atualizado_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $u['id']]);
        $this->auditar('google_desvinculado', 'usuario', (string)$u['id'], null, ['motivo' => $motivo]);
        return Resposta::json(['ok' => true]);
    }

    /** POST /admin/usuarios/{id}/excluir {motivo, confirmar:"EXCLUIR"} — pedido do titular (LGPD art. 18, VI); só dono */
    public function excluirUsuario(): Resposta
    {
        $this->adm('dono');
        $u = $this->achar('usuarios', $this->req->param('id'), true);
        $motivo = $this->motivo();
        if (($this->corpo()['confirmar'] ?? '') !== 'EXCLUIR') throw ErroHttp::validacao(['confirmar' => 'Digite EXCLUIR para confirmar']);
        EuControlador::excluirConta((string)$u['id'], ['admin' => $this->admin['id'], 'admin_email' => $this->admin['email'], 'motivo' => $motivo]);
        return Resposta::json(['ok' => true]);
    }

    // ------------------------------------------------------------ pessoas cuidadas

    /** GET /admin/pessoas?q=&arranjo=&status=&pagina= */
    public function pessoas(): Resposta
    {
        $this->adm();
        [$pag, $pp, $off] = $this->paginacao();
        $onde = [$this->req->q('status') === 'excluida' ? 'p.excluido_em IS NOT NULL' : 'p.excluido_em IS NULL'];
        $prm = [];
        if (($q = $this->busca()) !== null) {
            $onde[] = '(p.nome_completo LIKE :q1 OR p.apelido LIKE :q2 OR p.id = :qid)';
            $prm += ['q1' => $q, 'q2' => $q, 'qid' => trim((string)$this->req->q('q'))];
        }
        $arranjo = $this->req->q('arranjo');
        if ($arranjo !== null && $arranjo !== '') { $onde[] = 'p.arranjo = :arr'; $prm['arr'] = $arranjo; }
        $w = implode(' AND ', $onde);
        $total = (int)Bd::valor("SELECT COUNT(*) FROM pessoas_cuidadas p WHERE $w", $prm);
        $linhas = Bd::todos(
            "SELECT p.id, p.nome_completo, p.apelido, p.nascimento, p.arranjo, p.criado_em, p.excluido_em,
                    COALESCE(e.nome_fantasia, e.razao_social) AS empresa,
                    (SELECT COUNT(*) FROM vinculos v WHERE v.pessoa_id = p.id AND v.status = 'ativo') AS vinculos,
                    (SELECT GROUP_CONCAT(COALESCE(u.apelido, u.nome) SEPARATOR ', ') FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id
                      WHERE v.pessoa_id = p.id AND v.status = 'ativo' AND v.papel = 'gestor') AS gestores,
                    (SELECT MAX(r.recebido_em) FROM registros r WHERE r.pessoa_id = p.id) AS ultimo_registro
               FROM pessoas_cuidadas p LEFT JOIN empresas e ON e.id = p.empresa_id
              WHERE $w ORDER BY p.criado_em DESC LIMIT $pp OFFSET $off",
            $prm
        );
        return Resposta::json(['itens' => array_map(fn($l) => [
            'id' => $l['id'], 'nome' => $l['nome_completo'], 'apelido' => $l['apelido'],
            'idade' => self::idade($l['nascimento']), 'faixa' => self::faixa(self::idade($l['nascimento'])),
            'arranjo' => $l['arranjo'], 'empresa' => $l['empresa'], 'vinculos' => (int)$l['vinculos'], 'gestores' => $l['gestores'],
            'criado_em' => self::iso($l['criado_em']), 'ultimo_registro' => self::iso($l['ultimo_registro']), 'excluida' => $l['excluido_em'] !== null,
        ], $linhas), 'total' => $total, 'pagina' => $pag, 'por_pagina' => $pp]);
    }

    /** GET /admin/pessoas/{id} — cadastro, quem participa e contagens; sem conteúdo de saúde */
    public function pessoa(): Resposta
    {
        $this->adm();
        $p = $this->achar('pessoas_cuidadas', $this->req->param('id'));
        $id = (string)$p['id'];
        LogAcesso::perfil('admin', $id);
        $h30 = Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s');
        $agora = Tempo::agoraSql();

        $vinculos = Bd::todos(
            'SELECT v.*, u.nome, u.apelido AS u_apelido, u.excluido_em AS u_excluido
               FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id WHERE v.pessoa_id = :p ORDER BY v.status, FIELD(v.papel, \'gestor\', \'cuidador\', \'profissional\', \'rede\'), v.criado_em',
            ['p' => $id]
        );
        $convites = Bd::todos(
            'SELECT id, codigo, papel, destino_nome, criado_em, expira_em FROM convites
              WHERE pessoa_id = :p AND aceito_em IS NULL AND revogado_em IS NULL AND expira_em > :a ORDER BY criado_em DESC',
            ['p' => $id, 'a' => $agora]
        );
        $conversa = Bd::valor('SELECT id FROM conversas WHERE pessoa_id = :p', ['p' => $id]);
        $empresa = $p['empresa_id'] ? Bd::um('SELECT id, COALESCE(nome_fantasia, razao_social) AS nome FROM empresas WHERE id = :e', ['e' => $p['empresa_id']]) : null;

        // Quem acessou os dados desta pessoa nos últimos 30 dias (prestação de contas ao titular).
        $acessos = Bd::todos(
            "SELECT x.usuario_id, COALESCE(u.apelido, u.nome) AS nome, x.perfil, SUM(x.n) AS n, MAX(x.dia) AS ultimo_dia FROM (
                SELECT usuario_id, perfil, requisicoes AS n, dia FROM uso_diario WHERE contexto_id = :p1 AND dia >= :d
                UNION ALL
                SELECT usuario_id, COALESCE(perfil, ''), 1, DATE(CONVERT_TZ(criado_em, '+00:00', :tz)) FROM log_acessos WHERE pessoa_id = :p2 AND ator = 'usuario' AND criado_em >= :dt
             ) x LEFT JOIN usuarios u ON u.id = x.usuario_id GROUP BY x.usuario_id, nome, x.perfil ORDER BY n DESC",
            ['p1' => $id, 'p2' => $id, 'tz' => VisaoAdmin::deslocamento(), 'd' => Tempo::local(Tempo::agora())->modify('-29 days')->format('Y-m-d'), 'dt' => $h30]
        );

        $idade = self::idade($p['nascimento']);
        return Resposta::json([
            'pessoa' => [
                'id' => $id, 'nome' => $p['nome_completo'], 'apelido' => $p['apelido'], 'idade' => $idade, 'faixa' => self::faixa($idade),
                'menor' => $idade !== null && $idade < 18, 'arranjo' => $p['arranjo'], 'cpf' => self::mascararCpf($p['cpf']),
                'empresa' => $empresa, 'empresa_permissoes' => json_decode((string)($p['empresa_permissoes'] ?? 'null'), true),
                'criado_em' => self::iso($p['criado_em']), 'atualizado_em' => self::iso($p['atualizado_em']), 'excluida' => $p['excluido_em'] !== null,
            ],
            'vinculos' => array_map(fn($v) => [
                'id' => $v['id'], 'usuario_id' => $v['usuario_id'], 'nome' => $v['u_excluido'] ? 'Conta excluída' : ($v['u_apelido'] ?: $v['nome']),
                'papel' => $v['papel'], 'relacao' => $v['relacao'], 'modalidade' => $v['modalidade'], 'especialidade' => $v['especialidade'],
                'atua_como_cuidador' => (int)$v['atua_como_cuidador'] === 1, 'status' => $v['status'],
                'permissoes' => json_decode((string)$v['permissoes'], true) ?: [],
                'criado_em' => self::iso($v['criado_em']), 'encerrado_em' => self::iso($v['encerrado_em']),
            ], $vinculos),
            'convites_pendentes' => array_map(fn($c) => ['id' => $c['id'], 'codigo' => self::mascararCodigo($c['codigo']), 'papel' => $c['papel'],
                'destino_nome' => $c['destino_nome'], 'criado_em' => self::iso($c['criado_em']), 'expira_em' => self::iso($c['expira_em'])], $convites),
            'contagens' => [
                'registros_total' => (int)Bd::valor('SELECT COUNT(*) FROM registros WHERE pessoa_id = :p', ['p' => $id]),
                'registros_30d' => (int)Bd::valor('SELECT COUNT(*) FROM registros WHERE pessoa_id = :p AND recebido_em > :h', ['p' => $id, 'h' => $h30]),
                'mensagens_30d' => $conversa ? (int)Bd::valor('SELECT COUNT(*) FROM mensagens WHERE conversa_id = :c AND recebido_em > :h', ['c' => $conversa, 'h' => $h30]) : 0,
                'remedios_ativos' => (int)Bd::valor('SELECT COUNT(*) FROM remedios WHERE pessoa_id = :p AND ativo = 1', ['p' => $id]),
                'alertas_abertos' => (int)Bd::valor('SELECT COUNT(*) FROM alertas WHERE pessoa_id = :p AND resolvido_em IS NULL', ['p' => $id]),
                'atendimentos_futuros' => (int)Bd::valor("SELECT COUNT(*) FROM atendimentos WHERE pessoa_id = :p AND status IN ('proposto','aceito') AND inicio > :a", ['p' => $id, 'a' => $agora]),
                'midias' => (int)Bd::valor('SELECT COUNT(*) FROM midias WHERE pessoa_id = :p AND excluido_em IS NULL', ['p' => $id]),
            ],
            'consentimentos' => array_map(fn($c) => ['tipo' => $c['tipo'], 'versao' => $c['versao'], 'por' => $c['nome'],
                'aceito_em' => self::iso($c['aceito_em']), 'revogado_em' => self::iso($c['revogado_em'])],
                Bd::todos('SELECT c.*, COALESCE(u.apelido, u.nome) AS nome FROM consentimentos c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.pessoa_id = :p ORDER BY c.aceito_em DESC', ['p' => $id])),
            'acessos_30d' => array_map(fn($a) => ['usuario_id' => $a['usuario_id'], 'nome' => $a['nome'], 'perfil' => $a['perfil'] ?: null,
                'requisicoes' => (int)$a['n'], 'ultimo_dia' => $a['ultimo_dia']], $acessos),
        ]);
    }

    /** POST /admin/vinculos/{id}/encerrar {motivo} */
    public function encerrarVinculo(): Resposta
    {
        $this->adm('operador');
        $v = Bd::um("SELECT * FROM vinculos WHERE id = :id", ['id' => $this->req->param('id')]);
        if ($v === null) throw ErroHttp::naoEncontrado();
        $motivo = $this->motivo();
        if ($v['status'] !== 'ativo') throw ErroHttp::conflito('ja_encerrado', 'Este vínculo já está encerrado.');
        if ($v['papel'] === 'gestor') {
            $gestores = (int)Bd::valor("SELECT COUNT(*) FROM vinculos WHERE pessoa_id = :p AND papel = 'gestor' AND status = 'ativo'", ['p' => $v['pessoa_id']]);
            if ($gestores <= 1) throw ErroHttp::conflito('unico_gestor', 'É o(a) único(a) gestor(a). Torne alguém da Rede de Apoio gestor(a) antes.');
        }
        Bd::exec("UPDATE vinculos SET status = 'encerrado', encerrado_em = :a WHERE id = :id", ['a' => Tempo::agoraSql(), 'id' => $v['id']]);
        $this->auditar('vinculo_encerrado', 'vinculo', (string)$v['id'], (string)$v['pessoa_id'], ['papel' => $v['papel'], 'usuario' => $v['usuario_id'], 'motivo' => $motivo]);
        return Resposta::json(['ok' => true]);
    }

    /**
     * POST /admin/vinculos/{id}/tornar-gestor {motivo} — só dono; só para Rede de Apoio.
     * Caso típico: o(a) único(a) gestor(a) faleceu ou perdeu o acesso e a Rede de Apoio pede a gestão.
     * Cuidador(a) contratado(a) e profissional não viram gestor(a) por aqui (conflito de interesse e
     * risco de confundir quem contrata com quem presta o serviço).
     */
    public function tornarGestor(): Resposta
    {
        $this->adm('dono');
        $v = Bd::um("SELECT * FROM vinculos WHERE id = :id", ['id' => $this->req->param('id')]);
        if ($v === null) throw ErroHttp::naoEncontrado();
        $motivo = $this->motivo();
        if ($v['status'] !== 'ativo') throw ErroHttp::conflito('encerrado', 'O vínculo está encerrado.');
        if ($v['papel'] !== 'rede') throw ErroHttp::conflito('so_rede', 'Só alguém da Rede de Apoio pode passar a gestor(a) pelo painel.');
        Bd::exec('UPDATE vinculos SET papel = :p, permissoes = :perm WHERE id = :id',
            ['p' => 'gestor', 'perm' => json_encode(Permissoes::padrao('gestor')), 'id' => $v['id']]);
        $this->auditar('vinculo_tornado_gestor', 'vinculo', (string)$v['id'], (string)$v['pessoa_id'], ['usuario' => $v['usuario_id'], 'motivo' => $motivo]);
        return Resposta::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ empresas

    /** GET /admin/empresas?q=&pagina= */
    public function empresas(): Resposta
    {
        $this->adm();
        [$pag, $pp, $off] = $this->paginacao();
        $onde = '1 = 1';
        $prm = [];
        if (($q = $this->busca()) !== null) {
            $onde = '(e.razao_social LIKE :q1 OR e.nome_fantasia LIKE :q2 OR e.cnpj LIKE :q3)';
            $dig = preg_replace('/\D/', '', (string)$this->req->q('q'));
            $prm = ['q1' => $q, 'q2' => $q, 'q3' => $dig !== '' ? '%' . $dig . '%' : $q];
        }
        $total = (int)Bd::valor("SELECT COUNT(*) FROM empresas e WHERE $onde", $prm);
        $linhas = Bd::todos(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM empresa_membros m WHERE m.empresa_id = e.id AND m.ativo = 1) AS membros,
                    (SELECT COUNT(*) FROM empresa_cuidadores c WHERE c.empresa_id = e.id AND c.status = 'ativo') AS cuidadores,
                    (SELECT COUNT(*) FROM pessoas_cuidadas p WHERE p.empresa_id = e.id AND p.excluido_em IS NULL) AS clientes
               FROM empresas e WHERE $onde ORDER BY e.criado_em DESC LIMIT $pp OFFSET $off",
            $prm
        );
        return Resposta::json(['itens' => array_map(fn($e) => [
            'id' => $e['id'], 'nome' => $e['nome_fantasia'] ?: $e['razao_social'], 'razao_social' => $e['razao_social'],
            'cnpj' => self::cnpj($e['cnpj']), 'email' => $e['email'], 'telefone' => $e['telefone'],
            'membros' => (int)$e['membros'], 'cuidadores' => (int)$e['cuidadores'], 'clientes' => (int)$e['clientes'],
            'criado_em' => self::iso($e['criado_em']),
        ], $linhas), 'total' => $total, 'pagina' => $pag, 'por_pagina' => $pp]);
    }

    /** GET /admin/empresas/{id} */
    public function empresa(): Resposta
    {
        $this->adm();
        $e = $this->achar('empresas', $this->req->param('id'));
        $id = (string)$e['id'];
        LogAcesso::perfil('admin', null, $id);
        $h30 = Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s');
        $at = [];
        foreach (Bd::todos('SELECT status, COUNT(*) AS n FROM atendimentos WHERE empresa_id = :e AND criado_em > :h GROUP BY status', ['e' => $id, 'h' => $h30]) as $l) $at[$l['status']] = (int)$l['n'];
        return Resposta::json([
            'empresa' => ['id' => $id, 'nome' => $e['nome_fantasia'] ?: $e['razao_social'], 'razao_social' => $e['razao_social'], 'nome_fantasia' => $e['nome_fantasia'],
                'cnpj' => self::cnpj($e['cnpj']), 'email' => $e['email'], 'telefone' => $e['telefone'], 'criado_em' => self::iso($e['criado_em'])],
            'membros' => array_map(fn($m) => ['usuario_id' => $m['usuario_id'], 'nome' => $m['nome'], 'papel' => $m['papel'], 'ativo' => (int)$m['ativo'] === 1, 'criado_em' => self::iso($m['criado_em'])],
                Bd::todos('SELECT m.*, COALESCE(u.apelido, u.nome) AS nome FROM empresa_membros m JOIN usuarios u ON u.id = m.usuario_id WHERE m.empresa_id = :e ORDER BY m.ativo DESC, m.criado_em', ['e' => $id])),
            // Lista simples: sem horas, sem contagem de atendimentos por cuidador(a) (risco trabalhista).
            'cuidadores' => array_map(fn($c) => ['usuario_id' => $c['usuario_id'], 'nome' => $c['nome'], 'modalidade' => $c['modalidade'], 'status' => $c['status'], 'criado_em' => self::iso($c['criado_em'])],
                Bd::todos('SELECT c.*, COALESCE(u.apelido, u.nome) AS nome FROM empresa_cuidadores c JOIN usuarios u ON u.id = c.usuario_id WHERE c.empresa_id = :e ORDER BY c.status, c.criado_em', ['e' => $id])),
            'clientes' => array_map(fn($p) => ['id' => $p['id'], 'apelido' => $p['apelido']],
                Bd::todos('SELECT id, apelido FROM pessoas_cuidadas WHERE empresa_id = :e AND excluido_em IS NULL ORDER BY apelido', ['e' => $id])),
            'atendimentos_30d' => (object)$at,
        ]);
    }

    // ------------------------------------------------------------------ convites

    /** GET /admin/convites?status=pendente|aceito|expirado|revogado&pagina= */
    public function convites(): Resposta
    {
        $this->adm();
        [$pag, $pp, $off] = $this->paginacao();
        $agora = Tempo::agoraSql();
        [$w, $prm] = match ($this->req->q('status', 'pendente')) {
            'aceito' => ['c.aceito_em IS NOT NULL', []],
            'revogado' => ['c.revogado_em IS NOT NULL', []],
            'expirado' => ['c.aceito_em IS NULL AND c.revogado_em IS NULL AND c.expira_em <= :a', ['a' => $agora]],
            'todos' => ['1 = 1', []],
            default => ['c.aceito_em IS NULL AND c.revogado_em IS NULL AND c.expira_em > :a', ['a' => $agora]],
        };
        $total = (int)Bd::valor("SELECT COUNT(*) FROM convites c WHERE $w", $prm);
        $linhas = Bd::todos(
            "SELECT c.*, p.apelido AS pessoa, COALESCE(e.nome_fantasia, e.razao_social) AS empresa, COALESCE(u.apelido, u.nome) AS criador
               FROM convites c LEFT JOIN pessoas_cuidadas p ON p.id = c.pessoa_id LEFT JOIN empresas e ON e.id = c.empresa_id
               LEFT JOIN usuarios u ON u.id = c.criado_por
              WHERE $w ORDER BY c.criado_em DESC LIMIT $pp OFFSET $off",
            $prm
        );
        return Resposta::json(['itens' => array_map(fn($c) => [
            'id' => $c['id'], 'codigo' => self::mascararCodigo($c['codigo']), 'tipo' => $c['tipo'], 'papel' => $c['papel'],
            'pessoa_id' => $c['pessoa_id'], 'pessoa' => $c['pessoa'], 'empresa_id' => $c['empresa_id'], 'empresa' => $c['empresa'],
            'destino_nome' => $c['destino_nome'], 'criado_por' => $c['criador'], 'criado_em' => self::iso($c['criado_em']), 'expira_em' => self::iso($c['expira_em']),
            'status' => $c['revogado_em'] ? 'revogado' : ($c['aceito_em'] ? 'aceito' : ($c['expira_em'] <= $agora ? 'expirado' : 'pendente')),
        ], $linhas), 'total' => $total, 'pagina' => $pag, 'por_pagina' => $pp]);
    }

    /** POST /admin/convites/{id}/revogar {motivo} */
    public function revogarConvite(): Resposta
    {
        $this->adm('operador');
        $c = Bd::um('SELECT * FROM convites WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($c === null) throw ErroHttp::naoEncontrado();
        $motivo = $this->motivo();
        if ($c['aceito_em'] !== null || $c['revogado_em'] !== null) throw ErroHttp::conflito('encerrado', 'O convite já foi usado ou revogado.');
        Bd::exec('UPDATE convites SET revogado_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $c['id']]);
        $this->auditar('convite_revogado', 'convite', (string)$c['id'], $c['pessoa_id'], ['motivo' => $motivo]);
        return Resposta::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ apoio

    /** @return array<string,mixed> */
    private function achar(string $tabela, string $id, bool $soAtivo = false): array
    {
        $l = Bd::um("SELECT * FROM $tabela WHERE id = :id", ['id' => $id]);
        if ($l === null) throw ErroHttp::naoEncontrado();
        if ($soAtivo && ($l['excluido_em'] ?? null) !== null) throw ErroHttp::conflito('excluido', 'Esta conta foi excluída.');
        return $l;
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    public static function usuarioResumo(array $u): array
    {
        $papeis = array_values(array_filter(explode(',', (string)($u['papeis'] ?? ''))));
        if ((int)($u['empresas_membro'] ?? 0) > 0) $papeis[] = 'empresa';
        if ((int)($u['empresas_cuidador'] ?? 0) > 0 && !in_array('cuidador', $papeis, true)) $papeis[] = 'cuidador';
        return [
            'id' => $u['id'], 'nome' => $u['nome'], 'apelido' => $u['apelido'], 'email' => $u['email'], 'telefone' => $u['telefone'],
            'cpf' => self::mascararCpf($u['cpf']), 'google' => $u['google_sub'] !== null, 'papeis' => $papeis,
            'status' => $u['excluido_em'] !== null ? 'excluido' : ($u['bloqueado_em'] !== null ? 'bloqueado' : 'ativo'),
            'criado_em' => self::iso($u['criado_em']), 'ultimo_acesso_em' => self::iso($u['ultimo_acesso'] ?? null),
        ];
    }

    /** O código de convite dá acesso a dados de saúde: o painel nunca mostra inteiro. */
    private static function mascararCodigo(string $c): string
    {
        return substr($c, 0, 2) . '••••' . substr($c, -2);
    }

    private static function cnpj(?string $c): ?string
    {
        return $c === null || strlen($c) !== 14 ? $c : substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12);
    }
}
