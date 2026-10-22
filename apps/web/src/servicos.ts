import { criarApi, criarFila } from '@cuidados/core';
import { armazenamentoFila } from './armazenamento';

/** Na web a sessão vai no cookie httpOnly; o app móvel passaria `token`. */
export const api = criarApi({
  base: import.meta.env.VITE_API_BASE ?? '/api/v1',
  aoPerderSessao: () => window.dispatchEvent(new Event('cuidados:sessao-perdida')),
});

export const fila = criarFila(api, armazenamentoFila);

// Reenvia quando a internet volta e de tempos em tempos.
window.addEventListener('online', () => void fila.processar());
setInterval(() => void fila.processar(), 15_000);
void fila.carregar().then(() => fila.processar());
