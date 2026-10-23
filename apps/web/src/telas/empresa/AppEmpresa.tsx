import { useState } from 'react';
import { MODALIDADES, dia, hora, isoLocal, periodo, reais, telefone, type ConviteCriado, type PedidoCliente, type PedidoRede } from '@cuidados/core';
import { api } from '../../servicos';
import { busca, casar, navegar, useCaminho } from '../../rota';
import { useSessao } from '../../sessao';
import { BotaoSair } from '../../componentes/sair';
import { useCarregar, useRepetir } from '../../hooks';
import { Campo, Carregando, ErroGeral, Vazio, useAcao, useLanche } from '../../componentes/ui';
import { Direta } from '../cuidador/Direta';
import { ConvitesParaVoce } from '../../componentes/convites';
import { LinhaDoTempo } from '../acompanhamento/LinhaDoTempo';
import { EscolherPermissoesEmpresa, nomes } from '../acompanhamento/LigarEmpresa';

const ABAS = [
  { k: 'painel', e: '🏠', t: 'Painel' },
  { k: 'atendimentos', e: '📅', t: 'Atendimentos' },
  { k: 'equipe', e: '🤲', t: 'Cuidadores(as)' },
  { k: 'clientes', e: '🏡', t: 'Clientes' },
  { k: 'conversas', e: '💬', t: 'Conversas' },
];

/**
 * Painel da empresa (MVP: funções básicas). Sem ranking, sem avaliação dos(as) cuidadores(as) e sem
 * controle de horas dos(as) cuidadores(as) MEI: a empresa propõe, eles(as) decidem.
 */
export function AppEmpresa() {
  const caminho = useCaminho();
  const { eu } = useSessao();
  const m = casar('/e/:id/:aba/:sub', caminho) ?? casar('/e/:id/:aba', caminho) ?? casar('/e/:id', caminho);
  const id = m?.id ?? '';
  const painel = useCarregar(() => api.painelEmpresa(id), [id]);
  useRepetir(() => void painel.recarregar(true), 30000);
  const aba = ABAS.find((a) => a.k === (m?.aba ?? 'painel')) ?? ABAS[0];
  if (!painel.dados) return painel.erro ? <div className="principal"><ErroGeral erro={painel.erro} /></div> : <Carregando />;
  const e = painel.dados.empresa;

  return (
    <div className="painel modo-painel">
      <header className="painel-topo">
        <div className="marca"><img src="/icone.svg" alt="" /><b>{e.nome}</b></div>
        <span className="cresce" />
        <button className="botao secundario pequeno" onClick={() => navegar('/conta')}>👤 Meus dados</button>
        {(eu?.papeis.cuidador || eu?.papeis.acompanha || (eu?.empresas.length ?? 0) > 1) && <button className="botao secundario pequeno" onClick={() => navegar('/perfil')}>Trocar perfil</button>}
        <BotaoSair variante="pequeno" rotulo="Sair" />
      </header>
      <div className="painel-corpo">
        <nav className="lateral" aria-label="Seções">
          <div className="titulo">Empresa</div>
          {ABAS.map((a) => (
            <button key={a.k} aria-current={a.k === aba.k ? 'page' : undefined} onClick={() => navegar(`/e/${id}/${a.k}`)}>
              <span className="e" aria-hidden>{a.e}</span>{a.t}
            </button>
          ))}
        </nav>
        <main className="principal">
          {aba.k === 'painel' && <Painel id={id} d={painel.dados} aoMudar={() => void painel.recarregar(true)} />}
          {aba.k === 'atendimentos' && <Atendimentos id={id} />}
          {aba.k === 'equipe' && <Equipe id={id} admin={e.meu_papel === 'admin'} />}
          {aba.k === 'clientes' && <Clientes id={id} aberto={m?.sub} />}
          {aba.k === 'conversas' && <Conversas id={id} />}
        </main>
      </div>
    </div>
  );
}

