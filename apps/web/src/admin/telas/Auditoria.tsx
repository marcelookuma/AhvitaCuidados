import { useState } from 'react';
import { get, post, qs, type Pagina } from '../api';
import { Carregando, Erro, Paginacao, Tabela, Titulo, dataHora, mudarBusca, num, useCarregar, useRota } from '../ui';

interface Linha {
  id: number; criado_em: string; acao: string; usuario_id: string | null; usuario: string | null; entidade: string | null; entidade_id: string | null;
  pessoa_id: string | null; pessoa: string | null; dados: Record<string, unknown> | null; ip: string | null; hash: string;
}

export function Auditoria() {
  const [, busca] = useRota();
  const f = { acao: busca.get('acao') ?? '', usuario: busca.get('usuario') ?? '', pessoa: busca.get('pessoa') ?? '', de: busca.get('de') ?? '', ate: busca.get('ate') ?? '', pagina: busca.get('pagina') ?? '1' };
  const lista = useCarregar(() => get<Pagina<Linha> & { acoes: string[] }>('/admin/auditoria' + qs(f)), [busca.toString()]);
  const [verif, setVerif] = useState<{ ok: boolean; linhas: number; quebra_em: number | null } | null>(null);
  const [verificando, setVerificando] = useState(false);

  async function verificar() {
    setVerificando(true);
    try { setVerif(await post('/admin/auditoria/verificar')); } finally { setVerificando(false); }
  }

  return (
    <>
      <Titulo sub="Toda mudança importante, do app e do painel, encadeada por hash: apagar ou alterar uma linha antiga quebra a cadeia."
        acoes={<button className="b b-secundario" onClick={() => void verificar()} disabled={verificando}>{verificando ? 'Conferindo…' : 'Conferir integridade'}</button>}>
        Auditoria
      </Titulo>
      {verif && (verif.ok
        ? <p className="aviso ok">Cadeia íntegra: {num(verif.linhas)} linhas conferidas.</p>
        : <p className="aviso erro">Cadeia quebrada na linha {verif.quebra_em}. Alguém alterou ou apagou registros direto no banco. Guarde uma cópia do banco e investigue.</p>)}
      <div className="filtros">
        <label className="campo"><span>Ação</span>
          <select value={f.acao} onChange={(e) => mudarBusca({ acao: e.target.value || undefined })}>
            <option value="">Todas</option>
            <option value="admin.">Só ações do painel</option>
            {(lista.dados?.acoes ?? []).map((a) => <option key={a} value={a}>{a}</option>)}
          </select>
        </label>
        <label className="campo"><span>De</span><input type="date" value={f.de} onChange={(e) => mudarBusca({ de: e.target.value || undefined })} /></label>
        <label className="campo"><span>Até</span><input type="date" value={f.ate} onChange={(e) => mudarBusca({ ate: e.target.value || undefined })} /></label>
        {(f.usuario || f.pessoa) && <button className="filtro-chip" onClick={() => mudarBusca({ usuario: undefined, pessoa: undefined })}>Tirar filtro de pessoa ou usuário <span aria-hidden="true">×</span></button>}
      </div>
      <Erro erro={lista.erro} aoTentar={lista.recarregar} />
      {lista.carregando && !lista.dados && <Carregando />}
      {lista.dados && (
        <>
          <Tabela<Linha> linhas={lista.dados.itens} colunas={[
            { titulo: 'Quando', celula: (l) => dataHora(l.criado_em), classe: 'nowrap' },
            { titulo: 'Ação', celula: (l) => <code>{l.acao}</code> },
            { titulo: 'Quem', celula: (l) => l.dados?.admin_email ? <b>{String(l.dados.admin_email)}</b> : l.usuario_id ? <a href={`#/usuarios/${l.usuario_id}`}>{l.usuario ?? l.usuario_id}</a> : <span className="fraco">Sistema</span> },
            { titulo: 'Sobre', celula: (l) => l.pessoa_id ? <a href={`#/pessoas/${l.pessoa_id}`}>{l.pessoa ?? l.pessoa_id}</a>
              : l.entidade === 'usuario' && l.entidade_id ? <a href={`#/usuarios/${l.entidade_id}`}>conta</a> : (l.entidade ?? '—') },
            { titulo: 'Detalhes', celula: (l) => <Dados d={l.dados} /> },
            { titulo: 'Hash', celula: (l) => <code className="fraco">{l.hash}</code> },
          ]} />
          <Paginacao total={lista.dados.total} pagina={lista.dados.pagina} porPagina={lista.dados.por_pagina} />
        </>
      )}
    </>
  );
}

function Dados({ d }: { d: Record<string, unknown> | null }) {
  if (!d) return <span className="fraco">—</span>;
  const { admin: _a, admin_email: _e, motivo, ...resto } = d;
  const outros = Object.entries(resto).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join(', ');
  return (
    <>
      {motivo !== undefined && <div className="motivo">“{String(motivo)}”</div>}
      {outros && <div className="fraco rota">{outros}</div>}
    </>
  );
}
