import { useEffect } from 'react';
import { casar, navegar, useCaminho } from './rota';
import { useSessao } from './sessao';
import { Carregando } from './componentes/ui';
import { Entrar } from './telas/acesso/Entrar';
import { Convite } from './telas/acesso/Convite';
import { EscolhaPerfil } from './telas/acesso/EscolhaPerfil';
import { BemVindo } from './telas/onboarding/BemVindo';
import { OnboardingGestor } from './telas/onboarding/Gestor';
import { OnboardingCuidador } from './telas/onboarding/Cuidador';
import { OnboardingEmpresa } from './telas/onboarding/Empresa';
import { AppCuidador } from './telas/cuidador/AppCuidador';
import { AppAcompanhamento } from './telas/acompanhamento/AppAcompanhamento';
import { AppEmpresa } from './telas/empresa/AppEmpresa';
import { MeusDados } from './telas/conta/MeusDados';

const CHAVE_CONVITE = 'cuidados:convite';
import { PERFIL_ATIVO } from './rota';

/**
 * Um app só, com modo por perfil (decisão de produto: o mesmo aparelho pode
 * ser de quem cuida e de quem acompanha). Cada perfil tem seu onboarding.
 */
export function App() {
  const caminho = useCaminho();
  const { eu, carregando } = useSessao();

  // Link de convite antes de entrar: guarda o código e pede o login.
  const convite = casar('/convite/:codigo', caminho);
  useEffect(() => {
    if (convite) sessionStorage.setItem(CHAVE_CONVITE, convite.codigo.toUpperCase());
  }, [convite?.codigo]); // eslint-disable-line react-hooks/exhaustive-deps

  if (carregando) return <Carregando texto="Abrindo…" />;

  if (!eu) {
    if (!caminho.startsWith('/entrar')) {
      queueMicrotask(() => navegar('/entrar', true));
      return <Carregando />;
    }
    return <Entrar />;
  }

  // ---------- Onboarding (cada perfil separado do app principal)
  const pendente = sessionStorage.getItem(CHAVE_CONVITE);
  if (caminho.startsWith('/bem-vindo/gestor')) return <OnboardingGestor />;
  if (caminho.startsWith('/bem-vindo/cuidador')) return <OnboardingCuidador codigoInicial={pendente ?? undefined} aoTerminar={() => sessionStorage.removeItem(CHAVE_CONVITE)} />;
  if (caminho.startsWith('/bem-vindo/empresa')) return <OnboardingEmpresa />;
  if (!eu.perfil_completo || !eu.aceitou_termos || caminho.startsWith('/bem-vindo')) {
    return <BemVindo convitePendente={pendente} />;
  }
  if (convite || pendente) {
    const codigo = convite?.codigo ?? pendente!;
    return <Convite codigo={codigo} aoTerminar={() => sessionStorage.removeItem(CHAVE_CONVITE)} />;
  }

  // ---------- Meus dados (o mesmo para todos os perfis)
  if (caminho === '/conta') return <MeusDados />;

  // ---------- Apps por perfil
  if (caminho === '/c' || caminho.startsWith('/c/')) return <AppCuidador />;
  if (caminho === '/g' || caminho.startsWith('/g/')) return <AppAcompanhamento />;
  if (caminho.startsWith('/e/')) return <AppEmpresa />;
  if (caminho === '/perfil') return <EscolhaPerfil />;

  // ---------- Início: abre o perfil certo
  const opcoes: string[] = [];
  if (eu.papeis.cuidador) opcoes.push('/c');
  if (eu.papeis.acompanha) opcoes.push('/g');
  eu.empresas.forEach((e) => opcoes.push(`/e/${e.id}`));
  const salvo = localStorage.getItem(PERFIL_ATIVO);
  const destino = salvo && opcoes.includes(salvo) ? salvo : opcoes.length === 1 ? opcoes[0] : opcoes.length === 0 ? '/bem-vindo' : '/perfil';
  queueMicrotask(() => navegar(destino, true));
  return <Carregando />;
}
