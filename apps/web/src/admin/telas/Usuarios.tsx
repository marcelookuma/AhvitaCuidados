import { useState, type FormEvent } from 'react';
import { get, patch, post, qs, type Pagina } from '../api';
import {
  AcaoComMotivo, Busca, Carregando, Erro, Etiqueta, Gaveta, NOME_MODALIDADE, NOME_PERFIL, NOME_PERMISSAO, Paginacao, Pares, Secao, Seletor,
  StatusConta, Tabela, Titulo, data, dataHora, ir, num, pode, telefone, useAdmin, useCarregar, useRota,
} from '../ui';

export interface UsuarioResumo {
  id: string; nome: string | null; apelido: string | null; email: string | null; telefone: string | null; cpf: string | null;
  google: boolean; papeis: string[]; status: string; criado_em: string; ultimo_acesso_em: string | null;
}

export function Usuarios() {
  const [rota, busca] = useRota();
  const aberto = rota.split('/')[2];
  const filtros = { q: busca.get('q') ?? '', papel: busca.get('papel') ?? '', status: busca.get('status') ?? 'ativo', pagina: busca.get('pagina') ?? '1' };
  const lista = useCarregar(() => get<Pagina<UsuarioResumo>>('/admin/usuarios' + qs(filtros)), [filtros.q, filtros.papel, filtros.status, filtros.pagina]);
  const volta = '#/usuarios' + (busca.toString() ? '?' + busca.toString() : '');

  return (
    <>
      <Titulo sub="Todas as contas do app: gestores(as), cuidadores(as), Rede de Apoio, profissionais de saúde e equipes de empresas.">Usuários</Titulo>
      <div className="filtros">
        <Busca valor={filtros.q} rotulo="Buscar por nome, e-mail, celular ou CPF" />
        <Seletor rotulo="Perfil" chave="papel" valor={filtros.papel} opcoes={[
          ['', 'Todos'], ['gestor', 'Gestor(a)'], ['cuidador', 'Cuidador(a)'], ['rede', 'Rede de Apoio'], ['profissional', 'Profissional de saúde'],
          ['empresa', 'Equipe de empresa'], ['cuidador_empresa', 'Cuidador(a) de empresa'], ['sem_papel', 'Sem vínculo'],
        ]} />
        <Seletor rotulo="Situação" chave="status" valor={filtros.status} opcoes={[['ativo', 'Ativas'], ['bloqueado', 'Bloqueadas'], ['excluido', 'Excluídas'], ['todos', 'Todas']]} />
      </div>
      <Erro erro={lista.erro} aoTentar={lista.recarregar} />
      {lista.carregando && !lista.dados && <Carregando />}
      {lista.dados && (
        <>
          <Tabela<UsuarioResumo> linhas={lista.dados.itens} aoAbrir={(u) => ir(`/usuarios/${u.id}${busca.toString() ? '?' + busca.toString() : ''}`)} colunas={[
            { titulo: 'Nome', celula: (u) => <><b>{u.nome ?? 'Sem nome'}</b>{u.apelido && u.apelido !== u.nome && <span className="fraco"> ({u.apelido})</span>}</> },
            { titulo: 'Contato', celula: (u) => <><div>{telefone(u.telefone)}</div><div className="fraco">{u.email ?? ''}{u.google && ' (Google)'}</div></> },
            { titulo: 'Perfis', celula: (u) => u.papeis.length ? u.papeis.map((p) => <Etiqueta key={p} tom="marca">{NOME_PERFIL[p] ?? p}</Etiqueta>) : <span className="fraco">Sem vínculo</span> },
            { titulo: 'Situação', celula: (u) => <StatusConta s={u.status} /> },
            { titulo: 'Último acesso', celula: (u) => dataHora(u.ultimo_acesso_em), classe: 'nowrap' },
            { titulo: 'Criada em', celula: (u) => data(u.criado_em), classe: 'nowrap' },
          ]} />
          <Paginacao total={lista.dados.total} pagina={lista.dados.pagina} porPagina={lista.dados.por_pagina} />
        </>
      )}
      {aberto && <DetalheUsuario id={aberto} aoFechar={() => { location.hash = volta; }} aoMudar={lista.recarregar} />}
    </>
  );
}

