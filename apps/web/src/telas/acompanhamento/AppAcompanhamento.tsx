import type { ReactNode } from 'react';
import { PAPEIS, type Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { casar, navegar, useCaminho } from '../../rota';
import { useSessao } from '../../sessao';
import { BotaoSair } from '../../componentes/sair';
import { useCarregar } from '../../hooks';
import { Carregando, Vazio } from '../../componentes/ui';
import { Conversa } from '../conversa/Conversa';
import { Hoje } from './Hoje';
import { LinhaDoTempo } from './LinhaDoTempo';
import { Remedios } from './Remedios';
import { Plano } from './Plano';
import { Equipe } from './Equipe';
import { Atendimentos } from './Atendimentos';
import { Dados } from './Dados';

const ABAS: { k: string; e: string; t: string; pode: (p: Pessoa) => boolean }[] = [
  { k: 'hoje', e: '🏠', t: 'Hoje', pode: () => true },
  { k: 'conversa', e: '💬', t: 'Conversa', pode: (p) => p.permissoes.includes('mensagens') || p.permissoes.includes('ver_registros') },
  { k: 'linha', e: '🕑', t: 'Linha do tempo', pode: (p) => p.permissoes.includes('ver_registros') },
  { k: 'remedios', e: '💊', t: 'Remédios', pode: (p) => p.permissoes.includes('remedios_ver') },
  { k: 'plano', e: '📋', t: 'Plano de cuidado', pode: (p) => p.permissoes.includes('plano_ver') || p.permissoes.includes('plano_editar') },
  { k: 'atendimentos', e: '📅', t: 'Atendimentos', pode: (p) => p.permissoes.includes('atendimentos') },
  { k: 'pessoas', e: '👥', t: 'Quem cuida', pode: () => true },
  { k: 'dados', e: '🔒', t: 'Cadastro e privacidade', pode: (p) => p.permissoes.includes('administrar') },
];

/** Painel de quem acompanha: gestor(a), Rede de Apoio e equipe de saúde (o que cada um vê depende das permissões). */
export function AppAcompanhamento() {
  const caminho = useCaminho();
  const { eu } = useSessao();
  const lista = useCarregar(() => api.pessoas());
  const m = casar('/g/:id/:aba', caminho) ?? casar('/g/:id', caminho);
  const pessoas = lista.dados?.pessoas.filter((p) => p.meu_papel !== 'empresa') ?? [];

  if (lista.carregando && !lista.dados) return <Carregando />;
  if (!m) {
    if (pessoas.length === 1) { queueMicrotask(() => navegar(`/g/${pessoas[0].id}`, true)); return <Carregando />; }
    return (
      <Moldura pessoas={pessoas} atual={null} temOutros={!!(eu?.papeis.cuidador || eu?.papeis.empresa)}>
        <main className="principal">
          <h1>Pessoas que você acompanha</h1>
          {pessoas.length === 0 && <Vazio e="🧭">Nenhuma pessoa ainda.<br /><button className="botao" style={{ marginTop: '1em' }} onClick={() => navegar('/bem-vindo/gestor')}>Cadastrar pessoa cuidada</button></Vazio>}
          <div className="indicadores">
            {pessoas.map((p) => (
              <button key={p.id} className="indicador" style={{ textAlign: 'left', cursor: 'pointer' }} onClick={() => navegar(`/g/${p.id}`)}>
                <div className="n" style={{ fontSize: '1.15em' }}>{p.apelido}</div><div className="r">{PAPEIS[p.meu_papel]} · {p.idade} anos</div>
              </button>
            ))}
          </div>
          <button className="botao secundario" style={{ alignSelf: 'flex-start' }} onClick={() => navegar('/bem-vindo/gestor')}>➕ Cadastrar outra pessoa</button>
        </main>
      </Moldura>
    );
  }

  const pessoa = pessoas.find((p) => p.id === m.id);
  if (!pessoa) return <Carregando texto="Pessoa não encontrada ou sem acesso." />;
  const abas = ABAS.filter((a) => a.pode(pessoa));
  const aba = abas.find((a) => a.k === (m.aba ?? 'hoje')) ?? abas[0];
  const recarregarPessoa = () => void lista.recarregar(true);

  return (
    <Moldura pessoas={pessoas} atual={pessoa} temOutros={!!(eu?.papeis.cuidador || eu?.papeis.empresa)}>
      <nav className="lateral" aria-label="Seções">
        <div className="titulo">{pessoa.apelido}</div>
        {abas.map((a) => (
          <button key={a.k} aria-current={a.k === aba.k ? 'page' : undefined} onClick={() => navegar(`/g/${pessoa.id}/${a.k}`)}>
            <span className="e" aria-hidden>{a.e}</span>{a.t}
          </button>
        ))}
      </nav>
      <main className="principal">
        {aba.k === 'hoje' && <Hoje pessoa={pessoa} />}
        {aba.k === 'conversa' && <div className="chat-painel"><Conversa pessoaId={pessoa.id} modo="painel" /></div>}
        {aba.k === 'linha' && <LinhaDoTempo pessoa={pessoa} />}
        {aba.k === 'remedios' && <Remedios pessoa={pessoa} />}
        {aba.k === 'plano' && <Plano pessoa={pessoa} aoSalvar={recarregarPessoa} />}
        {aba.k === 'atendimentos' && <Atendimentos pessoa={pessoa} />}
        {aba.k === 'pessoas' && <Equipe pessoa={pessoa} />}
        {aba.k === 'dados' && <Dados pessoa={pessoa} aoSalvar={recarregarPessoa} />}
      </main>
    </Moldura>
  );
}

function Moldura({ pessoas, atual, children, temOutros }: { pessoas: Pessoa[]; atual: Pessoa | null; children: ReactNode; temOutros: boolean }) {
  return (
    <div className="painel modo-painel">
      <header className="painel-topo">
        <div className="marca"><img src="/icone.svg" alt="" /><b>Ahvita Cuidados</b></div>
        {pessoas.length > 0 && (
          <select aria-label="Pessoa cuidada" value={atual?.id ?? ''} onChange={(e) => navegar(e.target.value ? `/g/${e.target.value}` : '/g')}>
            <option value="">Todas as pessoas</option>
            {pessoas.map((p) => <option key={p.id} value={p.id}>{p.apelido}</option>)}
          </select>
        )}
        <span className="cresce" />
        <button className="botao secundario pequeno" onClick={() => navegar('/conta')}>👤 Meus dados</button>
        {temOutros && <button className="botao secundario pequeno" onClick={() => navegar('/perfil')}>Trocar perfil</button>}
        <BotaoSair variante="pequeno" rotulo="Sair" />
      </header>
      <div className={atual ? 'painel-corpo' : ''}>{children}</div>
    </div>
  );
}
