import { useEffect, useState } from 'react';
import { isoLocal, periodo, reais, type Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { useCarregar } from '../../hooks';
import { Campo, Carregando, ErroGeral, useAcao, useLanche } from '../../componentes/ui';

/**
 * Propor atendimentos. É sempre uma PROPOSTA: o(a) cuidador(a) aceita ou recusa.
 * Para MEI/autônomo, só dentro da disponibilidade que ele(a) marcou — a tela avisa antes.
 */
export function Atendimentos({ pessoa }: { pessoa: Pessoa }) {
  const lanche = useLanche();
  const lista = useCarregar(() => api.atendimentos(pessoa.id), [pessoa.id]);
  const vinc = useCarregar(() => api.vinculos(pessoa.id), [pessoa.id]);
  const cuidadores = (vinc.dados?.vinculos ?? []).filter((v) => v.status === 'ativo' && (v.papel === 'cuidador' || v.atua_como_cuidador));
  const amanha = new Date(Date.now() + 86400_000).toISOString().slice(0, 10);
  const [f, setF] = useState({ cuidador_id: '', data: amanha, inicio: '07:00', fim: '19:00', valor: '', observacao: '' });
  const [disp, setDisp] = useState<{ disponivel: boolean; motivo?: string } | null>(null);
  const fimIso = () => {
    const ini = isoLocal(f.data, f.inicio);
    let fim = isoLocal(f.data, f.fim);
    if (fim <= ini) fim = new Date(new Date(fim).getTime() + 86400_000).toISOString(); // noturno
    return { ini, fim };
  };
  useEffect(() => {
    if (!f.cuidador_id) { setDisp(null); return; }
    const { ini, fim } = fimIso();
    const t = setTimeout(() => api.checarDisponivel(pessoa.id, f.cuidador_id, ini, fim).then(setDisp).catch(() => setDisp(null)), 300);
    return () => clearTimeout(t);
  }, [f.cuidador_id, f.data, f.inicio, f.fim]); // eslint-disable-line react-hooks/exhaustive-deps

  const propor = useAcao(async () => {
    const { ini, fim } = fimIso();
    const v = f.valor ? Math.round(parseFloat(f.valor.replace(/\./g, '').replace(',', '.')) * 100) : undefined;
    await api.proporAtendimento(pessoa.id, { cuidador_id: f.cuidador_id, inicio: ini, fim, valor_centavos: v, observacao: f.observacao || undefined });
    lanche('Proposta enviada. Aguarde o aceite.');
    await lista.recarregar(true);
  });

  if (!lista.dados || !vinc.dados) return <Carregando />;
  const futuros = lista.dados.atendimentos.filter((a) => ['proposto', 'aceito'].includes(a.status) && new Date(a.fim) > new Date());
  const passados = lista.dados.atendimentos.filter((a) => !futuros.includes(a)).slice(0, 15);
  return (
    <>
      <h1>Atendimentos</h1>
      {cuidadores.length === 0 ? <p className="sutil">Convide um(a) cuidador(a) em "Quem cuida" para propor atendimentos.</p> : (
        <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void propor.executar(); }}>
          <h3 style={{ margin: 0 }}>Propor atendimento</h3>
          <div className="grade-2">
            <Campo rotulo="Cuidador(a)"><select value={f.cuidador_id} onChange={(e) => setF({ ...f, cuidador_id: e.target.value })} required>
              <option value="">Escolha</option>{cuidadores.map((c) => <option key={c.usuario.id} value={c.usuario.id}>{c.usuario.nome}</option>)}</select></Campo>
            <Campo rotulo="Dia"><input type="date" value={f.data} min={new Date().toISOString().slice(0, 10)} onChange={(e) => setF({ ...f, data: e.target.value })} required /></Campo>
            <Campo rotulo="Início"><input type="time" value={f.inicio} onChange={(e) => setF({ ...f, inicio: e.target.value })} required /></Campo>
            <Campo rotulo="Fim" ajuda={f.fim <= f.inicio ? '🌙 Termina no dia seguinte' : undefined}><input type="time" value={f.fim} onChange={(e) => setF({ ...f, fim: e.target.value })} required /></Campo>
            <Campo rotulo="Valor pelo atendimento (R$)" ajuda="Opcional"><input value={f.valor} onChange={(e) => setF({ ...f, valor: e.target.value })} inputMode="decimal" placeholder="180,00" /></Campo>
            <Campo rotulo="Observação" ajuda="Opcional"><input value={f.observacao} onChange={(e) => setF({ ...f, observacao: e.target.value })} maxLength={255} /></Campo>
          </div>
          {disp && (disp.disponivel
            ? <p className="sutil" style={{ margin: 0, color: 'var(--verde-escuro)' }}>✓ Dentro da agenda do(a) cuidador(a).</p>
            : <div className="alerta-linha cobertura"><span className="cresce">{disp.motivo}</span></div>)}
          <ErroGeral erro={propor.erro} />
          <button className="botao" style={{ alignSelf: 'flex-start' }} disabled={propor.enviando || !f.cuidador_id || disp?.disponivel === false}>Enviar proposta</button>
          <p className="fraco" style={{ margin: 0 }}>O(a) cuidador(a) aceita ou recusa, sem nenhuma consequência. O app não registra falta nem aplica penalidade.</p>
        </form>
      )}
      <section className="pilha">
        <h2>Próximos</h2>
        {futuros.length === 0 && <p className="sutil">Nenhum atendimento.</p>}
        <table className="tabela responsiva">
          <tbody>{futuros.map((a) => (
            <tr key={a.id}>
              <td><b>{a.cuidador?.nome}</b><br /><span className="sutil">{periodo(a.inicio, a.fim)}{a.valor_centavos ? ` · ${reais(a.valor_centavos)}` : ''}</span></td>
              <td>{a.status === 'proposto' ? <span className="etiqueta">Aguardando resposta</span>
                : a.confirmacao === 'vou' ? <span className="etiqueta ok">✓ Confirmou</span>
                : a.confirmacao === 'nao_vou' ? <span className="etiqueta alerta">Avisou que não vai{a.confirmacao_motivo ? `: ${a.confirmacao_motivo}` : ''}</span>
                : a.sem_resposta_no_prazo ? <span className="etiqueta atencao">Sem resposta no prazo</span>
                : <span className="etiqueta">Combinado</span>}</td>
              <td><button className="botao fantasma pequeno" onClick={async () => { if (confirm('Cancelar este atendimento?')) { await api.cancelarAtendimento(a.id); await lista.recarregar(true); } }}>Cancelar</button></td>
            </tr>
          ))}</tbody>
        </table>
      </section>
      {passados.length > 0 && (
        <section className="pilha">
          <h2>Anteriores e respondidos</h2>
          <table className="tabela responsiva"><tbody>{passados.map((a) => (
            <tr key={a.id}><td>{a.cuidador?.nome ?? '—'}</td><td className="sutil">{periodo(a.inicio, a.fim)}</td>
              <td><span className="etiqueta">{({ recusado: 'Recusado', cancelado: 'Cancelado', aceito: 'Realizado', expirado: 'Expirado', proposto: 'Sem resposta' } as Record<string, string>)[a.status]}</span></td></tr>
          ))}</tbody></table>
        </section>
      )}
    </>
  );
}
