<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\LogAcesso;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

/**
 * Vínculo entre empresa e pessoa cuidada, pedido pelos dois lados:
 *   - o(a) gestor(a) procura a empresa, escolhe a pessoa e o que a empresa pode ver, e pede;
 *     a empresa aceita no painel Clientes;
 *   - a empresa pede ao(à) gestor(a) (pelo celular ou e-mail) e diz o que gostaria de ver;
 *     o(a) gestor(a) aceita em "Quem cuida", escolhe a pessoa e decide o que a empresa vê.
 *
 * LGPD: quem decide é sempre o(a) gestor(a) (responsável pela pessoa cuidada). O aceite grava
 * um consentimento "compartilhar_empresa", avisa todos na conversa e pode ser desfeito a qualquer
 * hora (encerrar a relação ou mudar o que a empresa vê). A empresa nunca vê presença
 * (início e fim do serviço de cada cuidador(a)) — ver CuidadoControlador::linhaDoTempo.
 */
final class ClientesControlador extends Controlador
{
    private const DIAS_VALIDADE = 30;
    /** O que a empresa pode ver ou fazer. Registrar, editar remédios/plano e administrar ficam de fora. */
    public const PERMISSOES = ['atendimentos', 'plano_ver', 'ver_registros', 'remedios_ver', 'fotos', 'mensagens', 'ficha_emergencia'];

    // ------------------------------------------------------------ lado do(a) gestor(a)

