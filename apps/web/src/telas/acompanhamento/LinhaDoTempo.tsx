import { useState } from 'react';
import { dia, hora, itens as catalogo, refeicaoDaHora, type Pessoa, type Registro } from '@cuidados/core';
import { api } from '../../servicos';
import { useCarregar } from '../../hooks';
import { Carregando, ErroGeral, Vazio } from '../../componentes/ui';
import { Foto } from '../../componentes/midia';

const FILTROS: { k: string; t: string }[] = [
  { k: '', t: 'Tudo' }, { k: 'alertas', t: '⚠️ Alertas' }, { k: 'remedio', t: '💊 Remédios' }, { k: 'comida', t: '🍽️ Comida' },
  { k: 'agua', t: '💧 Água' }, { k: 'humor', t: '🙂 Como está' }, { k: 'sinais', t: '🌡️ Sinais' }, { k: 'algo', t: '⚠️ Ocorrências' },
  { k: 'pele', t: '🩹 Pele' }, { k: 'comportamento', t: '😵‍💫 Comportamento' }, { k: 'saida,visita', t: '🌳 Saídas e visitas' },
  { k: 'presencas', t: '▶️ Presença' },
];

/** Linha do tempo com filtros. Registros desfeitos continuam visíveis (riscados): nada é apagado. */
export function LinhaDoTempo({ pessoa }: { pessoa: Pessoa }) {
  const [filtro, setFiltro] = useState('');
  const [mais, setMais] = useState<Registro[]>([]);
  const cat = catalogo({ apelido: pessoa.apelido, sexo: pessoa.sexo, refeicao: refeicaoDaHora() });
  const icone = (t: string) => Object.values(cat).find((i) => i.tipo === t)?.e ?? (t === 'nota' ? '📝' : '📌');
  const r = useCarregar(() => {
    setMais([]);
    if (filtro === 'presencas') return api.linhaDoTempo(pessoa.id, { presencas: '1', tipo: 'nenhum' });
    if (filtro === 'alertas') return api.linhaDoTempo(pessoa.id, { alertas: '1' });
    return api.linhaDoTempo(pessoa.id, { tipo: filtro || undefined });
  }, [pessoa.id, filtro]);
  const regs = [...(r.dados?.registros ?? []), ...mais];

  let diaAnt = '';
  return (
    <>
      <h1>Linha do tempo</h1>
      <div className="chips" role="group" aria-label="Filtrar">
        {/* ⚖️ Empresa não vê presença (horário de entrada e saída de cuidadores(as)): sem esse filtro. */}
        {FILTROS.filter((f) => f.k !== 'presencas' || pessoa.meu_papel !== 'empresa').map((f) => <button key={f.k} className="chip" aria-pressed={filtro === f.k} onClick={() => setFiltro(f.k)}>{f.t}</button>)}
      </div>
      <ErroGeral erro={r.erro} />
      {r.carregando && <Carregando />}
      {filtro === 'presencas' && r.dados && (
        <table className="tabela responsiva">
          <thead><tr><th>Cuidador(a)</th><th>Início</th><th>Fim</th></tr></thead>
          <tbody>{r.dados.presencas.map((p) => (
            <tr key={p.id}><td>{p.cuidador.nome}</td><td>{dia(p.inicio)} {hora(p.inicio)}</td><td>{p.fim ? `${dia(p.fim)} ${hora(p.fim)}` : 'Em serviço'}</td></tr>
          ))}</tbody>
        </table>
      )}
      {filtro === 'presencas' && <p className="fraco">Registro de presença: só início e fim, como comprovação do serviço prestado. O app não soma horas nem aponta atrasos.</p>}
      {filtro !== 'presencas' && !r.carregando && regs.length === 0 && <Vazio e="🕑">Nada registrado{filtro ? ' com esse filtro' : ''}.</Vazio>}
      {filtro !== 'presencas' && (
        <div className="tempo">
          {regs.map((x) => {
            const d = dia(x.em);
            const sep = d !== diaAnt;
            diaAnt = d;
            return (
              <div key={x.id}>
                {sep && <div className="fraco" style={{ fontWeight: 800, margin: '.4em 0 .3em -1.6em' }}>{d}</div>}
                <div className={`tempo-item ${x.alerta ? 'alerta' : ''} ${x.anulado ? 'anulado' : ''}`} data-e={icone(x.tipo)}>
                  <div className="h">{hora(x.em)} · {x.autor?.nome}{x.anulado ? ' · desfeito' : ''}</div>
                  <b style={x.anulado ? { textDecoration: 'line-through' } : undefined}>{x.rotulo}</b>{x.detalhe && <span> — {x.detalhe}</span>}
                  {x.midia_id && pessoa.permissoes.includes('fotos') && <div style={{ maxWidth: 220, marginTop: '.4em' }}><Foto midiaId={x.midia_id} alt={x.rotulo} /></div>}
                </div>
              </div>
            );
          })}
        </div>
      )}
      {filtro !== 'presencas' && r.dados?.tem_mais && (
        <button className="botao secundario" onClick={async () => {
          const ult = regs[regs.length - 1];
          if (!ult) return;
          const nova = await api.linhaDoTempo(pessoa.id, { tipo: filtro && filtro !== 'alertas' ? filtro : undefined, alertas: filtro === 'alertas' ? '1' : undefined, antes: ult.em });
          setMais([...mais, ...nova.registros]);
        }}>Ver mais antigos</button>
      )}
    </>
  );
}
