import { useEffect, useState } from 'react';
import type { EmpresaEncontrada, PedidoRede } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar } from '../../rota';
import { useCarregar } from '../../hooks';
import { Carregando, ErroGeral, Ouvir, Topo, Vazio, useAcao, useLanche } from '../../componentes/ui';

const FORMA: Record<string, string> = { mei: 'como MEI', autonomo: 'como autônomo(a)', clt: 'com carteira assinada (CLT)' };

/**
 * Empresas (cuidador(a)): pedidos que chegaram, empresas em que estou e pedir para entrar
 * na rede de uma empresa. Entrar na rede não é exclusividade: dá para estar em várias,
 * recusar sem problema e sair quando quiser.
 */
export function Empresas() {
  const lanche = useLanche();
  const r = useCarregar(() => api.minhaRede());
  const [procurando, setProcurando] = useState(false);
  const [saindo, setSaindo] = useState<string | null>(null);

  const responder = useAcao(async (p: PedidoRede, acao: 'aceitar' | 'recusar' | 'cancelar') => {
    await api.responderPedidoRede(p.id, acao);
    lanche(acao === 'aceitar' ? `Pronto. Você está na rede da ${p.empresa?.nome}.` : acao === 'recusar' ? 'Pedido recusado.' : 'Pedido desfeito.');
    await r.recarregar(true);
  });
  const sair = useAcao(async (id: string, nome: string) => {
    await api.sairDaRede(id);
    setSaindo(null);
    lanche(`Você saiu da rede da ${nome}.`);
    await r.recarregar(true);
  });

  if (procurando) return <Procurar aoVoltar={() => { setProcurando(false); void r.recarregar(true); }} />;

  const d = r.dados;
  return (
    <div className="tela modo-cuidador">
      <Topo titulo="Empresas" aoVoltar={() => navegar('/c/eu')}
        direita={<Ouvir texto="Empresas. Aqui você vê os pedidos das empresas e as empresas de que você faz parte. Você pode estar em várias e sair quando quiser." />} />
      <div className="rolagem conteudo pilha">
        {!d && !r.erro && <Carregando />}
        <ErroGeral erro={r.erro ?? responder.erro ?? sair.erro} />
        {d && (
          <>
            {d.recebidos.map((p) => (
              <div key={p.id} className="cartao pilha destaque-pedido">
                <div className="linha-h">
                  <span className="avatar" style={{ background: '#E4DDF5' }} aria-hidden>🏢</span>
                  <span className="cresce"><b>{p.empresa?.nome}</b> quer você na rede dela<br /><span className="sutil">{FORMA[p.modalidade]}</span></span>
                  <Ouvir texto={`${p.empresa?.nome} quer você na rede dela, ${FORMA[p.modalidade]}. ${p.mensagem ?? ''} Você decide.`} />
                </div>
                {p.mensagem && <p className="recado">“{p.mensagem}”</p>}
                <p className="sutil" style={{ margin: 0 }}>Você decide. Pode estar em outras empresas também.</p>
                <button className="botao largo" disabled={responder.enviando} onClick={() => void responder.executar(p, 'aceitar')}>✓ Aceitar</button>
                <button className="botao secundario largo" disabled={responder.enviando} onClick={() => void responder.executar(p, 'recusar')}>Não quero</button>
              </div>
            ))}

            <h2 className="titulo-secao">Minhas empresas</h2>
            {d.empresas.length === 0 && <Vazio e="🏢">Você ainda não está na rede de nenhuma empresa.</Vazio>}
            {d.empresas.map((e) => (
              <div key={e.id} className="cartao pilha">
                <div className="linha-h">
                  <span className="avatar" style={{ background: '#E4DDF5' }} aria-hidden>🏢</span>
                  <span className="cresce"><b>{e.nome}</b><br /><span className="sutil">{FORMA[e.modalidade] ?? ''}</span></span>
                </div>
                {saindo === e.id ? (
                  <>
                    <p style={{ margin: 0 }}><b>Sair da rede da {e.nome}?</b> Você não recebe mais propostas dela. Atendimentos que você já aceitou continuam na sua agenda.</p>
                    <button className="botao perigo largo" disabled={sair.enviando} onClick={() => void sair.executar(e.id, e.nome)}>Sim, sair</button>
                    <button className="botao secundario largo" onClick={() => setSaindo(null)}>Ficar</button>
                  </>
                ) : (
                  <div className="linha-h" style={{ flexWrap: 'wrap' }}>
                    <button className="botao secundario cresce" onClick={() => navegar(`/c/d/${e.conversa_id}`)}>💬 Conversar</button>
                    <button className="botao fantasma" onClick={() => setSaindo(e.id)}>Sair da rede</button>
                  </div>
                )}
              </div>
            ))}

            {d.enviados.length > 0 && <h2 className="titulo-secao">Esperando a empresa</h2>}
            {d.enviados.map((p) => (
              <div key={p.id} className="cartao linha-h" style={{ flexWrap: 'wrap' }}>
                <span aria-hidden>🕓</span>
                <span className="cresce"><b>{p.empresa?.nome}</b><br /><span className="sutil">Você pediu {FORMA[p.modalidade]}. A empresa vai responder.</span></span>
                <button className="botao fantasma pequeno" disabled={responder.enviando} onClick={() => void responder.executar(p, 'cancelar')}>Desistir</button>
              </div>
            ))}

            <button className="botao largo" onClick={() => setProcurando(true)}>🔎 Procurar uma empresa</button>
          </>
        )}
      </div>
    </div>
  );
}

