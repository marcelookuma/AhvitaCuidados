import { useState } from 'react';
import { api } from '../../servicos';
import { navegar, PERFIL_ATIVO } from '../../rota';
import { useSessao } from '../../sessao';
import { Campo, ErroGeral, useAcao } from '../../componentes/ui';

/** Onboarding da empresa: dados da empresa; quem cadastra vira administração. */
export function OnboardingEmpresa() {
  const { recarregar } = useSessao();
  const [d, setD] = useState({ razao_social: '', nome_fantasia: '', cnpj: '', telefone: '', email: '' });
  const criar = useAcao(async () => {
    const e = await api.criarEmpresa(d);
    await recarregar();
    localStorage.setItem(PERFIL_ATIVO, `/e/${e.id}`);
    navegar(`/e/${e.id}`, true);
  });
  const er = criar.erro?.campos ?? {};
  const m = (k: keyof typeof d) => (e: { target: { value: string } }) => setD({ ...d, [k]: e.target.value });
  return (
    <main className="acesso">
      <h1>Cadastrar a empresa</h1>
      <p className="sutil">Depois você convida os(as) cuidadores(as) e os clientes.</p>
      <form className="pilha" onSubmit={(e) => { e.preventDefault(); void criar.executar(); }}>
        <Campo rotulo="Razão social" erro={er.razao_social}><input value={d.razao_social} onChange={m('razao_social')} required /></Campo>
        <Campo rotulo="Nome fantasia" erro={er.nome_fantasia}><input value={d.nome_fantasia} onChange={m('nome_fantasia')} /></Campo>
        <Campo rotulo="CNPJ" erro={er.cnpj}><input value={d.cnpj} onChange={m('cnpj')} inputMode="numeric" required /></Campo>
        <div className="grade-2">
          <Campo rotulo="Telefone"><input value={d.telefone} onChange={m('telefone')} inputMode="tel" /></Campo>
          <Campo rotulo="E-mail"><input value={d.email} onChange={m('email')} type="email" /></Campo>
        </div>
        {!Object.keys(er).length && <ErroGeral erro={criar.erro} />}
        <button className="botao largo" disabled={criar.enviando}>Cadastrar</button>
        <button type="button" className="botao fantasma" onClick={() => navegar('/bem-vindo', true)}>Voltar</button>
      </form>
    </main>
  );
}
