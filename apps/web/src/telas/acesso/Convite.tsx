import { useState } from 'react';
import { MODALIDADES, PAPEIS, FUNCOES } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar } from '../../rota';
import { useSessao } from '../../sessao';
import { useCarregar } from '../../hooks';
import { Carregando, ErroGeral, Ouvir, useAcao } from '../../componentes/ui';
import { PERFIL_ATIVO } from '../../rota';

/**
 * Aceitar convite já logado(a). Para cuidador(a) o fluxo completo fica no
 * onboarding dele(a) (/bem-vindo/cuidador); aqui tratamos Rede de Apoio,
 * profissional, equipe da empresa e "cliente" (gestor liga a pessoa à empresa).
 */
export function Convite({ codigo, aoTerminar }: { codigo: string; aoTerminar: () => void }) {
  const { recarregar, eu } = useSessao();
  const c = useCarregar(() => api.convite(codigo), [codigo]);
  const [pessoaId, setPessoaId] = useState('');
  const [permEmpresa, setPermEmpresa] = useState<string[]>(['atendimentos', 'plano_ver']);
  const aceitar = useAcao(async () => {
    const r = await api.aceitarConvite(codigo, c.dados?.tipo === 'empresa_cliente' ? { pessoa_id: pessoaId, permissoes: permEmpresa } : {});
    aoTerminar();
    await recarregar();
    if (r.empresa_id && c.dados?.tipo !== 'empresa_cliente' && c.dados?.tipo !== 'empresa_cuidador') { localStorage.setItem(PERFIL_ATIVO, `/e/${r.empresa_id}`); navegar(`/e/${r.empresa_id}`, true); return; }
    if (c.dados?.tipo === 'empresa_cuidador' || c.dados?.papel === 'cuidador' || c.dados?.atua_como_cuidador) { localStorage.setItem(PERFIL_ATIVO, '/c'); navegar('/c', true); return; }
    localStorage.setItem(PERFIL_ATIVO, '/g');
    navegar(r.pessoa_id ? `/g/${r.pessoa_id}` : '/g', true);
  });
  const recusar = () => { aoTerminar(); navegar('/', true); };

  if (c.carregando) return <Carregando />;
  if (c.erro) {
    return (
      <main className="acesso">
        <h1>Convite</h1>
        <ErroGeral erro={c.erro} />
        <button className="botao largo" onClick={recusar}>Continuar</button>
      </main>
    );
  }
  const d = c.dados!;
  // Cuidador(a) por convite do(a) gestor(a) ou da empresa: onboarding próprio (agenda, modo de leitura).
  if (d.tipo === 'empresa_cuidador' || d.papel === 'cuidador') {
    queueMicrotask(() => navegar('/bem-vindo/cuidador', true));
    return <Carregando />;
  }
  const alvo = d.pessoa ?? d.empresa;
  const papel = d.tipo === 'empresa_membro' ? 'equipe da empresa' : d.tipo === 'empresa_cliente' ? 'cliente da empresa' : PAPEIS[d.papel] ?? d.papel;
  const gerenciadas = (eu?.pessoas ?? []).filter((p) => p.papel === 'gestor');

  return (
    <main className="acesso">
      <span style={{ fontSize: '2.6em' }} aria-hidden>💌</span>
      <h1>{d.de?.nome} convidou você</h1>
      <p>Para participar como <b>{papel}</b>{alvo ? <> — <b>{alvo}</b></> : null}.</p>
      {d.modalidade && <p className="sutil">Forma de contratação: {MODALIDADES[d.modalidade] ?? d.modalidade}</p>}
      <Ouvir texto={`${d.de?.nome} convidou você para participar como ${papel}${alvo ? ', ' + alvo : ''}.`} />

      {d.tipo === 'empresa_cliente' && (
        <div className="cartao pilha">
          <b>Qual pessoa a empresa vai atender?</b>
          {gerenciadas.length === 0 && <p className="sutil">Cadastre primeiro a pessoa cuidada no seu perfil de gestor(a).</p>}
          <div className="escolhas">
            {gerenciadas.map((p) => (
              <button key={p.id} className="escolha" aria-pressed={pessoaId === p.id} onClick={() => setPessoaId(p.id)}>
                <span className="e">🧓</span><span><b>{p.apelido}</b></span>
              </button>
            ))}
          </div>
          <b>O que a empresa pode ver e fazer</b>
          <p className="sutil">Você pode mudar depois. Sem marcar, a empresa não vê os registros de saúde.</p>
          <div className="permissoes">
            {FUNCOES.filter((f) => ['atendimentos', 'plano_ver', 'ver_registros', 'mensagens', 'ficha_emergencia'].includes(f.chave)).map((f) => (
              <label key={f.chave} className="permissao">
                <input type="checkbox" checked={permEmpresa.includes(f.chave)}
                  onChange={(e) => setPermEmpresa(e.target.checked ? [...permEmpresa, f.chave] : permEmpresa.filter((x) => x !== f.chave))} />
                <span><b>{f.nome}</b><small>{f.desc}</small></span>
              </label>
            ))}
          </div>
        </div>
      )}
      <ErroGeral erro={aceitar.erro} />
      <button className="botao largo" disabled={aceitar.enviando || (d.tipo === 'empresa_cliente' && !pessoaId)} onClick={() => aceitar.executar()}>
        ✓ Aceitar
      </button>
      <button className="botao secundario largo" onClick={recusar}>Agora não</button>
    </main>
  );
}
