import { useState } from 'react';
import type { ItemConvite } from '@cuidados/core';
import { api } from '../servicos';
import { navegar, PERFIL_ATIVO } from '../rota';
import { useCarregar, useRepetir } from '../hooks';
import { Ouvir, useLanche } from './ui';

/**
 * "Convites para você": tudo o que espera uma resposta da pessoa, no início de todos os perfis
 * (convites por código para o celular dela e pedidos de empresas). Some quando não há nada.
 *  - grande: modo do(a) cuidador(a) (letra grande, botões largos, 🔊)
 */
export function ConvitesParaVoce({ grande = false }: { grande?: boolean }) {
  const lanche = useLanche();
  const r = useCarregar(() => api.meusConvites());
  useRepetir(() => void r.recarregar(true), 30000);
  const [recusando, setRecusando] = useState<string | null>(null);
  const itens = r.dados?.itens ?? [];
  if (itens.length === 0) return null;

  const abrir = (i: ItemConvite) => {
    // Lembra o perfil que o link abre, para a pessoa voltar ao mesmo lugar depois.
    const perfil = i.link.startsWith('/c') ? '/c' : i.link.startsWith('/g') ? '/g' : i.link.startsWith('/e/') ? i.link.split('/').slice(0, 3).join('/') : null;
    if (perfil) localStorage.setItem(PERFIL_ATIVO, perfil);
    navegar(i.link);
  };
  const recusar = async (i: ItemConvite) => {
    try {
      await api.recusarConvite(i.codigo!);
      lanche('Convite recusado.');
      setRecusando(null);
      await r.recarregar(true);
    } catch (e) {
      lanche(e instanceof Error ? e.message : 'Não deu para recusar agora.');
    }
  };

  return (
    <section className={`convites-voce ${grande ? 'grande' : ''}`} aria-label="Convites para você">
      <div className="linha-h">
        <h2 className="cresce">💌 {itens.length === 1 ? 'Um convite para você' : `${itens.length} convites para você`}</h2>
        {grande && <Ouvir texto={itens.map((i) => i.titulo).join('. ') + '. Toque para ver.'} />}
      </div>
      {itens.map((i) => (
        <div key={i.id} className="convite-item">
          <span className="convite-topo">
            <span className="avatar" aria-hidden>{i.icone}</span>
            <span className="cresce"><b>{i.titulo}</b><br /><span className="sutil">{i.texto}</span></span>
          </span>
          {recusando === i.id ? (
            <span className="convite-acoes">
              <span className="sutil">Recusar este convite?</span>
              <button className={`botao perigo ${grande ? '' : 'pequeno'}`} onClick={() => void recusar(i)}>Sim, recusar</button>
              <button className={`botao fantasma ${grande ? '' : 'pequeno'}`} onClick={() => setRecusando(null)}>Voltar</button>
            </span>
          ) : (
            <span className="convite-acoes">
              <button className={`botao ${grande ? '' : 'pequeno'}`} onClick={() => abrir(i)}>{i.rotulo}</button>
              {i.pode_recusar && <button className={`botao fantasma ${grande ? '' : 'pequeno'}`} onClick={() => setRecusando(i.id)}>Não é para mim</button>}
            </span>
          )}
        </div>
      ))}
    </section>
  );
}