    /** GET /pessoas/{id}/empresas/buscar?q= — nome ou CNPJ, mínimo 3 letras */
    public function buscarEmpresas(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'administrar');
        $q = trim((string)$this->req->q('q', ''));
        if (mb_strlen($q) < 3) return Resposta::json(['empresas' => []]);
        $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
        $dig = (string)preg_replace('/\D/', '', $q);
        $porCnpj = strlen($dig) >= 8;
        $linhas = Bd::todos(
            'SELECT id, nome_fantasia, razao_social, cnpj FROM empresas WHERE nome_fantasia LIKE :q1 OR razao_social LIKE :q2'
            . ($porCnpj ? ' OR cnpj LIKE :q3' : '') . ' ORDER BY COALESCE(nome_fantasia, razao_social) LIMIT 10',
            ['q1' => $like, 'q2' => $like] + ($porCnpj ? ['q3' => $dig . '%'] : [])
        );
        $pid = (string)$ctx['pessoa']['id'];
        return Resposta::json(['empresas' => array_map(fn($e) => [
            'id' => $e['id'], 'nome' => $e['nome_fantasia'] ?: $e['razao_social'], 'razao_social' => $e['razao_social'],
            'cnpj' => self::cnpj((string)$e['cnpj']),
            'pedido_pendente' => Bd::valor('SELECT id FROM pedidos_cliente WHERE chave_pendente = :k', ['k' => self::chaveGestor($pid, (string)$e['id'])]) !== null,
        ], $linhas)]);
    }

    /** POST /pessoas/{id}/pedidos-empresa {empresa_id, permissoes, mensagem?} */
    public function pedirPeloGestor(): Resposta
    {
        $ctx = Acesso::pessoa($this->u(), $this->req->param('id'));
        Acesso::exigir($ctx, 'administrar');
        $v = Valida::de($this->corpo());
        $empresaId = $v->ulid('empresa_id');
        $mensagem = $v->texto('mensagem', false, 200);
        $v->validar();
        $perm = self::limpar($this->corpo()['permissoes'] ?? []);
        $e = Bd::um('SELECT id, nome_fantasia, razao_social FROM empresas WHERE id = :id', ['id' => $empresaId]);
        if ($e === null) throw ErroHttp::naoEncontrado('Empresa não encontrada.');
        $p = $ctx['pessoa'];
        self::exigirSemOutraEmpresa($p, (string)$e['id']);
        $uid = $this->uid();

        $r = Bd::transacao(function () use ($e, $p, $perm, $mensagem, $uid) {
            self::expirar();
            // A empresa já tinha pedido a este(a) gestor(a)? Então os dois concordam: liga direto.
            $dela = Bd::um('SELECT * FROM pedidos_cliente WHERE chave_pendente = :k FOR UPDATE', ['k' => self::chaveEmpresa((string)$e['id'], $uid)]);
            if ($dela !== null) {
                $this->fechar($dela, 'aceito', (string)$p['id'], $perm);
                return self::formatar(Bd::um('SELECT * FROM pedidos_cliente WHERE id = :id', ['id' => $dela['id']]));
            }
            $id = $this->criar('gestor', (string)$e['id'], $uid, (string)$p['id'], $perm, $mensagem, self::chaveGestor((string)$p['id'], (string)$e['id']),
                'Você já pediu a esta empresa. Agora é ela que responde.');
            return self::formatar(Bd::um('SELECT * FROM pedidos_cliente WHERE id = :id', ['id' => $id]));
        });
        foreach (Bd::todos('SELECT usuario_id FROM empresa_membros WHERE empresa_id = :e AND ativo = 1', ['e' => $e['id']]) as $m) {
            Avisos::evento('pedido_cliente', (string)$m['usuario_id'], ['pedido_id' => $r['id'], 'empresa_id' => $e['id']]);
        }
        return Resposta::json($r + ['empresa' => ['id' => $e['id'], 'nome' => $e['nome_fantasia'] ?: $e['razao_social']]], $r['status'] === 'aceito' ? 200 : 201);
    }

    /** GET /eu/pedidos-empresa — pedidos que empresas fizeram a mim (gestor(a)) e os que fiz */
    public function meusPedidos(): Resposta
    {
        self::expirar();
        $uid = $this->uid();
        $lista = Bd::todos(
            "SELECT pc.*, e.nome_fantasia, e.razao_social, p.apelido AS pessoa_apelido
               FROM pedidos_cliente pc JOIN empresas e ON e.id = pc.empresa_id
               LEFT JOIN pessoas_cuidadas p ON p.id = pc.pessoa_id
              WHERE pc.gestor_id = :u AND pc.status = 'pendente' ORDER BY pc.criado_em DESC",
            ['u' => $uid]
        );
        $fmt = fn($x) => self::formatar($x) + [
            'empresa' => ['id' => $x['empresa_id'], 'nome' => $x['nome_fantasia'] ?: $x['razao_social']],
            'pessoa' => $x['pessoa_id'] ? ['id' => $x['pessoa_id'], 'apelido' => $x['pessoa_apelido']] : null,
        ];
        return Resposta::json([
            'recebidos' => array_values(array_map($fmt, array_filter($lista, fn($x) => $x['origem'] === 'empresa'))),
            'enviados' => array_values(array_map($fmt, array_filter($lista, fn($x) => $x['origem'] === 'gestor'))),
        ]);
    }

    // ------------------------------------------------------------ lado da empresa

    /** POST /empresas/{id}/pedidos-cliente {contato: celular ou e-mail do(a) gestor(a), permissoes, mensagem?} */
    public function pedirPelaEmpresa(): Resposta
    {
        $e = Acesso::empresa($this->u(), $this->req->param('id'));
        $v = Valida::de($this->corpo());
        $contato = (string)$v->texto('contato', true, 190);
        $mensagem = $v->texto('mensagem', false, 200);
        $v->validar();
        $perm = self::limpar($this->corpo()['permissoes'] ?? ['atendimentos', 'plano_ver']);

        $naoAchados = (int)Bd::valor(
            "SELECT COUNT(*) FROM log_acessos WHERE empresa_id = :e AND rota = '/empresas/{id}/pedidos-cliente' AND status = 404 AND criado_em > :h",
            ['e' => $e['id'], 'h' => Tempo::agora()->modify('-1 hour')->format('Y-m-d H:i:s')]
        );
        if ($naoAchados >= 20) throw ErroHttp::muitas('Muitos contatos não encontrados. Espere uma hora ou use "Convidar o(a) gestor(a)".');

        if (str_contains($contato, '@')) {
            $email = Valida::email($contato);
            if ($email === null) throw ErroHttp::validacao(['contato' => 'E-mail inválido']);
            $g = Bd::um('SELECT * FROM usuarios WHERE email = :x AND excluido_em IS NULL AND bloqueado_em IS NULL', ['x' => $email]);
        } else {
            $tel = Valida::telefone($contato);
            if ($tel === null) throw ErroHttp::validacao(['contato' => 'Celular inválido. Use DDD + número']);
            // Celular só informado (sem código) não identifica ninguém com segurança.
            $g = Bd::um('SELECT * FROM usuarios WHERE telefone = :x AND telefone_confirmado = 1 AND excluido_em IS NULL AND bloqueado_em IS NULL', ['x' => $tel]);
        }
        $ehGestor = $g !== null && Bd::valor(
            "SELECT 1 FROM vinculos v JOIN pessoas_cuidadas p ON p.id = v.pessoa_id
              WHERE v.usuario_id = :u AND v.papel = 'gestor' AND v.status = 'ativo' AND p.excluido_em IS NULL LIMIT 1",
            ['u' => $g['id']]
        ) !== null;
        if (!$ehGestor) {
            throw ErroHttp::naoEncontrado('Não achamos gestor(a) com este contato no app. Use "Convidar o(a) gestor(a)" para mandar um convite pelo WhatsApp.');
        }
        $gid = (string)$g['id'];

        $r = Bd::transacao(function () use ($e, $gid, $perm, $mensagem) {
            self::expirar();
            // O(a) gestor(a) já tinha pedido a esta empresa? Aceitar aqui vale para aquela pessoa, com o que ele(a) liberou.
            $dele = Bd::um("SELECT * FROM pedidos_cliente WHERE empresa_id = :e AND gestor_id = :g AND origem = 'gestor' AND status = 'pendente' ORDER BY criado_em LIMIT 1 FOR UPDATE",
                ['e' => $e['id'], 'g' => $gid]);
            if ($dele !== null) {
                $this->fechar($dele, 'aceito', (string)$dele['pessoa_id'], json_decode((string)$dele['permissoes'], true) ?: []);
                return self::formatar(Bd::um('SELECT * FROM pedidos_cliente WHERE id = :id', ['id' => $dele['id']]));
            }
            $id = $this->criar('empresa', (string)$e['id'], $gid, null, $perm, $mensagem, self::chaveEmpresa((string)$e['id'], $gid),
                'Já existe um pedido esperando resposta desta pessoa.');
            return self::formatar(Bd::um('SELECT * FROM pedidos_cliente WHERE id = :id', ['id' => $id]));
        });
        Avisos::evento('pedido_cliente', $gid, ['pedido_id' => $r['id'], 'empresa_id' => $e['id']]);
        return Resposta::json($r + ['gestor' => Formata::pessoaPublica($gid)], $r['status'] === 'aceito' ? 200 : 201);
    }

    /** Para o painel Clientes. @return array{recebidos:list<array<string,mixed>>,enviados:list<array<string,mixed>>} */
    public static function daEmpresa(string $empresaId): array
    {
        self::expirar();
        $lista = Bd::todos(
            "SELECT pc.*, p.apelido AS pessoa_apelido, p.nascimento FROM pedidos_cliente pc LEFT JOIN pessoas_cuidadas p ON p.id = pc.pessoa_id
              WHERE pc.empresa_id = :e AND (pc.status = 'pendente' OR pc.respondido_em > :h) ORDER BY pc.criado_em DESC LIMIT 100",
            ['e' => $empresaId, 'h' => Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s')]
        );
        $fmt = fn($x) => self::formatar($x) + [
            'gestor' => Formata::pessoaPublica((string)$x['gestor_id']),
            // Antes do aceite, só o "como gosta de ser chamado(a)" da pessoa (nada de saúde).
            'pessoa' => $x['pessoa_id'] ? ['id' => $x['pessoa_id'], 'apelido' => $x['pessoa_apelido']] : null,
        ];
        return [
            'recebidos' => array_values(array_map($fmt, array_filter($lista, fn($x) => $x['origem'] === 'gestor' && $x['status'] === 'pendente'))),
            'enviados' => array_values(array_map($fmt, array_filter($lista, fn($x) => $x['origem'] === 'empresa'))),
        ];
    }

    // ------------------------------------------------------------ responder (os dois lados)

    /** POST /pedidos-cliente/{id}/aceitar {pessoa_id?, permissoes?} — gestor(a) escolhe a pessoa e o que a empresa vê */
    public function aceitar(): Resposta
    {
        return $this->responder('aceito');
    }

    /** POST /pedidos-cliente/{id}/recusar */
    public function recusar(): Resposta
    {
        return $this->responder('recusado');
    }

    /** POST /pedidos-cliente/{id}/cancelar — quem pediu desiste */
    public function cancelar(): Resposta
    {
        return $this->responder('cancelado');
    }

    private function responder(string $novo): Resposta
    {
        $uid = $this->uid();
        $id = $this->req->param('id');
        $corpo = $this->corpo();
        $saida = Bd::transacao(function () use ($id, $uid, $novo, $corpo) {
            self::expirar();
            $p = Bd::um('SELECT * FROM pedidos_cliente WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($p === null) throw ErroHttp::naoEncontrado('Pedido não encontrado.');
            $souGestor = $p['gestor_id'] === $uid;
            $souEmpresa = Acesso::membroEmpresa($uid, (string)$p['empresa_id']) !== null;
            $recebeu = $p['origem'] === 'empresa' ? $souGestor : $souEmpresa;
            $mandou = $p['origem'] === 'empresa' ? $souEmpresa : $souGestor;
            if (!($novo === 'cancelado' ? $mandou : $recebeu)) throw ErroHttp::naoEncontrado('Pedido não encontrado.');
            LogAcesso::perfil($souGestor ? 'gestor' : 'empresa', $p['pessoa_id'], (string)$p['empresa_id']);
            if ($p['status'] !== 'pendente') {
                throw ErroHttp::conflito('pedido_encerrado', $p['status'] === 'expirado' ? 'Este pedido venceu. Peça de novo.' : 'Este pedido já foi respondido.');
            }
            $pessoaId = $p['pessoa_id'];
            $perm = json_decode((string)$p['permissoes'], true) ?: [];
            if ($novo === 'aceito' && $p['origem'] === 'empresa') {
                // Quem decide é o(a) gestor(a): qual pessoa e o que a empresa vê (pode ser menos que o pedido).
                $pessoaId = (string)($corpo['pessoa_id'] ?? '');
                if ($pessoaId === '') throw ErroHttp::validacao(['pessoa_id' => 'Escolha a pessoa'], 'Escolha qual pessoa a empresa vai acompanhar.');
                $ctx = Acesso::pessoa($this->u(), $pessoaId);
                Acesso::exigir($ctx, 'administrar');
                if (array_key_exists('permissoes', $corpo)) $perm = self::limpar($corpo['permissoes']);
            }
            $this->fechar($p, $novo, $pessoaId, $perm);
            return ['id' => $p['id'], 'status' => $novo, 'pessoa_id' => $pessoaId, 'empresa_id' => $p['empresa_id'], 'permissoes' => $novo === 'aceito' ? $perm : null];
        });
        return Resposta::json($saida);
    }

    // ------------------------------------------------------------ apoio

    /** @param list<string> $perm */
    private function criar(string $origem, string $empresaId, string $gestorId, ?string $pessoaId, array $perm, ?string $mensagem, string $chave, string $msgRepetido): string
    {
        if (Bd::valor('SELECT id FROM pedidos_cliente WHERE chave_pendente = :k', ['k' => $chave]) !== null) {
            throw ErroHttp::conflito('pedido_pendente', $msgRepetido);
        }
        $agora = Tempo::agora();
        $id = Ulid::novo();
        Bd::inserir('pedidos_cliente', [
            'id' => $id, 'empresa_id' => $empresaId, 'gestor_id' => $gestorId, 'pessoa_id' => $pessoaId, 'origem' => $origem,
            'permissoes' => $perm, 'mensagem' => $mensagem, 'status' => 'pendente', 'chave_pendente' => $chave,
            'criado_por' => $this->uid(), 'criado_em' => $agora->format('Y-m-d H:i:s'),
            'expira_em' => $agora->modify('+' . self::DIAS_VALIDADE . ' days')->format('Y-m-d H:i:s'),
        ]);
        Auditoria::registrar('cliente.pedido_criado', 'pedido_cliente', $id, $pessoaId, ['empresa' => $empresaId, 'origem' => $origem, 'permissoes' => $perm]);
        return $id;
    }

    /**
     * Fecha o pedido. No aceite, liga a pessoa à empresa com as permissões escolhidas,
     * grava o consentimento do(a) gestor(a) e avisa todos na conversa da pessoa.
     * @param array<string,mixed> $p @param list<string> $perm
     */
    private function fechar(array $p, string $novo, ?string $pessoaId, array $perm): void
    {
        $agora = Tempo::agoraSql();
        if ($novo === 'aceito') {
            $pessoa = Bd::um('SELECT * FROM pessoas_cuidadas WHERE id = :id AND excluido_em IS NULL FOR UPDATE', ['id' => $pessoaId]);
            if ($pessoa === null) throw ErroHttp::naoEncontrado('Pessoa não encontrada.');
            self::exigirSemOutraEmpresa($pessoa, (string)$p['empresa_id']);
            Bd::atualizar('pessoas_cuidadas', ['empresa_id' => $p['empresa_id'], 'empresa_permissoes' => $perm, 'atualizado_em' => $agora], ['id' => $pessoaId]);
            Bd::inserir('consentimentos', [
                'id' => Ulid::novo(), 'usuario_id' => $p['gestor_id'], 'pessoa_id' => $pessoaId, 'tipo' => 'compartilhar_empresa',
                'versao' => (string)Config::get('termos.versao'), 'ip' => $this->req->ip, 'aceito_em' => $agora,
            ]);
            $e = Bd::um('SELECT nome_fantasia, razao_social FROM empresas WHERE id = :id', ['id' => $p['empresa_id']]);
            $nomes = ['atendimentos' => 'organiza atendimentos', 'ver_registros' => 'vê a linha do tempo', 'plano_ver' => 'vê o plano de cuidado',
                'remedios_ver' => 'vê os remédios', 'fotos' => 'vê as fotos', 'mensagens' => 'participa da conversa', 'ficha_emergencia' => 'vê a ficha de emergência'];
            $oQue = implode(', ', array_map(fn($x) => $nomes[$x] ?? $x, $perm)) ?: 'sem acesso a dados';
            Conversas::sistema(Conversas::daPessoa((string)$pessoaId), '🏢 A empresa ' . ($e['nome_fantasia'] ?: $e['razao_social']) . " passou a acompanhar o cuidado ($oQue).");
        }
        Bd::exec('UPDATE pedidos_cliente SET status = :s, chave_pendente = NULL, pessoa_id = :p, permissoes = :perm, respondido_por = :u, respondido_em = :a WHERE id = :id',
            ['s' => $novo, 'p' => $pessoaId, 'perm' => json_encode($perm), 'u' => $this->uid(), 'a' => $agora, 'id' => $p['id']]);
        Auditoria::registrar('cliente.pedido_' . $novo, 'pedido_cliente', (string)$p['id'], $pessoaId,
            ['empresa' => $p['empresa_id'], 'origem' => $p['origem']] + ($novo === 'aceito' ? ['permissoes' => $perm] : []));
    }

    /** @param array<string,mixed> $pessoa */
    private static function exigirSemOutraEmpresa(array $pessoa, string $empresaId): void
    {
        if ($pessoa['empresa_id'] === $empresaId) throw ErroHttp::conflito('ja_cliente', 'Esta pessoa já é acompanhada por esta empresa.');
        if ($pessoa['empresa_id'] !== null) {
            throw ErroHttp::conflito('outra_empresa', 'Esta pessoa já é acompanhada por outra empresa. Encerre aquela relação em "Quem cuida" antes.');
        }
    }

    /** @param mixed $lista @return list<string> */
    public static function limpar(mixed $lista): array
    {
        $lista = is_array($lista) ? $lista : [];
        return array_values(array_unique(array_filter($lista, fn($x) => is_string($x) && in_array($x, self::PERMISSOES, true))));
    }

    public static function expirar(): void
    {
        Bd::exec("UPDATE pedidos_cliente SET status = 'expirado', chave_pendente = NULL WHERE status = 'pendente' AND expira_em <= :a", ['a' => Tempo::agoraSql()]);
    }

    private static function chaveGestor(string $pessoaId, string $empresaId): string
    {
        return "p:$pessoaId:$empresaId";
    }

    private static function chaveEmpresa(string $empresaId, string $gestorId): string
    {
        return "g:$gestorId:$empresaId";
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function formatar(array $p): array
    {
        return [
            'id' => $p['id'], 'origem' => $p['origem'], 'status' => $p['status'], 'mensagem' => $p['mensagem'],
            'permissoes' => json_decode((string)$p['permissoes'], true) ?: [],
            'criado_em' => Tempo::sqlParaIso($p['criado_em']), 'expira_em' => Tempo::sqlParaIso($p['expira_em']),
            'respondido_em' => Tempo::sqlParaIso($p['respondido_em']),
        ];
    }

    private static function cnpj(string $c): string
    {
        return strlen($c) === 14 ? substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12) : $c;
    }
}
