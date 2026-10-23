<?php
declare(strict_types=1);

namespace Cuidados\Modulos\Admin;

use Cuidados\Auth\AdminSessao;
use Cuidados\Auth\Google;
use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\LogAcesso;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

/** Entrar no painel com Google e gerir quem tem acesso a ele. */
final class AcessoAdmin extends AdminBase
{
    /** GET /admin/config — público: o painel precisa do client_id para desenhar o botão do Google. */
    public function config(): Resposta
    {
        return Resposta::json([
            'google' => Google::ligado() ? Google::clientId() : null,
            'configurado' => AdminSessao::donosDaConfiguracao() !== [] || (int)Bd::valor('SELECT COUNT(*) FROM admin_usuarios WHERE ativo = 1') > 0,
            'ambiente' => Config::emTeste() ? 'teste' : 'producao',
        ]);
    }

    /** POST /admin/entrar {credencial} — só Google, e-mail confirmado e na lista de administradores. */
    public function entrar(): Resposta
    {
        $recusas = (int)Bd::valor(
            "SELECT COUNT(*) FROM log_acessos WHERE evento = 'admin.entrada_recusada' AND ip <=> :ip AND criado_em > :lim",
            ['ip' => $this->req->ip, 'lim' => Tempo::agora()->modify('-15 minutes')->format('Y-m-d H:i:s')]
        );
        if ($recusas >= 10) throw ErroHttp::muitas('Muitas tentativas recusadas. Espere 15 minutos.');

        $v = Valida::de($this->corpo());
        $credencial = $v->texto('credencial', true, 4096);
        $v->validar();
        $g = Google::verificar((string)$credencial);
        if ($g['email'] === null || !$g['email_verificado']) {
            throw ErroHttp::proibido('O e-mail desta conta Google não está confirmado.');
        }
        $email = $g['email'];
        $agora = Tempo::agoraSql();

        $admin = Bd::transacao(function () use ($g, $email, $agora) {
            if (in_array($email, AdminSessao::donosDaConfiguracao(), true)) {
                // Dono pela configuração do servidor: cria ou reativa como dono.
                $ja = Bd::um('SELECT id FROM admin_usuarios WHERE email = :e', ['e' => $email]);
                if ($ja === null) {
                    Bd::inserir('admin_usuarios', ['id' => Ulid::novo(), 'email' => $email, 'nome' => $g['nome'], 'papel' => 'dono',
                        'ativo' => 1, 'criado_em' => $agora, 'atualizado_em' => $agora]);
                } else {
                    Bd::exec("UPDATE admin_usuarios SET papel = 'dono', ativo = 1, atualizado_em = :a WHERE id = :id", ['a' => $agora, 'id' => $ja['id']]);
                }
            }
            $a = Bd::um('SELECT * FROM admin_usuarios WHERE email = :e AND ativo = 1', ['e' => $email]);
            if ($a === null) throw ErroHttp::proibido('Este e-mail não tem acesso ao painel. Peça a um(a) dono(a) do painel.');
            if ($a['google_sub'] !== null && !hash_equals((string)$a['google_sub'], $g['sub'])) {
                // O e-mail passou a outra conta Google (ex.: domínio recriado): não entra sem um(a) dono(a) liberar.
                throw ErroHttp::proibido('Esta conta Google não é a mesma do primeiro acesso. Peça a um(a) dono(a) para liberar de novo.');
            }
            Bd::exec('UPDATE admin_usuarios SET google_sub = :s, nome = COALESCE(nome, :n), ultimo_acesso_em = :a, atualizado_em = :a2 WHERE id = :id',
                ['s' => $g['sub'], 'n' => $g['nome'], 'a' => $agora, 'a2' => $agora, 'id' => $a['id']]);
            return Bd::um('SELECT * FROM admin_usuarios WHERE id = :id', ['id' => $a['id']]);
        });

        $s = AdminSessao::criar((string)$admin['id'], $this->req->ip, LogAcesso::dispositivo($this->req));
        LogAcesso::admin((string)$admin['id'], $s['id']);
        LogAcesso::evento('admin.entrou');
        $this->admin = $admin;
        $this->auditar('entrou', 'admin', (string)$admin['id']);

        $r = Resposta::json(['admin' => self::formatar($admin)]);
        AdminSessao::cookie($r, $s['token']);
        return $r;
    }

    /** GET /admin/eu */
    public function eu(): Resposta
    {
        return Resposta::json(['admin' => self::formatar($this->adm())]);
    }

    /** POST /admin/sair */
    public function sair(): Resposta
    {
        $s = AdminSessao::atual($this->req);
        if ($s !== null) {
            LogAcesso::admin((string)$s['admin']['id'], $s['sessao_id']);
            LogAcesso::evento('admin.saiu');
            AdminSessao::revogar($s['sessao_id']);
        }
        $r = Resposta::vazio();
        AdminSessao::cookie($r, '', true);
        return $r;
    }