function Painel({ id, d, aoMudar }: { id: string; d: Awaited<ReturnType<typeof api.painelEmpresa>>; aoMudar: () => void }) {
  return (
    <>
      <h1>Painel</h1>
      <ConvitesParaVoce />
      <section className="indicadores">
        <div className="indicador"><div className="n">{d.equipe}</div><div className="r">cuidadores(as) na rede</div></div>
        <div className="indicador"><div className="n">{d.clientes}</div><div className="r">clientes</div></div>
        <div className="indicador"><div className="n">{d.atendimentos_7_dias}</div><div className="r">atendimentos combinados (7 dias)</div></div>
        <div className={`indicador ${d.propostas_abertas ? 'alerta' : ''}`}><div className="n">{d.propostas_abertas}</div><div className="r">propostas sem aceite</div></div>
      </section>
      <section className="pilha">
        <h2>Cobertura</h2>
        {d.alertas.length === 0 && <p className="sutil">Nenhum alerta. Os atendimentos de hoje estão confirmados ou dentro do prazo.</p>}
        {d.alertas.map((a) => (
          <div key={a.id} className="alerta-linha cobertura">
            <span className="cresce"><b>{a.pessoa.apelido}</b> · <span className="fraco">{hora(a.em)}</span><br />{a.texto}</span>
            <button className="botao pequeno" onClick={() => navegar(`/e/${id}/atendimentos`)}>Abrir proposta</button>
            <button className="botao secundario pequeno" onClick={async () => { await api.resolverAlerta(a.id); aoMudar(); }}>Ciente</button>
          </div>
        ))}
        <p className="fraco">A confirmação serve só para organizar a cobertura a tempo. O app não registra falta nem aplica penalidade; regras de reposição ficam no contrato entre as partes.</p>
      </section>
    </>
  );
}

