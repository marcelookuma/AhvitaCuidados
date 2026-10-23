import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { ErroAdmin, NIVEL, type Admin, type Papel } from './api';

/* ---------------- contexto do(a) administrador(a) ---------------- */

export const CtxAdmin = createContext<{ admin: Admin; avisar: (t: string, tipo?: 'ok' | 'erro') => void }>(null as never);
export const useAdmin = () => useContext(CtxAdmin);
export function pode(admin: Admin, papel: Papel) {
  return NIVEL[admin.papel] >= NIVEL[papel];
}

/* ---------------- rotas por "#" (o painel é uma página estática) ---------------- */

export function useRota(): [string, URLSearchParams] {
  const ler = () => {
    const h = location.hash.slice(1) || '/';
    const i = h.indexOf('?');
    return [i >= 0 ? h.slice(0, i) : h, new URLSearchParams(i >= 0 ? h.slice(i + 1) : '')] as [string, URLSearchParams];
  };
  const [r, setR] = useState(ler);
  useEffect(() => {
    const f = () => setR(ler());
    window.addEventListener('hashchange', f);
    return () => window.removeEventListener('hashchange', f);
  }, []);
  return r;
}
export function ir(caminho: string) {
  location.hash = caminho;
}
/** Atualiza a busca da rota atual (filtros ficam no endereço: dá para copiar e mandar a um colega). */
export function mudarBusca(mud: Record<string, string | undefined>) {
  const h = location.hash.slice(1) || '/';
  const [c, q] = h.split('?');
  const p = new URLSearchParams(q ?? '');
  Object.entries(mud).forEach(([k, v]) => (v ? p.set(k, v) : p.delete(k)));
  if (!('pagina' in mud)) p.delete('pagina');
  const s = p.toString();
  history.replaceState(null, '', '#' + c + (s ? '?' + s : ''));
  window.dispatchEvent(new HashChangeEvent('hashchange'));
}

/* ---------------- dados ---------------- */

export function useCarregar<T>(fn: () => Promise<T>, deps: unknown[]): { dados: T | null; erro: ErroAdmin | null; carregando: boolean; recarregar: () => void } {
  const [dados, setDados] = useState<T | null>(null);
  const [erro, setErro] = useState<ErroAdmin | null>(null);
  const [carregando, setCarregando] = useState(true);
  const [n, setN] = useState(0);
  useEffect(() => {
    let vivo = true;
    setCarregando(true);
    fn().then((d) => { if (vivo) { setDados(d); setErro(null); } })
      .catch((e) => { if (vivo) setErro(e instanceof ErroAdmin ? e : new ErroAdmin(0, 'erro', String(e))); })
      .finally(() => { if (vivo) setCarregando(false); });
    return () => { vivo = false; };
  }, [...deps, n]); // eslint-disable-line react-hooks/exhaustive-deps
  return { dados, erro, carregando, recarregar: useCallback(() => setN((x) => x + 1), []) };
}

/* ---------------- formatação ---------------- */

