import { useState } from 'react';
import type { Pessoa, Remedio } from '@cuidados/core';
import { api } from '../../servicos';
import { useCarregar } from '../../hooks';
import { Campo, Carregando, ErroGeral, useAcao, useLanche } from '../../componentes/ui';

/** Remédios e horários. Remédio novo já entra nos lembretes do(a) cuidador(a). */
export function Remedios({ pessoa }: { pessoa: Pessoa }) {
  const lanche = useLanche();
  const r = useCarregar(() => api.remedios(pessoa.id), [pessoa.id]);
  const edita = pessoa.permissoes.includes('remedios_editar');
  const [form, setForm] = useState<{ id?: string; nome: string; dose: string; como_dar: string; horarios: string[] } | null>(null);
  const salvar = useAcao(async () => {
    if (!form) return;
    const d = { nome: form.nome, dose: form.dose, como_dar: form.como_dar, horarios: form.horarios.filter(Boolean) };
    if (form.id) await api.atualizarRemedio(form.id, d); else await api.criarRemedio(pessoa.id, d);
    setForm(null);
    lanche('Remédio salvo. Já aparece nos lembretes.');
    await r.recarregar(true);
  });
  const suspender = async (x: Remedio) => {
    await api.atualizarRemedio(x.id, { ativo: !x.ativo });
    lanche(x.ativo ? 'Remédio suspenso' : 'Remédio reativado');
    await r.recarregar(true);
  };
  if (!r.dados) return <Carregando />;
  return (
    <>
      <div className="linha-h"><h1 className="cresce">Remédios</h1>
        {edita && !form && <button className="botao" onClick={() => setForm({ nome: '', dose: '', como_dar: '', horarios: ['08:00'] })}>➕ Novo remédio</button>}</div>
      {form && (
        <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void salvar.executar(); }}>
          <h3>{form.id ? 'Mudar remédio' : 'Novo remédio'}</h3>
          <div className="grade-2">
            <Campo rotulo="Nome" erro={salvar.erro?.campos?.nome}><input value={form.nome} onChange={(e) => setForm({ ...form, nome: e.target.value })} required /></Campo>
            <Campo rotulo="Dose"><input value={form.dose} onChange={(e) => setForm({ ...form, dose: e.target.value })} placeholder="50 mg" /></Campo>
          </div>
          <Campo rotulo="Como dar" ajuda="Em linguagem simples. Ex.: 1 comprimido branco, depois do café"><input value={form.como_dar} onChange={(e) => setForm({ ...form, como_dar: e.target.value })} /></Campo>
          <div className="campo"><span className="rotulo">Horários</span>
            <div className="chips">
              {form.horarios.map((h, i) => (
                <span key={i} className="linha-h" style={{ gap: '.2em' }}>
                  <input type="time" value={h} aria-label={`Horário ${i + 1}`} onChange={(e) => setForm({ ...form, horarios: form.horarios.map((x, k) => (k === i ? e.target.value : x)) })} style={{ border: '1.5px solid var(--linha)', borderRadius: 10, padding: '.4em' }} />
                  <button type="button" className="botao fantasma pequeno" aria-label="Tirar horário" onClick={() => setForm({ ...form, horarios: form.horarios.filter((_, k) => k !== i) })}>✕</button>
                </span>
              ))}
              <button type="button" className="chip" onClick={() => setForm({ ...form, horarios: [...form.horarios, '12:00'] })}>＋ horário</button>
            </div>
            {salvar.erro?.campos?.horarios && <span className="erro">{salvar.erro.campos.horarios}</span>}
          </div>
          <ErroGeral erro={salvar.erro && !Object.keys(salvar.erro.campos).length ? salvar.erro : null} />
          <div className="linha-h"><button className="botao" disabled={salvar.enviando}>Salvar</button><button type="button" className="botao fantasma" onClick={() => setForm(null)}>Cancelar</button></div>
          <p className="fraco">⚠️ Só cadastre o que foi receitado. Procedimentos de enfermagem (ex.: injeção) não são delegados ao(à) cuidador(a).</p>
        </form>
      )}
      <table className="tabela responsiva">
        <thead><tr><th>Remédio</th><th>Horários</th><th>Como dar</th><th /></tr></thead>
        <tbody>
          {r.dados.remedios.length === 0 && <tr><td colSpan={4} className="sutil">Nenhum remédio cadastrado.</td></tr>}
          {r.dados.remedios.map((x) => (
            <tr key={x.id} style={x.ativo ? undefined : { opacity: 0.55 }}>
              <td><b>{x.nome}</b> {x.dose}{!x.ativo && <span className="etiqueta" style={{ marginLeft: '.4em' }}>Suspenso</span>}</td>
              <td>{x.horarios.join(' · ')}</td>
              <td className="sutil">{x.como_dar}</td>
              <td>{edita && <div className="linha-h">
                <button className="botao fantasma pequeno" onClick={() => setForm({ id: x.id, nome: x.nome, dose: x.dose ?? '', como_dar: x.como_dar ?? '', horarios: x.horarios })}>Mudar</button>
                <button className="botao fantasma pequeno" onClick={() => void suspender(x)}>{x.ativo ? 'Suspender' : 'Reativar'}</button>
              </div>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
}
