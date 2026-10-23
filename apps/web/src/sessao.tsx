import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { ErroApi, type Eu } from '@cuidados/core';
import { api, fila } from './servicos';
import { apagarTudo } from './armazenamento';
import { navegar } from './rota';
import { apagarToken, desativarAvisos } from './plataforma';
import { esquecerMidias } from './componentes/midia';

interface Sessao {
  eu: Eu | null;
  carregando: boolean;
  semConexao: boolean;
  recarregar: () => Promise<Eu | null>;
  sair: () => Promise<void>;
}

const Ctx = createContext<Sessao>(null as unknown as Sessao);
export const useSessao = () => useContext(Ctx);

const CHAVE_EU = 'cuidados:eu';

export function ProvedorSessao({ children }: { children: ReactNode }) {
  const [eu, setEu] = useState<Eu | null>(null);
  const [carregando, setCarregando] = useState(true);
  const [semConexao, setSemConexao] = useState(false);

  const recarregar = useCallback(async () => {
    try {
      const e = await api.eu();
      setEu(e);
      setSemConexao(false);
      aplicarPreferencias(e);
      // Sem internet, o app abre com a última sessão conhecida (só papéis e nome).
      localStorage.setItem(CHAVE_EU, JSON.stringify(e));
      return e;
    } catch (e) {
      if (e instanceof ErroApi && e.semConexao) {
        const guardado = localStorage.getItem(CHAVE_EU);
        const g = guardado ? (JSON.parse(guardado) as Eu) : null;
        setEu(g);
        setSemConexao(true);
        if (g) aplicarPreferencias(g);
        return g;
      }
      setEu(null);
      localStorage.removeItem(CHAVE_EU);
      return null;
    } finally {
      setCarregando(false);
    }
  }, []);

  const sair = useCallback(async () => {
    // O aparelho deixa de receber avisos desta conta (antes de encerrar a sessão).
    await desativarAvisos((t) => api.removerDispositivo(t));
    try { await api.sair(); } catch { /* sem internet: apaga local mesmo assim */ }
    // Dados de saúde não ficam no aparelho depois de sair (LGPD): sessão, cópias e fila.
    await apagarToken();
    esquecerMidias();
    localStorage.clear();
    sessionStorage.clear();
    await apagarTudo();
    await fila.limpar();
    setEu(null);
    navegar('/entrar', true);
  }, []);

  useEffect(() => {
    void recarregar();
    const perdeu = () => { setEu(null); localStorage.removeItem(CHAVE_EU); };
    window.addEventListener('cuidados:sessao-perdida', perdeu);
    return () => window.removeEventListener('cuidados:sessao-perdida', perdeu);
  }, [recarregar]);

  return <Ctx.Provider value={{ eu, carregando, semConexao, recarregar, sair }}>{children}</Ctx.Provider>;
}

export function aplicarPreferencias(e: Eu) {
  document.documentElement.dataset.fonte = String(e.usuario.preferencias?.fonte ?? 1);
}
