import { useState } from 'react';
import { EscolherPermissoesEmpresa, LigarEmpresa, nomes } from './LigarEmpresa';
import { FUNCOES, MODALIDADES, PAPEIS, telefone, type ConviteCriado, type Pessoa, type Vinculo } from '@cuidados/core';
import { api } from '../../servicos';
import { useSessao } from '../../sessao';
import { useCarregar } from '../../hooks';
import { Campo, Carregando, ErroGeral, useAcao, useLanche } from '../../componentes/ui';

const ESPECIALIDADES = ['Enfermagem', 'Fisioterapia', 'Fonoaudiologia', 'Nutrição', 'Psicologia', 'Terapia ocupacional', 'Medicina'];

/**
 * Quem cuida: cuidadores(as), Rede de Apoio e equipe de saúde, com permissões
 * por funcionalidade definidas pelo(a) gestor(a).
 */
export function Equipe({ pessoa }: { pessoa: Pessoa }) {
  const { eu } = useSessao();
  const lanche = useLanche();
  const admin = pessoa.permissoes.includes('administrar');
  const v = useCarregar(() => api.vinculos(pessoa.id), [pessoa.id]);
  const [convidando, setConvidando] = useState(false);
  const [aberto, setAberto] = useState<string | null>(null);
  if (!v.dados) return <Carregando />;
  const ativos = v.dados.vinculos.filter((x) => x.status === 'ativo');
  const grupos: [string, Vinculo[]][] = [
    ['Gestão', ativos.filter((x) => x.papel === 'gestor')],
    ['Cuidadores(as)', ativos.filter((x) => x.papel === 'cuidador')],
    ['Rede de Apoio', ativos.filter((x) => x.papel === 'rede')],
    ['Equipe de saúde', ativos.filter((x) => x.papel === 'profissional')],
  ];

  return (
    <>
      <div className="linha-h"><h1 className="cresce">Quem cuida de {pessoa.apelido}</h1>
        {admin && !convidando && <button className="botao" onClick={() => setConvidando(true)}>➕ Convidar</button>}</div>
      {convidando && <Convidar pessoa={pessoa} aoFechar={() => { setConvidando(false); void v.recarregar(true); }} />}

      {grupos.map(([titulo, lista]) => lista.length > 0 && (
        <section key={titulo} className="pilha">
          <h2>{titulo}</h2>
          {lista.map((x) => (
            <div key={x.id} className="cartao pilha">
              <div className="linha-h" style={{ flexWrap: 'wrap' }}>
                <span className="cresce"><b>{x.usuario.nome ?? x.usuario.apelido}</b>{x.usuario.id === eu?.usuario.id && ' (você)'}<br />
                  <span className="sutil">{x.relacao ?? PAPEIS[x.papel]}{x.modalidade ? ` · ${MODALIDADES[x.modalidade]}` : ''}{x.especialidade ? ` · ${x.especialidade}` : ''}{x.atua_como_cuidador ? ' · também atua como cuidador(a)' : ''}{x.usuario.telefone ? ` · ${telefone(x.usuario.telefone)}` : ''}</span></span>
                {admin && x.usuario.id !== eu?.usuario.id && <button className="botao secundario pequeno" onClick={() => setAberto(aberto === x.id ? null : x.id)}>{aberto === x.id ? 'Fechar' : 'Permissões'}</button>}
              </div>
              {aberto === x.id && <EditarVinculo v={x} aoSalvar={() => { lanche('Salvo'); void v.recarregar(true); }} aoEncerrar={() => { setAberto(null); lanche('Acesso encerrado. O histórico fica guardado.'); void v.recarregar(true); }} />}
            </div>
          ))}
        </section>
      ))}

      {admin && v.dados.convites_pendentes.length > 0 && (
        <section className="pilha">
          <h2>Convites aguardando</h2>
          {v.dados.convites_pendentes.map((c) => (
            <div key={c.id} className="cartao linha-h" style={{ flexWrap: 'wrap' }}>
              <span className="cresce"><b>{c.nome}</b> · {PAPEIS[c.papel]} · código <b>{c.codigo}</b></span>
              <button className="botao fantasma pequeno" onClick={() => void navigator.clipboard?.writeText(`${location.origin}/convite/${c.codigo}`)}>Copiar link</button>
              <button className="botao fantasma pequeno" onClick={async () => { await api.revogarConvite(c.id); await v.recarregar(true); }}>Cancelar</button>
            </div>
          ))}
        </section>
      )}

      {!v.dados.empresa && admin && <LigarEmpresa pessoa={pessoa} aoLigar={() => void v.recarregar(true)} />}

      {v.dados.empresa && (
        <section className="cartao pilha">
          <h3 style={{ margin: 0 }}>🏢 Empresa: {v.dados.empresa.nome}</h3>
          <p className="sutil" style={{ margin: 0 }}>A empresa só vê o que você marcar. Ela nunca vê o horário de entrada e saída de cada cuidador(a).</p>
          {admin && <PermissoesEmpresa pessoa={pessoa} atuais={v.dados.empresa.permissoes} aoSalvar={() => { lanche('Salvo'); void v.recarregar(true); }} />}
          {admin && <button className="botao fantasma pequeno" style={{ alignSelf: 'flex-start', color: 'var(--alerta)' }} onClick={async () => {
            if (!confirm(`Encerrar a relação com ${v.dados!.empresa!.nome}?`)) return;
            await api.desvincularEmpresa(pessoa.id); await v.recarregar(true);
          }}>Encerrar relação com a empresa</button>}
        </section>
      )}
    </>
  );
}

