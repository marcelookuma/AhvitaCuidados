import { hora, periodo, type Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar } from '../../rota';
import { useCarregar, useRepetir } from '../../hooks';
import { Carregando, ErroGeral } from '../../componentes/ui';

const ROTULO_ALERTA: Record<string, string> = {
  sem_confirmacao: '🔔 Sem resposta no prazo combinado',
  nao_vou: '🙏 Avisou que não vai',
  proposta_recusada: '📅 Proposta recusada',
  remedio_recusado: '💊 Remédio não tomado',
  registro: '⚠️ Atenção',
};

/** Painel do dia: alertas, quem está em serviço, indicadores e próximos atendimentos. */
export function Hoje({ pessoa }: { pessoa: Pessoa }) {
  const p = useCarregar(() => api.painel(pessoa.id), [pessoa.id]);
  useRepetir(() => void p.recarregar(true), 20000);
  if (!p.dados) return p.erro ? <ErroGeral erro={p.erro} /> : <Carregando />;
  const d = p.dados;
  const h = d.hoje;
  return (
    <>
      <div>
        <h1>{pessoa.apelido}</h1>
        <p className="sutil" style={{ margin: '.2em 0 0' }}>{pessoa.idade} anos · {d.em_servico.length ? d.em_servico.map((s) => `${s.cuidador.nome} em serviço desde ${hora(s.desde)}`).join(' · ') : 'Ninguém em serviço agora'}</p>
      </div>

      {d.alertas.length > 0 && (
        <section className="pilha">
          <h2>Alertas</h2>
          {d.alertas.map((a) => (
            <div key={a.id} className={`alerta-linha ${['sem_confirmacao', 'nao_vou', 'proposta_recusada'].includes(a.tipo) ? 'cobertura' : ''}`}>
              <span className="cresce"><b>{ROTULO_ALERTA[a.tipo] ?? 'Alerta'}</b> · <span className="fraco">{hora(a.em)}</span><br />{a.texto}</span>
              <button className="botao secundario pequeno" onClick={async () => { await api.resolverAlerta(a.id); await p.recarregar(true); }}>Ciente</button>
            </div>
          ))}
        </section>
      )}

      {h && (
        <section className="indicadores" aria-label="Hoje">
          <div className={`indicador ${h.remedios_nao_dados ? 'alerta' : 'ok'}`}><div className="n">{h.remedios_dados}/{h.remedios_previstos || '—'}</div><div className="r">remédios dados{h.remedios_nao_dados ? ` · ${h.remedios_nao_dados} não tomados` : ''}</div></div>
          <div className="indicador"><div className="n">{h.agua_copos}{h.agua_meta ? `/${h.agua_meta}` : ''}</div><div className="r">copos de água</div></div>
          <div className="indicador"><div className="n">{h.refeicoes}</div><div className="r">refeições registradas</div></div>
          <div className="indicador"><div className="n" style={{ fontSize: '1.1em', paddingTop: '.3em' }}>{h.humor ?? '—'}</div><div className="r">como está (percepção de quem cuida)</div></div>
          <div className="indicador"><div className="n">{h.registros}</div><div className="r">registros hoje</div></div>
        </section>
      )}

      <div className="linha-h" style={{ flexWrap: 'wrap' }}>
        {pessoa.permissoes.includes('mensagens') && <button className="botao" onClick={() => navegar(`/g/${pessoa.id}/conversa`)}>💬 Abrir conversa</button>}
        {pessoa.permissoes.includes('registrar') && <button className="botao secundario" onClick={() => navegar(`/g/${pessoa.id}/conversa`)}>＋ Registrar um cuidado</button>}
      </div>

      {d.proximos_atendimentos.length > 0 && (
        <section className="cartao">
          <h3>Próximos atendimentos</h3>
          {d.proximos_atendimentos.map((a) => (
            <div key={a.id} className="linha-h" style={{ padding: '.4em 0', borderBottom: '1px solid var(--linha-fraca)' }}>
              <span className="cresce"><b>{a.cuidador?.nome ?? 'Proposta aberta'}</b> · {periodo(a.inicio, a.fim)}</span>
              {a.status === 'proposto' ? <span className="etiqueta">Aguardando resposta</span>
                : a.confirmacao === 'vou' ? <span className="etiqueta ok">Confirmou</span>
                : a.confirmacao === 'nao_vou' ? <span className="etiqueta alerta">Não vai</span>
                : a.sem_resposta_no_prazo ? <span className="etiqueta atencao">Sem resposta no prazo</span>
                : <span className="etiqueta">Combinado</span>}
            </div>
          ))}
        </section>
      )}
    </>
  );
}
