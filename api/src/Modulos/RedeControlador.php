<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\LogAcesso;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

/**
 * Rede da empresa: pedidos nos dois sentidos, sempre com o aceite do outro lado.
 *   - a empresa pede a um(a) cuidador(a) já cadastrado(a) (achado pelo celular);
 *     ele(a) aceita ou não no próprio app;
 *   - o(a) cuidador(a) pede para entrar na rede de uma empresa (achada pelo nome
 *     ou CNPJ); a empresa aceita ou não no painel Cuidadores(as).
 *
 * ⚖️ Risco trabalhista: entrar na rede não é exclusividade. O(a) cuidador(a) pode
 * estar na rede de várias empresas, recusar sem consequência e sair quando quiser
 * (POST /eu/empresas/{id}/sair). A empresa não vê a lista de cuidadores(as) do app,
 * nem de outras empresas, nem a agenda de ninguém.
 */
final class RedeControlador extends Controlador
{
    private const DIAS_VALIDADE = 30;
    private const MODALIDADES_EMPRESA = ['mei', 'autonomo', 'clt'];
    private const MODALIDADES_CUIDADOR = ['mei', 'autonomo'];

    // ------------------------------------------------------------ lado da empresa

    /** POST /empresas/{id}/pedidos-rede {telefone, modalidade, mensagem?} */
    public function pedirPelaEmpresa(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        $v = Valida::de($this->corpo());
        $tel = $v->texto('telefone', true, 20);
        $modalidade = $v->opcao('modalidade', self::MODALIDADES_EMPRESA);
        $mensagem = $v->texto('mensagem', false, 200);
        $telefone = $tel !== null ? Valida::telefone($tel) : null;
        if ($tel !== null && $telefone === null) $v->erro('telefone', 'Celular inválido');
        $v->validar();

        // Procurar celular por celular serve para achar uma pessoa, não para varrer o cadastro.
        $naoAchados = (int)Bd::valor(
            "SELECT COUNT(*) FROM log_acessos WHERE empresa_id = :e AND rota = '/empresas/{id}/pedidos-rede' AND status = 404 AND criado_em > :h",
            ['e' => $e['id'], 'h' => Tempo::agora()->modify('-1 hour')->format('Y-m-d H:i:s')]
        );
        if ($naoAchados >= 20) throw ErroHttp::muitas('Muitos celulares não encontrados. Espere uma hora ou use Convidar cuidador(a).');

        $u = Bd::um('SELECT * FROM usuarios WHERE telefone = :t AND excluido_em IS NULL AND bloqueado_em IS NULL', ['t' => $telefone]);
        if ($u === null || !self::ehCuidador((string)$u['id'])) {
            throw ErroHttp::naoEncontrado('Não achamos cuidador(a) com este celular no app. Use "Convidar cuidador(a)" para mandar um convite pelo WhatsApp.');
        }
        if (self::naRede($e['id'], (string)$u['id'])) {
            throw ErroHttp::conflito('ja_na_rede', 'Esta pessoa já está na rede da empresa.');
        }
        $r = $this->criarOuCasar($e['id'], (string)$u['id'], 'empresa', (string)$modalidade, $mensagem);
        Avisos::evento('pedido_rede', (string)$u['id'], ['pedido_id' => $r['id'], 'empresa_id' => $e['id']]);
        return Resposta::json($r + ['cuidador' => Formata::pessoaPublica((string)$u['id'])], $r['status'] === 'aceito' ? 200 : 201);
    }

