<?php
declare(strict_types=1);

namespace Cuidados;

use Cuidados\Http\Roteador;
use Cuidados\Modulos\AgendaControlador as Agenda;
use Cuidados\Modulos\AuthControlador as Auth;
use Cuidados\Modulos\ConversasControlador as Conv;
use Cuidados\Modulos\ConvitesControlador as Convites;
use Cuidados\Modulos\CuidadoControlador as Cuidado;
use Cuidados\Modulos\EmpresasControlador as Empresas;
use Cuidados\Modulos\EuControlador as Eu;
use Cuidados\Modulos\MidiasControlador as Midias;
use Cuidados\Modulos\PessoasControlador as Pessoas;

/** Contrato da API v1 (documentado em docs/API.md). */
final class Rotas
{
    public static function registrar(Roteador $r): void
    {
        $r->add('GET', '/saude', fn() => \Cuidados\Http\Resposta::json(['ok' => true, 'versao' => '1.0.0']), true);
        $r->add('POST', '/sistema/tarefas', fn($req) => self::tarefas($req), true);

        // Acesso
        $r->add('GET', '/auth/canais', [Auth::class, 'canais'], true);
        $r->add('POST', '/auth/codigo', [Auth::class, 'codigo'], true);
        $r->add('POST', '/auth/entrar', [Auth::class, 'entrar'], true);
        $r->add('POST', '/auth/sair', [Auth::class, 'sair']);

        // Eu
        $r->add('GET', '/eu', [Eu::class, 'ver']);
        $r->add('PATCH', '/eu', [Eu::class, 'atualizar']);
        $r->add('POST', '/eu/termos', [Eu::class, 'aceitarTermos']);
        $r->add('GET', '/eu/inicio', [Eu::class, 'inicio']);
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

        // Fotos e áudios
        $r->add('POST', '/midias', [Midias::class, 'enviar']);
        $r->add('GET', '/midias/{id}', [Midias::class, 'ver']);
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
}