interface Detalhe {
  usuario: UsuarioResumo & { bloqueio_motivo: string | null; atualizado_em: string };
  vinculos: { id: string; pessoa_id: string; pessoa: string; papel: string; relacao: string | null; modalidade: string | null; especialidade: string | null; atua_como_cuidador: boolean; status: string; permissoes: string[]; criado_em: string; encerrado_em: string | null }[];
  empresas: { id: string; nome: string; como: string; detalhe: string; status: string }[];
  sessoes: { id: string; dispositivo: string | null; criado_em: string; ultimo_uso_em: string; expira_em: string }[];
  consentimentos: { tipo: string; versao: string; pessoa: string | null; aceito_em: string; revogado_em: string | null }[];
  uso_30d: { perfil: string | null; leituras: number; dias: number }[];
}

export function DetalheUsuario({ id, aoFechar, aoMudar }: { id: string; aoFechar: () => void; aoMudar: () => void }) {
  const { admin } = useAdmin();
  const { dados: d, erro, recarregar } = useCarregar(() => get<Detalhe>('/admin/usuarios/' + id), [id]);
  const mudou = () => { recarregar(); aoMudar(); };
  const [editando, setEditando] = useState(false);
  const u = d?.usuario;
  const ativa = u && u.status !== 'excluido';

  return (
    <Gaveta titulo={u ? (u.nome ?? 'Conta sem nome') : 'Usuário'} aoFechar={aoFechar}>
      <Erro erro={erro} aoTentar={recarregar} />
      {!d && !erro && <Carregando />}
      {d && u && (
        <>
          <Pares itens={[
            ['Situação', <><StatusConta s={u.status} />{u.bloqueio_motivo && <span className="fraco"> {u.bloqueio_motivo}</span>}</>],
            ['Como gosta de ser chamado(a)', u.apelido],
            ['Celular', telefone(u.telefone)],
            ['E-mail', u.email],
            ['Google', u.google ? 'Ligado' : 'Não'],
            ['CPF', u.cpf],
            ['Conta criada', dataHora(u.criado_em)],
            ['Último acesso', dataHora(u.ultimo_acesso_em)],
            ['Identificador', <code>{u.id}</code>],
          ]} />

          {ativa && pode(admin, 'operador') && (
            <div className="acoes">
              {!editando && <button className="b b-secundario" onClick={() => setEditando(true)}>Corrigir cadastro</button>}
              {u.status === 'bloqueado'
                ? <AcaoComMotivo rotulo="Desbloquear" titulo="Desbloquear a conta" explicacao="A pessoa volta a poder entrar no app." executar={(m) => post(`/admin/usuarios/${id}/desbloquear`, { motivo: m })} aoConcluir={mudou} />
                : <AcaoComMotivo rotulo="Bloquear" perigo titulo="Bloquear a conta"
                    explicacao={<p>A pessoa sai de todos os aparelhos e não consegue entrar até alguém desbloquear. Os dados e vínculos continuam como estão. Use em suspeita de invasão ou abuso.</p>}
                    executar={(m) => post(`/admin/usuarios/${id}/bloquear`, { motivo: m })} aoConcluir={mudou} />}
              <AcaoComMotivo rotulo="Encerrar sessões" titulo="Encerrar todas as sessões"
                explicacao={<p>Desconecta o app em todos os aparelhos ({num(d.sessoes.length)} agora). Útil quando o celular foi perdido ou roubado. A pessoa entra de novo com código.</p>}
                executar={(m) => post(`/admin/usuarios/${id}/encerrar-sessoes`, { motivo: m })} aoConcluir={mudou} desabilitado={d.sessoes.length === 0} />
              {u.google && <AcaoComMotivo rotulo="Desligar o Google" titulo="Desligar o login com Google"
                explicacao="A conta deixa de entrar pelo Google e passa a entrar só por código no celular ou e-mail."
                executar={(m) => post(`/admin/usuarios/${id}/desvincular-google`, { motivo: m })} aoConcluir={mudou} />}
              {pode(admin, 'dono') && <AcaoComMotivo rotulo="Excluir conta" perigo confirmarTexto="EXCLUIR" titulo="Excluir a conta a pedido do titular"
                explicacao={<><p>Anonimiza a conta (nome, CPF, celular, e-mail), encerra os vínculos e apaga a agenda. Não dá para desfazer.</p><p>Use só com pedido do titular (LGPD, art. 18). Se for o(a) único(a) gestor(a) de alguém, passe a gestão antes.</p></>}
                executar={(m, x) => post(`/admin/usuarios/${id}/excluir`, { motivo: m, confirmar: x.confirmar })} aoConcluir={() => { aoMudar(); aoFechar(); }} />}
            </div>
          )}
          {editando && <CorrigirCadastro u={u} aoFechar={() => setEditando(false)} aoSalvar={() => { setEditando(false); mudou(); }} />}

          <Secao titulo="Vínculos com pessoas cuidadas">
            {d.vinculos.length === 0 ? <p className="vazio">Nenhum vínculo.</p> : (
              <ul className="cartoes">
                {d.vinculos.map((v) => (
                  <li key={v.id} className={v.status !== 'ativo' ? 'apagado' : undefined}>
                    <div className="cartao-topo">
                      <a href={`#/pessoas/${v.pessoa_id}`}>{v.pessoa}</a>
                      <span><Etiqueta tom="marca">{NOME_PERFIL[v.papel] ?? v.papel}</Etiqueta>{v.status !== 'ativo' && <Etiqueta>Encerrado {data(v.encerrado_em)}</Etiqueta>}</span>
                    </div>
                    <p className="fraco">
                      {[v.relacao, v.modalidade && (NOME_MODALIDADE[v.modalidade] ?? v.modalidade), v.especialidade, v.atua_como_cuidador && 'também atua como cuidador(a)']
                        .filter(Boolean).join(', ') || 'Sem detalhes'}. Desde {data(v.criado_em)}.
                    </p>
                    <p className="permissoes">{v.permissoes.map((p) => NOME_PERMISSAO[p] ?? p).join(', ') || 'Sem permissões'}</p>
                  </li>
                ))}
              </ul>
            )}
          </Secao>

          {d.empresas.length > 0 && (
            <Secao titulo="Empresas">
              <ul className="lista-simples">
                {d.empresas.map((e) => (
                  <li key={e.id + e.como}><a href={`#/empresas/${e.id}`}>{e.nome}</a> <span className="fraco">
                    {e.como === 'membro' ? `equipe (${e.detalhe})` : `cuidador(a) ${NOME_MODALIDADE[e.detalhe] ?? e.detalhe}`}, {e.status}</span></li>
                ))}
              </ul>
            </Secao>
          )}

          <Secao titulo="Aparelhos conectados">
            {d.sessoes.length === 0 ? <p className="vazio">Nenhuma sessão aberta.</p> : (
              <ul className="lista-simples">
                {d.sessoes.map((s) => <li key={s.id}><span className="dispositivo">{s.dispositivo ?? 'Aparelho sem nome'}</span><span className="fraco">desde {dataHora(s.criado_em)}, último uso {dataHora(s.ultimo_uso_em)}</span></li>)}
              </ul>
            )}
          </Secao>

          <Secao titulo="Consentimentos (LGPD)">
            {d.consentimentos.length === 0 ? <p className="vazio">Nenhum consentimento registrado.</p> : (
              <ul className="lista-simples">
                {d.consentimentos.map((c, i) => <li key={i}><b>{NOME_CONSENT[c.tipo] ?? c.tipo}</b> versão {c.versao}{c.pessoa && `, ${c.pessoa}`}
                  <span className="fraco"> em {dataHora(c.aceito_em)}{c.revogado_em && `, revogado em ${dataHora(c.revogado_em)}`}</span></li>)}
              </ul>
            )}
          </Secao>

          <Secao titulo="Uso nos últimos 30 dias" acao={<a href={`#/logs?usuario=${id}`}>Abrir no log de acessos</a>}>
            {d.uso_30d.length === 0 ? <p className="vazio">Sem uso registrado.</p> : (
              <ul className="lista-simples">
                {d.uso_30d.map((x) => <li key={x.perfil ?? '-'}>{x.perfil ? NOME_PERFIL[x.perfil] ?? x.perfil : 'Sem perfil (tela inicial e conta)'}: <b>{num(x.dias)} {x.dias === 1 ? 'dia' : 'dias'}</b></li>)}
              </ul>
            )}
          </Secao>
        </>
      )}
    </Gaveta>
  );
}

