import { Fragment, useEffect, useRef, useState } from 'react';
import { dia, hora, ulid, type Mensagem } from '@cuidados/core';
import { api, fila } from '../../servicos';
import { useSessao } from '../../sessao';
import { useCarregar, useRepetir } from '../../hooks';
import { IcDois, IcEnviar, IcRelogio, IcUm, IcVoltar } from '../../componentes/icones';
import { Audio, Foto } from '../../componentes/midia';

/** Conversa direta empresa ↔ cuidador(a). Usada nos dois lados. */
export function Direta({ conversaId, aoVoltar, titulo = 'Empresa', embutida = false }: { conversaId: string; aoVoltar?: () => void; titulo?: string; embutida?: boolean }) {
  const { eu } = useSessao();
  const [itens, setItens] = useState<Map<string, Mensagem>>(new Map());
  const [pend, setPend] = useState<{ id: string; texto: string; em: string }[]>([]);
  const [texto, setTexto] = useState('');
  const cursor = useRef<string>();
  const [visto, setVisto] = useState<string | null>(null);
  const rol = useRef<HTMLDivElement>(null);

  const carregar = async (inicial = false) => {
    const r = await api.mensagensDireta(conversaId, inicial ? undefined : cursor.current);
    cursor.current = r.cursor;
    setVisto(r.visto_por_outros_ate);
    setItens((m) => { const n = inicial ? new Map() : new Map(m); r.itens.forEach((i) => n.set(i.id, i)); return n; });
    if (r.itens.length) void api.vistoDireta(conversaId).catch(() => {});
  };
  const inicial = useCarregar(() => carregar(true), [conversaId]);
  useRepetir(() => void carregar().catch(() => {}), 5000, !inicial.carregando);
  useEffect(() => fila.ouvir((ops) => setPend(ops.flatMap((o) => (o.op === 'mensagem' && o.conversaId === conversaId ? [{ id: o.id, texto: String(o.corpo.texto ?? ''), em: String(o.corpo.em) }] : [])))), [conversaId]);
  useEffect(() => { if (rol.current) rol.current.scrollTop = rol.current.scrollHeight; }, [itens.size, pend.length]);

  const enviar = async () => {
    const t = texto.trim();
    if (!t) return;
    setTexto('');
    const id = ulid();
    await fila.adicionar({ op: 'mensagem', id, conversaId, corpo: { id, tipo: 'texto', texto: t, em: new Date().toISOString() } });
    setTimeout(() => void carregar(), 600);
  };

  const lista = [...itens.values()].sort((a, b) => (a.em < b.em ? -1 : 1));
  let diaAnt = '';
  return (
    <div className="chat" style={embutida ? { height: '100%' } : undefined}>
      <header className="topo">
        {aoVoltar && <button className="ic" onClick={aoVoltar} aria-label="Voltar"><IcVoltar /></button>}
        <h1>{titulo}<small>Combinados e dúvidas</small></h1>
      </header>
      <div className="mensagens" ref={rol}>
        {lista.map((m) => {
          const minha = m.autor?.id === eu?.usuario.id;
          const d = dia(m.em);
          const sep = d !== diaAnt;
          diaAnt = d;
          return (
            <Fragment key={m.id}>
              {sep && <div className="dia-sep">{d}</div>}
              {m.tipo === 'sistema' ? <div className="sis">{m.texto}</div> : (
                <div className={`bolha ${minha ? 'minha' : 'outra'}`}>
                  {!minha && <div className="de">{m.autor?.nome}</div>}
                  {m.tipo === 'foto' && <Foto midiaId={m.midia_id} alt="Foto" />}
                  {m.tipo === 'audio' && <Audio midiaId={m.midia_id} />}
                  {m.texto && <div>{m.texto}</div>}
                  <div className="meta">{hora(m.em)}{minha && (visto && m.recebido_em <= visto ? <span className="tique visto"><IcDois /></span> : <span className="tique"><IcUm /></span>)}</div>
                </div>
              )}
            </Fragment>
          );
        })}
        {pend.filter((p) => !itens.has(p.id)).map((p) => (
          <div key={p.id} className="bolha minha"><div>{p.texto}</div><div className="meta">{hora(p.em)}<span className="tique relogio"><IcRelogio /></span></div></div>
        ))}
      </div>
      <div className="compositor">
        <div className="campo-msg"><input value={texto} onChange={(e) => setTexto(e.target.value)} placeholder="Escreva aqui" aria-label="Escrever mensagem" onKeyDown={(e) => e.key === 'Enter' && void enviar()} /></div>
        <button className="redondo mic" aria-label="Enviar" onClick={() => void enviar()}><IcEnviar /></button>
      </div>
    </div>
  );
}
