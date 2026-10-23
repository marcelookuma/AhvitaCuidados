import { get, post, qs, type Pagina } from '../api';
import { AcaoComMotivo, Carregando, Erro, Etiqueta, NOME_PERFIL, Paginacao, Seletor, Tabela, Titulo, dataHora, pode, useAdmin, useCarregar, useRota } from '../ui';

interface Convite {
  id: string; codigo: string; tipo: string; papel: string | null; pessoa_id: string | null; pessoa: string | null; empresa_id: string | null; empresa: string | null;
  destino_nome: string | null; criado_por: string | null; criado_em: string; expira_em: string; status: string;
}

const NOME_TIPO: Record<string, string> = { vinculo: 'Para cuidar de alguém', empresa_cuidador: 'Cuidador(a) da empresa', empresa_membro: 'Equipe da empresa', empresa_cliente: 'Cliente da empresa' };
const TOM: Record<string, 'ok' | 'atencao' | 'neutro' | 'perigo'> = { pendente: 'atencao', aceito: 'ok', expirado: 'neutro', revogado: 'perigo' };

export function Convites() {
  const { admin } = useAdmin();
  const [, busca] = useRota();
  const f = { status: busca.get('status') ?? 'pendente', pagina: busca.get('pagina') ?? '1' };
  const lista = useCarregar(() => get<Pagina<Convite>>('/admin/convites' + qs(f)), [f.status, f.pagina]);
  return (
    <>
      <Titulo sub="O código dá acesso a dados de saúde, por isso aparece pela metade. Revogue convites enviados ao número errado.">Convites</Titulo>
      <div className="filtros">
        <Seletor rotulo="Situação" chave="status" valor={f.status} opcoes={[['pendente', 'Esperando aceite'], ['aceito', 'Aceitos'], ['expirado', 'Vencidos'], ['revogado', 'Revogados'], ['todos', 'Todos']]} />
      </div>
      <Erro erro={lista.erro} aoTentar={lista.recarregar} />
      {lista.carregando && !lista.dados && <Carregando />}
      {lista.dados && (
        <>
          <Tabela<Convite> linhas={lista.dados.itens} vazio="Nenhum convite nesta situação." colunas={[
            { titulo: 'Código', celula: (c) => <code>{c.codigo}</code> },
            { titulo: 'Para', celula: (c) => <><b>{c.destino_nome ?? 'Sem nome'}</b><div className="fraco">{NOME_TIPO[c.tipo] ?? c.tipo}{c.papel && `, ${NOME_PERFIL[c.papel] ?? c.papel}`}</div></> },
            { titulo: 'Pessoa ou empresa', celula: (c) => c.pessoa_id ? <a href={`#/pessoas/${c.pessoa_id}`}>{c.pessoa}</a> : c.empresa_id ? <a href={`#/empresas/${c.empresa_id}`}>{c.empresa}</a> : '—' },
            { titulo: 'Enviado por', celula: (c) => c.criado_por ?? '—' },
            { titulo: 'Criado', celula: (c) => dataHora(c.criado_em), classe: 'nowrap' },
            { titulo: 'Vence', celula: (c) => dataHora(c.expira_em), classe: 'nowrap' },
            { titulo: 'Situação', celula: (c) => <Etiqueta tom={TOM[c.status]}>{c.status === 'pendente' ? 'Esperando' : c.status === 'expirado' ? 'Vencido' : c.status[0].toUpperCase() + c.status.slice(1)}</Etiqueta> },
            { titulo: '', celula: (c) => c.status === 'pendente' && pode(admin, 'operador')
              ? <AcaoComMotivo rotulo="Revogar" perigo titulo="Revogar convite" explicacao={<p>O código {c.codigo} deixa de funcionar. Quem enviou pode mandar outro pelo app.</p>}
                  executar={(m) => post(`/admin/convites/${c.id}/revogar`, { motivo: m })} aoConcluir={lista.recarregar} />
              : null },
          ]} />
          <Paginacao total={lista.dados.total} pagina={lista.dados.pagina} porPagina={lista.dados.por_pagina} />
        </>
      )}
    </>
  );
}
