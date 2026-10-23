import { navegar } from '../../rota';
import { useSessao } from '../../sessao';
import { BotaoSair } from '../../componentes/sair';
import { PERFIL_ATIVO } from '../../rota';

/** Quem tem mais de um papel escolhe como entrar (fica lembrado no aparelho). */
export function EscolhaPerfil() {
  const { eu } = useSessao();
  if (!eu) return null;
  const ir = (c: string) => { localStorage.setItem(PERFIL_ATIVO, c); navegar(c, true); };
  return (
    <main className="acesso">
      <h1>Como você quer entrar agora?</h1>
      <div className="escolhas">
        {eu.papeis.cuidador && (
          <button className="escolha" onClick={() => ir('/c')}>
            <span className="e">🤲</span><span><b>Cuidador(a)</b><small>Minhas conversas e minha agenda</small></span>
          </button>
        )}
        {eu.papeis.acompanha && (
          <button className="escolha" onClick={() => ir('/g')}>
            <span className="e">🧭</span><span><b>Acompanhar o cuidado</b><small>Gestão, Rede de Apoio ou equipe de saúde</small></span>
          </button>
        )}
        {eu.empresas.map((e) => (
          <button key={e.id} className="escolha" onClick={() => ir(`/e/${e.id}`)}>
            <span className="e">🏢</span><span><b>{e.nome}</b><small>Painel da empresa</small></span>
          </button>
        ))}
        <button className="escolha" onClick={() => navegar('/bem-vindo')}>
          <span className="e">➕</span><span><b>Outro papel</b><small>Cadastrar pessoa cuidada, empresa ou usar um convite</small></span>
        </button>
      </div>
      <div className="acesso-rodape"><BotaoSair /></div>
    </main>
  );
}
