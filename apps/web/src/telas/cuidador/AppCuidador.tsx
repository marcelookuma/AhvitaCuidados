import { casar, navegar, useCaminho } from '../../rota';
import { Conversa } from '../conversa/Conversa';
import { Conversas } from './Conversas';
import { Agenda } from './Agenda';
import { Eu } from './Eu';
import { Direta } from './Direta';

/** App do(a) cuidador(a) — versão Conversa. Três abas: Conversas, Minha agenda, Eu. */
export function AppCuidador() {
  const caminho = useCaminho();
  const chat = casar('/c/p/:id', caminho);
  if (chat) return <Conversa pessoaId={chat.id} modo="cuidador" aoVoltar={() => navegar('/c')} />;
  const direta = casar('/c/d/:id', caminho);
  if (direta) return <Direta conversaId={direta.id} aoVoltar={() => navegar('/c')} />;
  if (caminho === '/c/agenda') return <Agenda />;
  if (caminho === '/c/eu') return <Eu />;
  return <Conversas />;
}

export function AbasCuidador({ atual, propostas = 0 }: { atual: 'conversas' | 'agenda' | 'eu'; propostas?: number }) {
  const b = (k: typeof atual, e: string, t: string, c: string, n = 0) => (
    <button aria-current={atual === k ? 'page' : undefined} onClick={() => navegar(c)}>
      <span className="e" aria-hidden>{e}</span>{t}{n > 0 && <span className="bolinha">{n}</span>}
    </button>
  );
  return (
    <nav className="abas-baixo" aria-label="Menu">
      {b('conversas', '💬', 'Conversas', '/c')}
      {b('agenda', '📅', 'Minha agenda', '/c/agenda', propostas)}
      {b('eu', '🙂', 'Eu', '/c/eu')}
    </nav>
  );
}
