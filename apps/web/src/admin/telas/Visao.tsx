import { get } from '../api';
import { Barras, Carregando, Erro, NOME_ARRANJO, NOME_FAIXA, NOME_MODALIDADE, NOME_PERFIL, Secao, Titulo, num, useCarregar } from '../ui';

interface Painel {
  usuarios: { total: number; novos_7d: number; bloqueados: number; com_google: number; ativos_hoje: number; ativos_7d: number; ativos_30d: number };
  pessoas: { total: number; novas_7d: number; por_arranjo: Record<string, number>; por_faixa: Record<string, number> };
  vinculos: Record<string, number>;
  empresas: { total: number; cuidadores_por_modalidade: Record<string, number> };
  convites_pendentes: number;
  atividade_7d: { registros: number; mensagens: number; alertas_abertos: number };
  seguranca_24h: { entradas: number; entradas_recusadas: number; painel_recusadas: number; erros_servidor: number; negados: number };
  perfis_ativos_30d: Record<string, number>;
  serie_30d: { dia: string; ativos: number; entradas: number; registros: number; novas_contas: number }[];
}

export function Visao() {
  const { dados: d, erro, carregando, recarregar } = useCarregar(() => get<Painel>('/admin/painel'), []);
  return (
    <>
      <Titulo sub="Números da plataforma. Nenhum conteúdo de saúde aparece no painel." acoes={<button className="b b-secundario" onClick={recarregar}>Atualizar</button>}>
        Visão geral
      </Titulo>
      <Erro erro={erro} aoTentar={recarregar} />
      {carregando && !d && <Carregando />}
      {d && (
        <>
          <div className="numeros">
            <Numero valor={d.usuarios.ativos_hoje} rotulo="usaram hoje" detalhe={`${num(d.usuarios.ativos_7d)} em 7 dias, ${num(d.usuarios.ativos_30d)} em 30 dias`} />
            <Numero valor={d.usuarios.total} rotulo="contas" detalhe={`${num(d.usuarios.novos_7d)} novas em 7 dias`} href="#/usuarios" />
            <Numero valor={d.pessoas.total} rotulo="pessoas cuidadas" detalhe={`${num(d.pessoas.novas_7d)} novas em 7 dias`} href="#/pessoas" />
            <Numero valor={d.empresas.total} rotulo="empresas" href="#/empresas" />
            <Numero valor={d.convites_pendentes} rotulo="convites esperando aceite" href="#/convites" />
          </div>

          <div className="grade-2">
            <Secao titulo="Quem usou o app por dia">
              <Barras serie={d.serie_30d} campo="ativos" rotulo="Pessoas que usaram o app" />
            </Secao>
            <Secao titulo="Registros de cuidado recebidos por dia">
              <Barras serie={d.serie_30d} campo="registros" rotulo="Registros recebidos" />
            </Secao>
          </div>

          <div className="grade-3">
            <Secao titulo="Perfis em uso (30 dias)">
              <Lista itens={Object.entries(d.perfis_ativos_30d).map(([k, v]) => [NOME_PERFIL[k] ?? k, v])} unidade="pessoas" />
            </Secao>
            <Secao titulo="Vínculos ativos">
              <Lista itens={Object.entries(d.vinculos).map(([k, v]) => [NOME_PERFIL[k] ?? k, v])} />
            </Secao>
            <Secao titulo="Segurança nas últimas 24 h">
              <Lista itens={[
                ['Entradas no app', d.seguranca_24h.entradas],
                ['Entradas recusadas', d.seguranca_24h.entradas_recusadas, d.seguranca_24h.entradas_recusadas > 20 ? 'atencao' : undefined],
                ['Recusas no painel', d.seguranca_24h.painel_recusadas, d.seguranca_24h.painel_recusadas > 0 ? 'atencao' : undefined],
                ['Acessos negados (401/403)', d.seguranca_24h.negados],
                ['Erros do servidor', d.seguranca_24h.erros_servidor, d.seguranca_24h.erros_servidor > 0 ? 'perigo' : undefined],
              ]} link={{ texto: 'Ver erros no log', href: '#/logs?status=erro' }} />
            </Secao>
            <Secao titulo="Pessoas cuidadas por faixa">
              <Lista itens={Object.entries(d.pessoas.por_faixa).map(([k, v]) => [NOME_FAIXA[k] ?? k, v])} />
            </Secao>
            <Secao titulo="Arranjos de cuidado">
              <Lista itens={Object.entries(d.pessoas.por_arranjo).map(([k, v]) => [NOME_ARRANJO[k] ?? k, v])} />
            </Secao>
            <Secao titulo="Atividade em 7 dias">
              <Lista itens={[
                ['Registros de cuidado', d.atividade_7d.registros],
                ['Mensagens na conversa', d.atividade_7d.mensagens],
                ['Alertas sem resolver', d.atividade_7d.alertas_abertos, d.atividade_7d.alertas_abertos > 0 ? 'atencao' : undefined],
                ...Object.entries(d.empresas.cuidadores_por_modalidade).map(([k, v]) => [`Cuidadores(as) em empresas: ${NOME_MODALIDADE[k] ?? k}`, v] as [string, number]),
              ]} />
            </Secao>
          </div>
        </>
      )}
    </>
  );
}

function Numero({ valor, rotulo, detalhe, href }: { valor: number; rotulo: string; detalhe?: string; href?: string }) {
  const corpo = (
    <>
      <span className="numero-valor">{num(valor)}</span>
      <span className="numero-rotulo">{rotulo}</span>
      {detalhe && <span className="numero-detalhe">{detalhe}</span>}
    </>
  );
  return href ? <a className="numero" href={href}>{corpo}</a> : <div className="numero">{corpo}</div>;
}

function Lista({ itens, unidade, link }: { itens: [string, number, string?][]; unidade?: string; link?: { texto: string; href: string } }) {
  if (itens.length === 0) return <p className="vazio">Ainda sem dados.</p>;
  return (
    <>
      <ul className="lista-num">
        {itens.map(([k, v, tom]) => (
          <li key={k} className={tom ? `tom-${tom}` : undefined}><span>{k}</span><b>{num(v)}{unidade ? ` ${unidade}` : ''}</b></li>
        ))}
      </ul>
      {link && <a className="link-secao" href={link.href}>{link.texto}</a>}
    </>
  );
}
