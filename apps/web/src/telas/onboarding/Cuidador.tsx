import { useEffect, useState } from 'react';
import { DIAS_CURTOS, MODALIDADES, PAPEIS, type Janela } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar, PERFIL_ATIVO } from '../../rota';
import { useSessao } from '../../sessao';
import { ErroGeral, Ouvir, useAcao } from '../../componentes/ui';
import { falar } from '../../voz';
import { BotaoSair } from '../../componentes/sair';

/**
 * Onboarding do(a) Cuidador(a) (6 passos curtos, com 🔊 em todos):
 * código do convite → como prefere usar (ver ou ouvir) → a SUA agenda →
 * 3 cartões de como funciona → aceite. A agenda é dele(a) desde o começo.
 */
export function OnboardingCuidador({ codigoInicial, aoTerminar }: { codigoInicial?: string; aoTerminar: () => void }) {
  const { eu, recarregar } = useSessao();
  const [passo, setPasso] = useState(codigoInicial ? 2 : 1);
  const [codigo, setCodigo] = useState(codigoInicial ?? '');
  const [convite, setConvite] = useState<any>(null);
  const [voz, setVoz] = useState(false);
  const [dias, setDias] = useState<number[]>([1, 2, 3, 4, 5]);
  const [ini, setIni] = useState('07:00');
  const [fim, setFim] = useState('19:00');
  const [cartao, setCartao] = useState(0);

  const buscar = useAcao(async (c: string) => {
    const r = await api.convite(c.trim().toUpperCase());
    if (r.tipo === 'empresa_membro' || r.tipo === 'empresa_cliente') {
      navegar(`/convite/${c.trim().toUpperCase()}`, true);
      return;
    }
    setConvite(r);
    setCodigo(c.trim().toUpperCase());
    setPasso(2);
  });
  useEffect(() => {
    if (codigoInicial) void buscar.executar(codigoInicial).then((ok) => { if (!ok) setPasso(1); });
  }, [codigoInicial]); // eslint-disable-line react-hooks/exhaustive-deps

  const ehCuidador = convite && (convite.tipo === 'empresa_cuidador' || convite.papel === 'cuidador' || convite.atua_como_cuidador);
  const finalizar = useAcao(async () => {
    await api.aceitarConvite(codigo);
    if (ehCuidador) {
      const janelas: Janela[] = dias.map((d) => ({ dia_semana: d, inicio: ini, fim }));
      await api.salvarDisponibilidade(janelas);
    }
    await api.atualizarEu({ preferencias: { ...(eu?.usuario.preferencias ?? {}), voz, modo: 'conversa' } } as never);
    aoTerminar();
    await recarregar();
    localStorage.setItem(PERFIL_ATIVO, ehCuidador ? '/c' : '/g');
    navegar(ehCuidador ? '/c' : '/g', true);
  });

  const CARTOES = [
    { e: '💬', t: 'Cada pessoa é uma conversa', d: 'Como no WhatsApp. Toque no nome para conversar e registrar.' },
    { e: '➕', t: 'Registrar é tocar no ＋', d: 'Escolha o que fez: remédio, comida, água… Ou toque no 🎤 e fale.' },
    { e: '📵', t: 'Funciona sem internet', d: 'O relógio 🕓 mostra o que está guardado. Vai sozinho quando a internet voltar.' },
  ];

  return (
    <main className="acesso" style={{ justifyContent: 'flex-start', gap: '1.2em' }}>
      <div className="passos" aria-hidden>{[1, 2, 3, 4, 5].map((i) => <i key={i} className={i <= passo ? 'feito' : ''} />)}</div>

      {passo === 1 && (
        <form className="pilha" onSubmit={(e) => { e.preventDefault(); void buscar.executar(codigo); }}>
          <h1>Digite o código do convite</h1>
          <Ouvir texto="Digite o código do convite. São 6 letras e números que chegaram no seu WhatsApp." />
          <input aria-label="Código do convite" value={codigo} onChange={(e) => setCodigo(e.target.value.toUpperCase())} maxLength={6}
            style={{ fontSize: '1.8em', textAlign: 'center', letterSpacing: '.25em', fontWeight: 800, padding: '.4em', borderRadius: 12, border: '2px solid var(--linha)' }} />
          <ErroGeral erro={buscar.erro} />
          <button className="botao largo" disabled={codigo.length !== 6 || buscar.enviando}>Continuar</button>
          <button type="button" className="botao fantasma" onClick={() => navegar('/bem-vindo', true)}>Voltar</button>
        </form>
      )}

      {passo === 2 && convite && (
        <div className="pilha">
          <span style={{ fontSize: '2.6em' }} aria-hidden>💌</span>
          <h1>{convite.de?.nome} convidou você</h1>
          <p>
            Para {ehCuidador ? 'cuidar de' : 'acompanhar'} <b>{convite.pessoa ?? convite.empresa}</b>
            {!ehCuidador && <> como <b>{PAPEIS[convite.papel] ?? convite.papel}</b></>}.
          </p>
          {convite.modalidade && <p className="sutil">Você atende como: {MODALIDADES[convite.modalidade] ?? convite.modalidade}</p>}
          <Ouvir texto={`${convite.de?.nome} convidou você para ${ehCuidador ? 'cuidar de' : 'acompanhar'} ${convite.pessoa ?? convite.empresa}.`} />
          <button className="botao largo" onClick={() => setPasso(ehCuidador ? 3 : 5)}>Continuar</button>
        </div>
      )}

      {passo === 3 && (
        <div className="pilha">
          <h1>Como prefere usar o app?</h1>
          <div className="escolhas">
            <button className="escolha" aria-pressed={!voz} onClick={() => setVoz(false)}>
              <span className="e">👀</span><span><b>Ver</b><small>Leio as telas</small></span>
            </button>
            <button className="escolha" aria-pressed={voz} onClick={() => { setVoz(true); falar('Pronto. O app vai ler os avisos para você.'); }}>
              <span className="e">🔊</span><span><b>Ouvir</b><small>O app lê os avisos em voz alta</small></span>
            </button>
          </div>
          <p className="sutil">Em todas as telas tem o botão 🔊 Ouvir.</p>
          <button className="botao largo" onClick={() => setPasso(4)}>Continuar</button>
        </div>
      )}

      {passo === 4 && (
        <div className="pilha">
          <h1>A sua agenda</h1>
          <Ouvir texto="A sua agenda. Marque os dias e horários em que você atende. A agenda é sua: as propostas só chegam nesses horários, e você decide se aceita." />
          <p className="sutil">Marque quando você atende. <b>A agenda é sua</b>: as propostas só chegam nesses horários e você decide se aceita.</p>
          <div className="semana" role="group" aria-label="Dias em que você atende">
            {DIAS_CURTOS.map((n, i) => (
              <button key={n} aria-pressed={dias.includes(i)} onClick={() => setDias(dias.includes(i) ? dias.filter((x) => x !== i) : [...dias, i].sort())}>
                <span aria-hidden>{dias.includes(i) ? '✓' : '—'}</span>{n}
              </button>
            ))}
          </div>
          <div className="grade-2">
            <label className="campo"><span className="rotulo">Das</span><input type="time" value={ini} onChange={(e) => setIni(e.target.value)} /></label>
            <label className="campo"><span className="rotulo">Até</span><input type="time" value={fim} onChange={(e) => setFim(e.target.value)} /></label>
          </div>
          {fim <= ini && <p className="sutil">🌙 Termina no dia seguinte (atendimento noturno).</p>}
          <button className="botao largo" onClick={() => setPasso(5)}>Continuar</button>
        </div>
      )}

      {passo === 5 && (
        <div className="pilha">
          {ehCuidador && cartao < CARTOES.length ? (
            <>
              <div className="cartao" style={{ textAlign: 'center', padding: '2em 1em' }}>
                <span style={{ fontSize: '3em' }} aria-hidden>{CARTOES[cartao].e}</span>
                <h1 style={{ fontSize: '1.3em' }}>{CARTOES[cartao].t}</h1>
                <p className="sutil">{CARTOES[cartao].d}</p>
                <Ouvir texto={`${CARTOES[cartao].t}. ${CARTOES[cartao].d}`} />
              </div>
              <button className="botao largo" onClick={() => setCartao(cartao + 1)}>{cartao < 2 ? 'Próximo' : 'Entendi'}</button>
            </>
          ) : (
            <>
              <h1>Tudo certo?</h1>
              <div className="cartao sutil pilha">
                <p>O que você registrar vai para quem acompanha o cuidado de {convite?.pessoa ?? 'cada pessoa'}, com dia e hora. Também fica guardado para você, como comprovante dos serviços prestados.</p>
                {ehCuidador && <p>Você pode recusar qualquer proposta, sem nenhuma consequência.</p>}
              </div>
              <ErroGeral erro={finalizar.erro} />
              <button className="botao largo" disabled={finalizar.enviando} onClick={() => finalizar.executar()}>✓ Aceitar e começar</button>
            </>
          )}
        </div>
      )}
      <div className="acesso-rodape"><BotaoSair /></div>
    </main>
  );
}
