import { useState } from 'react';
import { cpf as fmtCpf } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar } from '../../rota';
import { useSessao } from '../../sessao';
import { Campo, ErroGeral, Ouvir, useAcao } from '../../componentes/ui';

/**
 * Passo comum a todos os perfis: dados pessoais (CPF é a chave da conta),
 * aceite dos termos e escolha do caminho. Depois cada perfil segue o seu
 * onboarding (Gestor, Cuidador(a), Empresa), separado do app principal.
 */
export function BemVindo({ convitePendente }: { convitePendente: string | null }) {
  const { eu, recarregar, sair } = useSessao();
  const [nome, setNome] = useState(eu?.usuario.nome ?? '');
  const [apelido, setApelido] = useState(eu?.usuario.apelido ?? '');
  const [cpf, setCpf] = useState(eu?.usuario.cpf ? fmtCpf(eu.usuario.cpf) : '');
  const [termos, setTermos] = useState(eu?.aceitou_termos ?? false);
  const precisaPerfil = !eu?.perfil_completo || !eu?.aceitou_termos;

  const salvar = useAcao(async () => {
    await api.atualizarEu({ nome, apelido: apelido || undefined, cpf } as never);
    if (!eu?.aceitou_termos) await api.aceitarTermos();
    const e = await recarregar();
    if (convitePendente) navegar(`/convite/${convitePendente}`, true);
    else if (e && !e.papeis.cuidador && !e.papeis.acompanha && !e.papeis.empresa) navegar('/bem-vindo', true);
    else navegar('/', true);
  });

  if (precisaPerfil) {
    const c = salvar.erro?.campos ?? {};
    return (
      <main className="acesso">
        <div className="passos" aria-hidden><i className="feito" /><i /><i /></div>
        <h1>Vamos começar</h1>
        <Ouvir texto="Vamos começar. Escreva seu nome e seu CPF. O CPF identifica a sua conta." />
        <form className="pilha" onSubmit={(e) => { e.preventDefault(); void salvar.executar(); }}>
          <Campo rotulo="Nome completo" erro={c.nome}>
            <input value={nome} onChange={(e) => setNome(e.target.value)} autoComplete="name" required />
          </Campo>
          <Campo rotulo="Como gosta de ser chamado(a)" ajuda="Opcional" erro={c.apelido}>
            <input value={apelido} onChange={(e) => setApelido(e.target.value)} autoComplete="nickname" />
          </Campo>
          <Campo rotulo="CPF" ajuda="Identifica a sua conta. Não aparece para os outros." erro={c.cpf}>
            <input value={cpf} onChange={(e) => setCpf(fmtCpf(e.target.value))} inputMode="numeric" required placeholder="000.000.000-00" />
          </Campo>
          <label className="permissao" style={{ alignItems: 'flex-start' }}>
            <input type="checkbox" checked={termos} onChange={(e) => setTermos(e.target.checked)} style={{ width: '1.4em', height: '1.4em', marginTop: '.1em' }} />
            <span>Li e aceito os <a href="/termos.html" target="_blank" rel="noreferrer">Termos de uso</a> e a <a href="/privacidade.html" target="_blank" rel="noreferrer">Política de privacidade</a>.</span>
          </label>
          {!c.nome && !c.cpf && <ErroGeral erro={salvar.erro} />}
          <button className="botao largo" disabled={salvar.enviando || !termos || nome.trim().length < 3 || cpf.replace(/\D/g, '').length !== 11}>
            Continuar
          </button>
        </form>
        <button className="botao fantasma" onClick={() => void sair()}>Sair</button>
      </main>
    );
  }

  return (
    <main className="acesso">
      <div className="passos" aria-hidden><i className="feito" /><i className="feito" /><i /></div>
      <h1>Como você vai usar o app?</h1>
      <Ouvir texto="Como você vai usar o app? Organizo o cuidado de alguém. Recebi um convite. Ou sou de uma empresa de cuidadores." />
      <div className="escolhas">
        <button className="escolha" onClick={() => navegar('/bem-vindo/gestor')}>
          <span className="e">🧭</span>
          <span><b>Organizo o cuidado de alguém</b><small>Cadastro a pessoa cuidada e convido quem ajuda</small></span>
        </button>
        <button className="escolha" onClick={() => navegar('/bem-vindo/cuidador')}>
          <span className="e">💌</span>
          <span><b>Recebi um convite</b><small>Cuidador(a), Rede de Apoio ou equipe de saúde</small></span>
        </button>
        <button className="escolha" onClick={() => navegar('/bem-vindo/empresa')}>
          <span className="e">🏢</span>
          <span><b>Sou de uma empresa de cuidadores</b><small>Cadastro a empresa e a equipe</small></span>
        </button>
      </div>
      {(eu?.papeis.cuidador || eu?.papeis.acompanha || eu?.papeis.empresa) && (
        <button className="botao fantasma" onClick={() => navegar('/', true)}>Voltar para o app</button>
      )}
    </main>
  );
}
