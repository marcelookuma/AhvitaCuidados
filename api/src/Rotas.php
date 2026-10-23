<?php
declare(strict_types=1);

namespace Cuidados;

use Cuidados\Http\Roteador;
use Cuidados\Modulos\Admin\AcessoAdmin;
use Cuidados\Modulos\Admin\CadastrosAdmin as Cad;
use Cuidados\Modulos\Admin\LogsAdmin;
use Cuidados\Modulos\Admin\VisaoAdmin;
use Cuidados\Modulos\AgendaControlador as Agenda;
use Cuidados\Modulos\ClientesControlador as Clientes;
use Cuidados\Modulos\AuthControlador as Auth;
use Cuidados\Modulos\ConversasControlador as Conv;
use Cuidados\Modulos\ConvitesControlador as Convites;
use Cuidados\Modulos\CuidadoControlador as Cuidado;
use Cuidados\Modulos\EmpresasControlador as Empresas;
use Cuidados\Modulos\EuControlador as Eu;
use Cuidados\Modulos\MidiasControlador as Midias;
use Cuidados\Modulos\PessoasControlador as Pessoas;
use Cuidados\Modulos\RedeControlador as Rede;

/** Contrato da API v1 (documentado em docs/API.md). */
final class Rotas
{
    public static function registrar(Roteador $r): void
    {
        $r->add('GET', '/saude', fn() => self::saude(), true);
        $r->add('POST', '/sistema/tarefas', fn($req) => self::tarefas($req), true);

        // Acesso
        $r->add('GET', '/auth/canais', [Auth::class, 'canais'], true);
        $r->add('POST', '/auth/codigo', [Auth::class, 'codigo'], true);
        $r->add('POST', '/auth/entrar', [Auth::class, 'entrar'], true);
        $r->add('POST', '/auth/google', [Auth::class, 'google'], true);
        $r->add('POST', '/auth/sair', [Auth::class, 'sair']);

        // Eu
        $r->add('GET', '/eu', [Eu::class, 'ver']);
        $r->add('PATCH', '/eu', [Eu::class, 'atualizar']);
        $r->add('POST', '/eu/termos', [Eu::class, 'aceitarTermos']);
        $r->add('POST', '/eu/contato/codigo', [Eu::class, 'pedirCodigoContato']);
        $r->add('POST', '/eu/contato/confirmar', [Eu::class, 'confirmarContato']);
        $r->add('POST', '/eu/contato/remover', [Eu::class, 'removerContato']);
        $r->add('GET', '/eu/inicio', [Eu::class, 'inicio']);
        $r->add('GET', '/eu/convites', [Eu::class, 'convites']);
        $r->add('GET', '/eu/exportar', [Eu::class, 'exportar']);
        $r->add('POST', '/eu/excluir', [Eu::class, 'excluir']);

        // Pessoa cuidada
        $r->add('GET', '/pessoas', [Pessoas::class, 'listar']);
        $r->add('POST', '/pessoas', [Pessoas::class, 'criar']);
        $r->add('GET', '/pessoas/{id}', [Pessoas::class, 'ver']);
        $r->add('PATCH', '/pessoas/{id}', [Pessoas::class, 'atualizar']);
        $r->add('GET', '/pessoas/{id}/painel', [Pessoas::class, 'painel']);
        $r->add('GET', '/pessoas/{id}/vinculos', [Pessoas::class, 'vinculos']);
        $r->add('POST', '/pessoas/{id}/empresa/desvincular', [Pessoas::class, 'desvincularEmpresa']);
        $r->add('GET', '/pessoas/{id}/exportar', [Pessoas::class, 'exportar']);
        $r->add('POST', '/pessoas/{id}/excluir', [Pessoas::class, 'excluir']);
        $r->add('POST', '/alertas/{id}/resolver', [Pessoas::class, 'resolverAlerta']);

        // Convites e vínculos (permissões por funcionalidade)
        $r->add('POST', '/pessoas/{id}/convites', [Convites::class, 'criarParaPessoa']);
        $r->add('POST', '/empresas/{id}/convites', [Convites::class, 'criarParaEmpresa']);
        $r->add('GET', '/convites/{codigo}', [Convites::class, 'ver']);
        $r->add('POST', '/convites/{codigo}/aceitar', [Convites::class, 'aceitar']);
        $r->add('POST', '/convites/{codigo}/recusar', [Convites::class, 'recusar']);
        $r->add('POST', '/convites/{id}/revogar', [Convites::class, 'revogar']);
        $r->add('PATCH', '/vinculos/{id}', [Convites::class, 'atualizarVinculo']);
        $r->add('POST', '/vinculos/{id}/encerrar', [Convites::class, 'encerrarVinculo']);

        // Conversa, registros, remédios e presença
        $r->add('GET', '/pessoas/{id}/feed', [Cuidado::class, 'feed']);
        $r->add('POST', '/pessoas/{id}/mensagens', [Cuidado::class, 'enviarMensagem']);
        $r->add('POST', '/pessoas/{id}/visto', [Cuidado::class, 'visto']);
        $r->add('POST', '/pessoas/{id}/registros', [Cuidado::class, 'registrar']);
        $r->add('GET', '/pessoas/{id}/registros', [Cuidado::class, 'linhaDoTempo']);
        $r->add('POST', '/registros/{id}/anular', [Cuidado::class, 'anular']);
        $r->add('GET', '/pessoas/{id}/remedios', [Cuidado::class, 'remedios']);
        $r->add('POST', '/pessoas/{id}/remedios', [Cuidado::class, 'criarRemedio']);
        $r->add('PATCH', '/remedios/{id}', [Cuidado::class, 'atualizarRemedio']);
        $r->add('POST', '/pessoas/{id}/presencas', [Cuidado::class, 'iniciarServico']);
        $r->add('POST', '/presencas/{id}/finalizar', [Cuidado::class, 'finalizarServico']);

        // Agenda do(a) cuidador(a) e atendimentos
        $r->add('GET', '/agenda', [Agenda::class, 'ver']);
        $r->add('PUT', '/agenda/disponibilidade', [Agenda::class, 'salvarDisponibilidade']);
        $r->add('POST', '/agenda/bloqueios', [Agenda::class, 'bloquear']);
        $r->add('DELETE', '/agenda/bloqueios/{id}', [Agenda::class, 'desbloquear']);
        $r->add('GET', '/pessoas/{id}/atendimentos', [Agenda::class, 'listar']);
        $r->add('POST', '/pessoas/{id}/atendimentos', [Agenda::class, 'propor']);
        $r->add('GET', '/pessoas/{id}/cuidadores/{cuidador}/disponivel', [Agenda::class, 'checarDisponivel']);
        $r->add('POST', '/atendimentos/{id}/responder', [Agenda::class, 'responder']);
        $r->add('POST', '/atendimentos/{id}/confirmar', [Agenda::class, 'confirmar']);
        $r->add('POST', '/atendimentos/{id}/cancelar', [Agenda::class, 'cancelar']);

        // Empresa
        $r->add('POST', '/empresas', [Empresas::class, 'criar']);
        $r->add('GET', '/empresas/{id}/painel', [Empresas::class, 'painel']);
        $r->add('GET', '/empresas/{id}/cuidadores', [Empresas::class, 'equipe']);
        $r->add('POST', '/empresas/{id}/cuidadores/{cuidador}/encerrar', [Empresas::class, 'encerrarCuidador']);
        $r->add('GET', '/empresas/{id}/clientes', [Empresas::class, 'clientes']);
        $r->add('GET', '/empresas/{id}/atendimentos', [Empresas::class, 'atendimentos']);
        $r->add('POST', '/empresas/{id}/propostas', [Empresas::class, 'criarProposta']);
        $r->add('GET', '/empresas/{id}/conversas', [Conv::class, 'daEmpresa']);
        $r->add('GET', '/conversas/{id}/mensagens', [Conv::class, 'mensagens']);
        $r->add('POST', '/conversas/{id}/mensagens', [Conv::class, 'enviar']);
        $r->add('POST', '/conversas/{id}/visto', [Conv::class, 'visto']);

        // Empresa ↔ pessoa cuidada: pedidos nos dois sentidos; o(a) gestor(a) decide o que a empresa vê
        $r->add('GET', '/pessoas/{id}/empresas/buscar', [Clientes::class, 'buscarEmpresas']);
        $r->add('POST', '/pessoas/{id}/pedidos-empresa', [Clientes::class, 'pedirPeloGestor']);
        $r->add('GET', '/eu/pedidos-empresa', [Clientes::class, 'meusPedidos']);
        $r->add('POST', '/empresas/{id}/pedidos-cliente', [Clientes::class, 'pedirPelaEmpresa']);
        $r->add('POST', '/pedidos-cliente/{id}/aceitar', [Clientes::class, 'aceitar']);
        $r->add('POST', '/pedidos-cliente/{id}/recusar', [Clientes::class, 'recusar']);
        $r->add('POST', '/pedidos-cliente/{id}/cancelar', [Clientes::class, 'cancelar']);

        // Rede da empresa: pedidos nos dois sentidos, com aceite do outro lado
        $r->add('POST', '/empresas/{id}/pedidos-rede', [Rede::class, 'pedirPelaEmpresa']);
        $r->add('GET', '/rede/empresas', [Rede::class, 'buscarEmpresas']);
        $r->add('GET', '/eu/rede', [Rede::class, 'minhaRede']);
        $r->add('POST', '/eu/pedidos-rede', [Rede::class, 'pedirPeloCuidador']);
        $r->add('POST', '/eu/empresas/{id}/sair', [Rede::class, 'sair']);
        $r->add('POST', '/pedidos-rede/{id}/aceitar', [Rede::class, 'aceitar']);
        $r->add('POST', '/pedidos-rede/{id}/recusar', [Rede::class, 'recusar']);
        $r->add('POST', '/pedidos-rede/{id}/cancelar', [Rede::class, 'cancelar']);

        // Fotos e áudios
        $r->add('POST', '/midias', [Midias::class, 'enviar']);
        $r->add('GET', '/midias/{id}', [Midias::class, 'ver']);

        self::admin($r);
    }

