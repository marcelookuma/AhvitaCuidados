import { useState } from 'react';
import type { Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { Campo, ErroGeral, useAcao, useLanche } from '../../componentes/ui';

/**
 * Plano de cuidado: descreve o que a pessoa PRECISA. Como organizar o serviço
 * fica com o(a) cuidador(a) — não é lista de tarefas com horário (risco trabalhista).
 */
export function Plano({ pessoa, aoSalvar }: { pessoa: Pessoa; aoSalvar: () => void }) {
  const lanche = useLanche();
  const edita = pessoa.permissoes.includes('plano_editar');
  const [pl, setPl] = useState({
    texto: pessoa.plano?.texto ?? '', importante: pessoa.plano?.importante ?? '',
    cuidados: pessoa.plano?.cuidados ?? [], agua_meta_copos: pessoa.plano?.agua_meta_copos ?? 0,
  });
  const salvar = useAcao(async () => {
    await api.atualizarPessoa(pessoa.id, { plano: pl });
    lanche('Plano salvo. Aparece em "Como cuidar".');
    aoSalvar();
  });
  const copo = pessoa.idade < 12 ? 100 : 200;
  return (
    <>
      <h1>Plano de cuidado</h1>
      <p className="sutil" style={{ margin: 0 }}>O que {pessoa.apelido} precisa e as orientações de saúde. Como organizar o serviço fica com o(a) cuidador(a).</p>
      <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void salvar.executar(); }}>
        <Campo rotulo="📌 Importante (aparece fixado no topo da conversa)" ajuda="Uma frase. Ex.: Alergia a dipirona.">
          <input value={pl.importante} onChange={(e) => setPl({ ...pl, importante: e.target.value })} maxLength={200} disabled={!edita} />
        </Campo>
        <Campo rotulo="Como cuidar" ajuda="Frases curtas e simples. O(a) cuidador(a) pode ouvir este texto.">
          <textarea value={pl.texto} onChange={(e) => setPl({ ...pl, texto: e.target.value })} rows={6} disabled={!edita} />
        </Campo>
        <div className="campo"><span className="rotulo">Cuidados específicos</span>
          {pl.cuidados.map((c, i) => (
            <div key={i} className="grade-2" style={{ gridTemplateColumns: '1fr 2fr auto' }}>
              <input aria-label="Título" value={c.titulo} disabled={!edita} placeholder="Ex.: Pele" onChange={(e) => setPl({ ...pl, cuidados: pl.cuidados.map((x, k) => (k === i ? { ...x, titulo: e.target.value } : x)) })} />
              <input aria-label="Como" value={c.como} disabled={!edita} placeholder="Ex.: Hidratar as pernas depois do banho" onChange={(e) => setPl({ ...pl, cuidados: pl.cuidados.map((x, k) => (k === i ? { ...x, como: e.target.value } : x)) })} />
              {edita && <button type="button" className="botao fantasma pequeno" aria-label="Tirar" onClick={() => setPl({ ...pl, cuidados: pl.cuidados.filter((_, k) => k !== i) })}>✕</button>}
            </div>
          ))}
          {edita && <button type="button" className="chip" style={{ alignSelf: 'flex-start' }} onClick={() => setPl({ ...pl, cuidados: [...pl.cuidados, { titulo: '', como: '' }] })}>＋ cuidado</button>}
        </div>
        <Campo rotulo={`Meta de água por dia (copos de ${copo} ml)`} ajuda={`${pl.agua_meta_copos || 0} copos ≈ ${((pl.agua_meta_copos || 0) * copo / 1000).toFixed(1).replace('.', ',')} litro(s)`}>
          <input type="number" min={0} max={20} value={pl.agua_meta_copos} disabled={!edita} onChange={(e) => setPl({ ...pl, agua_meta_copos: Number(e.target.value) })} />
        </Campo>
        <ErroGeral erro={salvar.erro} />
        {edita && <button className="botao" style={{ alignSelf: 'flex-start' }} disabled={salvar.enviando}>Salvar plano</button>}
      </form>
    </>
  );
}
