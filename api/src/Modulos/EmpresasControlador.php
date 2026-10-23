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
 * Painel da empresa (funções básicas do MVP): equipe, clientes, propostas de
 * atendimento em massa (o primeiro aceite fica), cobertura e conversas.
 * Sem ranking, sem avaliação individual, sem controle de horas dos(as) cuidadores(as).
 */
final class EmpresasControlador extends Controlador
{
    /** POST /empresas {razao_social, nome_fantasia?, cnpj, telefone?, email?} */
    public function criar(): Resposta
    {
        $v = Valida::de($this->corpo());
        $razao = $v->texto('razao_social', true, 160, 3);
        $fantasia = $v->texto('nome_fantasia', false, 120);
        $cnpj = $v->cnpj('cnpj');
        $tel = $v->texto('telefone', false, 20);
        $email = $v->texto('email', false, 190);
        $v->validar();
        if (Bd::valor('SELECT id FROM empresas WHERE cnpj = :c', ['c' => $cnpj]) !== null) {
            throw ErroHttp::conflito('cnpj_em_uso', 'Esta empresa já tem cadastro. Peça um convite à administração dela.');
        }
        $id = Ulid::novo();
        $agora = Tempo::agoraSql();
        Bd::transacao(function () use ($id, $razao, $fantasia, $cnpj, $tel, $email, $agora) {
            Bd::inserir('empresas', [
                'id' => $id, 'razao_social' => $razao, 'nome_fantasia' => $fantasia, 'cnpj' => $cnpj,
                'telefone' => $tel ? \Cuidados\Support\Valida::telefone($tel) : null,
                'email' => $email ? \Cuidados\Support\Valida::email($email) : null,
                'criado_por' => $this->uid(), 'criado_em' => $agora, 'atualizado_em' => $agora,
            ]);
            Bd::inserir('empresa_membros', ['id' => Ulid::novo(), 'empresa_id' => $id, 'usuario_id' => $this->uid(), 'papel' => 'admin', 'criado_em' => $agora]);
            Auditoria::registrar('empresa.criada', 'empresa', $id);
        });
        return Resposta::json($this->formatar(Acesso::empresa($this->u(), $id)), 201);
    }

    /** GET /empresas/{id}/painel */
    public function painel(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        Avisos::verificarCobertura();
        $agora = Tempo::agora();
        $ate7 = $agora->modify('+7 days')->format('Y-m-d H:i:s');
        return Resposta::json([
            'empresa' => $this->formatar($e),
            'equipe' => (int)Bd::valor("SELECT COUNT(*) FROM empresa_cuidadores WHERE empresa_id = :e AND status = 'ativo'", ['e' => $e['id']]),
            'clientes' => (int)Bd::valor('SELECT COUNT(*) FROM pessoas_cuidadas WHERE empresa_id = :e AND excluido_em IS NULL', ['e' => $e['id']]),
            'propostas_abertas' => (int)Bd::valor("SELECT COUNT(*) FROM atendimentos WHERE empresa_id = :e AND status = 'proposto' AND cuidador_id IS NULL AND inicio > :a",
                ['e' => $e['id'], 'a' => $agora->format('Y-m-d H:i:s')]),
            'atendimentos_7_dias' => (int)Bd::valor("SELECT COUNT(*) FROM atendimentos WHERE empresa_id = :e AND status = 'aceito' AND inicio BETWEEN :a AND :b",
                ['e' => $e['id'], 'a' => $agora->format('Y-m-d H:i:s'), 'b' => $ate7]),
            'alertas' => array_map([Formata::class, 'alerta'], Bd::todos(
                "SELECT * FROM alertas WHERE empresa_id = :e AND resolvido_em IS NULL AND tipo IN ('sem_confirmacao','nao_vou','proposta_recusada') ORDER BY criado_em DESC LIMIT 30",
                ['e' => $e['id']]
            )),
        ]);
    }