function EditarVinculo({ v, aoSalvar, aoEncerrar }: { v: Vinculo; aoSalvar: () => void; aoEncerrar: () => void }) {
  const [perm, setPerm] = useState<string[]>(v.permissoes);
  const [h, setH] = useState(Math.floor(v.prazo_confirmacao_min / 60));
  const [m, setM] = useState(v.prazo_confirmacao_min % 60);
  const ehCuidador = v.papel === 'cuidador' || v.atua_como_cuidador;
  const salvar = useAcao(async () => {
    await api.atualizarVinculo(v.id, { permissoes: perm, ...(ehCuidador ? { prazo_confirmacao_min: h * 60 + m } : {}) });
    aoSalvar();
  });
  const encerrar = useAcao(async () => {
    if (!confirm('Encerrar o acesso? A pessoa deixa de ver a conversa. O histórico fica guardado.')) return;
    await api.encerrarVinculo(v.id);
    aoEncerrar();
  });
  return (
    <div className="pilha">
      <div className="permissoes">
        {FUNCOES.map((f) => (
          <label key={f.chave} className="permissao">
            <input type="checkbox" checked={perm.includes(f.chave)} onChange={(e) => setPerm(e.target.checked ? [...perm, f.chave] : perm.filter((x) => x !== f.chave))} />
            <span><b>{f.nome}</b><small>{f.desc}</small></span>
          </label>
        ))}
      </div>
      {ehCuidador && (
        <div className="campo"><span className="rotulo">Prazo para confirmar a ida (combinado entre as partes)</span>
          <div className="linha-h">
            <input type="number" min={0} max={48} value={h} onChange={(e) => setH(Number(e.target.value))} aria-label="Horas" style={{ width: '5em', border: '1.5px solid var(--linha)', borderRadius: 10, padding: '.5em' }} /> h
            <input type="number" min={0} max={59} step={5} value={m} onChange={(e) => setM(Number(e.target.value))} aria-label="Minutos" style={{ width: '5em', border: '1.5px solid var(--linha)', borderRadius: 10, padding: '.5em' }} /> min antes do início
          </div>
          <span className="ajuda">Serve só para organizar a cobertura. Sem resposta no prazo, você recebe um alerta — sem falta nem penalidade.</span>
        </div>
      )}
      <ErroGeral erro={salvar.erro ?? encerrar.erro} />
      <div className="linha-h">
        <button className="botao pequeno" disabled={salvar.enviando} onClick={() => void salvar.executar()}>Salvar</button>
        <span className="cresce" />
        <button className="botao fantasma pequeno" style={{ color: 'var(--alerta)' }} onClick={() => void encerrar.executar()}>Encerrar acesso</button>
      </div>
    </div>
  );
}

function PermissoesEmpresa({ pessoa, atuais, aoSalvar }: { pessoa: Pessoa; atuais: string[]; aoSalvar: () => void }) {
  const lanche = useLanche();
  const [perm, setPerm] = useState(atuais);
  const mudou = [...perm].sort().join() !== [...atuais].sort().join();
  const salvar = useAcao(async () => {
    await api.atualizarPessoa(pessoa.id, { empresa_permissoes: perm });
    lanche(`Salvo. A empresa vê: ${nomes(perm)}.`);
    aoSalvar();
  });
  return (
    <>
      <EscolherPermissoesEmpresa valor={perm} aoMudar={setPerm} />
      <ErroGeral erro={salvar.erro} />
      <button className="botao pequeno" style={{ alignSelf: 'flex-start' }} disabled={!mudou || salvar.enviando} onClick={() => void salvar.executar()}>{mudou ? 'Salvar o que a empresa vê' : 'Salvo'}</button>
    </>
  );
}