    /** GET /admin/admins */
    public function listar(): Resposta
    {
        $this->adm();
        $donos = AdminSessao::donosDaConfiguracao();
        $itens = array_map(function ($a) use ($donos) {
            $f = self::formatar($a);
            $f['da_configuracao'] = in_array($a['email'], $donos, true);
            $f['sessoes_ativas'] = (int)$a['sessoes_ativas'];
            return $f;
        }, Bd::todos(
            'SELECT a.*, (SELECT COUNT(*) FROM admin_sessoes s WHERE s.admin_id = a.id AND s.revogada_em IS NULL AND s.expira_em > :ag) AS sessoes_ativas
               FROM admin_usuarios a ORDER BY a.ativo DESC, a.email',
            ['ag' => Tempo::agoraSql()]
        ));
        $pendentes = array_values(array_diff($donos, array_column($itens, 'email')));
        return Resposta::json(['itens' => $itens, 'donos_sem_primeiro_acesso' => $pendentes]);
    }

    /** POST /admin/admins {email, nome?, papel} — só dono */
    public function criar(): Resposta
    {
        $this->adm('dono');
        $v = Valida::de($this->corpo());
        $emailBruto = $v->texto('email', true, 190);
        $nome = $v->texto('nome', false, 120);
        $papel = $v->opcao('papel', array_keys(AdminSessao::PAPEIS), true);
        $email = Valida::email((string)$emailBruto);
        if ($email === null) $v->erro('email', 'E-mail inválido');
        $v->validar();
        if (Bd::um('SELECT id FROM admin_usuarios WHERE email = :e', ['e' => $email]) !== null) {
            throw ErroHttp::conflito('ja_existe', 'Este e-mail já está na lista. Altere o papel ou reative.');
        }
        $agora = Tempo::agoraSql();
        $id = Ulid::novo();
        Bd::inserir('admin_usuarios', ['id' => $id, 'email' => $email, 'nome' => $nome, 'papel' => $papel, 'ativo' => 1,
            'criado_por' => $this->admin['id'], 'criado_em' => $agora, 'atualizado_em' => $agora]);
        $this->auditar('admin_criado', 'admin', $id, null, ['email' => $email, 'papel' => $papel]);
        return Resposta::json(['admin' => self::formatar(Bd::um('SELECT * FROM admin_usuarios WHERE id = :id', ['id' => $id]))], 201);
    }

    /** PATCH /admin/admins/{id} {papel?, ativo?, liberar_google?} — só dono; ninguém muda a si mesmo nem os donos da configuração */
    public function atualizar(): Resposta
    {
        $eu = $this->adm('dono');
        $id = $this->req->param('id');
        $a = Bd::um('SELECT * FROM admin_usuarios WHERE id = :id', ['id' => $id]);
        if ($a === null) throw ErroHttp::naoEncontrado();
        if ($a['id'] === $eu['id']) throw ErroHttp::conflito('proprio', 'Você não pode mudar o seu próprio acesso. Peça a outro(a) dono(a).');
        if (in_array($a['email'], AdminSessao::donosDaConfiguracao(), true)) {
            throw ErroHttp::conflito('da_configuracao', 'Este e-mail é dono(a) pela configuração do servidor (admin.donos). Altere lá.');
        }
        $c = $this->corpo();
        $mud = [];
        if (isset($c['papel'])) {
            if (!isset(AdminSessao::PAPEIS[$c['papel']])) throw ErroHttp::validacao(['papel' => 'Papel inválido']);
            $mud['papel'] = $c['papel'];
        }
        if (array_key_exists('ativo', $c)) $mud['ativo'] = $c['ativo'] ? 1 : 0;
        if (!empty($c['liberar_google'])) $mud['google_sub'] = null;
        if ($mud === []) throw ErroHttp::validacao([], 'Nada para mudar.');
        $mud['atualizado_em'] = Tempo::agoraSql();
        Bd::atualizar('admin_usuarios', $mud, ['id' => $id]);
        if (($mud['ativo'] ?? 1) === 0 || array_key_exists('google_sub', $mud) || isset($mud['papel'])) {
            AdminSessao::revogarTodas($id); // mudou o acesso: entra de novo
        }
        unset($mud['atualizado_em']);
        $this->auditar('admin_alterado', 'admin', $id, null, ['email' => $a['email']] + $mud);
        return Resposta::json(['admin' => self::formatar(Bd::um('SELECT * FROM admin_usuarios WHERE id = :id', ['id' => $id]))]);
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    public static function formatar(array $a): array
    {
        return [
            'id' => $a['id'],
            'email' => $a['email'],
            'nome' => $a['nome'],
            'papel' => $a['papel'],
            'ativo' => (int)$a['ativo'] === 1,
            'google_vinculado' => $a['google_sub'] !== null,
            'criado_em' => self::iso($a['criado_em']),
            'ultimo_acesso_em' => self::iso($a['ultimo_acesso_em']),
        ];
    }
}
