import { useEffect, useState } from 'react';

/**
 * Roteador mínimo (sem dependência): /c/p/:id, /g/:id/hoje…
 * As rotas ficam depois do "#" (ex.: /#/c/p/123). Assim o servidor só precisa
 * entregar o index.html: a KingHost serve por nginx, que ignora o .htaccess.
 */
function rotaCompleta(): string {
  const h = location.hash.slice(1);
  return h.startsWith('/') ? h : '/';
}

/** Caminho atual, sem a parte "?…". */
export function caminhoAtual(): string {
  return rotaCompleta().split('?')[0] || '/';
}

/** Parâmetros "?…" da rota atual (ex.: /e/1/conversas?c=2). */
export function busca(): URLSearchParams {
  const r = rotaCompleta();
  const i = r.indexOf('?');
  return new URLSearchParams(i >= 0 ? r.slice(i + 1) : '');
}

/** Links antigos sem "#" (ex.: /convite/ABC) passam para /#/convite/ABC. */
export function migrarRotaAntiga() {
  const p = location.pathname;
  if (p !== '/' && p !== '/index.html' && !location.hash) {
    history.replaceState(null, '', '/#' + p + location.search);
  }
}

export function navegar(caminho: string, substituir = false) {
  if (caminho === rotaCompleta()) return;
  if (substituir) history.replaceState(null, '', '#' + caminho);
  else history.pushState(null, '', '#' + caminho);
  window.dispatchEvent(new Event('cuidados:rota'));
  window.scrollTo(0, 0);
}

export function voltar(padrao: string) {
  if (history.length > 1 && document.referrer.startsWith(location.origin)) history.back();
  else navegar(padrao, true);
}

export function useCaminho(): string {
  const [c, setC] = useState(caminhoAtual());
  useEffect(() => {
    const f = () => setC(caminhoAtual());
    window.addEventListener('popstate', f);
    window.addEventListener('hashchange', f);
    window.addEventListener('cuidados:rota', f);
    return () => {
      window.removeEventListener('popstate', f);
      window.removeEventListener('hashchange', f);
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
