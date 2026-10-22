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

/**
 * Convites por código de 6 letras/números. Quem convida manda o link pelo
 * próprio WhatsApp (sem custo de SMS e sem o app falar em nome de ninguém).
 */
final class ConvitesControlador extends Controlador
{
    public const MODALIDADES = ['mei', 'autonomo', 'clt', 'rede', 'empresa'];

    /** POST /pessoas/{id}/convites — gestor(a) convida cuidador(a), Rede de Apoio ou profissional */
    public function criarParaPessoa(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'administrar');
        $d = $this->corpo();
        $v = Valida::de($d);
        $papel = $v->opcao('papel', ['cuidador', 'rede', 'profissional', 'gestor']);
        $nome = $v->texto('nome', true, 120);
        $tel = $v->texto('telefone', false, 20);
        $relacao = $v->texto('relacao', false, 40);
        $modalidade = $papel === 'cuidador' ? $v->opcao('modalidade', self::MODALIDADES, true) : null;
        $especialidade = $papel === 'profissional' ? $v->texto('especialidade', true, 40) : null;
        $atua = $papel === 'profissional' && $v->booleano('atua_como_cuidador');
        $prazo = $v->inteiro('prazo_confirmacao_min', false, 0, 2880) ?? 60;
        $telefone = $tel !== null ? Valida::telefone($tel) : null;
        if ($tel !== null && $telefone === null) $v->erro('telefone', 'Celular inválido');
        $v->validar();

        $perm = array_key_exists('permissoes', $d)
            ? Permissoes::limpar($d['permissoes'], (string)$papel)
            : Permissoes::padrao((string)$papel, $atua);

