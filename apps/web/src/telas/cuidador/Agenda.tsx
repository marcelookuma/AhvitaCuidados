import { useEffect, useState } from 'react';
import { DIAS_CURTOS, horaCurta, periodo, reais, type Agenda as TAgenda, type Atendimento, type Janela } from '@cuidados/core';
import { api } from '../../servicos';
import { useCarregar } from '../../hooks';
import { Carregando, ErroGeral, Ouvir, Topo, useLanche } from '../../componentes/ui';
import { AbasCuidador } from './AppCuidador';

const DIAS_LONGOS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

/**
 * Minha agenda — é do(a) cuidador(a). Só ele(a) marca a disponibilidade e
 * bloqueia dias. Propostas chegam dentro dela e ele(a) decide.
 */
export function Agenda() {
  const lanche = useLanche();
  const a = useCarregar(() => api.agenda());
  const [janelas, setJanelas] = useState<Janela[]>([]);
  const [mudou, setMudou] = useState(false);
  const [bloq, setBloq] = useState({ de: '', ate: '' });
  const [erro, setErro] = useState<string | null>(null);
  useEffect(() => { if (a.dados) { setJanelas(a.dados.disponibilidade); setMudou(false); } }, [a.dados]);

  if (!a.dados) return <div className="tela"><Topo titulo="Minha agenda" /><Carregando /><AbasCuidador atual="agenda" /></div>;
  const ag: TAgenda = a.dados;

  const alternarDia = (d: number) => {
    setMudou(true);
    if (janelas.some((j) => j.dia_semana === d)) setJanelas(janelas.filter((j) => j.dia_semana !== d));
    else setJanelas([...janelas, { dia_semana: d, inicio: '07:00', fim: '19:00' }].sort((x, y) => x.dia_semana - y.dia_semana));
  };
  const mudarHora = (d: number, campo: 'inicio' | 'fim', v: string) => { setMudou(true); setJanelas(janelas.map((j) => (j.dia_semana === d ? { ...j, [campo]: v } : j))); };
  const salvar = async () => {
    try { a.setDados(await api.salvarDisponibilidade(janelas)); lanche('✅ Agenda salva'); } catch (e: any) { setErro(e.message); }
  };
  const responder = async (x: Atendimento, aceitar: boolean) => {
    try { await api.responderAtendimento(x.id, aceitar); lanche(aceitar ? '✅ Combinado! Está na sua agenda.' : 'Proposta recusada. Tudo bem!'); await a.recarregar(true); }
    catch (e: any) { lanche(e.message); await a.recarregar(true); }
  };
  const propostas = [...ag.propostas_abertas, ...ag.atendimentos.filter((x) => x.status === 'proposto')];
  const combinados = ag.atendimentos.filter((x) => x.status === 'aceito');

  return (
    <div className="tela modo-cuidador">
      <Topo titulo="Minha agenda" direita={<Ouvir texto="Minha agenda. A agenda é sua. Você marca quando atende e decide se aceita as propostas." />} />
      <div className="rolagem conteudo">
        {propostas.length > 0 && (
          <section className="pilha">
            <h2 style={{ fontSize: '1.05em', margin: 0 }}>Propostas para você</h2>
            <p className="sutil" style={{ margin: 0 }}>Você decide se aceita. Recusar não tem nenhuma consequência.</p>
            {propostas.map((x) => (
              <div key={x.id} className="cartao-chat">
                <div className="cab"><span>📅 {x.pessoa.apelido} · {x.empresa ?? x.solicitante?.nome}</span></div>
                <div className="corpo"><span className="e" aria-hidden>🗓️</span><div><b>{periodo(x.inicio, x.fim)}</b>
                  <span>{x.valor_centavos ? `${reais(x.valor_centavos)} pelo atendimento` : 'Valor a combinar'}</span>
                  {x.empresa && <span>Quem aceitar primeiro fica com o atendimento.</span>}</div></div>
                <div className="acoes"><button className="sim" onClick={() => void responder(x, true)}>✓ Aceitar</button><button className="nao" onClick={() => void responder(x, false)}>Recusar</button></div>
              </div>
            ))}
          </section>
        )}

        <section className="cartao">
          <h3>Meus próximos atendimentos</h3>
          {combinados.length === 0 && <p className="sutil">Nenhum atendimento combinado.</p>}
          {combinados.map((x) => {
            const d = new Date(x.inicio);
            return (
              <div key={x.id} className="dia-linha">
                <span className="calendario"><small>{DIAS_CURTOS[d.getDay()]}</small><b>{d.getDate()}</b></span>
                <span className="cresce"><b>{x.pessoa.apelido}</b><br /><span className="sutil">{horaCurta(x.inicio)} às {horaCurta(x.fim)}</span></span>
                {x.confirmacao === 'vou' ? <span className="etiqueta ok">✓ Confirmado</span>
                  : x.confirmacao === 'nao_vou' ? <span className="etiqueta alerta">Avisei que não vou</span>
                  : <span className="etiqueta">Combinado</span>}
              </div>
            );
          })}
          <p className="fraco" style={{ marginTop: '.6em' }}>🔔 Antes de cada atendimento chega um aviso para você confirmar, no prazo combinado entre as partes. Se não puder ir, é só avisar.</p>
        </section>

        <section className="cartao pilha">
          <h3 style={{ margin: 0 }}>Quando eu atendo</h3>
          <p className="sutil" style={{ margin: 0 }}>A agenda é sua. Toque nos dias para marcar ou desmarcar.</p>
          <div className="semana" role="group" aria-label="Dias em que atendo">
            {DIAS_CURTOS.map((n, i) => {
              const on = janelas.some((j) => j.dia_semana === i);
              return <button key={n} aria-pressed={on} onClick={() => alternarDia(i)}><span aria-hidden>{on ? '✓' : '—'}</span>{n}</button>;
            })}
          </div>
          {janelas.map((j) => (
            <div key={j.dia_semana} className="janela">
              <b>{DIAS_LONGOS[j.dia_semana]}{j.fim <= j.inicio ? ' 🌙 (termina no dia seguinte)' : ''}</b>
              <input type="time" aria-label={`${DIAS_LONGOS[j.dia_semana]}: das`} value={j.inicio} onChange={(e) => mudarHora(j.dia_semana, 'inicio', e.target.value)} />
              <span>às</span>
              <input type="time" aria-label={`${DIAS_LONGOS[j.dia_semana]}: até`} value={j.fim} onChange={(e) => mudarHora(j.dia_semana, 'fim', e.target.value)} />
            </div>
          ))}
          <ErroGeral erro={erro} />
          {mudou && <button className="botao largo" onClick={() => void salvar()}>Salvar minha agenda</button>}
        </section>

        <section className="cartao pilha">
          <h3 style={{ margin: 0 }}>🚫 Bloquear dias</h3>
          <p className="sutil" style={{ margin: 0 }}>Ninguém vê o motivo. Nesses dias não chega proposta.</p>
          <div className="grade-2 fixa">
            <label className="campo"><span className="rotulo">De</span><input type="date" value={bloq.de} onChange={(e) => setBloq({ ...bloq, de: e.target.value })} /></label>
            <label className="campo"><span className="rotulo">Até</span><input type="date" value={bloq.ate} onChange={(e) => setBloq({ ...bloq, ate: e.target.value })} /></label>
          </div>
          <button className="botao secundario largo" disabled={!bloq.de || !bloq.ate || bloq.ate < bloq.de} onClick={async () => {
            await api.bloquear({ inicio: new Date(bloq.de + 'T00:00').toISOString(), fim: new Date(bloq.ate + 'T23:59').toISOString() });
            setBloq({ de: '', ate: '' }); lanche('Dias bloqueados'); await a.recarregar(true);
          }}>Bloquear</button>
          {ag.bloqueios.map((b) => (
            <div key={b.id} className="linha-h"><span className="cresce">🚫 {new Date(b.inicio).toLocaleDateString('pt-BR')} a {new Date(b.fim).toLocaleDateString('pt-BR')}</span>
              <button className="botao fantasma pequeno" onClick={async () => { await api.desbloquear(b.id); await a.recarregar(true); }}>Desbloquear</button></div>
          ))}
        </section>
      </div>
      <AbasCuidador atual="agenda" propostas={propostas.length} />
    </div>
  );
}