function Atendimentos({ id }: { id: string }) {
  const lanche = useLanche();
  const lista = useCarregar(() => api.atendimentosEmpresa(id), [id]);
  const clientes = useCarregar(() => api.clientes(id), [id]);
  const amanha = new Date(Date.now() + 86400_000).toISOString().slice(0, 10);
  const [f, setF] = useState({ pessoa_id: '', data: amanha, inicio: '08:00', fim: '17:00', valor: '', observacao: '' });
  const enviar = useAcao(async () => {
    const ini = isoLocal(f.data, f.inicio);
    let fim = isoLocal(f.data, f.fim);
    if (fim <= ini) fim = new Date(new Date(fim).getTime() + 86400_000).toISOString();
    const r = await api.criarProposta(id, {
      pessoa_id: f.pessoa_id, inicio: ini, fim, observacao: f.observacao || undefined,
      valor_centavos: f.valor ? Math.round(parseFloat(f.valor.replace(/\./g, '').replace(',', '.')) * 100) : undefined,
    });
    lanche(`Proposta enviada para ${r.enviada_para} cuidador(es/as) com disponibilidade.`);
    await lista.recarregar(true);
  });
  const podeOrganizar = (clientes.dados?.clientes ?? []).filter((c) => c.permissoes.includes('atendimentos'));
  return (
    <>
      <h1>Atendimentos</h1>
      <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void enviar.executar(); }}>
        <h3 style={{ margin: 0 }}>📢 Nova proposta de atendimento</h3>
        {podeOrganizar.length === 0 && <p className="sutil">Nenhum cliente autorizou a empresa a organizar atendimentos ainda.</p>}
        <div className="grade-2">
          <Campo rotulo="Cliente"><select value={f.pessoa_id} onChange={(e) => setF({ ...f, pessoa_id: e.target.value })} required>
            <option value="">Escolha</option>{podeOrganizar.map((c) => <option key={c.id} value={c.id}>{c.apelido}</option>)}</select></Campo>
          <Campo rotulo="Dia"><input type="date" value={f.data} onChange={(e) => setF({ ...f, data: e.target.value })} required /></Campo>
          <Campo rotulo="Início"><input type="time" value={f.inicio} onChange={(e) => setF({ ...f, inicio: e.target.value })} /></Campo>
          <Campo rotulo="Fim" ajuda={f.fim <= f.inicio ? '🌙 Termina no dia seguinte' : undefined}><input type="time" value={f.fim} onChange={(e) => setF({ ...f, fim: e.target.value })} /></Campo>
          <Campo rotulo="Valor oferecido (R$)"><input value={f.valor} onChange={(e) => setF({ ...f, valor: e.target.value })} inputMode="decimal" placeholder="190,00" /></Campo>
          <Campo rotulo="Observação"><input value={f.observacao} onChange={(e) => setF({ ...f, observacao: e.target.value })} placeholder="Ex.: reforço — cuidador(a) de referência indisponível" /></Campo>
        </div>
        <ErroGeral erro={enviar.erro} />
        <button className="botao" style={{ alignSelf: 'flex-start' }} disabled={enviar.enviando || !f.pessoa_id}>Enviar para quem tem disponibilidade</button>
        <p className="fraco" style={{ margin: 0 }}>A proposta chega ao mesmo tempo para quem marcou disponibilidade nesse horário. Cada um(a) decide; o primeiro aceite fica com o atendimento. Sem ranking.</p>
      </form>
      {!lista.dados ? <Carregando /> : (
        <table className="tabela responsiva">
          <thead><tr><th>Cliente</th><th>Quando</th><th>Cuidador(a)</th><th>Situação</th></tr></thead>
          <tbody>
            {lista.dados.atendimentos.length === 0 && <tr><td colSpan={4} className="sutil">Nenhum atendimento próximo.</td></tr>}
            {lista.dados.atendimentos.map((a) => (
              <tr key={a.id}>
                <td><b>{a.pessoa.apelido}</b></td>
                <td>{periodo(a.inicio, a.fim)}{a.valor_centavos ? <><br /><span className="sutil">{reais(a.valor_centavos)}</span></> : null}</td>
                <td>{a.cuidador?.nome ?? <span className="sutil">Enviada para {a.enviada_para}{a.recusas ? ` · ${a.recusas} recusaram` : ''}</span>}</td>
                <td>{a.status === 'proposto' ? <span className="etiqueta atencao">Aguardando aceite</span>
                  : a.confirmacao === 'vou' ? <span className="etiqueta ok">✓ Confirmou a ida</span>
                  : a.confirmacao === 'nao_vou' ? <span className="etiqueta alerta">Não vai — buscar cobertura</span>
                  : a.sem_resposta_no_prazo ? <span className="etiqueta atencao">Sem resposta no prazo</span>
                  : <span className="etiqueta">Combinado</span>}
                  {a.status === 'proposto' && <button className="botao fantasma pequeno" onClick={async () => { await api.cancelarAtendimento(a.id); await lista.recarregar(true); }}>Cancelar</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  );
}

const NOME_MOD = (m: string) => (m === 'clt' ? 'CLT' : MODALIDADES[m] ?? m);
const RESPOSTA: Record<string, [string, string]> = {
  aceito: ['ok', 'Aceitou'], recusado: ['', 'Não aceitou'], cancelado: ['', 'Pedido desfeito'], expirado: ['', 'Venceu sem resposta'],
};

function Equipe({ id, admin }: { id: string; admin: boolean }) {
  const lanche = useLanche();
  const r = useCarregar(() => api.equipe(id), [id]);
  const [novo, setNovo] = useState<{ tipo: string; nome: string; telefone: string; modalidade: string } | null>(null);
  const [pedido, setPedido] = useState<{ telefone: string; modalidade: string; mensagem: string } | null>(null);
  const [convite, setConvite] = useState<ConviteCriado | null>(null);
  const criar = useAcao(async () => {
    if (!novo) return;
    setConvite(await api.convidarEmpresa(id, { tipo: novo.tipo, nome: novo.nome, telefone: novo.telefone || undefined, modalidade: novo.tipo === 'cuidador' ? novo.modalidade : undefined }));
    setNovo(null);
    await r.recarregar(true);
  });
  const pedir = useAcao(async () => {
    if (!pedido) return;
    const res = await api.pedirRedeEmpresa(id, { telefone: pedido.telefone, modalidade: pedido.modalidade, mensagem: pedido.mensagem || undefined });
    lanche(res.status === 'aceito' ? `${res.cuidador.nome} já tinha pedido para entrar: agora está na rede.` : `Pedido enviado para ${res.cuidador.nome}. Ele(a) responde no app.`);
    setPedido(null);
    await r.recarregar(true);
  });
  const responder = useAcao(async (p: PedidoRede, acao: 'aceitar' | 'recusar' | 'cancelar') => {
    await api.responderPedidoRede(p.id, acao);
    lanche(acao === 'aceitar' ? `${p.cuidador?.nome} entrou na rede.` : acao === 'recusar' ? 'Pedido recusado.' : 'Pedido desfeito.');
    await r.recarregar(true);
  });
  if (!r.dados) return <Carregando />;
  const recebidos = r.dados.pedidos?.recebidos ?? [];
  const enviados = r.dados.pedidos?.enviados ?? [];
  const pendentes = enviados.filter((p) => p.status === 'pendente');
  const respostas = enviados.filter((p) => p.status !== 'pendente');
  return (
    <>
      <div className="linha-h" style={{ flexWrap: 'wrap' }}><h1 className="cresce">Cuidadores(as)</h1>
        <button className="botao" onClick={() => { setConvite(null); setNovo(null); setPedido({ telefone: '', modalidade: 'mei', mensagem: '' }); }}>🔎 Chamar quem já usa o app</button>
        <button className="botao secundario" onClick={() => { setConvite(null); setPedido(null); setNovo({ tipo: 'cuidador', nome: '', telefone: '', modalidade: 'mei' }); }}>➕ Convidar cuidador(a)</button>
        {admin && <button className="botao secundario" onClick={() => { setConvite(null); setPedido(null); setNovo({ tipo: 'membro', nome: '', telefone: '', modalidade: '' }); }}>Convidar alguém da equipe</button>}
      </div>

      {recebidos.length > 0 && (
        <section className="pilha">
          <h2>Pedidos para entrar na rede</h2>
          {recebidos.map((p) => (
            <div key={p.id} className="alerta-linha pedido-empresa">
              <span className="cresce"><b>{p.cuidador?.nome}</b> <span className="sutil">celular final {p.cuidador?.telefone_final ?? '—'}</span><br />
                Quer entrar na rede como {p.modalidade === 'mei' ? 'MEI' : 'autônomo(a)'} · <span className="fraco">pediu {dia(p.criado_em).toLowerCase()}</span>
                {p.mensagem && <><br /><span className="sutil">“{p.mensagem}”</span></>}</span>
              <button className="botao pequeno" disabled={responder.enviando} onClick={() => void responder.executar(p, 'aceitar')}>Aceitar</button>
              <button className="botao secundario pequeno" disabled={responder.enviando} onClick={() => void responder.executar(p, 'recusar')}>Recusar</button>
            </div>
          ))}
          <ErroGeral erro={responder.erro} />
        </section>
      )}

      {pedido && (
        <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void pedir.executar(); }}>
          <h3 style={{ margin: 0 }}>Chamar para a rede quem já é cuidador(a) no app</h3>
          <p className="sutil" style={{ margin: 0 }}>Digite o celular. A pessoa recebe o pedido no app e decide. Até ela aceitar, a empresa vê só o primeiro nome. Se ainda não usa o app, mande um convite.</p>
          <div className="grade-2">
            <Campo rotulo="Celular do(a) cuidador(a)"><input value={pedido.telefone} onChange={(e) => setPedido({ ...pedido, telefone: e.target.value })} inputMode="tel" required /></Campo>
            <Campo rotulo="Forma de contratação" ajuda="MEI e autônomo(a): prestação de serviço. A agenda é dele(a); a empresa só envia propostas dentro da disponibilidade.">
              <select value={pedido.modalidade} onChange={(e) => setPedido({ ...pedido, modalidade: e.target.value })}>
                {['mei', 'autonomo', 'clt'].map((k) => <option key={k} value={k}>{k === 'clt' ? 'CLT (empregado(a) da empresa)' : MODALIDADES[k]}</option>)}
              </select>
            </Campo>
          </div>
          <Campo rotulo="Recado (opcional)"><input value={pedido.mensagem} onChange={(e) => setPedido({ ...pedido, mensagem: e.target.value })} maxLength={200} placeholder="Ex.: atendemos na Zona Sul" /></Campo>
          <ErroGeral erro={pedir.erro} />
          <div className="linha-h"><button className="botao" disabled={pedir.enviando}>Enviar pedido</button><button type="button" className="botao fantasma" onClick={() => setPedido(null)}>Cancelar</button></div>
        </form>
      )}

      {novo && (
        <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void criar.executar(); }}>
          <h3 style={{ margin: 0 }}>{novo.tipo === 'cuidador' ? 'Convidar cuidador(a)' : 'Convidar para a equipe da empresa'}</h3>
          <div className="grade-2">
            <Campo rotulo="Nome"><input value={novo.nome} onChange={(e) => setNovo({ ...novo, nome: e.target.value })} required /></Campo>
            <Campo rotulo="Celular (WhatsApp)"><input value={novo.telefone} onChange={(e) => setNovo({ ...novo, telefone: e.target.value })} inputMode="tel" /></Campo>
          </div>
          {novo.tipo === 'cuidador' && (
            <Campo rotulo="Forma de contratação" ajuda="MEI e autônomo(a): prestação de serviço. A agenda é dele(a); a empresa só envia propostas dentro da disponibilidade.">
              <select value={novo.modalidade} onChange={(e) => setNovo({ ...novo, modalidade: e.target.value })}>
                {['mei', 'autonomo', 'clt'].map((k) => <option key={k} value={k}>{k === 'clt' ? 'CLT (empregado(a) da empresa)' : MODALIDADES[k]}</option>)}
              </select>
            </Campo>
          )}
          <ErroGeral erro={criar.erro} />
          <div className="linha-h"><button className="botao" disabled={criar.enviando}>Gerar convite</button><button type="button" className="botao fantasma" onClick={() => setNovo(null)}>Cancelar</button></div>
        </form>
      )}
      {convite && (
        <div className="cartao linha-h" style={{ flexWrap: 'wrap' }}>
          <span className="cresce">Convite pronto — código <b style={{ letterSpacing: '.15em' }}>{convite.codigo}</b></span>
          <a className="botao" href={convite.whatsapp} target="_blank" rel="noreferrer">💬 Enviar pelo WhatsApp</a>
          <button className="botao secundario" onClick={() => void navigator.clipboard?.writeText(convite.link)}>Copiar link</button>
        </div>
      )}
      <table className="tabela responsiva">
        <thead><tr><th>Nome</th><th>Contratação</th><th>Agenda</th><th /></tr></thead>
        <tbody>
          {r.dados.cuidadores.length === 0 && pendentes.length === 0 && r.dados.convites_pendentes.length === 0 && <tr><td colSpan={4} className="sutil">Ninguém na rede ainda.</td></tr>}
          {r.dados.cuidadores.map((c) => (
            <tr key={c.id} style={c.status === 'encerrado' ? { opacity: 0.5 } : undefined}>
              <td><b>{c.nome}</b><br /><span className="sutil">{telefone(c.telefone)}</span></td>
              <td>{NOME_MOD(c.modalidade)}</td>
              <td>{c.status === 'encerrado' ? <span className="etiqueta">Fora da rede</span> : c.marcou_disponibilidade ? <span className="etiqueta ok">Marcou disponibilidade</span> : <span className="etiqueta">Sem disponibilidade marcada</span>}</td>
              <td className="linha-h">
                <button className="botao fantasma pequeno" onClick={() => navegar(`/e/${id}/conversas?c=${c.conversa_id}`)}>Conversar</button>
                {admin && c.status === 'ativo' && <button className="botao fantasma pequeno" onClick={async () => { if (confirm(`Encerrar ${c.nome} na rede da empresa?`)) { await api.encerrarCuidadorEmpresa(id, c.id); await r.recarregar(true); } }}>Encerrar</button>}
              </td>
            </tr>
          ))}
          {pendentes.map((p) => (
            <tr key={p.id}>
              <td>{p.cuidador?.nome}<br /><span className="sutil">celular final {p.cuidador?.telefone_final ?? '—'}</span></td>
              <td>{NOME_MOD(p.modalidade)}</td>
              <td><span className="etiqueta atencao">Pedido enviado, esperando resposta</span></td>
              <td><button className="botao fantasma pequeno" disabled={responder.enviando} onClick={() => void responder.executar(p, 'cancelar')}>Desfazer pedido</button></td>
            </tr>
          ))}
          {r.dados.convites_pendentes.map((c) => (
            <tr key={c.id}><td>{c.nome}</td><td>{c.modalidade ? NOME_MOD(c.modalidade) : ''}</td><td><span className="etiqueta">Convite {c.codigo}</span></td><td /></tr>
          ))}
        </tbody>
      </table>
      {respostas.length > 0 && (
        <section className="pilha">
          <h3 style={{ margin: 0 }}>Respostas dos últimos 30 dias</h3>
          <ul className="lista-respostas">
            {respostas.map((p) => (
              <li key={p.id}><span className={`etiqueta ${RESPOSTA[p.status]?.[0] ?? ''}`}>{RESPOSTA[p.status]?.[1] ?? p.status}</span> {p.cuidador?.nome} <span className="fraco">{p.respondido_em ? dia(p.respondido_em).toLowerCase() : ''}</span></li>
            ))}
          </ul>
        </section>
      )}
      <p className="fraco">Entrar na rede não é exclusividade: o(a) cuidador(a) pode estar em outras empresas, recusar pedidos e propostas e sair quando quiser. A empresa não vê a agenda completa de ninguém, só se a pessoa está disponível quando uma proposta é enviada.</p>
    </>
  );
}

