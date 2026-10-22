import { useEffect, useState } from 'react';

/** Roteador mínimo (sem dependência): /c/p/:id, /g/:id/hoje… */
export function navegar(caminho: string, substituir = false) {
  if (caminho === location.pathname) return;
  if (substituir) history.replaceState(null, '', caminho);
  else history.pushState(null, '', caminho);
  window.dispatchEvent(new Event('cuidados:rota'));
  window.scrollTo(0, 0);
}

export function voltar(padrao: string) {
  if (history.length > 1 && document.referrer.startsWith(location.origin)) history.back();
  else navegar(padrao, true);
}

export function useCaminho(): string {
  const [c, setC] = useState(location.pathname);
  useEffect(() => {
    const f = () => setC(location.pathname);
    window.addEventListener('popstate', f);
    window.addEventListener('cuidados:rota', f);
    return () => {
      window.removeEventListener('popstate', f);
      window.removeEventListener('cuidados:rota', f);
    };
  }, []);
  return c;
}

/** Compara "/c/p/:id" com o caminho e devolve os parâmetros. */
export function casar(padrao: string, caminho: string): Record<string, string> | null {
  const p = padrao.split('/').filter(Boolean);
  const c = caminho.split('/').filter(Boolean);
  if (p.length !== c.length) return null;
  const params: Record<string, string> = {};
  for (let i = 0; i < p.length; i++) {
    if (p[i].startsWith(':')) params[p[i].slice(1)] = decodeURIComponent(c[i]);
    else if (p[i] !== c[i]) return null;
  }
  return params;
}

/** Perfil escolhido por último neste aparelho (/c, /g ou /e/<id>). */
export const PERFIL_ATIVO = 'cuidados:perfil';