    /** GET /empresas/{id}/cuidadores — equipe (a agenda de cada um(a) não aparece aqui) */
    public function equipe(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        $lista = Bd::todos(
            "SELECT ec.*, u.nome, u.apelido, u.telefone,
                    (SELECT COUNT(*) FROM disponibilidades d WHERE d.usuario_id = ec.usuario_id) AS janelas
               FROM empresa_cuidadores ec JOIN usuarios u ON u.id = ec.usuario_id
              WHERE ec.empresa_id = :e ORDER BY ec.status, u.nome",
            ['e' => $e['id']]
        );
        $convites = Bd::todos(
            "SELECT id, codigo, destino_nome, dados, expira_em FROM convites WHERE empresa_id = :e AND tipo = 'empresa_cuidador'
               AND aceito_em IS NULL AND revogado_em IS NULL AND expira_em > :a",
            ['e' => $e['id'], 'a' => Tempo::agoraSql()]
        );
        return Resposta::json([
            'cuidadores' => array_map(fn($c) => [
                'id' => $c['usuario_id'], 'nome' => $c['nome'], 'apelido' => $c['apelido'], 'telefone' => $c['telefone'],
                'modalidade' => $c['modalidade'], 'status' => $c['status'],
                'marcou_disponibilidade' => (int)$c['janelas'] > 0,
                'conversa_id' => Conversas::direta($e['id'], $c['usuario_id']),
            ], $lista),
            'convites_pendentes' => array_map(fn($c) => [
                'id' => $c['id'], 'codigo' => $c['codigo'], 'nome' => $c['destino_nome'],
                'modalidade' => (json_decode((string)$c['dados'], true) ?: [])['modalidade'] ?? null, 'expira_em' => Tempo::sqlParaIso($c['expira_em']),
            ], $convites),
            'pedidos' => RedeControlador::daEmpresa((string)$e['id']),
        ]);
    }

