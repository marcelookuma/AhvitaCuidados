import { useCallback, useEffect, useRef, useState } from 'react';
import { ErroApi } from '@cuidados/core';

/** Carrega dados com estado de carregando/erro e função para recarregar. */
export function useCarregar<T>(fn: () => Promise<T>, deps: unknown[] = []) {
  const [dados, setDados] = useState<T | null>(null);
  const [erro, setErro] = useState<ErroApi | null>(null);
  const [carregando, setCarregando] = useState(true);
  const atual = useRef(0);
  const recarregar = useCallback(async (silencioso = false) => {
    const n = ++atual.current;
    if (!silencioso) setCarregando(true);
    try {
      const d = await fn();
      if (n === atual.current) { setDados(d); setErro(null); }
    } catch (e) {
      if (n === atual.current) setErro(e instanceof ErroApi ? e : new ErroApi(0, 'erro', String(e)));
    } finally {
      if (n === atual.current) setCarregando(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => { void recarregar(); }, [recarregar]);
  return { dados, erro, carregando, recarregar, setDados };
}

/** Repete enquanto a tela está visível (o MVP usa consulta periódica; push vem com o app). */
export function useRepetir(fn: () => void, ms: number, ativo = true) {
  const ref = useRef(fn);
  ref.current = fn;
  useEffect(() => {
    if (!ativo) return;
    const t = setInterval(() => { if (document.visibilityState === 'visible') ref.current(); }, ms);
    const vis = () => { if (document.visibilityState === 'visible') ref.current(); };
    document.addEventListener('visibilitychange', vis);
    return () => { clearInterval(t); document.removeEventListener('visibilitychange', vis); };
  }, [ms, ativo]);
}

export function useOnline(): boolean {
  const [on, setOn] = useState(navigator.onLine);
  useEffect(() => {
    const a = () => setOn(true), b = () => setOn(false);
    window.addEventListener('online', a);
    window.addEventListener('offline', b);
    return () => { window.removeEventListener('online', a); window.removeEventListener('offline', b); };
  }, []);
  return on;
}
