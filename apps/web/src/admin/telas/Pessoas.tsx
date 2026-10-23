import { get, post, qs, type Pagina } from '../api';
import {
  AcaoComMotivo, Busca, Carregando, Erro, Etiqueta, Gaveta, NOME_ARRANJO, NOME_FAIXA, NOME_MODALIDADE, NOME_PERFIL, NOME_PERMISSAO,
  Paginacao, Pares, Secao, Seletor, Tabela, Titulo, data, dataHora, ir, num, pode, useAdmin, useCarregar, useRota,
} from '../ui';

interface PessoaResumo {
  id: string; nome: string; apelido: string; idade: number | null; faixa: string; arranjo: string; empresa: string | null;
  vinculos: number; gestores: string | null; criado_em: string; ultimo_registro: string | null; excluida: boolean;
}

export function Pessoas() {
  const [rota, busca] = useRota();
  const aberta = rota.split('/')[2];
  const f = { q: busca.get('q') ?? '', arranjo: busca.get('arranjo') ?? '', status: busca.get('status') ?? '', pagina: busca.get('pagina') ?? '1' };
  const lista = useCarregar(() => get<Pagina<PessoaResumo>>('/admin/pessoas' + qs(f)), [f.q, f.arranjo, f.status, f.pagina]);
  const volta = '#/pessoas' + (busca.toString() ? '?' + busca.toString() : '');

  return (
    <>
      <Titulo sub="Cadastro, quem participa do cuidado e contagens. Registros, conversa, ficha, plano, remédios, fotos e áudios não aparecem aqui.">
        Pessoas cuidadas
      </Titulo>
      <div className="filtros">
        <Busca valor={f.q} rotulo="Buscar por nome ou como gosta de ser chamada" />
        <Seletor rotulo="Arranjo" chave="arranjo" valor={f.arranjo} opcoes={[['', 'Todos'], ...Object.entries(NOME_ARRANJO)]} />
        <Seletor rotulo="Situação" chave="status" valor={f.status} opcoes={[['', 'Ativas'], ['excluida', 'Excluídas']]} />
      </div>
      <Erro erro={lista.erro} aoTentar={lista.recarregar} />
      {lista.carregando && !lista.dados && <Carregando />}
      {lista.dados && (
        <>
          <Tabela<PessoaResumo> linhas={lista.dados.itens} aoAbrir={(p) => ir(`/pessoas/${p.id}${busca.toString() ? '?' + busca.toString() : ''}`)} colunas={[
            { titulo: 'Pessoa', celula: (p) => <><b>{p.apelido}</b><div className="fraco">{p.nome}</div></> },
            { titulo: 'Idade', celula: (p) => <>{p.idade ?? '—'} <span className="fraco">{NOME_FAIXA[p.faixa]}</span></>, classe: 'nowrap' },
            { titulo: 'Arranjo', celula: (p) => NOME_ARRANJO[p.arranjo] ?? p.arranjo },
            { titulo: 'Gestão', celula: (p) => p.gestores ?? <Etiqueta tom="perigo">Sem gestor(a)</Etiqueta> },
            { titulo: 'Vínculos', celula: (p) => num(p.vinculos), classe: 'num' },
            { titulo: 'Empresa', celula: (p) => p.empresa ?? <span className="fraco">Particular</span> },
            { titulo: 'Último registro', celula: (p) => dataHora(p.ultimo_registro), classe: 'nowrap' },
          ]} />
          <Paginacao total={lista.dados.total} pagina={lista.dados.pagina} porPagina={lista.dados.por_pagina} />
        </>
      )}
      {aberta && <DetalhePessoa id={aberta} aoFechar={() => { location.hash = volta; }} aoMudar={lista.recarregar} />}
    </>
  );
}

