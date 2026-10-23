import { hora, dia, iniciais, type ConversaResumo, type ItemFeed } from '@cuidados/core';
import { api, fila } from '../../servicos';
import { navegar } from '../../rota';
import { useSessao } from '../../sessao';
import { useCarregar, useOnline, useRepetir } from '../../hooks';
import { guardar, ler } from '../../armazenamento';
import { Ouvir, Vazio, corDe } from '../../componentes/ui';
import { AbasCuidador } from './AppCuidador';
import { useEffect, useState } from 'react';

function previa(u: ItemFeed | null, euId: string): string {
  if (!u) return 'Toque para abrir';
  if (u.tipo_item === 'registro') return `${u.autor?.id === euId ? '' : u.autor?.nome + ': '}${u.rotulo}${u.detalhe ? ' · ' + u.detalhe : ''}`;
  if (u.tipo === 'sistema') return u.texto ?? '';
  const quem = u.autor?.id === euId ? '' : `${u.autor?.nome}: `;
  if (u.tipo === 'audio') return `${quem}🎤 Áudio`;
  if (u.tipo === 'foto') return `${quem}📷 Foto`;
  return `${quem}${u.texto ?? ''}`;
}

/** Lista de conversas, como no WhatsApp: cada pessoa cuidada é uma conversa. */
export function Conversas() {
  const { eu } = useSessao();
  const online = useOnline();
  const [pendentes, setPendentes] = useState(fila.pendentes().length);
  const [guardadas, setGuardadas] = useState<ConversaResumo[] | null>(null);
  const r = useCarregar(async () => {
    const d = await api.inicio();
    void guardar('inicio', d.conversas);
    return d;
  });
  useEffect(() => { void ler<ConversaResumo[]>('inicio').then((g) => g && setGuardadas(g)); }, []);
  useEffect(() => fila.ouvir((p) => setPendentes(p.length)), []);
  useRepetir(() => void r.recarregar(true), 15000);
  const conversas = r.dados?.conversas ?? guardadas ?? [];
  const nome = eu?.usuario.apelido || eu?.usuario.nome?.split(' ')[0] || '';

  return (
    <div className="tela modo-cuidador">
      <header className="topo">
        <h1>Ahvita Cuidados</h1>
        <button className="sos" onClick={() => conversas[0]?.pessoa_id && navegar(`/c/p/${conversas[0].pessoa_id}`)} aria-label="Emergência: abrir a conversa e o SOS">🚑 SOS</button>
      </header>
      {!online && <div className="faixa off">📵 Sem internet. Pode continuar: tudo fica guardado.</div>}
      {pendentes > 0 && <div className="faixa off">🕓 {pendentes} {pendentes === 1 ? 'envio guardado' : 'envios guardados'} no celular. Vão sozinhos.</div>}
      <div className="rolagem">
        <div className="conteudo" style={{ paddingBottom: '.4em' }}>
          <div className="linha-h"><span className="cresce">Olá, <b>{nome}</b>. Toque no nome da pessoa para registrar e conversar.</span>
            <Ouvir texto={`Olá, ${nome}. Toque no nome da pessoa para registrar e conversar.`} /></div>
        </div>
        {(r.dados?.pedidos_rede ?? 0) > 0 && (
          <button className="conversa-linha" onClick={() => navegar('/c/empresas')}>
            <span className="avatar" style={{ background: '#E4DDF5' }}>🏢</span>
            <span className="cresce"><span className="nome">Uma empresa quer você na rede dela</span><span className="previa">Você decide se aceita</span></span>
            <span className="fim"><span className="bolinha">{r.dados!.pedidos_rede}</span></span>
          </button>
        )}
        {(r.dados?.propostas_abertas ?? 0) > 0 && (
          <button className="conversa-linha" onClick={() => navegar('/c/agenda')}>
            <span className="avatar" style={{ background: '#F7E7C9' }}>📅</span>
            <span className="cresce"><span className="nome">Novas propostas de atendimento</span><span className="previa">Você decide se aceita</span></span>
            <span className="fim"><span className="bolinha">{r.dados!.propostas_abertas}</span></span>
          </button>
        )}
        <div className="lista-conversas">
          {conversas.map((c) => (
            <button key={c.conversa_id} className="conversa-linha" onClick={() => navegar(c.tipo === 'cuidado' ? `/c/p/${c.pessoa_id}` : `/c/d/${c.conversa_id}`)}>
              <span className="avatar" style={{ background: c.tipo === 'direta' ? '#E4DDF5' : corDe(c.pessoa_id ?? c.conversa_id) }}>{c.tipo === 'direta' ? '🏢' : iniciais(c.nome)}</span>
              <span className="cresce">
                <span className="nome">{c.nome}{c.em_servico_desde && <span className="selo-servico">em serviço</span>}</span>
                <span className="previa">{previa(c.ultimo, eu!.usuario.id)}</span>
              </span>
              <span className="fim">
                <span className={`quando ${c.nao_lidas ? 'quente' : ''}`}>{c.ultimo ? (dia(c.ultimo.em) === 'Hoje' ? hora(c.ultimo.em) : dia(c.ultimo.em)) : ''}</span>
                {c.nao_lidas > 0 && <span className="bolinha" aria-label={`${c.nao_lidas} novidades`}>{c.nao_lidas}</span>}
              </span>
            </button>
          ))}
        </div>
        {!r.carregando && conversas.length === 0 && (
          <Vazio e="💬">Ainda não há conversas. Quando alguém convidar você para cuidar de uma pessoa, ela aparece aqui.</Vazio>
        )}
        {conversas.length > 0 && (
          <div className="dica-lista"><span aria-hidden>💡</span><span>Cada pessoa que você cuida é uma conversa, como no WhatsApp. Quem acompanha o cuidado vê o que você manda.</span></div>
        )}
      </div>
      <AbasCuidador atual="conversas" propostas={r.dados?.propostas_abertas ?? 0} />
    </div>
  );
}
