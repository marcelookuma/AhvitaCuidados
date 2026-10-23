/**
 * Cliente da API do painel. Sessão no cookie "cuidados_admin" (httpOnly, SameSite=Strict);
 * toda escrita leva X-Cuidados-Admin (anti-CSRF). Mesmo caminho do app, que funciona
 * no nginx da KingHost sem reescrita de URL.
 */
const BASE: string = import.meta.env.VITE_API_BASE ?? '/api/index.php?r=';

export class ErroAdmin extends Error {
  constructor(public status: number, public codigo: string, mensagem: string, public campos: Record<string, string> = {}) {
    super(mensagem);
  }
}

function url(caminho: string): string {
  // Com "?r=", a busca da rota vira "&" (ex.: ?r=/admin/logs&ator=admin)
  return BASE.includes('?') ? BASE + caminho.replace('?', '&') : BASE + caminho;
}

export async function chamar<T = any>(metodo: string, caminho: string, corpo?: unknown): Promise<T> {
  let r: Response;
  try {
    r = await fetch(url(caminho), {
      method: metodo,
      credentials: 'same-origin',
      headers: {
        'X-Cuidados-Admin': '1',
        'X-Cuidados': '1', // se a mesma pessoa estiver logada no app, a API não estranha o envio
        ...(corpo !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: corpo !== undefined ? JSON.stringify(corpo) : undefined,
    });
  } catch {
    throw new ErroAdmin(0, 'sem_conexao', 'Sem conexão com o servidor. Confira a internet e tente de novo.');
  }
  if (r.status === 204) return undefined as T;
  const j = await r.json().catch(() => null);
  if (!r.ok) {
    const e = j?.erro ?? {};
    const erro = new ErroAdmin(r.status, e.codigo ?? 'erro', e.mensagem ?? `Erro ${r.status}`, e.campos ?? {});
    if (r.status === 401 && !caminho.startsWith('/admin/entrar')) window.dispatchEvent(new Event('admin:sessao-perdida'));
    throw erro;
  }
  return j as T;
}

export const get = <T = any>(c: string) => chamar<T>('GET', c);
export const post = <T = any>(c: string, corpo: unknown = {}) => chamar<T>('POST', c, corpo);
export const patch = <T = any>(c: string, corpo: unknown) => chamar<T>('PATCH', c, corpo);

/** Monta "?a=1&b=2" ignorando vazios. */
export function qs(p: Record<string, string | number | undefined | null>): string {
  const u = new URLSearchParams();
  Object.entries(p).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') u.set(k, String(v)); });
  const s = u.toString();
  return s ? '?' + s : '';
}

export function urlCsv(caminho: string): string {
  return url(caminho);
}

export type Papel = 'leitura' | 'operador' | 'dono';
export interface Admin { id: string; email: string; nome: string | null; papel: Papel; ativo: boolean; google_vinculado: boolean; ultimo_acesso_em: string | null; criado_em: string | null }
export interface Pagina<T> { itens: T[]; total: number; pagina: number; por_pagina: number }
export const NIVEL: Record<Papel, number> = { leitura: 1, operador: 2, dono: 3 };