interface Detalhe {
  pessoa: { id: string; nome: string; apelido: string; idade: number | null; faixa: string; menor: boolean; arranjo: string; cpf: string | null;
    empresa: { id: string; nome: string } | null; empresa_permissoes: string[] | null; criado_em: string; atualizado_em: string; excluida: boolean };
  vinculos: { id: string; usuario_id: string; nome: string; papel: string; relacao: string | null; modalidade: string | null; especialidade: string | null;
    atua_como_cuidador: boolean; status: string; permissoes: string[]; criado_em: string; encerrado_em: string | null }[];
  convites_pendentes: { id: string; codigo: string; papel: string; destino_nome: string | null; criado_em: string; expira_em: string }[];
  contagens: Record<string, number>;
  consentimentos: { tipo: string; versao: string; por: string | null; aceito_em: string; revogado_em: string | null }[];
  acessos_30d: { usuario_id: string; nome: string | null; perfil: string | null; requisicoes: number; ultimo_dia: string }[];
}

function DetalhePessoa({ id, aoFechar, aoMudar }: { id: string; aoFechar: () => void; aoMudar: () => void }) {
  const { admin } = useAdmin();
  const { dados: d, erro, recarregar } = useCarregar(() => get<Detalhe>('/admin/pessoas/' + id), [id]);
  const mudou = () => { recarregar(); aoMudar(); };
  const p = d?.pessoa;
  const gestoresAtivos = d?.vinculos.filter((v) => v.papel === 'gestor' && v.status === 'ativo').length ?? 0;

  return (
    <Gaveta titulo={p ? p.apelido : 'Pessoa cuidada'} aoFechar={aoFechar}>
      <Erro erro={erro} aoTentar={recarregar} />
      {!d && !erro && <Carregando />}
      {d && p && (
        <>
          {p.menor && <p className="aviso atencao">Pessoa menor de idade. Dados com proteção reforçada (LGPD, art. 14): confirme o responsável legal antes de qualquer ação.</p>}
          {gestoresAtivos === 0 && !p.excluida && <p className="aviso erro">Ninguém está na gestão. Se alguém da Rede de Apoio pediu, use "Tornar gestor(a)" abaixo.</p>}
          <Pares itens={[
            ['Nome completo', p.nome],
            ['Idade', p.idade !== null ? `${p.idade} anos (${NOME_FAIXA[p.faixa]})` : '—'],
            ['Arranjo', NOME_ARRANJO[p.arranjo] ?? p.arranjo],
            ['CPF', p.cpf],
            ['Empresa', p.empresa ? <a href={`#/empresas/${p.empresa.id}`}>{p.empresa.nome}</a> : 'Particular'],
            ['O que a empresa pode', p.empresa ? (p.empresa_permissoes ?? []).map((x) => NOME_PERMISSAO[x] ?? x).join(', ') || 'Padrão' : '—'],
            ['Cadastrada em', dataHora(p.criado_em)],
            ['Situação', p.excluida ? <Etiqueta>Excluída</Etiqueta> : <Etiqueta tom="ok">Ativa</Etiqueta>],
            ['Identificador', <code>{p.id}</code>],
          ]} />

          <Secao titulo="Contagens">
            <ul className="contagens">
              <li><b>{num(d.contagens.registros_total)}</b> registros ({num(d.contagens.registros_30d)} em 30 dias)</li>
              <li><b>{num(d.contagens.mensagens_30d)}</b> mensagens em 30 dias</li>
              <li><b>{num(d.contagens.remedios_ativos)}</b> remédios em uso</li>
              <li><b>{num(d.contagens.alertas_abertos)}</b> alertas sem resolver</li>
              <li><b>{num(d.contagens.atendimentos_futuros)}</b> atendimentos marcados</li>
              <li><b>{num(d.contagens.midias)}</b> fotos e áudios</li>
            </ul>
          </Secao>

          <Secao titulo="Quem participa do cuidado">
            <ul className="cartoes">
              {d.vinculos.map((v) => (
                <li key={v.id} className={v.status !== 'ativo' ? 'apagado' : undefined}>
                  <div className="cartao-topo">
                    <a href={`#/usuarios/${v.usuario_id}`}>{v.nome}</a>
                    <span><Etiqueta tom="marca">{NOME_PERFIL[v.papel] ?? v.papel}</Etiqueta>{v.status !== 'ativo' && <Etiqueta>Encerrado {data(v.encerrado_em)}</Etiqueta>}</span>
                  </div>
                  <p className="fraco">
                    {[v.relacao, v.modalidade && (NOME_MODALIDADE[v.modalidade] ?? v.modalidade), v.especialidade, v.atua_como_cuidador && 'também atua como cuidador(a)']
                      .filter(Boolean).join(', ') || 'Sem detalhes'}. Desde {data(v.criado_em)}.
                  </p>
                  <p className="permissoes">{v.permissoes.map((x) => NOME_PERMISSAO[x] ?? x).join(', ') || 'Sem permissões'}</p>
                  {v.status === 'ativo' && pode(admin, 'operador') && (
                    <div className="acoes acoes-pequenas">
                      {v.papel === 'rede' && pode(admin, 'dono') && (
                        <AcaoComMotivo rotulo="Tornar gestor(a)" titulo={`Passar a gestão para ${v.nome}`}
                          explicacao={<><p>{v.nome} passa a gestor(a), com todas as permissões, inclusive cadastro e dados (LGPD).</p>
                            <p>Use quando quem fazia a gestão faleceu ou perdeu o acesso e a Rede de Apoio pediu. Guarde a comprovação do pedido.</p></>}
                          executar={(m) => post(`/admin/vinculos/${v.id}/tornar-gestor`, { motivo: m })} aoConcluir={mudou} />
                      )}
                      <AcaoComMotivo rotulo="Encerrar vínculo" perigo titulo={`Encerrar o vínculo de ${v.nome}`}
                        explicacao={<p>{v.nome} deixa de ver e registrar o cuidado de {p.apelido}. O histórico fica guardado.
                          {v.papel === 'gestor' && gestoresAtivos <= 1 && ' Não dá para encerrar o(a) único(a) gestor(a).'}</p>}
                        desabilitado={v.papel === 'gestor' && gestoresAtivos <= 1}
                        executar={(m) => post(`/admin/vinculos/${v.id}/encerrar`, { motivo: m })} aoConcluir={mudou} />
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </Secao>

          {d.convites_pendentes.length > 0 && (
            <Secao titulo="Convites esperando aceite" acao={<a href="#/convites">Ver todos</a>}>
              <ul className="lista-simples">
                {d.convites_pendentes.map((c) => <li key={c.id}><code>{c.codigo}</code> {NOME_PERFIL[c.papel ?? ''] ?? c.papel} para {c.destino_nome ?? 'sem nome'}<span className="fraco"> vence {dataHora(c.expira_em)}</span></li>)}
              </ul>
            </Secao>
          )}

          <Secao titulo="Consentimentos (LGPD)">
            {d.consentimentos.length === 0 ? <p className="vazio">Nenhum consentimento ligado a esta pessoa.</p> : (
              <ul className="lista-simples">
                {d.consentimentos.map((c, i) => <li key={i}><b>{c.tipo === 'dados_saude' ? 'Dados de saúde' : c.tipo === 'responsavel_legal' ? 'Responsável legal' : c.tipo}</b> versão {c.versao}, por {c.por ?? '—'}
                  <span className="fraco"> em {dataHora(c.aceito_em)}{c.revogado_em && `, revogado em ${dataHora(c.revogado_em)}`}</span></li>)}
              </ul>
            )}
          </Secao>

          <Secao titulo="Quem acessou os dados (30 dias)" acao={<a href={`#/logs?pessoa=${id}`}>Abrir no log de acessos</a>}>
            <p className="fraco">Serve para responder ao titular quem viu os dados (LGPD, art. 18). Mostra dias e quantidade, nunca horários.</p>
            {d.acessos_30d.length === 0 ? <p className="vazio">Nenhum acesso registrado.</p> : (
              <Tabela linhas={d.acessos_30d} chave={(a, i) => a.usuario_id + i} colunas={[
                { titulo: 'Quem', celula: (a) => <a href={`#/usuarios/${a.usuario_id}`}>{a.nome ?? a.usuario_id}</a> },
                { titulo: 'Como', celula: (a) => (a.perfil ? NOME_PERFIL[a.perfil] ?? a.perfil : '—') },
                { titulo: 'Consultas', celula: (a) => num(a.requisicoes), classe: 'num' },
                { titulo: 'Último dia', celula: (a) => a.ultimo_dia.split('-').reverse().join('/'), classe: 'nowrap' },
              ]} />
            )}
          </Secao>
        </>
      )}
    </Gaveta>
  );
}