        $c = $this->novo('vinculo', $ctx['pessoa']['id'], null, $papel, [
            'modalidade' => $modalidade,
            'relacao' => $relacao,
            'especialidade' => $especialidade,
            'atua_como_cuidador' => $atua,
            'permissoes' => $perm,
            'prazo_confirmacao_min' => $prazo,
        ], $nome, $telefone);
        Auditoria::registrar('convite.criado', 'convite', $c['id'], $ctx['pessoa']['id'], ['papel' => $papel]);
        return Resposta::json($this->saida($c, $ctx['pessoa']['apelido']), 201);
    }

    /** POST /empresas/{id}/convites {tipo: cuidador|membro|cliente} */
    public function criarParaEmpresa(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        $v = Valida::de($this->corpo());
        $tipo = $v->opcao('tipo', ['cuidador', 'membro', 'cliente']);
        $nome = $v->texto('nome', true, 120);
        $tel = $v->texto('telefone', false, 20);
        $modalidade = $tipo === 'cuidador' ? $v->opcao('modalidade', ['mei', 'autonomo', 'clt']) : null;
        $papelMembro = $tipo === 'membro' ? $v->opcao('papel', ['admin', 'coordenacao'], false, 'coordenacao') : null;
        $telefone = $tel !== null ? Valida::telefone($tel) : null;
        if ($tel !== null && $telefone === null) $v->erro('telefone', 'Celular inválido');
        $v->validar();
        if ($tipo === 'membro' && $e['meu_papel'] !== 'admin') throw ErroHttp::proibido('Só a administração da empresa convida a equipe.');

        $c = $this->novo('empresa_' . $tipo, null, $e['id'], $papelMembro, ['modalidade' => $modalidade], $nome, $telefone);
        Auditoria::registrar('convite.criado', 'convite', $c['id'], null, ['empresa' => $e['id'], 'tipo' => $tipo]);
        return Resposta::json($this->saida($c, $e['nome_fantasia'] ?: $e['razao_social']), 201);
    }

    /** GET /convites/{codigo} — o que a pessoa vai aceitar */
    public function ver(): Resposta
    {
        $c = $this->buscar($this->req->param('codigo'));
        $d = json_decode((string)$c['dados'], true) ?: [];
        $de = Formata::pessoaPublica($c['criado_por']);
        $saida = ['codigo' => $c['codigo'], 'tipo' => $c['tipo'], 'papel' => $c['papel'], 'de' => $de, 'nome_convidado' => $c['destino_nome'], 'modalidade' => $d['modalidade'] ?? null];
        if ($c['pessoa_id'] !== null) {
            $p = Bd::um('SELECT apelido FROM pessoas_cuidadas WHERE id = :id', ['id' => $c['pessoa_id']]);
            $saida['pessoa'] = $p['apelido'] ?? '';
            $saida['atua_como_cuidador'] = (bool)($d['atua_como_cuidador'] ?? false);
        }
        if ($c['empresa_id'] !== null) {
            $e = Bd::um('SELECT nome_fantasia, razao_social FROM empresas WHERE id = :id', ['id' => $c['empresa_id']]);
            $saida['empresa'] = $e['nome_fantasia'] ?: $e['razao_social'];
        }
        return Resposta::json($saida);
    }

    /** POST /convites/{codigo}/aceitar {pessoa_id?, permissoes?} */
    public function aceitar(): Resposta
    {
        $c = $this->buscar($this->req->param('codigo'));
        $uid = $this->uid();
        $d = json_decode((string)$c['dados'], true) ?: [];
        $agora = Tempo::agoraSql();
        $resultado = Bd::transacao(function () use ($c, $uid, $d, $agora) {
            // Trava o convite: dois aceites simultâneos não podem passar.
            $trava = Bd::um('SELECT aceito_em FROM convites WHERE id = :id FOR UPDATE', ['id' => $c['id']]);
            if ($trava['aceito_em'] !== null) throw ErroHttp::conflito('convite_usado', 'Este convite já foi usado.');
            $saida = [];
            switch ($c['tipo']) {
                case 'vinculo':
                    $ja = Bd::um("SELECT id FROM vinculos WHERE pessoa_id = :p AND usuario_id = :u AND status = 'ativo'", ['p' => $c['pessoa_id'], 'u' => $uid]);
                    if ($ja !== null) throw ErroHttp::conflito('ja_participa', 'Você já participa do cuidado desta pessoa.');
                    $vid = Ulid::novo();
                    Bd::inserir('vinculos', [
                        'id' => $vid,
                        'pessoa_id' => $c['pessoa_id'],
                        'usuario_id' => $uid,
                        'papel' => $c['papel'],
                        'relacao' => $d['relacao'] ?? null,
                        'modalidade' => $d['modalidade'] ?? null,
                        'especialidade' => $d['especialidade'] ?? null,
                        'atua_como_cuidador' => (int)($d['atua_como_cuidador'] ?? 0),
                        'permissoes' => Permissoes::limpar($d['permissoes'] ?? Permissoes::padrao((string)$c['papel']), (string)$c['papel']),
                        'prazo_confirmacao_min' => (int)($d['prazo_confirmacao_min'] ?? 60),
                        'criado_em' => $agora,
                    ]);
                    $nome = Formata::pessoaPublica($uid)['nome'];
                    $papelTxt = ['cuidador' => 'cuidador(a)', 'rede' => 'Rede de Apoio', 'profissional' => 'equipe de saúde', 'gestor' => 'gestão'][$c['papel']] ?? '';
                    Conversas::sistema(Conversas::daPessoa($c['pessoa_id']), "👋 $nome entrou na conversa ($papelTxt).");
                    $saida = ['pessoa_id' => $c['pessoa_id'], 'vinculo_id' => $vid];
                    break;
                case 'empresa_cuidador':
                    Bd::exec(
                        "INSERT INTO empresa_cuidadores (id, empresa_id, usuario_id, modalidade, status, criado_em)
                         VALUES (:id, :e, :u, :m, 'ativo', :a)
                         ON DUPLICATE KEY UPDATE status = 'ativo', modalidade = VALUES(modalidade), encerrado_em = NULL",
                        ['id' => Ulid::novo(), 'e' => $c['empresa_id'], 'u' => $uid, 'm' => $d['modalidade'] ?? 'mei', 'a' => $agora]
                    );
                    Conversas::direta($c['empresa_id'], $uid);
                    $saida = ['empresa_id' => $c['empresa_id']];
                    break;
                case 'empresa_membro':
                    Bd::exec(
                        'INSERT INTO empresa_membros (id, empresa_id, usuario_id, papel, ativo, criado_em) VALUES (:id, :e, :u, :p, 1, :a)
                         ON DUPLICATE KEY UPDATE ativo = 1, papel = VALUES(papel)',
                        ['id' => Ulid::novo(), 'e' => $c['empresa_id'], 'u' => $uid, 'p' => $c['papel'] ?? 'coordenacao', 'a' => $agora]
                    );
                    $saida = ['empresa_id' => $c['empresa_id']];
                    break;
                case 'empresa_cliente':
                    // Quem aceita é o(a) gestor(a) da pessoa cuidada: ele(a) escolhe a pessoa e o que a empresa pode ver.
                    $pid = (string)($this->corpo()['pessoa_id'] ?? '');
                    $ctx = Acesso::pessoa($this->u(), $pid);
                    Acesso::exigir($ctx, 'administrar');
                    $perm = array_key_exists('permissoes', $this->corpo())
                        ? Permissoes::limpar($this->corpo()['permissoes'], 'empresa')
                        : Permissoes::padrao('empresa');
                    Bd::atualizar('pessoas_cuidadas', ['empresa_id' => $c['empresa_id'], 'empresa_permissoes' => $perm, 'atualizado_em' => $agora], ['id' => $pid]);
                    $saida = ['empresa_id' => $c['empresa_id'], 'pessoa_id' => $pid];
                    break;
                default:
                    throw ErroHttp::naoEncontrado('Convite inválido.');
            }
            Bd::exec('UPDATE convites SET aceito_por = :u, aceito_em = :a WHERE id = :id', ['u' => $uid, 'a' => $agora, 'id' => $c['id']]);
            Auditoria::registrar('convite.aceito', 'convite', $c['id'], $c['pessoa_id'] ?? ($saida['pessoa_id'] ?? null), ['tipo' => $c['tipo']]);
            return $saida;
        });
        return Resposta::json($resultado);
    }

    /** POST /convites/{id}/revogar */
    public function revogar(): Resposta
    {
        $c = Bd::um('SELECT * FROM convites WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($c === null) throw ErroHttp::naoEncontrado();
        if ($c['pessoa_id'] !== null) {
            Acesso::exigir(Acesso::pessoa($this->u(), $c['pessoa_id']), 'administrar');
        } else {
            Acesso::empresa($this->u(), (string)$c['empresa_id']);
        }
        Bd::exec('UPDATE convites SET revogado_em = :a WHERE id = :id AND aceito_em IS NULL', ['a' => Tempo::agoraSql(), 'id' => $c['id']]);
        return Resposta::vazio();
    }

    /** PATCH /vinculos/{id} {permissoes, prazo_confirmacao_min, modalidade, relacao, atua_como_cuidador} */
    public function atualizarVinculo(): Resposta
    {
        $vin = $this->vinculo();
        $ctx = Acesso::pessoa($this->u(), $vin['pessoa_id']);
        Acesso::exigir($ctx, 'administrar');
        $d = $this->corpo();
        $v = Valida::de($d);
        $campos = [];
        if (array_key_exists('permissoes', $d)) {
            $perm = Permissoes::limpar($d['permissoes'], $vin['papel']);
            if ($vin['usuario_id'] === $this->uid() && $vin['papel'] === 'gestor' && !in_array('administrar', $perm, true)) {
                throw ErroHttp::conflito('sem_admin', 'Você não pode tirar de si mesmo(a) a gestão.');
            }
            $campos['permissoes'] = $perm;
        }
        if (array_key_exists('prazo_confirmacao_min', $d)) $campos['prazo_confirmacao_min'] = $v->inteiro('prazo_confirmacao_min', true, 0, 2880);
        if (array_key_exists('modalidade', $d) && $vin['papel'] === 'cuidador') $campos['modalidade'] = $v->opcao('modalidade', self::MODALIDADES);
        if (array_key_exists('relacao', $d)) $campos['relacao'] = $v->texto('relacao', false, 40);
        if (array_key_exists('atua_como_cuidador', $d) && $vin['papel'] === 'profissional') $campos['atua_como_cuidador'] = (int)$v->booleano('atua_como_cuidador');
        $v->validar();
        if ($campos !== []) {
            Bd::atualizar('vinculos', $campos, ['id' => $vin['id']]);
            Auditoria::registrar('vinculo.atualizado', 'vinculo', $vin['id'], $vin['pessoa_id'], ['campos' => array_keys($campos)]);
        }
        return Resposta::json(Formata::vinculo(Bd::um('SELECT * FROM vinculos WHERE id = :id', ['id' => $vin['id']])));
    }

    /** POST /vinculos/{id}/encerrar — encerra o acesso (o histórico fica) */
    public function encerrarVinculo(): Resposta
    {
        $vin = $this->vinculo();
        $ctx = Acesso::pessoa($this->u(), $vin['pessoa_id']);
        $proprio = $vin['usuario_id'] === $this->uid();
        if (!$proprio) Acesso::exigir($ctx, 'administrar');
        if ($vin['papel'] === 'gestor') {
            $n = (int)Bd::valor("SELECT COUNT(*) FROM vinculos WHERE pessoa_id = :p AND papel = 'gestor' AND status = 'ativo'", ['p' => $vin['pessoa_id']]);
            if ($n <= 1) throw ErroHttp::conflito('unico_gestor', 'A pessoa cuidada precisa de pelo menos um(a) gestor(a).');
        }
        $agora = Tempo::agoraSql();
        Bd::transacao(function () use ($vin, $agora) {
            Bd::exec("UPDATE vinculos SET status = 'encerrado', encerrado_em = :a WHERE id = :id", ['a' => $agora, 'id' => $vin['id']]);
            Bd::exec('UPDATE presencas SET fim_em = :a WHERE pessoa_id = :p AND cuidador_id = :u AND fim_em IS NULL', ['a' => $agora, 'p' => $vin['pessoa_id'], 'u' => $vin['usuario_id']]);
            Bd::exec(
                "UPDATE atendimentos SET status = 'cancelado' WHERE pessoa_id = :p AND cuidador_id = :u AND status IN ('proposto','aceito') AND inicio > :a",
                ['p' => $vin['pessoa_id'], 'u' => $vin['usuario_id'], 'a' => $agora]
            );
            $nome = Formata::pessoaPublica($vin['usuario_id'])['nome'];
            Conversas::sistema(Conversas::daPessoa($vin['pessoa_id']), "$nome saiu da conversa.");
            Auditoria::registrar('vinculo.encerrado', 'vinculo', $vin['id'], $vin['pessoa_id']);
        });
        return Resposta::vazio();
    }

    /** @return array<string,mixed> */
    private function vinculo(): array
    {
        $v = Bd::um('SELECT * FROM vinculos WHERE id = :id', ['id' => $this->req->param('id')]);
        if ($v === null || $v['status'] !== 'ativo') throw ErroHttp::naoEncontrado('Vínculo não encontrado.');
        return $v;
    }

    /** @param array<string,mixed> $dados @return array<string,mixed> */
    private function novo(string $tipo, ?string $pessoaId, ?string $empresaId, ?string $papel, array $dados, ?string $nome, ?string $telefone): array
    {
        $agora = Tempo::agora();
        for ($i = 0; $i < 5; $i++) {
            $c = [
                'id' => Ulid::novo(),
                'codigo' => Ulid::codigo(6),
                'tipo' => $tipo,
                'pessoa_id' => $pessoaId,
                'empresa_id' => $empresaId,
                'papel' => $papel,
                'dados' => $dados,
                'destino_nome' => $nome,
                'destino_telefone' => $telefone,
                'criado_por' => $this->uid(),
                'criado_em' => $agora->format('Y-m-d H:i:s'),
                'expira_em' => $agora->modify('+7 days')->format('Y-m-d H:i:s'),
            ];
            if (Bd::inserir('convites', $c, true) === 1) return $c;
        }
        throw new ErroHttp(500, 'codigo', 'Tente de novo.');
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function saida(array $c, string $alvo): array
    {
        $link = rtrim((string)Config::get('url_app'), '/') . '/convite/' . $c['codigo'];
        $texto = "Olá, {$c['destino_nome']}! Convidei você para o Ahvita Cuidados ($alvo). Toque para entrar: $link — código {$c['codigo']}";
        return [
            'id' => $c['id'],
            'codigo' => $c['codigo'],
            'link' => $link,
            'expira_em' => Tempo::sqlParaIso($c['expira_em']),
            'whatsapp' => 'https://wa.me/' . ($c['destino_telefone'] ? ltrim((string)$c['destino_telefone'], '+') : '') . '?text=' . rawurlencode($texto),
        ];
    }

    /** @return array<string,mixed> */
    private function buscar(string $codigo): array
    {
        $c = Bd::um(
            'SELECT * FROM convites WHERE codigo = :c AND revogado_em IS NULL AND expira_em > :a',
            ['c' => strtoupper($codigo), 'a' => Tempo::agoraSql()]
        );
        if ($c === null) throw ErroHttp::naoEncontrado('Convite vencido ou inválido. Peça um novo para quem convidou você.');
        if ($c['aceito_em'] !== null) throw ErroHttp::conflito('convite_usado', 'Este convite já foi usado.');
        return $c;
    }
}