    /** POST /empresas/{id}/cuidadores/{cuidador}/encerrar */
    public function encerrarCuidador(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'), true);
        Bd::exec("UPDATE empresa_cuidadores SET status = 'encerrado', encerrado_em = :a WHERE empresa_id = :e AND usuario_id = :u",
            ['a' => Tempo::agoraSql(), 'e' => $e['id'], 'u' => $this->req->param('cuidador')]);
        Bd::exec("UPDATE proposta_destinatarios d JOIN atendimentos a ON a.id = d.atendimento_id SET d.status = 'encerrada'
                   WHERE a.empresa_id = :e AND d.cuidador_id = :u AND d.status = 'enviada'", ['e' => $e['id'], 'u' => $this->req->param('cuidador')]);
        Auditoria::registrar('empresa.cuidador_encerrado', 'usuario', $this->req->param('cuidador'), null, ['empresa' => $e['id']]);
        return Resposta::vazio();
    }

    /** GET /empresas/{id}/clientes */
    public function clientes(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        $pessoas = Bd::todos('SELECT id FROM pessoas_cuidadas WHERE empresa_id = :e AND excluido_em IS NULL ORDER BY apelido', ['e' => $e['id']]);
        $saida = [];
        foreach ($pessoas as $p) {
            $ctx = Acesso::pessoa($this->u(), $p['id']);
            $f = Formata::pessoa($ctx);
            $g = Bd::um("SELECT u.nome, u.telefone FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id WHERE v.pessoa_id = :p AND v.papel = 'gestor' AND v.status = 'ativo' LIMIT 1", ['p' => $p['id']]);
            $f['gestor'] = $g;
            $f['cuidadores'] = array_map(fn($v) => Formata::pessoaPublica($v['usuario_id']), Bd::todos(
                "SELECT usuario_id FROM vinculos WHERE pessoa_id = :p AND status = 'ativo' AND (papel = 'cuidador' OR atua_como_cuidador = 1)", ['p' => $p['id']]
            ));
            $saida[] = $f;
        }
        return Resposta::json(['clientes' => $saida]);
    }

    /**
     * POST /empresas/{id}/propostas {pessoa_id, inicio, fim, valor_centavos, observacao?, cuidadores?: [ids]}
     * Vai ao mesmo tempo para quem tem disponibilidade no horário; o primeiro aceite fica.
     */
    public function criarProposta(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        $v = Valida::de($this->corpo());
        $pid = $v->ulid('pessoa_id');
        $ini = $v->dataHora('inicio');
        $fim = $v->dataHora('fim');
        $valor = $v->inteiro('valor_centavos', false, 0, 10_000_000);
        $obs = $v->texto('observacao', false, 255);
        $escolhidos = $v->lista('cuidadores', false, 200);
        $v->validar();
        $ctx = Acesso::pessoa($this->u(), (string)$pid);
        if ($ctx['papel'] !== 'empresa' || $ctx['empresa_id'] !== $e['id']) throw ErroHttp::naoEncontrado('Cliente não encontrado.');
        Acesso::exigir($ctx, 'atendimentos');
        if (Tempo::utc((string)$ini) <= Tempo::agora()) throw ErroHttp::validacao(['inicio' => 'Escolha um horário no futuro']);
        if ((string)$fim <= (string)$ini) throw ErroHttp::validacao(['fim' => 'O fim precisa ser depois do início']);

        $candidatos = Bd::todos("SELECT usuario_id, modalidade FROM empresa_cuidadores WHERE empresa_id = :e AND status = 'ativo'", ['e' => $e['id']]);
        if (is_array($escolhidos)) {
            $candidatos = array_values(array_filter($candidatos, fn($c) => in_array($c['usuario_id'], $escolhidos, true)));
        }
        $destinatarios = [];
        foreach ($candidatos as $c) {
            try {
                RegrasAgenda::validarProposta($c['usuario_id'], $c['modalidade'], (string)$ini, (string)$fim);
                $destinatarios[] = $c['usuario_id'];
            } catch (ErroHttp) {
                // Fora da agenda dele(a): não recebe. A agenda é do(a) cuidador(a).
            }
        }
        if ($destinatarios === []) {
            throw ErroHttp::conflito('ninguem_disponivel', 'Nenhum(a) cuidador(a) marcou disponibilidade nesse horário.');
        }
        $id = Ulid::novo();
        Bd::transacao(function () use ($id, $pid, $e, $ini, $fim, $valor, $obs, $destinatarios, $ctx) {
            Bd::inserir('atendimentos', [
                'id' => $id, 'pessoa_id' => $pid, 'cuidador_id' => null, 'solicitante_id' => $this->uid(), 'empresa_id' => $e['id'],
                'origem' => 'empresa', 'inicio' => $ini, 'fim' => $fim, 'valor_centavos' => $valor, 'observacao' => $obs,
                'prazo_confirmacao_min' => (int)(Config::get('empresa.prazo_confirmacao_min', 60)), 'criado_em' => Tempo::agoraSql(),
            ]);
            foreach ($destinatarios as $u) {
                Bd::inserir('proposta_destinatarios', ['atendimento_id' => $id, 'cuidador_id' => $u]);
                Avisos::evento('proposta', $u, ['atendimento_id' => $id]);
            }
            Auditoria::registrar('proposta.criada', 'atendimento', $id, (string)$pid, ['destinatarios' => count($destinatarios)]);
        });
        return Resposta::json(['atendimento' => Formata::atendimento(Bd::um('SELECT * FROM atendimentos WHERE id = :id', ['id' => $id])), 'enviada_para' => count($destinatarios)], 201);
    }

    /** GET /empresas/{id}/atendimentos?periodo=proximos|abertas — propostas e cobertura */
    public function atendimentos(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        Avisos::verificarCobertura();
        $agora = Tempo::agoraSql();
        $lista = Bd::todos(
            "SELECT * FROM atendimentos WHERE empresa_id = :e AND status IN ('proposto','aceito') AND fim > :a ORDER BY inicio LIMIT 200",
            ['e' => $e['id'], 'a' => $agora]
        );
        return Resposta::json(['atendimentos' => array_map(function ($a) {
            $f = Formata::atendimento($a);
            if ($a['cuidador_id'] === null) {
                $f['enviada_para'] = (int)Bd::valor('SELECT COUNT(*) FROM proposta_destinatarios WHERE atendimento_id = :a', ['a' => $a['id']]);
                $f['recusas'] = (int)Bd::valor("SELECT COUNT(*) FROM proposta_destinatarios WHERE atendimento_id = :a AND status = 'recusada'", ['a' => $a['id']]);
            }
            return $f;
        }, $lista)]);
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    private function formatar(array $e): array
    {
        return [
            'id' => $e['id'], 'razao_social' => $e['razao_social'], 'nome_fantasia' => $e['nome_fantasia'],
            'nome' => $e['nome_fantasia'] ?: $e['razao_social'], 'cnpj' => $e['cnpj'], 'telefone' => $e['telefone'],
            'email' => $e['email'], 'meu_papel' => $e['meu_papel'] ?? null,
        ];
    }
}
