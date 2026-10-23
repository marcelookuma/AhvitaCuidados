import { api } from '../../servicos';
import { navegar } from '../../rota';
import { aplicarPreferencias, useSessao } from '../../sessao';
import { Interruptor, Ouvir, Topo, useLanche } from '../../componentes/ui';
import { telefone } from '@cuidados/core';
import { falar } from '../../voz';
import { AbasCuidador } from './AppCuidador';
import { BotaoSair } from '../../componentes/sair';

/** Eu: tamanho da letra, ler em voz alta, meus registros, ajuda e sair. */
export function Eu() {
  const { eu, recarregar } = useSessao();
  const lanche = useLanche();
  if (!eu) return null;
  const pref = eu.usuario.preferencias ?? {};
  const salvar = async (mud: Record<string, unknown>) => {
    const novo = { ...pref, ...mud };
    aplicarPreferencias({ ...eu, usuario: { ...eu.usuario, preferencias: novo } });
    try { await api.atualizarEu({ preferencias: novo } as never); await recarregar(); } catch { lanche('Sem internet: vale neste aparelho.'); }
  };
  return (
    <div className="tela modo-cuidador">
      <Topo titulo="Eu" direita={<Ouvir texto="Eu. Aqui você muda o tamanho da letra e liga a leitura em voz alta." />} />
      <div className="rolagem conteudo">
        <div className="cartao linha-h">
          <span className="avatar" style={{ background: 'var(--verde)', color: '#fff' }}>{(eu.usuario.apelido || eu.usuario.nome || '?')[0]}</span>
          <span className="cresce"><b>{eu.usuario.nome}</b><br /><span className="sutil">{telefone(eu.usuario.telefone) || eu.usuario.email}</span></span>
        </div>
        <div className="cartao pilha">
          <div className="linha-h"><span className="cresce"><b>Tamanho da letra</b><br /><span className="sutil">Do jeito que você lê melhor</span></span>
            <div className="chips">{[1, 2, 3].map((n) => (
              <button key={n} className="chip" aria-pressed={(pref.fonte ?? 1) === n} style={{ fontSize: `${0.75 + n * 0.18}em` }} onClick={() => void salvar({ fonte: n })} aria-label={`Letra tamanho ${n}`}>A</button>
            ))}</div>
          </div>
          <div className="separador" />
          <div className="linha-h"><span className="cresce"><b>Ler as mensagens em voz alta</b><br /><span className="sutil">O app lê os avisos para você</span></span>
            <Interruptor rotulo="Ler em voz alta" ligado={!!pref.voz} aoMudar={(v) => { void salvar({ voz: v }); if (v) falar('Pronto. Vou ler os avisos para você.'); }} /></div>
        </div>
        <div className="cartao pilha">
          <div className="linha-h"><span aria-hidden>🛡️</span><span className="cresce"><b>Meus registros guardados</b><br /><span className="sutil">Tudo o que você mandou fica guardado com dia e hora. Comprova os serviços que você prestou.</span></span></div>
          <a className="botao secundario largo" href={api.urlExportarEu()} download>Baixar uma cópia</a>
        </div>
        <div className="cartao pilha">
          <div className="linha-h"><span aria-hidden>❓</span><span className="cresce"><b>Preciso de ajuda com o app</b><br /><span className="sutil">Fale com o suporte pelo WhatsApp</span></span></div>
          <a className="botao secundario largo" href="https://wa.me/5511900000000?text=Preciso%20de%20ajuda%20com%20o%20Ahvita%20Cuidados" target="_blank" rel="noreferrer">💬 Falar com o suporte</a>
        </div>
        {(eu.papeis.acompanha || eu.papeis.empresa) && <button className="botao secundario largo" onClick={() => navegar('/perfil')}>Trocar de perfil</button>}
        <BotaoSair />
      </div>
      <AbasCuidador atual="eu" />
    </div>
  );
}
