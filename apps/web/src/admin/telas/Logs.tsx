import { useState } from 'react';
import { get, qs, urlCsv, type Pagina } from '../api';
import {
  Carregando, Erro, Gaveta, NOME_EVENTO, NOME_PERFIL, Paginacao, Pares, Secao, Seletor, StatusHttp, Tabela, Titulo,
  dataHora, mudarBusca, num, pode, useAdmin, useCarregar, useRota,
} from '../ui';

export interface Linha {
  id: number; criado_em: string; ator: string; usuario_id: string | null; usuario: string | null; admin_id: string | null; admin: string | null;
  sessao_id: string | null; perfil: string | null; evento: string; metodo: string | null; rota: string | null; recurso_id: string | null;
  pessoa_id: string | null; pessoa: string | null; empresa_id: string | null; empresa: string | null; status: number | null;
  duracao_ms: number | null; ip: string | null; cliente: string | null; dispositivo: string | null;
}

const FILTROS = ['ator', 'usuario', 'admin', 'pessoa', 'empresa', 'perfil', 'evento', 'rota', 'status', 'ip', 'metodo', 'sessao', 'de', 'ate'] as const;
const ROTULO_FILTRO: Record<string, string> = {
  usuario: 'Usuário', admin: 'Administrador(a)', pessoa: 'Pessoa cuidada', empresa: 'Empresa', ip: 'IP', sessao: 'Sessão', rota: 'Rota',
};

/** O que cada rota faz, em palavras (as mais comuns). */
const ACAO: Record<string, string> = {
  'POST /auth/codigo': 'Pediu código de acesso', 'POST /auth/entrar': 'Entrou com código', 'POST /auth/google': 'Entrou com Google', 'POST /auth/sair': 'Saiu',
  'PATCH /eu': 'Alterou o próprio cadastro', 'POST /eu/termos': 'Aceitou os termos', 'GET /eu/exportar': 'Exportou os próprios dados', 'POST /eu/excluir': 'Excluiu a própria conta',
  'POST /pessoas': 'Cadastrou pessoa cuidada', 'PATCH /pessoas/{id}': 'Alterou cadastro da pessoa', 'GET /pessoas/{id}/exportar': 'Exportou dados da pessoa',
  'POST /pessoas/{id}/excluir': 'Excluiu dados da pessoa', 'POST /pessoas/{id}/registros': 'Registrou cuidado', 'POST /pessoas/{id}/mensagens': 'Mandou mensagem',
  'POST /pessoas/{id}/presencas': 'Iniciou serviço', 'POST /presencas/{id}/finalizar': 'Finalizou serviço', 'POST /pessoas/{id}/convites': 'Convidou alguém',
  'POST /convites/{codigo}/aceitar': 'Aceitou convite', 'PATCH /vinculos/{id}': 'Mudou permissões', 'POST /vinculos/{id}/encerrar': 'Encerrou vínculo',
  'POST /pessoas/{id}/atendimentos': 'Propôs atendimento', 'POST /atendimentos/{id}/responder': 'Respondeu proposta', 'POST /atendimentos/{id}/confirmar': 'Confirmou ida',
  'GET /pessoas/{id}/feed': 'Abriu a conversa', 'GET /pessoas/{id}/painel': 'Abriu o acompanhamento', 'GET /pessoas/{id}/registros': 'Viu a linha do tempo',
  'GET /pessoas/{id}': 'Viu o cadastro da pessoa', 'GET /pessoas/{id}/remedios': 'Viu os remédios', 'GET /midias/{id}': 'Viu foto ou ouviu áudio',
  'GET /eu': 'Abriu o app', 'GET /eu/inicio': 'Abriu a lista de conversas', 'GET /agenda': 'Abriu a própria agenda', 'GET /pessoas/{id}/vinculos': 'Viu quem participa',
  'PUT /agenda/disponibilidade': 'Mudou a própria agenda',
  'POST /empresas/{id}/pedidos-rede': 'Empresa chamou cuidador(a) para a rede', 'POST /eu/pedidos-rede': 'Pediu para entrar na rede de empresa',
  'POST /pedidos-rede/{id}/aceitar': 'Aceitou pedido de rede', 'POST /pedidos-rede/{id}/recusar': 'Recusou pedido de rede',
  'POST /pedidos-rede/{id}/cancelar': 'Desfez pedido de rede', 'POST /eu/empresas/{id}/sair': 'Saiu da rede de empresa', 'POST /midias': 'Enviou foto ou áudio', 'POST /registros/{id}/anular': 'Corrigiu registro',
};
export function descrever(l: Pick<Linha, 'metodo' | 'rota' | 'evento'>) {
  if (l.evento !== 'requisicao') return NOME_EVENTO[l.evento] ?? l.evento;
  const chave = `${l.metodo} ${l.rota}`;
  if (ACAO[chave]) return ACAO[chave];
  if (l.rota?.startsWith('/admin')) return l.metodo === 'GET' ? 'Consultou o painel' : 'Ação no painel';
  return l.metodo === 'GET' ? 'Consultou' : 'Alterou';
}