const fmtDataHora = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' });
const fmtData = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'America/Sao_Paulo' });
export const dataHora = (iso?: string | null) => (iso ? fmtDataHora.format(new Date(iso)) : '—');
export const data = (iso?: string | null) => (iso ? fmtData.format(new Date(iso)) : '—');
export const diaCurto = (dia: string) => dia.slice(8, 10) + '/' + dia.slice(5, 7);
export const num = (n?: number | null) => (n ?? 0).toLocaleString('pt-BR');
export function bytes(b: number) {
  if (b < 1024) return `${b} B`;
  if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)} kB`;
  if (b < 1024 ** 3) return `${(b / 1024 ** 2).toFixed(1)} MB`;
  return `${(b / 1024 ** 3).toFixed(2)} GB`;
}
export function telefone(t?: string | null) {
  if (!t) return '—';
  const d = t.replace(/\D/g, '').replace(/^55/, '');
  return d.length === 11 ? `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}` : d.length === 10 ? `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}` : t;
}

export const NOME_PERFIL: Record<string, string> = {
  gestor: 'Gestor(a)', cuidador: 'Cuidador(a)', rede: 'Rede de Apoio', profissional: 'Profissional de saúde',
  empresa: 'Empresa', admin: 'Painel',
};
export const NOME_ARRANJO: Record<string, string> = {
  somente_eu: 'Somente eu', so_cuidador: 'Só cuidador(a)', gestor_cuidador: 'Gestor(a) + cuidador(a)',
  gestor_rede: 'Rodízio com a Rede de Apoio', gestor_rede_cuidador: 'Gestor(a) + Rede + cuidador(a)',
};
export const NOME_FAIXA: Record<string, string> = { crianca: 'Criança', adolescente: 'Adolescente', adulto: 'Adulto', idoso: 'Idoso(a)', sem_data: 'Sem data' };
export const NOME_MODALIDADE: Record<string, string> = { mei: 'MEI', autonomo: 'Autônomo(a)', clt: 'CLT', empresa: 'Por empresa', rede: 'Rede de Apoio' };
export const NOME_PAPEL_ADMIN: Record<Papel, string> = { leitura: 'Leitura', operador: 'Operador(a)', dono: 'Dono(a)' };
export const NOME_PERMISSAO: Record<string, string> = {
  registrar: 'Registrar', ver_registros: 'Ver registros', mensagens: 'Conversa', remedios_ver: 'Ver remédios', remedios_editar: 'Editar remédios',
  plano_ver: 'Ver plano', plano_editar: 'Editar plano', ficha_emergencia: 'Ficha de emergência', fotos: 'Fotos', atendimentos: 'Atendimentos', administrar: 'Administrar',
};
export const NOME_EVENTO: Record<string, string> = {
  requisicao: 'Uso', entrou: 'Entrou', conta_criada: 'Criou a conta', saiu: 'Saiu', entrada_recusada: 'Entrada recusada',
  'admin.entrou': 'Entrou no painel', 'admin.saiu': 'Saiu do painel', 'admin.entrada_recusada': 'Painel recusado',
};

/* ---------------- peças ---------------- */

export function Titulo({ children, acoes, sub }: { children: ReactNode; acoes?: ReactNode; sub?: ReactNode }) {
  return (
    <header className="titulo">
      <div>
        <h1>{children}</h1>
        {sub && <p className="sub">{sub}</p>}
      </div>
      {acoes && <div className="titulo-acoes">{acoes}</div>}
    </header>
  );
}

export function Erro({ erro, aoTentar }: { erro: ErroAdmin | null; aoTentar?: () => void }) {
  if (!erro) return null;
  return (
    <div className="aviso erro" role="alert">
      <span>{erro.message}</span>
      {aoTentar && <button className="b b-texto" onClick={aoTentar}>Tentar de novo</button>}
    </div>
  );
}

export function Vazio({ children }: { children: ReactNode }) {
  return <p className="vazio">{children}</p>;
}

export function Carregando() {
  return <p className="carregando" aria-live="polite">Carregando…</p>;
}

export function Etiqueta({ children, tom = 'neutro' }: { children: ReactNode; tom?: 'neutro' | 'ok' | 'atencao' | 'perigo' | 'marca' }) {
  return <span className={`etq etq-${tom}`}>{children}</span>;
}

export function StatusConta({ s }: { s: string }) {
  return s === 'bloqueado' ? <Etiqueta tom="perigo">Bloqueada</Etiqueta> : s === 'excluido' ? <Etiqueta>Excluída</Etiqueta> : <Etiqueta tom="ok">Ativa</Etiqueta>;
}

export function StatusHttp({ s }: { s: number | null }) {
  if (s === null) return <span>—</span>;
  const tom = s >= 500 ? 'perigo' : s >= 400 ? 'atencao' : 'ok';
  return <Etiqueta tom={tom}>{s}</Etiqueta>;
}

export interface Coluna<T> { titulo: string; celula: (l: T) => ReactNode; classe?: string }

export function Tabela<T extends object>({ colunas, linhas, aoAbrir, chave, vazio = 'Nada encontrado com estes filtros.' }:
  { colunas: Coluna<T>[]; linhas: T[]; aoAbrir?: (l: T) => void; chave?: (l: T, i: number) => string | number; vazio?: string }) {
  if (linhas.length === 0) return <Vazio>{vazio}</Vazio>;
  return (
    <div className="tabela-rolagem">
      <table className="tabela">
        <thead>
          <tr>{colunas.map((c) => <th key={c.titulo} className={c.classe}>{c.titulo}</th>)}</tr>
        </thead>
        <tbody>
          {linhas.map((l, i) => (
            <tr key={chave ? chave(l, i) : String((l as { id?: string | number }).id ?? i)} className={aoAbrir ? 'clicavel' : undefined}
              onClick={aoAbrir ? () => aoAbrir(l) : undefined}
              onKeyDown={aoAbrir ? (e) => { if (e.key === 'Enter') aoAbrir(l); } : undefined}
              tabIndex={aoAbrir ? 0 : undefined}>
              {colunas.map((c) => <td key={c.titulo} className={c.classe}>{c.celula(l)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function Paginacao({ total, pagina, porPagina }: { total: number; pagina: number; porPagina: number }) {
  const paginas = Math.max(1, Math.ceil(total / porPagina));
  if (total === 0) return null;
  return (
    <nav className="paginacao" aria-label="Páginas">
      <span>{num(total)} {total === 1 ? 'resultado' : 'resultados'}</span>
      {paginas > 1 && (
        <span className="paginacao-botoes">
          <button className="b b-texto" disabled={pagina <= 1} onClick={() => mudarBusca({ pagina: String(pagina - 1) })}>Anterior</button>
          <span>Página {pagina} de {paginas}</span>
          <button className="b b-texto" disabled={pagina >= paginas} onClick={() => mudarBusca({ pagina: String(pagina + 1) })}>Próxima</button>
        </span>
      )}
    </nav>
  );
}

/** Campo de busca que só atualiza o endereço depois que a pessoa para de digitar. */
export function Busca({ valor, rotulo }: { valor: string; rotulo: string }) {
  const [v, setV] = useState(valor);
  useEffect(() => setV(valor), [valor]);
  useEffect(() => {
    if (v === valor) return;
    const t = setTimeout(() => mudarBusca({ q: v || undefined }), 350);
    return () => clearTimeout(t);
  }, [v]); // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <label className="campo campo-busca">
      <span>{rotulo}</span>
      <input type="search" value={v} onChange={(e) => setV(e.target.value)} />
    </label>
  );
}

export function Seletor({ rotulo, valor, opcoes, chave }: { rotulo: string; valor: string; opcoes: [string, string][]; chave: string }) {
  return (
    <label className="campo">
      <span>{rotulo}</span>
      <select value={valor} onChange={(e) => mudarBusca({ [chave]: e.target.value || undefined })}>
        {opcoes.map(([v, t]) => <option key={v} value={v}>{t}</option>)}
      </select>
    </label>
  );
}

/** Painel lateral de detalhe: a lista continua atrás, Esc fecha. */
export function Gaveta({ titulo, aoFechar, children }: { titulo: ReactNode; aoFechar: () => void; children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const f = (e: KeyboardEvent) => { if (e.key === 'Escape' && !document.querySelector('dialog[open]')) aoFechar(); };
    window.addEventListener('keydown', f);
    ref.current?.focus();
    return () => window.removeEventListener('keydown', f);
  }, [aoFechar]);
  return (
    <div className="gaveta-fundo" onMouseDown={(e) => { if (e.target === e.currentTarget) aoFechar(); }}>
      <aside className="gaveta" role="dialog" aria-modal="true" aria-label={typeof titulo === 'string' ? titulo : 'Detalhe'} tabIndex={-1} ref={ref}>
        <header className="gaveta-topo">
          <h2>{titulo}</h2>
          <button className="b b-texto" onClick={aoFechar} aria-label="Fechar detalhe">Fechar</button>
        </header>
        <div className="gaveta-corpo">{children}</div>
      </aside>
    </div>
  );
}

export function Secao({ titulo, children, acao }: { titulo: string; children: ReactNode; acao?: ReactNode }) {
  return (
    <section className="secao">
      <div className="secao-topo"><h3>{titulo}</h3>{acao}</div>
      {children}
    </section>
  );
}

export function Pares({ itens }: { itens: [string, ReactNode][] }) {
  return (
    <dl className="pares">
      {itens.map(([k, v]) => (
        <div key={k}><dt>{k}</dt><dd>{v ?? '—'}</dd></div>
      ))}
    </dl>
  );
}

/**
 * Ação sensível: pede a justificativa, mostra que ela fica na auditoria em nome
 * de quem faz, e só então executa. É a única forma de mudar dados pelo painel.
 */
export function AcaoComMotivo({ rotulo, titulo, explicacao, perigo, confirmarTexto, executar, aoConcluir, desabilitado }: {
  rotulo: string; titulo: string; explicacao: ReactNode; perigo?: boolean; confirmarTexto?: string;
  executar: (motivo: string, extra: { confirmar?: string }) => Promise<unknown>; aoConcluir: () => void; desabilitado?: boolean;
}) {
  const { admin, avisar } = useAdmin();
  const dlg = useRef<HTMLDialogElement>(null);
  const [motivo, setMotivo] = useState('');
  const [confirma, setConfirma] = useState('');
  const [erro, setErro] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  const pronto = motivo.trim().length >= 10 && (!confirmarTexto || confirma === confirmarTexto);

  async function enviar() {
    setEnviando(true);
    setErro(null);
    try {
      await executar(motivo.trim(), { confirmar: confirmarTexto ? confirma : undefined });
      dlg.current?.close();
      avisar(`${rotulo}: feito.`);
      aoConcluir();
    } catch (e) {
      setErro(e instanceof Error ? e.message : String(e));
    } finally {
      setEnviando(false);
    }
  }

  return (
    <>
      <button className={`b ${perigo ? 'b-perigo' : 'b-secundario'}`} disabled={desabilitado}
        onClick={() => { setMotivo(''); setConfirma(''); setErro(null); dlg.current?.showModal(); }}>{rotulo}</button>
      <dialog ref={dlg} className="dialogo" aria-labelledby={`t-${rotulo}`}>
        <form method="dialog" onSubmit={(e) => { e.preventDefault(); if (pronto) void enviar(); }}>
          <h2 id={`t-${rotulo}`}>{titulo}</h2>
          <div className="dialogo-explica">{explicacao}</div>
          <label className="campo">
            <span>Motivo</span>
            <textarea value={motivo} onChange={(e) => setMotivo(e.target.value)} rows={3} maxLength={300} required
              placeholder="Ex.: pedido do titular pelo suporte em 23/10, protocolo 1234" />
          </label>
          {confirmarTexto && (
            <label className="campo">
              <span>Digite {confirmarTexto} para confirmar</span>
              <input value={confirma} onChange={(e) => setConfirma(e.target.value)} autoComplete="off" />
            </label>
          )}
          <p className="registro-assinatura">
            Fica na trilha de auditoria em nome de <b>{admin.email}</b>, com data, hora, IP e este motivo.
          </p>
          {erro && <p className="aviso erro" role="alert">{erro}</p>}
          <div className="dialogo-botoes">
            <button type="button" className="b b-texto" onClick={() => dlg.current?.close()}>Cancelar</button>
            <button type="submit" className={`b ${perigo ? 'b-perigo' : 'b-primario'}`} disabled={!pronto || enviando}>
              {enviando ? 'Enviando…' : rotulo}
            </button>
          </div>
        </form>
      </dialog>
    </>
  );
}

/** Barras simples de uma série (sem biblioteca). */
export function Barras({ serie, campo, rotulo }: { serie: { dia: string; [k: string]: number | string }[]; campo: string; rotulo: string }) {
  const max = Math.max(1, ...serie.map((s) => Number(s[campo]) || 0));
  return (
    <figure className="barras" aria-label={rotulo}>
      <div className="barras-area" role="img" aria-label={`${rotulo} por dia, últimos ${serie.length} dias`}>
        {serie.map((s) => {
          const v = Number(s[campo]) || 0;
          return (
            <div key={s.dia} className="barra" title={`${diaCurto(s.dia)}: ${num(v)}`}>
              <span style={{ height: `${(v / max) * 100}%` }} className={v === 0 ? 'zero' : undefined} />
            </div>
          );
        })}
      </div>
      <figcaption>
        <span>{diaCurto(serie[0]?.dia ?? '')}</span>
        <span>máximo {num(max)} por dia</span>
        <span>{diaCurto(serie[serie.length - 1]?.dia ?? '')}</span>
      </figcaption>
    </figure>
  );
}
