import { useState, type FormEvent } from 'react';
import { get, patch, post, type Admin, type Papel } from '../api';
import { Carregando, Erro, Etiqueta, NOME_PAPEL_ADMIN, Secao, Tabela, Titulo, dataHora, pode, useAdmin, useCarregar } from '../ui';

type Linha = Admin & { da_configuracao: boolean; sessoes_ativas: number };

const EXPLICA_PAPEL: Record<Papel, string> = {
  leitura: 'Consulta tudo, não muda nada.',
  operador: 'Também bloqueia contas, encerra sessões, corrige cadastro, revoga convites, encerra vínculos e baixa o log.',
  dono: 'Também exclui contas a pedido do titular, passa a gestão para a Rede de Apoio e gere quem acessa o painel.',
};

export function Administradores() {
  const { admin, avisar } = useAdmin();
  const lista = useCarregar(() => get<{ itens: Linha[]; donos_sem_primeiro_acesso: string[] }>('/admin/admins'), []);
  const ehDono = pode(admin, 'dono');

  async function mudar(a: Linha, corpo: Record<string, unknown>, texto: string) {
    try {
      await patch('/admin/admins/' + a.id, corpo);
      avisar(texto);
      lista.recarregar();
    } catch (e: any) {
      avisar(e.message, 'erro');
    }
  }

  return (
    <>
      <Titulo sub="Quem da equipe Ahvita entra neste painel. Só com conta Google e e-mail confirmado.">Administradores</Titulo>
      <ul className="papeis">
        {(Object.keys(EXPLICA_PAPEL) as Papel[]).map((p) => <li key={p}><b>{NOME_PAPEL_ADMIN[p]}</b> {EXPLICA_PAPEL[p]}</li>)}
      </ul>
      <Erro erro={lista.erro} aoTentar={lista.recarregar} />
      {lista.carregando && !lista.dados && <Carregando />}
      {lista.dados && (
        <>
          {lista.dados.donos_sem_primeiro_acesso.length > 0 && (
            <p className="aviso nota">Na configuração do servidor, ainda sem primeiro acesso: {lista.dados.donos_sem_primeiro_acesso.join(', ')}.</p>
          )}
          <Tabela<Linha> linhas={lista.dados.itens} colunas={[
            { titulo: 'Quem', celula: (a) => <><b>{a.nome ?? a.email}</b><div className="fraco">{a.email}</div></> },
            { titulo: 'Papel', celula: (a) => ehDono && !a.da_configuracao && a.id !== admin.id ? (
              <select aria-label={`Papel de ${a.email}`} value={a.papel} onChange={(e) => void mudar(a, { papel: e.target.value }, 'Papel alterado. A pessoa entra de novo.')}>
                {(Object.keys(NOME_PAPEL_ADMIN) as Papel[]).map((p) => <option key={p} value={p}>{NOME_PAPEL_ADMIN[p]}</option>)}
              </select>
            ) : <>{NOME_PAPEL_ADMIN[a.papel]}{a.da_configuracao && <span className="fraco"> (configuração do servidor)</span>}</> },
            { titulo: 'Situação', celula: (a) => a.ativo ? <Etiqueta tom="ok">Ativo(a)</Etiqueta> : <Etiqueta>Desativado(a)</Etiqueta> },
            { titulo: 'Google', celula: (a) => a.google_vinculado ? 'Ligado' : <span className="fraco">Ainda não entrou</span> },
            { titulo: 'Último acesso', celula: (a) => dataHora(a.ultimo_acesso_em), classe: 'nowrap' },
            { titulo: 'Sessões', celula: (a) => a.sessoes_ativas, classe: 'num' },
            { titulo: '', celula: (a) => ehDono && !a.da_configuracao && a.id !== admin.id ? (
              <span className="acoes acoes-pequenas">
                <button className="b b-texto" onClick={() => void mudar(a, { ativo: !a.ativo }, a.ativo ? 'Acesso desativado.' : 'Acesso reativado.')}>{a.ativo ? 'Desativar' : 'Reativar'}</button>
                {a.google_vinculado && <button className="b b-texto" title="Para quando a pessoa trocou de conta Google com o mesmo e-mail"
                  onClick={() => void mudar(a, { liberar_google: true }, 'Liberado: vale a próxima conta Google que entrar com este e-mail.')}>Liberar outra conta Google</button>}
              </span>
            ) : null },
          ]} />
        </>
      )}
      {ehDono && <Novo aoCriar={lista.recarregar} />}
    </>
  );
}

function Novo({ aoCriar }: { aoCriar: () => void }) {
  const { avisar } = useAdmin();
  const [email, setEmail] = useState('');
  const [nome, setNome] = useState('');
  const [papel, setPapel] = useState<Papel>('leitura');
  const [erro, setErro] = useState<string | null>(null);

  async function criar(e: FormEvent) {
    e.preventDefault();
    setErro(null);
    try {
      await post('/admin/admins', { email, nome: nome || undefined, papel });
      avisar(`${email} pode entrar no painel.`);
      setEmail(''); setNome(''); setPapel('leitura');
      aoCriar();
    } catch (x: any) {
      setErro(x.message);
    }
  }

  return (
    <Secao titulo="Dar acesso a alguém">
      <form className="formulario formulario-linha" onSubmit={criar}>
        <label className="campo"><span>E-mail da conta Google</span><input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></label>
        <label className="campo"><span>Nome</span><input value={nome} onChange={(e) => setNome(e.target.value)} /></label>
        <label className="campo"><span>Papel</span>
          <select value={papel} onChange={(e) => setPapel(e.target.value as Papel)}>
            {(Object.keys(NOME_PAPEL_ADMIN) as Papel[]).map((p) => <option key={p} value={p}>{NOME_PAPEL_ADMIN[p]}</option>)}
          </select>
        </label>
        <button className="b b-primario">Dar acesso</button>
      </form>
      <p className="fraco">Comece por Leitura. Mude depois, se a pessoa precisar agir.</p>
      {erro && <p className="aviso erro" role="alert">{erro}</p>}
    </Secao>
  );
}