/** Procurar a empresa pelo nome, escolher como presta o serviço e pedir. */
function Procurar({ aoVoltar }: { aoVoltar: () => void }) {
  const lanche = useLanche();
  const [texto, setTexto] = useState('');
  const [achadas, setAchadas] = useState<EmpresaEncontrada[] | null>(null);
  const [escolhida, setEscolhida] = useState<EmpresaEncontrada | null>(null);
  const buscar = useAcao(async (t: string) => setAchadas((await api.buscarEmpresas(t)).empresas));
  useEffect(() => {
    if (texto.trim().length < 3) { setAchadas(null); return; }
    const id = setTimeout(() => void buscar.executar(texto.trim()), 400);
    return () => clearTimeout(id);
  }, [texto]); // eslint-disable-line react-hooks/exhaustive-deps

  const pedir = useAcao(async (modalidade: 'mei' | 'autonomo') => {
    const r = await api.pedirRedeCuidador({ empresa_id: escolhida!.id, modalidade });
    lanche(r.status === 'aceito' ? `A ${escolhida!.nome} já tinha pedido. Você entrou na rede.` : `Pedido enviado para a ${escolhida!.nome}.`);
    aoVoltar();
  });

  if (escolhida) {
    return (
      <div className="tela modo-cuidador">
        <Topo titulo={escolhida.nome} aoVoltar={() => setEscolhida(null)}
          direita={<Ouvir texto={`Pedir para entrar na rede da ${escolhida.nome}. Como você presta o serviço? Toque em MEI ou em autônomo.`} />} />
        <div className="rolagem conteudo pilha">
          <h1 style={{ margin: 0 }}>Como você presta o serviço?</h1>
          <p className="sutil" style={{ margin: 0 }}>A empresa vê seu primeiro nome e o fim do seu celular. Ela decide se aceita.</p>
          <button className="botao largo" disabled={pedir.enviando} onClick={() => void pedir.executar('mei')}>Sou MEI</button>
          <button className="botao largo" disabled={pedir.enviando} onClick={() => void pedir.executar('autonomo')}>Sou autônomo(a)</button>
          <ErroGeral erro={pedir.erro} />
          <p className="fraco" style={{ margin: 0 }}>Na rede, a empresa manda propostas de atendimento. Você aceita só as que quiser, dentro da sua agenda.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="tela modo-cuidador">
      <Topo titulo="Procurar empresa" aoVoltar={aoVoltar} direita={<Ouvir texto="Escreva o nome da empresa. Depois toque nela." />} />
      <div className="rolagem conteudo pilha">
        <label className="campo-grande">
          <span>Nome da empresa</span>
          <input value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus enterKeyHint="search" placeholder="Ex.: CuidaBem" />
        </label>
        <ErroGeral erro={buscar.erro} />
        {texto.trim().length > 0 && texto.trim().length < 3 && <p className="sutil">Escreva pelo menos 3 letras.</p>}
        {achadas?.length === 0 && <Vazio e="🔎">Não achamos. Confira o nome ou peça o convite para a empresa.</Vazio>}
        <div className="lista-conversas">
          {achadas?.map((e) => (
            <button key={e.id} className="conversa-linha" disabled={e.na_rede || e.pedido_pendente} onClick={() => setEscolhida(e)}>
              <span className="avatar" style={{ background: '#E4DDF5' }} aria-hidden>🏢</span>
              <span className="cresce">
                <span className="nome">{e.nome}</span>
                <span className="previa">{e.na_rede ? 'Você já está na rede' : e.pedido_pendente ? 'Pedido esperando resposta' : e.razao_social}</span>
              </span>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
