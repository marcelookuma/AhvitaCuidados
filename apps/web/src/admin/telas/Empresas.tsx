import { get, qs, type Pagina } from '../api';
import { Busca, Carregando, Erro, Etiqueta, Gaveta, NOME_MODALIDADE, Paginacao, Pares, Secao, Tabela, Titulo, data, ir, num, telefone, useCarregar, useRota } from '../ui';

interface EmpresaResumo { id: string; nome: string; razao_social: string; cnpj: string; email: string | null; telefone: string | null; membros: number; cuidadores: number; clientes: number; criado_em: string }

export function Empresas() {
  const [rota, busca] = useRota();
  const aberta = rota.split('/')[2];
  const f = { q: busca.get('q') ?? '', pagina: busca.get('pagina') ?? '1' };
  const lista = useCarregar(() => get<Pagina<EmpresaResumo>>('/admin/empresas' + qs(f)), [f.q, f.pagina]);
  const volta = '#/empresas' + (busca.toString() ? '?' + busca.toString() : '');
  return (
    <>
      <Titulo sub="Empresas de cuidadores(as) que usam o app.">Empresas</Titulo>
      <div className="filtros"><Busca valor={f.q} rotulo="Buscar por nome ou CNPJ" /></div>
      <Erro erro={lista.erro} aoTentar={lista.recarregar} />
      {lista.carregando && !lista.dados && <Carregando />}
      {lista.dados && (
        <>
          <Tabela<EmpresaResumo> linhas={lista.dados.itens} aoAbrir={(e) => ir(`/empresas/${e.id}`)} vazio="Nenhuma empresa cadastrada ainda." colunas={[
            { titulo: 'Empresa', celula: (e) => <><b>{e.nome}</b><div className="fraco">{e.razao_social}</div></> },
            { titulo: 'CNPJ', celula: (e) => e.cnpj, classe: 'nowrap' },
            { titulo: 'Equipe', celula: (e) => num(e.membros), classe: 'num' },
            { titulo: 'Cuidadores(as)', celula: (e) => num(e.cuidadores), classe: 'num' },
            { titulo: 'Clientes', celula: (e) => num(e.clientes), classe: 'num' },
            { titulo: 'Desde', celula: (e) => data(e.criado_em), classe: 'nowrap' },
          ]} />
          <Paginacao total={lista.dados.total} pagina={lista.dados.pagina} porPagina={lista.dados.por_pagina} />
        </>
      )}
      {aberta && <DetalheEmpresa id={aberta} aoFechar={() => { location.hash = volta; }} />}
    </>
  );
}

interface Detalhe {
  empresa: { id: string; nome: string; razao_social: string; nome_fantasia: string | null; cnpj: string; email: string | null; telefone: string | null; criado_em: string };
  membros: { usuario_id: string; nome: string; papel: string; ativo: boolean; criado_em: string }[];
  cuidadores: { usuario_id: string; nome: string; modalidade: string; status: string; criado_em: string }[];
  clientes: { id: string; apelido: string }[];
  atendimentos_30d: Record<string, number>;
}

const NOME_STATUS_AT: Record<string, string> = { proposto: 'Propostos', aceito: 'Aceitos', recusado: 'Recusados', cancelado: 'Cancelados', expirado: 'Expirados' };

function DetalheEmpresa({ id, aoFechar }: { id: string; aoFechar: () => void }) {
  const { dados: d, erro, recarregar } = useCarregar(() => get<Detalhe>('/admin/empresas/' + id), [id]);
  return (
    <Gaveta titulo={d ? d.empresa.nome : 'Empresa'} aoFechar={aoFechar}>
      <Erro erro={erro} aoTentar={recarregar} />
      {!d && !erro && <Carregando />}
      {d && (
        <>
          <Pares itens={[
            ['Razão social', d.empresa.razao_social], ['Nome fantasia', d.empresa.nome_fantasia], ['CNPJ', d.empresa.cnpj],
            ['E-mail', d.empresa.email], ['Telefone', telefone(d.empresa.telefone)], ['Desde', data(d.empresa.criado_em)],
          ]} />
          <Secao titulo="Equipe da empresa">
            <ul className="lista-simples">
              {d.membros.map((m) => <li key={m.usuario_id}><a href={`#/usuarios/${m.usuario_id}`}>{m.nome}</a> <span className="fraco">{m.papel === 'admin' ? 'administração' : 'coordenação'}{!m.ativo && ', inativo(a)'}</span></li>)}
            </ul>
          </Secao>
          <Secao titulo="Cuidadores(as) da rede da empresa">
            <p className="fraco">Lista simples, sem horas nem contagem de atendimentos por pessoa: com MEI e autônomos, isso poderia indicar subordinação.</p>
            {d.cuidadores.length === 0 ? <p className="vazio">Nenhum(a) cuidador(a).</p> : (
              <ul className="lista-simples">
                {d.cuidadores.map((c) => <li key={c.usuario_id}><a href={`#/usuarios/${c.usuario_id}`}>{c.nome}</a> <Etiqueta>{NOME_MODALIDADE[c.modalidade] ?? c.modalidade}</Etiqueta>{c.status !== 'ativo' && <Etiqueta>Encerrado</Etiqueta>}</li>)}
              </ul>
            )}
          </Secao>
          <Secao titulo="Clientes">
            {d.clientes.length === 0 ? <p className="vazio">Nenhum cliente ligado.</p> : (
              <ul className="lista-inline">{d.clientes.map((c) => <li key={c.id}><a href={`#/pessoas/${c.id}`}>{c.apelido}</a></li>)}</ul>
            )}
          </Secao>
          <Secao titulo="Atendimentos criados em 30 dias">
            {Object.keys(d.atendimentos_30d).length === 0 ? <p className="vazio">Nenhum.</p> : (
              <ul className="lista-num">{Object.entries(d.atendimentos_30d).map(([k, v]) => <li key={k}><span>{NOME_STATUS_AT[k] ?? k}</span><b>{num(v)}</b></li>)}</ul>
            )}
          </Secao>
        </>
      )}
    </Gaveta>
  );
}