export function Logs() {
  const { admin } = useAdmin();
  const [, busca] = useRota();
  const f = Object.fromEntries(FILTROS.map((k) => [k, busca.get(k) ?? ''])) as Record<string, string>;
  const pagina = busca.get('pagina') ?? '1';
  const lista = useCarregar(() => get<Pagina<Linha>>('/admin/logs' + qs({ ...f, pagina })), [busca.toString()]);
  const [aberta, setAberta] = useState<Linha | null>(null);
  const filtrosId = (['usuario', 'admin', 'pessoa', 'empresa', 'ip', 'sessao', 'rota'] as const).filter((k) => f[k]);

  return (
    <>
      <Titulo
        sub="Entradas, saídas, toda alteração, todo erro e tudo o que a equipe faz no painel. Leituras de rotina entram somadas por dia em Uso por perfil."
        acoes={pode(admin, 'operador') && <a className="b b-secundario" href={urlCsv('/admin/logs/csv' + qs(f))}>Baixar CSV</a>}
      >Log de acessos</Titulo>
      <p className="aviso nota">
        Uso restrito à equipe Ahvita: segurança, suporte e pedidos de titulares (LGPD). Guardado por no mínimo 6 meses (Marco Civil, art. 15).
        Não repasse a gestores(as) ou empresas para acompanhar cuidadores(as): isso pode caracterizar controle típico de vínculo empregatício.
      </p>
      <div className="filtros">
        <Seletor rotulo="Quem" chave="ator" valor={f.ator} opcoes={[['', 'Todos'], ['usuario', 'Usuários do app'], ['admin', 'Equipe do painel'], ['anonimo', 'Sem sessão']]} />
        <Seletor rotulo="Perfil" chave="perfil" valor={f.perfil} opcoes={[['', 'Todos'], ...Object.entries(NOME_PERFIL)]} />
        <Seletor rotulo="Evento" chave="evento" valor={f.evento} opcoes={[['', 'Todos'], ...Object.entries(NOME_EVENTO)]} />
        <Seletor rotulo="Resultado" chave="status" valor={f.status} opcoes={[['', 'Todos'], ['2xx', 'Deu certo (2xx)'], ['erro', 'Com erro'], ['4xx', 'Recusado (4xx)'], ['5xx', 'Falha do servidor (5xx)'], ['401', 'Sem sessão (401)'], ['403', 'Sem permissão (403)']]} />
        <label className="campo"><span>De</span><input type="date" value={f.de} onChange={(e) => mudarBusca({ de: e.target.value || undefined })} /></label>
        <label className="campo"><span>Até</span><input type="date" value={f.ate} onChange={(e) => mudarBusca({ ate: e.target.value || undefined })} /></label>
      </div>
      {filtrosId.length > 0 && (
        <p className="filtros-ativos">
          {filtrosId.map((k) => (
            <button key={k} className="filtro-chip" onClick={() => mudarBusca({ [k]: undefined })} aria-label={`Tirar filtro ${ROTULO_FILTRO[k]}`}>
              {ROTULO_FILTRO[k]}: {f[k].length > 14 ? f[k].slice(0, 6) + '…' + f[k].slice(-4) : f[k]} <span aria-hidden="true">×</span>
            </button>
          ))}
        </p>
      )}
      <Erro erro={lista.erro} aoTentar={lista.recarregar} />
      {lista.carregando && !lista.dados && <Carregando />}
      {lista.dados && (
        <>
          <Tabela<Linha> linhas={lista.dados.itens} aoAbrir={setAberta} colunas={[
            { titulo: 'Quando', celula: (l) => dataHora(l.criado_em), classe: 'nowrap' },
            { titulo: 'Quem', celula: (l) => l.ator === 'admin' ? <><b>{l.admin}</b><div className="fraco">equipe do painel</div></>
              : l.usuario_id ? <><b>{l.usuario ?? 'Sem nome'}</b><div className="fraco">{l.perfil ? NOME_PERFIL[l.perfil] ?? l.perfil : 'sem perfil'}</div></>
              : <span className="fraco">Sem sessão</span> },
            { titulo: 'O que fez', celula: (l) => <><div>{descrever(l)}</div><div className="fraco rota">{l.metodo} {l.rota}</div></> },
            { titulo: 'Sobre', celula: (l) => l.pessoa ?? l.empresa ?? <span className="fraco">—</span> },
            { titulo: 'Resultado', celula: (l) => <StatusHttp s={l.status} /> },
            { titulo: 'IP', celula: (l) => <span className="rota">{l.ip ?? '—'}</span> },
          ]} />
          <Paginacao total={lista.dados.total} pagina={lista.dados.pagina} porPagina={lista.dados.por_pagina} />
        </>
      )}
      <UsoPorPerfil />
      {aberta && (
        <Gaveta titulo={descrever(aberta)} aoFechar={() => setAberta(null)}>
          <Pares itens={[
            ['Quando', dataHora(aberta.criado_em)],
            ['Quem', aberta.ator === 'admin' ? aberta.admin : aberta.usuario_id ? <a href={`#/usuarios/${aberta.usuario_id}`} onClick={() => setAberta(null)}>{aberta.usuario ?? aberta.usuario_id}</a> : 'Sem sessão'],
            ['Perfil', aberta.perfil ? NOME_PERFIL[aberta.perfil] ?? aberta.perfil : '—'],
            ['Evento', NOME_EVENTO[aberta.evento] ?? aberta.evento],
            ['Rota', <code>{aberta.metodo} {aberta.rota}</code>],
            ['Recurso', aberta.recurso_id ? <code>{aberta.recurso_id}</code> : '—'],
            ['Pessoa cuidada', aberta.pessoa_id ? <a href={`#/pessoas/${aberta.pessoa_id}`} onClick={() => setAberta(null)}>{aberta.pessoa ?? aberta.pessoa_id}</a> : '—'],
            ['Empresa', aberta.empresa_id ? <a href={`#/empresas/${aberta.empresa_id}`} onClick={() => setAberta(null)}>{aberta.empresa ?? aberta.empresa_id}</a> : '—'],
            ['Resultado', <StatusHttp s={aberta.status} />],
            ['Tempo de resposta', aberta.duracao_ms !== null ? `${num(aberta.duracao_ms)} ms` : '—'],
            ['IP', aberta.ip],
            ['Origem', aberta.cliente === 'app' ? 'App (token)' : 'Navegador'],
            ['Aparelho', aberta.dispositivo],
            ['Sessão', aberta.sessao_id ? <code>{aberta.sessao_id}</code> : '—'],
          ]} />
          <div className="acoes">
            {aberta.usuario_id && <button className="b b-secundario" onClick={() => { mudarBusca({ usuario: aberta.usuario_id! }); setAberta(null); }}>Tudo deste usuário</button>}
            {aberta.ip && <button className="b b-secundario" onClick={() => { mudarBusca({ ip: aberta.ip! }); setAberta(null); }}>Tudo deste IP</button>}
            {aberta.sessao_id && <button className="b b-secundario" onClick={() => { mudarBusca({ sessao: aberta.sessao_id! }); setAberta(null); }}>Tudo desta sessão</button>}
          </div>
        </Gaveta>
      )}
    </>
  );
}