function Convidar({ pessoa, aoFechar }: { pessoa: Pessoa; aoFechar: () => void }) {
  const [d, setD] = useState({ papel: 'cuidador', nome: '', telefone: '', relacao: '', modalidade: 'mei', especialidade: 'Enfermagem', atua_como_cuidador: false });
  const [convite, setConvite] = useState<ConviteCriado | null>(null);
  const criar = useAcao(async () => {
    setConvite(await api.convidar(pessoa.id, {
      papel: d.papel, nome: d.nome, telefone: d.telefone || undefined, relacao: d.relacao || undefined,
      ...(d.papel === 'cuidador' ? { modalidade: d.modalidade } : {}),
      ...(d.papel === 'profissional' ? { especialidade: d.especialidade, atua_como_cuidador: d.atua_como_cuidador } : {}),
    }));
  });
  if (convite) {
    return (
      <div className="cartao pilha">
        <h3>Convite pronto — código <span style={{ letterSpacing: '.15em' }}>{convite.codigo}</span></h3>
        <p className="sutil">Vale por 7 dias. Mande pelo seu WhatsApp.</p>
        <div className="linha-h" style={{ flexWrap: 'wrap' }}>
          <a className="botao" href={convite.whatsapp} target="_blank" rel="noreferrer">💬 Enviar pelo WhatsApp</a>
          <button className="botao secundario" onClick={() => void navigator.clipboard?.writeText(convite.link)}>Copiar link</button>
          <button className="botao fantasma" onClick={aoFechar}>Fechar</button>
        </div>
      </div>
    );
  }
  return (
    <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void criar.executar(); }}>
      <h3>Convidar</h3>
      <div className="chips" role="group" aria-label="Papel">
        {['cuidador', 'rede', 'profissional'].map((p) => <button type="button" key={p} className="chip" aria-pressed={d.papel === p} onClick={() => setD({ ...d, papel: p })}>{PAPEIS[p]}</button>)}
      </div>
      <div className="grade-2">
        <Campo rotulo="Nome" erro={criar.erro?.campos?.nome}><input value={d.nome} onChange={(e) => setD({ ...d, nome: e.target.value })} required /></Campo>
        <Campo rotulo="Celular (WhatsApp)" erro={criar.erro?.campos?.telefone}><input value={d.telefone} onChange={(e) => setD({ ...d, telefone: e.target.value })} inputMode="tel" /></Campo>
      </div>
      {d.papel === 'cuidador' && (
        <Campo rotulo="Forma de contratação" ajuda="MEI e autônomo(a): a agenda é do(a) cuidador(a); você envia propostas dentro da disponibilidade dele(a), e ele(a) aceita ou recusa.">
          <select value={d.modalidade} onChange={(e) => setD({ ...d, modalidade: e.target.value })}>
            {Object.entries(MODALIDADES).filter(([k]) => k !== 'empresa').map(([k, t]) => <option key={k} value={k}>{t}</option>)}
          </select>
        </Campo>
      )}
      {d.papel === 'rede' && <Campo rotulo="Relação" ajuda="Ex.: Filha, Vizinho, Amiga"><input value={d.relacao} onChange={(e) => setD({ ...d, relacao: e.target.value })} /></Campo>}
      {d.papel === 'profissional' && (
        <>
          <Campo rotulo="Especialidade"><select value={d.especialidade} onChange={(e) => setD({ ...d, especialidade: e.target.value })}>{ESPECIALIDADES.map((x) => <option key={x}>{x}</option>)}</select></Campo>
          <label className="permissao"><input type="checkbox" checked={d.atua_como_cuidador} onChange={(e) => setD({ ...d, atua_como_cuidador: e.target.checked })} />
            <span><b>Também vai atuar como cuidador(a)</b><small>Ex.: enfermeiro(a) em atendimento. Usa a tela do(a) cuidador(a) com os itens da especialidade.</small></span></label>
        </>
      )}
      <p className="fraco">Cada papel começa com permissões mínimas. Você ajusta depois em "Permissões".</p>
      <ErroGeral erro={criar.erro && !Object.keys(criar.erro.campos).length ? criar.erro : null} />
      <div className="linha-h"><button className="botao" disabled={criar.enviando}>Gerar convite</button><button type="button" className="botao fantasma" onClick={aoFechar}>Cancelar</button></div>
    </form>
  );
}