const NOME_CONSENT: Record<string, string> = { termos: 'Termos de uso', dados_saude: 'Dados de saúde', responsavel_legal: 'Responsável legal (menor)' };

function CorrigirCadastro({ u, aoFechar, aoSalvar }: { u: UsuarioResumo; aoFechar: () => void; aoSalvar: () => void }) {
  const [nome, setNome] = useState(u.nome ?? '');
  const [email, setEmail] = useState(u.email ?? '');
  const [tel, setTel] = useState(u.telefone ? telefone(u.telefone) : '');
  const [motivo, setMotivo] = useState('');
  const [erro, setErro] = useState<string | null>(null);
  const [campos, setCampos] = useState<Record<string, string>>({});
  const { avisar, admin } = useAdmin();

  async function salvar(e: FormEvent) {
    e.preventDefault();
    const corpo: Record<string, string | null> = { motivo };
    if (nome !== (u.nome ?? '')) corpo.nome = nome;
    if (email !== (u.email ?? '')) corpo.email = email || null;
    if (tel !== (u.telefone ? telefone(u.telefone) : '')) corpo.telefone = tel || null;
    try {
      await patch('/admin/usuarios/' + u.id, corpo);
      avisar('Cadastro corrigido.');
      aoSalvar();
    } catch (x: any) {
      setErro(x.message);
      setCampos(x.campos ?? {});
    }
  }

  return (
    <form className="formulario" onSubmit={salvar}>
      <h3>Corrigir cadastro</h3>
      <p className="fraco">Só a pedido do titular. Se o celular ou o e-mail mudar, a pessoa sai dos aparelhos e entra de novo com código.</p>
      <label className="campo"><span>Nome</span><input value={nome} onChange={(e) => setNome(e.target.value)} />{campos.nome && <small className="erro-campo">{campos.nome}</small>}</label>
      <label className="campo"><span>E-mail</span><input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />{campos.email && <small className="erro-campo">{campos.email}</small>}</label>
      <label className="campo"><span>Celular</span><input inputMode="tel" value={tel} onChange={(e) => setTel(e.target.value)} />{campos.telefone && <small className="erro-campo">{campos.telefone}</small>}</label>
      <label className="campo"><span>Motivo</span><textarea rows={2} value={motivo} onChange={(e) => setMotivo(e.target.value)} placeholder="Ex.: titular trocou de número, pedido pelo WhatsApp do suporte" /></label>
      <p className="registro-assinatura">Fica na trilha de auditoria em nome de <b>{admin.email}</b>.</p>
      {erro && <p className="aviso erro" role="alert">{erro}</p>}
      <div className="dialogo-botoes">
        <button type="button" className="b b-texto" onClick={aoFechar}>Cancelar</button>
        <button className="b b-primario" disabled={motivo.trim().length < 10}>Salvar correção</button>
      </div>
    </form>
  );
}