    /**
     * Painel de administração (cuidados.ahvita.com/admin). "Públicas" para o Kernel
     * porque têm sessão própria: cada ação confere a sessão do painel e o papel.
     */
    private static function admin(Roteador $r): void
    {
        $r->add('GET', '/admin/config', [AcessoAdmin::class, 'config'], true);
        $r->add('POST', '/admin/entrar', [AcessoAdmin::class, 'entrar'], true);
        $r->add('POST', '/admin/sair', [AcessoAdmin::class, 'sair'], true);
        $r->add('GET', '/admin/eu', [AcessoAdmin::class, 'eu'], true);
        $r->add('GET', '/admin/admins', [AcessoAdmin::class, 'listar'], true);
        $r->add('POST', '/admin/admins', [AcessoAdmin::class, 'criar'], true);
        $r->add('PATCH', '/admin/admins/{id}', [AcessoAdmin::class, 'atualizar'], true);

        $r->add('GET', '/admin/painel', [VisaoAdmin::class, 'painel'], true);
        $r->add('GET', '/admin/uso', [VisaoAdmin::class, 'uso'], true);
        $r->add('GET', '/admin/sistema', [VisaoAdmin::class, 'sistema'], true);
        $r->add('POST', '/admin/sistema/tarefas', [VisaoAdmin::class, 'tarefas'], true);
        $r->add('POST', '/admin/auditoria/verificar', [VisaoAdmin::class, 'verificarAuditoria'], true);

        $r->add('GET', '/admin/usuarios', [Cad::class, 'usuarios'], true);
        $r->add('GET', '/admin/usuarios/{id}', [Cad::class, 'usuario'], true);
        $r->add('PATCH', '/admin/usuarios/{id}', [Cad::class, 'corrigirUsuario'], true);
        $r->add('POST', '/admin/usuarios/{id}/bloquear', [Cad::class, 'bloquear'], true);
        $r->add('POST', '/admin/usuarios/{id}/desbloquear', [Cad::class, 'desbloquear'], true);
        $r->add('POST', '/admin/usuarios/{id}/encerrar-sessoes', [Cad::class, 'encerrarSessoes'], true);
        $r->add('POST', '/admin/usuarios/{id}/desvincular-google', [Cad::class, 'desvincularGoogle'], true);
        $r->add('POST', '/admin/usuarios/{id}/excluir', [Cad::class, 'excluirUsuario'], true);
        $r->add('GET', '/admin/pessoas', [Cad::class, 'pessoas'], true);
        $r->add('GET', '/admin/pessoas/{id}', [Cad::class, 'pessoa'], true);
        $r->add('POST', '/admin/vinculos/{id}/encerrar', [Cad::class, 'encerrarVinculo'], true);
        $r->add('POST', '/admin/vinculos/{id}/tornar-gestor', [Cad::class, 'tornarGestor'], true);
        $r->add('GET', '/admin/empresas', [Cad::class, 'empresas'], true);
        $r->add('GET', '/admin/empresas/{id}', [Cad::class, 'empresa'], true);
        $r->add('GET', '/admin/convites', [Cad::class, 'convites'], true);
        $r->add('POST', '/admin/convites/{id}/revogar', [Cad::class, 'revogarConvite'], true);

        $r->add('GET', '/admin/logs', [LogsAdmin::class, 'listar'], true);
        $r->add('GET', '/admin/logs/csv', [LogsAdmin::class, 'csv'], true);
        $r->add('GET', '/admin/auditoria', [LogsAdmin::class, 'auditoria'], true);
    }

