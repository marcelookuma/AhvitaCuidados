import { criarApi, criarFila } from '@cuidados/core';
import { armazenamentoFila } from './armazenamento';
import { apagarToken, baseApi, guardarToken, tokenSessao } from './plataforma';

/**
 * Web: a sessão vai no cookie httpOnly. App Android: token Bearer guardado no
 * cofre do aparelho (ver plataforma.ts). As telas não sabem a diferença.
 */
export const api = criarApi({
  // Web: sem depender de reescrita de URL (a KingHost serve por nginx, que ignora .htaccess)
  base: baseApi(),
  token: tokenSessao,
  aoReceberToken: (t) => void guardarToken(t),
  aoPerderSessao: () => {
    void apagarToken();
    window.dispatchEvent(new Event('cuidados:sessao-perdida'));
  },
});

export const fila = criarFila(api, armazenamentoFila);

// Reenvia quando a internet volta e de tempos em tempos.
window.addEventListener('online', () => void fila.processar());
setInterval(() => void fila.processar(), 15_000);
void fila.carregar().then(() => fila.processar());