interface Uso { desde: string; por_dia: { dia: string; perfil: string | null; usuarios: number }[]; rotas: { rota: string; metodo: string; perfil: string | null; requisicoes: number }[] }

function UsoPorPerfil() {
  const [dias, setDias] = useState(30);
  const { dados: d, erro } = useCarregar(() => get<Uso>('/admin/uso' + qs({ dias })), [dias]);
  const perfis = d ? [...new Set(d.por_dia.map((x) => x.perfil ?? ''))].filter(Boolean) : [];
  const diasLista = d ? [...new Set(d.por_dia.map((x) => x.dia))].sort().reverse() : [];
  return (
    <Secao titulo="Uso por perfil" acao={
      <label className="campo campo-inline"><span>Período</span>
        <select value={dias} onChange={(e) => setDias(Number(e.target.value))}>
          <option value={7}>7 dias</option><option value={30}>30 dias</option><option value={90}>90 dias</option>
        </select>
      </label>}>
      <p className="fraco">Pessoas diferentes por dia e perfil. Sem horários: não serve para medir tempo de ninguém.</p>
      <Erro erro={erro} />
      {d && (
        <div className="grade-2">
          <Tabela linhas={diasLista.slice(0, 31).map((dia) => ({ id: dia, dia }))} vazio="Sem uso no período." colunas={[
            { titulo: 'Dia', celula: (x) => x.dia.split('-').reverse().join('/'), classe: 'nowrap' },
            ...perfis.map((p) => ({ titulo: NOME_PERFIL[p] ?? p, classe: 'num', celula: (x: { dia: string }) => num(d.por_dia.find((y) => y.dia === x.dia && y.perfil === p)?.usuarios ?? 0) })),
          ]} />
          <Tabela linhas={d.rotas.slice(0, 20).map((r, i) => ({ ...r, id: i }))} vazio="Sem uso no período." colunas={[
            { titulo: 'Mais usado', celula: (r) => <><div>{descrever({ metodo: r.metodo, rota: r.rota, evento: 'requisicao' })}</div><div className="fraco rota">{r.metodo} {r.rota}</div></> },
            { titulo: 'Perfil', celula: (r) => (r.perfil ? NOME_PERFIL[r.perfil] ?? r.perfil : '—') },
            { titulo: 'Vezes', celula: (r) => num(r.requisicoes), classe: 'num' },
          ]} />
        </div>
      )}
    </Secao>
  );
}