function Clientes({ id, aberto }: { id: string; aberto?: string }) {
  const lanche = useLanche();
  const r = useCarregar(() => api.clientes(id), [id]);
  const [convite, setConvite] = useState<ConviteCriado | null>(null);
  const [modo, setModo] = useState<'nada' | 'pedir' | 'convidar'>('nada');
  const [nome, setNome] = useState('');
  const [tel, setTel] = useState('');
  const [pedido, setPedido] = useState({ contato: '', mensagem: '', permissoes: ['atendimentos', 'ver_registros'] });
  const criar = useAcao(async () => { setConvite(await api.convidarEmpresa(id, { tipo: 'cliente', nome, telefone: tel || undefined })); });
  const pedir = useAcao(async () => {
    const res = await api.pedirCliente(id, { contato: pedido.contato, permissoes: pedido.permissoes, mensagem: pedido.mensagem || undefined });
    lanche(res.status === 'aceito' ? `${res.gestor.nome} já tinha pedido: o cliente foi ligado.` : `Pedido enviado para ${res.gestor.nome}. Ele(a) decide no app.`);
    setModo('nada');
    setPedido({ contato: '', mensagem: '', permissoes: ['atendimentos', 'ver_registros'] });
    await r.recarregar(true);
  });
  const responder = useAcao(async (p: PedidoCliente, acao: 'aceitar' | 'recusar' | 'cancelar') => {
    await api.responderPedidoCliente(p.id, acao);
    lanche(acao === 'aceitar' ? `${p.pessoa?.apelido} agora é cliente.` : acao === 'recusar' ? 'Pedido recusado.' : 'Pedido desfeito.');
    await r.recarregar(true);
  });
  if (!r.dados) return <Carregando />;
  const cliente = aberto ? r.dados.clientes.find((c) => c.id === aberto) : undefined;
  if (aberto) {
    if (!cliente) return <><button className="botao fantasma pequeno" onClick={() => navegar(`/e/${id}/clientes`)}>← Clientes</button><Vazio e="🔒">Este cliente não está mais ligado à empresa.</Vazio></>;
    const veLinha = cliente.permissoes.includes('ver_registros');
    return (
      <>
        <div className="linha-h" style={{ flexWrap: 'wrap' }}>
          <button className="botao fantasma pequeno" onClick={() => navegar(`/e/${id}/clientes`)}>← Clientes</button>
          <span className="cresce" />
          <span className="sutil">Gestor(a): {cliente.gestor?.nome ?? '—'}</span>
        </div>
        <p className="aviso-empresa">A empresa vê: <b>{nomes(cliente.permissoes)}</b>. Quem decide é o(a) gestor(a). Os registros são do cuidado de {cliente.apelido}: não servem para controlar horário ou tarefas de cuidadores(as) MEI ou autônomos(as).</p>
        {veLinha ? <LinhaDoTempo pessoa={cliente} /> : <Vazio e="🔒">O(a) gestor(a) não liberou a linha do tempo de {cliente.apelido}. Ele(a) pode liberar em “Quem cuida”.</Vazio>}
      </>
    );
  }
  const recebidos = r.dados.pedidos?.recebidos ?? [];
  const enviados = r.dados.pedidos?.enviados ?? [];
  const pendentes = enviados.filter((p) => p.status === 'pendente');
  const respostas = enviados.filter((p) => p.status !== 'pendente');
  return (
    <>
      <div className="linha-h" style={{ flexWrap: 'wrap' }}><h1 className="cresce">Clientes</h1>
        <button className="botao" onClick={() => { setConvite(null); setModo('pedir'); }}>🔎 Pedir para acompanhar um cliente</button>
        <button className="botao secundario" onClick={() => { setConvite(null); setModo('convidar'); }}>Convidar o(a) gestor(a)</button>
      </div>

      {recebidos.length > 0 && (
        <section className="pilha">
          <h2>Pedidos de gestores(as)</h2>
          {recebidos.map((p) => (
            <div key={p.id} className="alerta-linha pedido-empresa">
              <span className="cresce"><b>{p.gestor?.nome}</b> quer que a empresa acompanhe <b>{p.pessoa?.apelido}</b><br />
                <span className="sutil">Libera: {nomes(p.permissoes)}</span>
                {p.mensagem && <><br /><span className="sutil">“{p.mensagem}”</span></>}</span>
              <button className="botao pequeno" disabled={responder.enviando} onClick={() => void responder.executar(p, 'aceitar')}>Aceitar</button>
              <button className="botao secundario pequeno" disabled={responder.enviando} onClick={() => void responder.executar(p, 'recusar')}>Recusar</button>
            </div>
          ))}
          <ErroGeral erro={responder.erro} />
        </section>
      )}

      {modo === 'pedir' && (
        <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void pedir.executar(); }}>
          <h3 style={{ margin: 0 }}>Pedir para acompanhar o cuidado de um cliente</h3>
          <p className="sutil" style={{ margin: 0 }}>Para quem já usa o app como gestor(a). Ele(a) recebe o pedido, escolhe a pessoa e decide o que a empresa vê, que pode ser menos do que você pedir.</p>
          <Campo rotulo="Celular ou e-mail do(a) gestor(a)"><input value={pedido.contato} onChange={(e) => setPedido({ ...pedido, contato: e.target.value })} required /></Campo>
          <p style={{ margin: 0 }}><b>O que a empresa gostaria de ver</b></p>
          <EscolherPermissoesEmpresa valor={pedido.permissoes} aoMudar={(p) => setPedido({ ...pedido, permissoes: p })} />
          <Campo rotulo="Recado (opcional)"><input value={pedido.mensagem} onChange={(e) => setPedido({ ...pedido, mensagem: e.target.value })} maxLength={200} /></Campo>
          <ErroGeral erro={pedir.erro} />
          <div className="linha-h"><button className="botao" disabled={pedir.enviando}>Enviar pedido</button><button type="button" className="botao fantasma" onClick={() => setModo('nada')}>Cancelar</button></div>
        </form>
      )}

      {modo === 'convidar' && (
        <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void criar.executar(); }}>
          <h3 style={{ margin: 0 }}>Convidar o(a) gestor(a) de um cliente</h3>
          <p className="sutil" style={{ margin: 0 }}>Para quem ainda não usa o app. Quem organiza o cuidado (gestor(a)) aceita o convite, escolhe a pessoa e decide o que a empresa pode ver.</p>
          <div className="grade-2">
            <Campo rotulo="Nome do(a) gestor(a)"><input value={nome} onChange={(e) => setNome(e.target.value)} required /></Campo>
            <Campo rotulo="Celular (WhatsApp)"><input value={tel} onChange={(e) => setTel(e.target.value)} inputMode="tel" /></Campo>
          </div>
          <ErroGeral erro={criar.erro} />
          {convite ? (
            <div className="linha-h" style={{ flexWrap: 'wrap' }}><span className="cresce">Código <b>{convite.codigo}</b></span>
              <a className="botao" href={convite.whatsapp} target="_blank" rel="noreferrer">💬 Enviar pelo WhatsApp</a></div>
          ) : <button className="botao" style={{ alignSelf: 'flex-start' }} disabled={criar.enviando}>Gerar convite</button>}
        </form>
      )}

      {r.dados.clientes.length === 0 && pendentes.length === 0 ? <Vazio e="🏡">Nenhum cliente ainda.</Vazio> : (
        <table className="tabela responsiva">
          <thead><tr><th>Pessoa</th><th>Gestor(a)</th><th>Cuidadores(as)</th><th>A empresa vê</th><th /></tr></thead>
          <tbody>
            {r.dados.clientes.map((c) => (
              <tr key={c.id}>
                <td><b>{c.apelido}</b><br /><span className="sutil">{c.idade} anos</span></td>
                <td>{c.gestor?.nome}<br /><span className="sutil">{telefone(c.gestor?.telefone)}</span></td>
                <td>{c.cuidadores.map((x) => x.nome).join(', ') || '—'}</td>
                <td className="sutil">{nomes(c.permissoes)}</td>
                <td>{c.permissoes.includes('ver_registros') && <button className="botao fantasma pequeno" onClick={() => navegar(`/e/${id}/clientes/${c.id}`)}>🕑 Linha do tempo</button>}</td>
              </tr>
            ))}
            {pendentes.map((p) => (
              <tr key={p.id}>
                <td className="sutil">—</td>
                <td>{p.gestor?.nome}</td>
                <td />
                <td><span className="etiqueta atencao">Pedido enviado, esperando o(a) gestor(a)</span></td>
                <td><button className="botao fantasma pequeno" disabled={responder.enviando} onClick={() => void responder.executar(p, 'cancelar')}>Desfazer pedido</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {respostas.length > 0 && (
        <section className="pilha">
          <h3 style={{ margin: 0 }}>Respostas dos últimos 30 dias</h3>
          <ul className="lista-respostas">
            {respostas.map((p) => <li key={p.id}><span className={`etiqueta ${p.status === 'aceito' ? 'ok' : ''}`}>{{ aceito: 'Aceitou', recusado: 'Não aceitou', cancelado: 'Pedido desfeito', expirado: 'Venceu sem resposta' }[p.status as string] ?? p.status}</span> {p.gestor?.nome}{p.pessoa ? ` · ${p.pessoa.apelido}` : ''}</li>)}
          </ul>
        </section>
      )}
    </>
  );
}

function Conversas({ id }: { id: string }) {
  const r = useCarregar(() => api.conversasEmpresa(id), [id]);
  const inicial = busca().get('c');
  const [aberta, setAberta] = useState<string | null>(inicial);
  useRepetir(() => void r.recarregar(true), 15000);
  if (!r.dados) return <Carregando />;
  const atual = r.dados.conversas.find((c) => c.conversa_id === aberta);
  return (
    <>
      <h1>Conversas com a equipe</h1>
      <div className={`conversas-empresa ${atual ? 'com-aberta' : ''}`}>
        <div className="lista-conversas">
          {r.dados.conversas.length === 0 && <p className="sutil" style={{ padding: '1em' }}>Sem conversas.</p>}
          {r.dados.conversas.map((c) => (
            <button key={c.conversa_id} className="conversa-linha" style={c.conversa_id === aberta ? { background: 'var(--verde-claro)' } : undefined} onClick={() => setAberta(c.conversa_id)}>
              <span className="cresce"><span className="nome">{c.com.nome}</span><span className="previa">{c.ultimo?.texto ?? ''}</span></span>
              {c.nao_lidas > 0 && <span className="bolinha">{c.nao_lidas}</span>}
            </button>
          ))}
        </div>
        <div className="chat-painel">
          {atual && <button className="botao fantasma pequeno so-celular voltar-lista" onClick={() => setAberta(null)}>← Conversas</button>}
          {atual ? <Direta key={atual.conversa_id} conversaId={atual.conversa_id} titulo={atual.com.nome} embutida /> : <Vazio e="💬">Escolha uma conversa.</Vazio>}
        </div>
      </div>
    </>
  );
}
