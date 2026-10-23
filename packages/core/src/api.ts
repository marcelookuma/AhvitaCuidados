/**
 * Cliente da API v1. Funciona no navegador (cookie httpOnly) e no app
 * Android/iOS (token Bearer guardado no cofre do aparelho).
 */
import type {
  Agenda, Alerta, Atendimento, ConversaResumo, ConviteCriado, Empresa, EmpresaEncontrada, Eu, Feed, Janela,
  Mensagem, MinhaRede, Painel, PedidoRede, Pessoa, Registro, Remedio, Usuario, Vinculo,
} from './tipos';

export class ErroApi extends Error {
  constructor(
    public status: number,
    public codigo: string,
    mensagem: string,
    public campos: Record<string, string> = {},
  ) {
    super(mensagem);
  }
  /** Sem internet ou servidor fora: o registro deve ficar na fila do aparelho. */
  get semConexao(): boolean {
    return this.status === 0 || this.status === 503 || this.status === 502 || this.status === 504;
  }
}

export interface OpcoesApi {
  base: string;                         // ex.: https://cuidados.ahvita.com/api/index.php?r=  (ou .../api/v1 com reescrita)
  token?: () => string | null;          // app móvel: Bearer
  aoPerderSessao?: () => void;
}

export function criarApi(op: OpcoesApi) {
  /** Com base "…/index.php?r=", o "?" do caminho vira "&" (servidor sem reescrita de URL, ex.: nginx). */
  const url = (caminho: string) => (op.base.includes('?') ? op.base + caminho.replace('?', '&') : op.base + caminho);

  async function chamar<T>(metodo: string, caminho: string, corpo?: unknown, form?: FormData): Promise<T> {
    const cab: Record<string, string> = { 'X-Cuidados': '1', Accept: 'application/json' };
    const tk = op.token?.();
    if (tk) cab.Authorization = `Bearer ${tk}`;
    if (corpo !== undefined) cab['Content-Type'] = 'application/json';
    let resp: Response;
    try {
      resp = await fetch(url(caminho), {
        method: metodo,
        headers: cab,
        credentials: 'include',
        body: form ?? (corpo !== undefined ? JSON.stringify(corpo) : undefined),
      });
    } catch {
      throw new ErroApi(0, 'sem_conexao', 'Sem internet. Pode continuar: tudo fica guardado.');
    }
    if (resp.status === 204) return undefined as T;
    let dados: any = null;
    try { dados = await resp.json(); } catch { /* corpo vazio */ }
    if (!resp.ok) {
      if (resp.status === 401) op.aoPerderSessao?.();
      const e = dados?.erro ?? {};
      const padrao = dados === null && resp.status >= 500
        ? `O servidor está com problema (erro ${resp.status}). Tente de novo em alguns minutos.`
        : 'Algo deu errado. Tente de novo.';
      throw new ErroApi(resp.status, e.codigo ?? 'erro', e.mensagem ?? padrao, e.campos ?? {});
    }
    return dados as T;
  }

  const get = <T>(c: string) => chamar<T>('GET', c);
  const post = <T>(c: string, b: unknown = {}) => chamar<T>('POST', c, b);
  const patch = <T>(c: string, b: unknown) => chamar<T>('PATCH', c, b);
  const put = <T>(c: string, b: unknown) => chamar<T>('PUT', c, b);
  const q = (o: Record<string, string | undefined>) => {
    const s = new URLSearchParams();
    Object.entries(o).forEach(([k, v]) => v !== undefined && v !== '' && s.set(k, v));
    const t = s.toString();
    return t ? `?${t}` : '';
  };

  return {
    // Acesso
    canais: () => get<{ canais: string[]; google?: string | null }>('/auth/canais'),
    pedirCodigo: (destino: string, canal?: string) => post<{ canal: string; expira_em: string; codigo_teste?: string }>('/auth/codigo', { destino, canal }),
    entrar: (destino: string, codigo: string) => post<{ token: string; usuario: Usuario; novo: boolean }>('/auth/entrar', { destino, codigo }),
    entrarGoogle: (credencial: string) => post<{ token: string; usuario: Usuario; novo: boolean }>('/auth/google', { credencial }),
    sair: () => post<void>('/auth/sair'),

    // Eu
    eu: () => get<Eu>('/eu'),
    atualizarEu: (d: Partial<Usuario> & { cpf?: string }) => patch<Usuario>('/eu', d),
    // Trocar celular ou e-mail: o código vai para o contato novo
    pedirCodigoContato: (destino: string) => post<{ canal?: string; campo: 'telefone' | 'email'; sem_codigo: boolean; expira_em?: string; codigo_teste?: string }>('/eu/contato/codigo', { destino }),
    confirmarContato: (destino: string, codigo?: string) => post<Usuario>('/eu/contato/confirmar', codigo ? { destino, codigo } : { destino }),
    removerContato: (campo: 'email' | 'telefone' | 'google') => post<Usuario>('/eu/contato/remover', { campo }),
    aceitarTermos: () => post<void>('/eu/termos'),
    inicio: () => get<{ conversas: ConversaResumo[]; propostas_abertas: number; pedidos_rede: number }>('/eu/inicio'),
    excluirConta: () => post<void>('/eu/excluir', { confirmar: 'EXCLUIR' }),
    urlExportarEu: () => op.base + '/eu/exportar',

    // Pessoas
    pessoas: () => get<{ pessoas: Pessoa[] }>('/pessoas'),
    criarPessoa: (d: Record<string, unknown>) => post<Pessoa>('/pessoas', d),
    pessoa: (id: string) => get<Pessoa>(`/pessoas/${id}`),
    atualizarPessoa: (id: string, d: Record<string, unknown>) => patch<Pessoa>(`/pessoas/${id}`, d),
    painel: (id: string) => get<Painel>(`/pessoas/${id}/painel`),
    resolverAlerta: (id: string) => post<void>(`/alertas/${id}/resolver`),
    vinculos: (id: string) => get<{ vinculos: Vinculo[]; convites_pendentes: any[]; empresa: { id: string; nome: string; permissoes: string[] } | null }>(`/pessoas/${id}/vinculos`),
    desvincularEmpresa: (id: string) => post<void>(`/pessoas/${id}/empresa/desvincular`),
    urlExportarPessoa: (id: string) => `${op.base}/pessoas/${id}/exportar`,
    excluirPessoa: (id: string, confirmar: string) => post<void>(`/pessoas/${id}/excluir`, { confirmar }),

    // Convites e vínculos
    convidar: (pessoaId: string, d: Record<string, unknown>) => post<ConviteCriado>(`/pessoas/${pessoaId}/convites`, d),
    convidarEmpresa: (empresaId: string, d: Record<string, unknown>) => post<ConviteCriado>(`/empresas/${empresaId}/convites`, d),
    convite: (codigo: string) => get<any>(`/convites/${codigo}`),
    aceitarConvite: (codigo: string, d: Record<string, unknown> = {}) => post<{ pessoa_id?: string; empresa_id?: string }>(`/convites/${codigo}/aceitar`, d),
    revogarConvite: (id: string) => post<void>(`/convites/${id}/revogar`),
    atualizarVinculo: (id: string, d: Record<string, unknown>) => patch<Vinculo>(`/vinculos/${id}`, d),
    encerrarVinculo: (id: string) => post<void>(`/vinculos/${id}/encerrar`),

    // Conversa, registros, remédios, presença
    feed: (id: string, o: { desde?: string; antes?: string } = {}) => get<Feed>(`/pessoas/${id}/feed${q(o)}`),
    mensagem: (id: string, d: { id: string; tipo: string; texto?: string; midia_id?: string; em: string }) => post<Mensagem>(`/pessoas/${id}/mensagens`, d),
    visto: (id: string) => post<void>(`/pessoas/${id}/visto`),
    registrar: (id: string, d: Record<string, unknown>) => post<Registro>(`/pessoas/${id}/registros`, d),
    anular: (registroId: string, motivo?: string) => post<void>(`/registros/${registroId}/anular`, { motivo }),
    linhaDoTempo: (id: string, o: { tipo?: string; antes?: string; alertas?: string; presencas?: string } = {}) =>
      get<{ registros: Registro[]; presencas: { id: string; cuidador: { nome: string }; inicio: string; fim: string | null }[]; tem_mais: boolean }>(`/pessoas/${id}/registros${q(o)}`),
    remedios: (id: string) => get<{ remedios: Remedio[] }>(`/pessoas/${id}/remedios`),
    criarRemedio: (id: string, d: Partial<Remedio>) => post<Remedio>(`/pessoas/${id}/remedios`, d),
    atualizarRemedio: (id: string, d: Partial<Remedio>) => patch<Remedio>(`/remedios/${id}`, d),
    iniciarServico: (pessoaId: string, d: { id: string; em: string }) => post<{ id: string; desde: string }>(`/pessoas/${pessoaId}/presencas`, d),
    finalizarServico: (presencaId: string, d: { em: string; recado?: string }) => post<{ resumo: any }>(`/presencas/${presencaId}/finalizar`, d),

    // Agenda e atendimentos
    agenda: () => get<Agenda>('/agenda'),
    salvarDisponibilidade: (janelas: Janela[]) => put<Agenda>('/agenda/disponibilidade', { janelas }),
    bloquear: (d: { inicio: string; fim: string; motivo?: string }) => post<{ id: string }>('/agenda/bloqueios', d),
    desbloquear: (id: string) => chamar<void>('DELETE', `/agenda/bloqueios/${id}`),
    atendimentos: (pessoaId: string) => get<{ atendimentos: Atendimento[] }>(`/pessoas/${pessoaId}/atendimentos`),
    proporAtendimento: (pessoaId: string, d: Record<string, unknown>) => post<Atendimento>(`/pessoas/${pessoaId}/atendimentos`, d),
    checarDisponivel: (pessoaId: string, cuidadorId: string, inicio: string, fim: string) =>
      get<{ disponivel: boolean; motivo?: string }>(`/pessoas/${pessoaId}/cuidadores/${cuidadorId}/disponivel${q({ inicio, fim })}`),
    responderAtendimento: (id: string, aceitar: boolean, motivo?: string) => post<Atendimento>(`/atendimentos/${id}/responder`, { aceitar, motivo }),
    confirmarIda: (id: string, vou: boolean, motivo?: string) => post<Atendimento>(`/atendimentos/${id}/confirmar`, { vou, motivo }),
    cancelarAtendimento: (id: string) => post<void>(`/atendimentos/${id}/cancelar`),

    // Empresa
    criarEmpresa: (d: Record<string, unknown>) => post<Empresa>('/empresas', d),
    painelEmpresa: (id: string) => get<{ empresa: Empresa; equipe: number; clientes: number; propostas_abertas: number; atendimentos_7_dias: number; alertas: Alerta[] }>(`/empresas/${id}/painel`),
    equipe: (id: string) => get<{ cuidadores: any[]; convites_pendentes: any[]; pedidos: { recebidos: PedidoRede[]; enviados: PedidoRede[] } }>(`/empresas/${id}/cuidadores`),

    // Rede da empresa: pedidos nos dois sentidos (o outro lado aceita)
    pedirRedeEmpresa: (empresaId: string, d: { telefone: string; modalidade: string; mensagem?: string }) =>
      post<PedidoRede & { cuidador: { nome: string } }>(`/empresas/${empresaId}/pedidos-rede`, d),
    minhaRede: () => get<MinhaRede>('/eu/rede'),
    buscarEmpresas: (texto: string) => get<{ empresas: EmpresaEncontrada[] }>(`/rede/empresas${q({ q: texto })}`),
    pedirRedeCuidador: (d: { empresa_id: string; modalidade: string; mensagem?: string }) => post<PedidoRede & { empresa: { nome: string } }>('/eu/pedidos-rede', d),
    sairDaRede: (empresaId: string) => post<void>(`/eu/empresas/${empresaId}/sair`),
    responderPedidoRede: (pedidoId: string, acao: 'aceitar' | 'recusar' | 'cancelar') =>
      post<{ id: string; status: string; conversa_id: string | null }>(`/pedidos-rede/${pedidoId}/${acao}`),
    encerrarCuidadorEmpresa: (id: string, cuidadorId: string) => post<void>(`/empresas/${id}/cuidadores/${cuidadorId}/encerrar`),
    clientes: (id: string) => get<{ clientes: (Pessoa & { gestor: { nome: string; telefone: string } | null; cuidadores: { nome: string }[] })[] }>(`/empresas/${id}/clientes`),
    atendimentosEmpresa: (id: string) => get<{ atendimentos: Atendimento[] }>(`/empresas/${id}/atendimentos`),
    criarProposta: (id: string, d: Record<string, unknown>) => post<{ atendimento: Atendimento; enviada_para: number }>(`/empresas/${id}/propostas`, d),
    conversasEmpresa: (id: string) => get<{ conversas: { conversa_id: string; com: { nome: string }; ultimo: Mensagem | null; nao_lidas: number }[] }>(`/empresas/${id}/conversas`),
    mensagensDireta: (conversaId: string, desde?: string) => get<{ itens: Mensagem[]; cursor: string; visto_por_outros_ate: string | null }>(`/conversas/${conversaId}/mensagens${q({ desde })}`),
    enviarDireta: (conversaId: string, d: { id: string; tipo: string; texto?: string; midia_id?: string; em: string }) => post<Mensagem>(`/conversas/${conversaId}/mensagens`, d),
    vistoDireta: (conversaId: string) => post<void>(`/conversas/${conversaId}/visto`),

    // Mídias
    enviarMidia: (arquivo: Blob, nome: string, alvo: { pessoa_id?: string; conversa_id?: string }) => {
      const f = new FormData();
      f.append('arquivo', arquivo, nome);
      if (alvo.pessoa_id) f.append('pessoa_id', alvo.pessoa_id);
      if (alvo.conversa_id) f.append('conversa_id', alvo.conversa_id);
      return chamar<{ id: string; tipo: 'foto' | 'audio' }>('POST', '/midias', undefined, f);
    },
    urlMidia: (id: string) => url(`/midias/${id}`),
  };
}

export type Api = ReturnType<typeof criarApi>;
