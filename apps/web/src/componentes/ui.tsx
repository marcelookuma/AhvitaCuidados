import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import type { ErroApi } from '@cuidados/core';
import { falar } from '../voz';
import { IcVoltar } from './icones';

// ---------------------------------------------------------------- Lanche (aviso rápido)
type Lanche = { texto: string; desfazer?: () => void } | null;
const CtxLanche = createContext<(texto: string, desfazer?: () => void) => void>(() => {});

export function ProvedorLanche({ children }: { children: ReactNode }) {
  const [l, setL] = useState<Lanche>(null);
  const t = useRef<number>();
  const mostrar = useCallback((texto: string, desfazer?: () => void) => {
    setL({ texto, desfazer });
    window.clearTimeout(t.current);
    t.current = window.setTimeout(() => setL(null), desfazer ? 5000 : 2600);
  }, []);
  return (
    <CtxLanche.Provider value={mostrar}>
      {children}
      <div className={`lanche ${l ? 'on' : ''}`} role="status" aria-live="polite">
        <span>{l?.texto}</span>
        {l?.desfazer && <button onClick={() => { l.desfazer?.(); setL(null); }}>Desfazer</button>}
      </div>
    </CtxLanche.Provider>
  );
}
export const useLanche = () => useContext(CtxLanche);

// ---------------------------------------------------------------- Ouvir
export function Ouvir({ texto, rotulo = 'Ouvir' }: { texto: string; rotulo?: string }) {
  return (
    <button type="button" className="ouvir" onClick={(e) => { e.stopPropagation(); falar(texto); }} aria-label={`Ouvir: ${texto}`}>
      🔊 {rotulo}
    </button>
  );
}

// ---------------------------------------------------------------- Topo
export function Topo({ titulo, sub, aoVoltar, direita }: { titulo: ReactNode; sub?: ReactNode; aoVoltar?: () => void; direita?: ReactNode }) {
  return (
    <header className="topo">
      {aoVoltar && <button className="ic" onClick={aoVoltar} aria-label="Voltar"><IcVoltar /></button>}
      <h1>{titulo}{sub && <small>{sub}</small>}</h1>
      {direita}
    </header>
  );
}

// ---------------------------------------------------------------- Folha (de baixo para cima)
export function Folha({ aberta, aoFechar, children, rotulo }: { aberta: boolean; aoFechar: () => void; children: ReactNode; rotulo: string }) {
  useEffect(() => {
    if (!aberta) return;
    const f = (e: KeyboardEvent) => e.key === 'Escape' && aoFechar();
    window.addEventListener('keydown', f);
    return () => window.removeEventListener('keydown', f);
  }, [aberta, aoFechar]);
  if (!aberta) return null;
  return (
    <div className="fundo-folha" onClick={aoFechar}>
      <div className="folha" role="dialog" aria-modal="true" aria-label={rotulo} onClick={(e) => e.stopPropagation()}>
        <div className="alca" />
        {children}
      </div>
    </div>
  );
}

export function FolhaTopo({ e, titulo, aoVoltar, ouvir }: { e?: string; titulo: string; aoVoltar?: () => void; ouvir?: string }) {
  return (
    <div className="folha-topo">
      {aoVoltar && <button className="botao fantasma pequeno" onClick={aoVoltar} aria-label="Voltar">←</button>}
      {e && <span className="e" aria-hidden>{e}</span>}
      <h4>{titulo}</h4>
      {ouvir && <Ouvir texto={ouvir} />}
    </div>
  );
}

// ---------------------------------------------------------------- Campos
export function Campo({ rotulo, erro, ajuda, children }: { rotulo: string; erro?: string; ajuda?: string; children: ReactNode }) {
  return (
    <div className="campo">
      <label>{rotulo}{children}</label>
      {ajuda && <span className="ajuda">{ajuda}</span>}
      {erro && <span className="erro" role="alert">{erro}</span>}
    </div>
  );
}

export function ErroGeral({ erro }: { erro: ErroApi | string | null | undefined }) {
  if (!erro) return null;
  return <div className="erro-geral" role="alert">{typeof erro === 'string' ? erro : erro.message}</div>;
}

export function Interruptor({ ligado, aoMudar, rotulo }: { ligado: boolean; aoMudar: (v: boolean) => void; rotulo: string }) {
  return <button type="button" role="switch" aria-checked={ligado} aria-label={rotulo} className="interruptor" onClick={() => aoMudar(!ligado)} />;
}

export function Carregando({ texto = 'Carregando…' }: { texto?: string }) {
  return <div className="carregando" role="status">{texto}</div>;
}

export function Vazio({ e, children }: { e: string; children: ReactNode }) {
  return <div className="vazio"><span className="e" aria-hidden>{e}</span>{children}</div>;
}

export const CORES_AVATAR = ['#D9E1F8', '#F6DCE6', '#E0EEDF', '#F7E7C9', '#E4DDF5', '#D6EEF0'];
export function corDe(id: string): string {
  let h = 0;
  for (const c of id) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return CORES_AVATAR[h % CORES_AVATAR.length];
}

/** Promessa com estado "enviando" e erro, para botões de ação. */
export function useAcao<A extends unknown[]>(fn: (...a: A) => Promise<unknown>) {
  const [enviando, setEnviando] = useState(false);
  const [erro, setErro] = useState<ErroApi | null>(null);
  const executar = async (...a: A) => {
    setEnviando(true);
    setErro(null);
    try { await fn(...a); return true; } catch (e) { setErro(e as ErroApi); return false; } finally { setEnviando(false); }
  };
  return { executar, enviando, erro, setErro };
}