    /** Pedidos da empresa, para o painel Cuidadores(as). @return array{recebidos:list<array<string,mixed>>,enviados:list<array<string,mixed>>} */
    public static function daEmpresa(string $empresaId): array
    {
        self::expirar();
        $lista = Bd::todos(
            "SELECT p.*, u.telefone FROM pedidos_rede p JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.empresa_id = :e AND (p.status = 'pendente' OR p.respondido_em > :h)
              ORDER BY p.criado_em DESC LIMIT 100",
            ['e' => $empresaId, 'h' => Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s')]
        );
        $fmt = fn($p) => self::formatar($p) + [
            'cuidador' => Formata::pessoaPublica((string)$p['usuario_id'])
                // Antes do aceite a empresa vê só o primeiro nome e o fim do celular.
                + ['telefone_final' => $p['telefone'] ? substr((string)$p['telefone'], -4) : null],
        ];
        return [
            'recebidos' => array_values(array_map($fmt, array_filter($lista, fn($p) => $p['origem'] === 'cuidador' && $p['status'] === 'pendente'))),
            'enviados' => array_values(array_map($fmt, array_filter($lista, fn($p) => $p['origem'] === 'empresa'))),
        ];
    }

    // ------------------------------------------------------------ lado do(a) cuidador(a)

    /** GET /rede/empresas?q= — procurar empresa pelo nome ou CNPJ (mínimo 3 letras) */
    public function buscarEmpresas(): Resposta
    {
        $this->exigirCuidador();
        $q = trim((string)$this->req->q('q', ''));
        if (mb_strlen($q) < 3) return Resposta::json(['empresas' => []]);
        $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
        $dig = preg_replace('/\D/', '', $q);
        $linhas = Bd::todos(
            'SELECT id, nome_fantasia, razao_social, cnpj FROM empresas
              WHERE nome_fantasia LIKE :q1 OR razao_social LIKE :q2' . (strlen((string)$dig) >= 8 ? ' OR cnpj LIKE :q3' : '') . '
              ORDER BY COALESCE(nome_fantasia, razao_social) LIMIT 10',
            ['q1' => $like, 'q2' => $like] + (strlen((string)$dig) >= 8 ? ['q3' => $dig . '%'] : [])
        );
        $uid = $this->uid();
        return Resposta::json(['empresas' => array_map(fn($e) => [
            'id' => $e['id'],
            'nome' => $e['nome_fantasia'] ?: $e['razao_social'],
            'razao_social' => $e['razao_social'],
            'cnpj' => self::cnpj((string)$e['cnpj']),
            'na_rede' => self::naRede((string)$e['id'], $uid),
            'pedido_pendente' => Bd::valor("SELECT id FROM pedidos_rede WHERE chave_pendente = :k", ['k' => $e['id'] . ':' . $uid]) !== null,
        ], $linhas)]);
    }

    /** POST /eu/pedidos-rede {empresa_id, modalidade, mensagem?} */
    public function pedirPeloCuidador(): Resposta
    {
        $this->exigirCuidador();
        $v = Valida::de($this->corpo());
        $empresaId = $v->ulid('empresa_id');
        $modalidade = $v->opcao('modalidade', self::MODALIDADES_CUIDADOR);
        $mensagem = $v->texto('mensagem', false, 200);
        $v->validar();
        $e = Bd::um('SELECT id, nome_fantasia, razao_social FROM empresas WHERE id = :id', ['id' => $empresaId]);
        if ($e === null) throw ErroHttp::naoEncontrado('Empresa não encontrada.');
        $uid = $this->uid();
        if (self::naRede((string)$e['id'], $uid)) throw ErroHttp::conflito('ja_na_rede', 'Você já está na rede desta empresa.');
        $r = $this->criarOuCasar((string)$e['id'], $uid, 'cuidador', (string)$modalidade, $mensagem);
        foreach (Bd::todos('SELECT usuario_id FROM empresa_membros WHERE empresa_id = :e AND ativo = 1', ['e' => $e['id']]) as $m) {
            Avisos::evento('pedido_rede', (string)$m['usuario_id'], ['pedido_id' => $r['id'], 'empresa_id' => $e['id']]);
        }
        return Resposta::json($r + ['empresa' => ['id' => $e['id'], 'nome' => $e['nome_fantasia'] ?: $e['razao_social']]], $r['status'] === 'aceito' ? 200 : 201);
    }

    /** GET /eu/rede — empresas em que estou, pedidos que recebi e que mandei */
    public function minhaRede(): Resposta
    {
        $this->exigirCuidador();
        self::expirar();
        $uid = $this->uid();
        $empresas = Bd::todos(
            "SELECT e.id, e.nome_fantasia, e.razao_social, ec.modalidade FROM empresa_cuidadores ec JOIN empresas e ON e.id = ec.empresa_id
              WHERE ec.usuario_id = :u AND ec.status = 'ativo' ORDER BY COALESCE(e.nome_fantasia, e.razao_social)",
            ['u' => $uid]
        );
        $pedidos = Bd::todos(
            "SELECT p.*, e.nome_fantasia, e.razao_social FROM pedidos_rede p JOIN empresas e ON e.id = p.empresa_id
              WHERE p.usuario_id = :u AND p.status = 'pendente' ORDER BY p.criado_em DESC",
            ['u' => $uid]
        );
        $fmt = fn($p) => self::formatar($p) + ['empresa' => ['id' => $p['empresa_id'], 'nome' => $p['nome_fantasia'] ?: $p['razao_social']]];
        return Resposta::json([
            'empresas' => array_map(fn($e) => [
                'id' => $e['id'], 'nome' => $e['nome_fantasia'] ?: $e['razao_social'], 'modalidade' => $e['modalidade'],
                'conversa_id' => Conversas::direta((string)$e['id'], $uid),
            ], $empresas),
            'recebidos' => array_values(array_map($fmt, array_filter($pedidos, fn($p) => $p['origem'] === 'empresa'))),
            'enviados' => array_values(array_map($fmt, array_filter($pedidos, fn($p) => $p['origem'] === 'cuidador'))),
        ]);
    }

    /** POST /eu/empresas/{id}/sair — o(a) cuidador(a) sai da rede quando quiser */
    public function sair(): Resposta
    {
        $this->exigirCuidador();
        $uid = $this->uid();
        $empresaId = $this->req->param('id');
        $agora = Tempo::agoraSql();
        Bd::transacao(function () use ($uid, $empresaId, $agora) {
            $n = Bd::exec("UPDATE empresa_cuidadores SET status = 'encerrado', encerrado_em = :a WHERE empresa_id = :e AND usuario_id = :u AND status = 'ativo'",
                ['a' => $agora, 'e' => $empresaId, 'u' => $uid])->rowCount();
            if ($n === 0) throw ErroHttp::naoEncontrado('Você não está na rede desta empresa.');
            // Propostas ainda sem resposta deixam de valer. Atendimentos já aceitos continuam (são compromisso com o cliente).
            Bd::exec("UPDATE proposta_destinatarios d JOIN atendimentos a ON a.id = d.atendimento_id SET d.status = 'encerrada'
                       WHERE a.empresa_id = :e AND d.cuidador_id = :u AND d.status = 'enviada'", ['e' => $empresaId, 'u' => $uid]);
            Conversas::sistema(Conversas::direta($empresaId, $uid), Formata::pessoaPublica($uid)['nome'] . ' saiu da rede da empresa.');
            Auditoria::registrar('rede.cuidador_saiu', 'empresa', $empresaId);
        });
        return Resposta::vazio();
    }

    // ------------------------------------------------------------ responder (os dois lados)

    /** POST /pedidos-rede/{id}/aceitar */
    public function aceitar(): Resposta
    {
        return $this->responder('aceito');
    }

    /** POST /pedidos-rede/{id}/recusar — recusar não tem consequência nenhuma */
    public function recusar(): Resposta
    {
        return $this->responder('recusado');
    }

    /** POST /pedidos-rede/{id}/cancelar — quem pediu desiste */
    public function cancelar(): Resposta
    {
        return $this->responder('cancelado');
    }

    private function responder(string $novo): Resposta
    {
        self::expirar();
        $uid = $this->uid();
        $id = $this->req->param('id');
        $saida = Bd::transacao(function () use ($id, $uid, $novo) {
            $p = Bd::um('SELECT * FROM pedidos_rede WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($p === null) throw ErroHttp::naoEncontrado('Pedido não encontrado.');
            $souCuidador = $p['usuario_id'] === $uid;
            $souEmpresa = Acesso::membroEmpresa($uid, (string)$p['empresa_id']) !== null;
            // Quem recebeu responde; quem mandou cancela. Os demais nem sabem que o pedido existe.
            $recebeu = $p['origem'] === 'empresa' ? $souCuidador : $souEmpresa;
            $mandou = $p['origem'] === 'empresa' ? $souEmpresa : $souCuidador;
            if (!($novo === 'cancelado' ? $mandou : $recebeu)) throw ErroHttp::naoEncontrado('Pedido não encontrado.');
            LogAcesso::perfil($souCuidador ? 'cuidador' : 'empresa', null, (string)$p['empresa_id']);
            if ($p['status'] !== 'pendente') {
                throw ErroHttp::conflito('pedido_encerrado', match ($p['status']) {
                    'aceito' => 'Este pedido já foi aceito.',
                    'expirado' => 'Este pedido venceu. Peça de novo.',
                    default => 'Este pedido já foi respondido.',
                });
            }
            $agora = Tempo::agoraSql();
            Bd::exec('UPDATE pedidos_rede SET status = :s, chave_pendente = NULL, respondido_por = :u, respondido_em = :a WHERE id = :id',
                ['s' => $novo, 'u' => $uid, 'a' => $agora, 'id' => $p['id']]);
            if ($novo === 'aceito') self::entrarNaRede($p);
            Auditoria::registrar('rede.pedido_' . $novo, 'pedido_rede', (string)$p['id'], null, ['empresa' => $p['empresa_id'], 'origem' => $p['origem']]);
            return ['id' => $p['id'], 'status' => $novo, 'empresa_id' => $p['empresa_id'],
                'conversa_id' => $novo === 'aceito' ? Conversas::direta((string)$p['empresa_id'], (string)$p['usuario_id']) : null];
        });
        return Resposta::json($saida);
    }

    // ------------------------------------------------------------ apoio

    /**
     * Cria o pedido. Se o outro lado já tinha pedido o mesmo, os dois já concordaram:
     * aceita o pedido dele e a pessoa entra na rede.
     * @return array<string,mixed>
     */
    private function criarOuCasar(string $empresaId, string $usuarioId, string $origem, string $modalidade, ?string $mensagem): array
    {
        self::expirar();
        return Bd::transacao(function () use ($empresaId, $usuarioId, $origem, $modalidade, $mensagem) {
            $chave = $empresaId . ':' . $usuarioId;
            $ja = Bd::um('SELECT * FROM pedidos_rede WHERE chave_pendente = :k FOR UPDATE', ['k' => $chave]);
            $agora = Tempo::agora();
            if ($ja !== null && $ja['origem'] === $origem) {
                throw ErroHttp::conflito('pedido_pendente', $origem === 'empresa'
                    ? 'Já existe um pedido esperando resposta desta pessoa.'
                    : 'Você já pediu. Agora é a empresa que responde.');
            }
            if ($ja !== null) {
                // O outro lado já tinha pedido: vale como aceite.
                Bd::exec("UPDATE pedidos_rede SET status = 'aceito', chave_pendente = NULL, respondido_por = :u, respondido_em = :a WHERE id = :id",
                    ['u' => $this->uid(), 'a' => $agora->format('Y-m-d H:i:s'), 'id' => $ja['id']]);
                // Quando quem chega agora é o(a) cuidador(a), vale a forma que ele(a) declarou.
                if ($origem === 'cuidador') $ja['modalidade'] = $modalidade;
                self::entrarNaRede($ja);
                Auditoria::registrar('rede.pedido_aceito', 'pedido_rede', (string)$ja['id'], null, ['empresa' => $empresaId, 'origem' => $ja['origem'], 'pedido_dos_dois' => true]);
                return self::formatar(Bd::um('SELECT * FROM pedidos_rede WHERE id = :id', ['id' => $ja['id']]));
            }
            $id = Ulid::novo();
            Bd::inserir('pedidos_rede', [
                'id' => $id, 'empresa_id' => $empresaId, 'usuario_id' => $usuarioId, 'origem' => $origem,
                'modalidade' => $modalidade, 'mensagem' => $mensagem, 'status' => 'pendente', 'chave_pendente' => $chave,
                'criado_por' => $this->uid(), 'criado_em' => $agora->format('Y-m-d H:i:s'),
                'expira_em' => $agora->modify('+' . self::DIAS_VALIDADE . ' days')->format('Y-m-d H:i:s'),
            ]);
            Auditoria::registrar('rede.pedido_criado', 'pedido_rede', $id, null, ['empresa' => $empresaId, 'origem' => $origem]);
            return self::formatar(Bd::um('SELECT * FROM pedidos_rede WHERE id = :id', ['id' => $id]));
        });
    }

    /** @param array<string,mixed> $p */
    private static function entrarNaRede(array $p): void
    {
        Bd::exec(
            "INSERT INTO empresa_cuidadores (id, empresa_id, usuario_id, modalidade, status, criado_em)
             VALUES (:id, :e, :u, :m, 'ativo', :a)
             ON DUPLICATE KEY UPDATE status = 'ativo', modalidade = VALUES(modalidade), encerrado_em = NULL",
            ['id' => Ulid::novo(), 'e' => $p['empresa_id'], 'u' => $p['usuario_id'], 'm' => $p['modalidade'], 'a' => Tempo::agoraSql()]
        );
        $conversa = Conversas::direta((string)$p['empresa_id'], (string)$p['usuario_id']);
        Conversas::sistema($conversa, '🤝 ' . Formata::pessoaPublica((string)$p['usuario_id'])['nome'] . ' entrou na rede da empresa.');
    }

    /** Já cadastrado(a) como cuidador(a): cuida de alguém pelo app ou está na rede de uma empresa. */
    public static function ehCuidador(string $usuarioId): bool
    {
        return Bd::valor(
            "SELECT 1 FROM vinculos WHERE usuario_id = :u1 AND status = 'ativo' AND (papel = 'cuidador' OR atua_como_cuidador = 1)
             UNION SELECT 1 FROM empresa_cuidadores WHERE usuario_id = :u2 AND status = 'ativo' LIMIT 1",
            ['u1' => $usuarioId, 'u2' => $usuarioId]
        ) !== null;
    }

    private function exigirCuidador(): void
    {
        LogAcesso::perfil('cuidador');
        if (!self::ehCuidador($this->uid())) {
            throw ErroHttp::proibido('Esta parte é para quem já cuida de alguém pelo app.');
        }
    }

    private static function naRede(string $empresaId, string $usuarioId): bool
    {
        return Bd::valor("SELECT 1 FROM empresa_cuidadores WHERE empresa_id = :e AND usuario_id = :u AND status = 'ativo'", ['e' => $empresaId, 'u' => $usuarioId]) !== null;
    }

    /** Pedido sem resposta em 30 dias vence sozinho. */
    public static function expirar(): void
    {
        Bd::exec("UPDATE pedidos_rede SET status = 'expirado', chave_pendente = NULL WHERE status = 'pendente' AND expira_em <= :a", ['a' => Tempo::agoraSql()]);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function formatar(array $p): array
    {
        return [
            'id' => $p['id'], 'origem' => $p['origem'], 'modalidade' => $p['modalidade'], 'mensagem' => $p['mensagem'], 'status' => $p['status'],
            'criado_em' => Tempo::sqlParaIso($p['criado_em']), 'expira_em' => Tempo::sqlParaIso($p['expira_em']),
            'respondido_em' => Tempo::sqlParaIso($p['respondido_em']),
        ];
    }

    private static function cnpj(string $c): string
    {
        return strlen($c) === 14 ? substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12) : $c;
    }
}