    /** Chamada externa das tarefas (ex.: cron-job.org). Desligada se tarefas.chave estiver vazia. */
    public static function tarefas(\Cuidados\Http\Requisicao $req): \Cuidados\Http\Resposta
    {
        $chave = (string)\Cuidados\Support\Config::get('tarefas.chave', '');
        $enviada = (string)($req->cabecalho('x-chave-tarefas') ?? '');
        if (strlen($chave) < 32 || !hash_equals($chave, $enviada)) {
            throw \Cuidados\Support\ErroHttp::naoEncontrado();
        }
        return \Cuidados\Http\Resposta::json(['ok' => true] + \Cuidados\Support\Tarefas::executar());
    }

    /** Diagnóstico da instalação, sem dados de pessoas. */
    public static function saude(): \Cuidados\Http\Resposta
    {
        $banco = 'sem_configuracao';
        if (\Cuidados\Support\Config::configurado()) {
            try {
                \Cuidados\Support\Bd::pdo()->query('SELECT 1');
                $banco = 'ok';
            } catch (\Throwable $e) {
                error_log('[cuidados] saude: ' . $e->getMessage());
                $banco = 'erro';
            }
        }
        return \Cuidados\Http\Resposta::json([
            'ok' => true, 'versao' => '1.0.0', 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'configuracao' => \Cuidados\Support\Config::configurado(), 'banco' => $banco,
            // O PHP consegue gravar fotos/áudios e o log? (false = ajustar permissão da pasta no FTP)
            'grava_midias' => self::gravavel((string)\Cuidados\Support\Config::get('midias.pasta')),
            'grava_logs' => self::gravavel(CUIDADOS_RAIZ . '/logs'),
        ]);
    }

    private static function gravavel(string $pasta): bool
    {
        if (!is_dir($pasta)) @mkdir($pasta, 0750, true);
        return is_dir($pasta) && is_writable($pasta);
    }
}
