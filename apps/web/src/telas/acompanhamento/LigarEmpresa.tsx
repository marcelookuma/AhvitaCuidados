import { useEffect, useState } from 'react';
import { FUNCOES, PERMISSOES_EMPRESA, type PedidoCliente, type Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { useCarregar } from '../../hooks';
import { Campo, ErroGeral, useAcao, useLanche } from '../../componentes/ui';

const OPCOES = FUNCOES.filter((f) => PERMISSOES_EMPRESA.includes(f.chave));

/** Caixas "o que a empresa pode ver". A linha do tempo vai com o aviso de que presença não aparece. */
export function EscolherPermissoesEmpresa({ valor, aoMudar, pedidas }: { valor: string[]; aoMudar: (p: string[]) => void; pedidas?: string[] }) {
  return (
    <div className="permissoes">
      {OPCOES.map((f) => (
        <label key={f.chave} className="permissao">
          <input type="checkbox" checked={valor.includes(f.chave)} onChange={(e) => aoMudar(e.target.checked ? [...valor, f.chave] : valor.filter((x) => x !== f.chave))} />
          <span><b>{f.nome}</b>
            <small>{f.chave === 'ver_registros' ? 'O que foi registrado (remédio, comida, sinais…). Sem horário de entrada e saída de cuidadores(as).' : f.desc}
              {pedidas && pedidas.includes(f.chave) && ' · a empresa pediu'}</small></span>
        </label>
      ))}
    </div>
  );
}

/**
 * Ligar a pessoa a uma empresa de cuidadores(as), pelos dois lados:
 * pedidos que empresas fizeram ao(à) gestor(a) e procurar uma empresa para pedir.
 * Quem decide o que a empresa vê é sempre o(a) gestor(a).
 */
export function LigarEmpresa({ pessoa, aoLigar }: { pessoa: Pessoa; aoLigar: () => void }) {
  const lanche = useLanche();
  const pedidos = useCarregar(() => api.meusPedidosEmpresa());
  const recebidos = pedidos.dados?.recebidos ?? [];
  const enviados = (pedidos.dados?.enviados ?? []).filter((p) => p.pessoa?.id === pessoa.id);
  const [procurando, setProcurando] = useState(false);

  const desfazer = useAcao(async (p: PedidoCliente) => {
    await api.responderPedidoCliente(p.id, 'cancelar');
    lanche('Pedido desfeito.');
    await pedidos.recarregar(true);
  });

  return (
    <section className="cartao pilha">
      <h3 style={{ margin: 0 }}>🏢 Empresa de cuidadores(as)</h3>
      <p className="sutil" style={{ margin: 0 }}>Uma empresa pode organizar atendimentos e acompanhar o cuidado de {pessoa.apelido}. Ela só vê o que você marcar, e você pode encerrar quando quiser.</p>

      {recebidos.map((p) => <PedidoRecebido key={p.id} pedido={p} pessoa={pessoa} aoResponder={(ligou) => { void pedidos.recarregar(true); if (ligou) aoLigar(); }} />)}

      {enviados.map((p) => (
        <div key={p.id} className="alerta-linha pedido-empresa">
          <span className="cresce">🕓 Pedido enviado para <b>{p.empresa?.nome}</b>. A empresa vai responder.<br />
            <span className="sutil">Vai ver: {nomes(p.permissoes)}</span></span>
          <button className="botao fantasma pequeno" disabled={desfazer.enviando} onClick={() => void desfazer.executar(p)}>Desfazer pedido</button>
        </div>
      ))}
      <ErroGeral erro={desfazer.erro} />

      {procurando
        ? <Procurar pessoa={pessoa} aoFechar={() => setProcurando(false)} aoEnviar={(ligou) => { setProcurando(false); void pedidos.recarregar(true); if (ligou) aoLigar(); }} />
        : enviados.length === 0 && <button className="botao secundario pequeno" style={{ alignSelf: 'flex-start' }} onClick={() => setProcurando(true)}>🔎 Procurar uma empresa</button>}
    </section>
  );
}

function PedidoRecebido({ pedido, pessoa, aoResponder }: { pedido: PedidoCliente; pessoa: Pessoa; aoResponder: (ligou: boolean) => void }) {
  const lanche = useLanche();
  // Começa com o que a empresa pediu; o(a) gestor(a) tira o que não quiser.
  const [perm, setPerm] = useState<string[]>(pedido.permissoes.filter((x) => PERMISSOES_EMPRESA.includes(x)));
  const aceitar = useAcao(async () => {
    await api.responderPedidoCliente(pedido.id, 'aceitar', { pessoa_id: pessoa.id, permissoes: perm });
    lanche(`${pedido.empresa?.nome} passou a acompanhar ${pessoa.apelido}.`);
    aoResponder(true);
  });
  const recusar = useAcao(async () => {
    await api.responderPedidoCliente(pedido.id, 'recusar');
    lanche('Pedido recusado.');
    aoResponder(false);
  });
  return (
    <div className="cartao pilha destaque-pedido">
      <p style={{ margin: 0 }}><b>{pedido.empresa?.nome}</b> pediu para acompanhar o cuidado de uma pessoa que você organiza.</p>
      {pedido.mensagem && <p className="recado">“{pedido.mensagem}”</p>}
      <p className="sutil" style={{ margin: 0 }}>Se aceitar aqui, vale para <b>{pessoa.apelido}</b>. Marque o que a empresa pode ver:</p>
      <EscolherPermissoesEmpresa valor={perm} aoMudar={setPerm} pedidas={pedido.permissoes} />
      <ErroGeral erro={aceitar.erro ?? recusar.erro} />
      <div className="linha-h" style={{ flexWrap: 'wrap' }}>
        <button className="botao pequeno" disabled={aceitar.enviando} onClick={() => void aceitar.executar()}>Aceitar para {pessoa.apelido}</button>
        <button className="botao fantasma pequeno" disabled={recusar.enviando} onClick={() => void recusar.executar()}>Recusar</button>
      </div>
    </div>
  );
}

function Procurar({ pessoa, aoFechar, aoEnviar }: { pessoa: Pessoa; aoFechar: () => void; aoEnviar: (ligou: boolean) => void }) {
  const lanche = useLanche();
  const [texto, setTexto] = useState('');
  const [achadas, setAchadas] = useState<{ id: string; nome: string; razao_social: string; cnpj: string; pedido_pendente: boolean }[] | null>(null);
  const [escolhida, setEscolhida] = useState<{ id: string; nome: string } | null>(null);
  const [perm, setPerm] = useState<string[]>(['atendimentos']);
  const [recado, setRecado] = useState('');
  const buscar = useAcao(async (t: string) => setAchadas((await api.buscarEmpresasParaPessoa(pessoa.id, t)).empresas));
  useEffect(() => {
    if (texto.trim().length < 3) { setAchadas(null); return; }
    const id = setTimeout(() => void buscar.executar(texto.trim()), 400);
    return () => clearTimeout(id);
  }, [texto]); // eslint-disable-line react-hooks/exhaustive-deps
  const pedir = useAcao(async () => {
    const r = await api.pedirEmpresa(pessoa.id, { empresa_id: escolhida!.id, permissoes: perm, mensagem: recado || undefined });
    lanche(r.status === 'aceito' ? `${escolhida!.nome} já tinha pedido: agora acompanha ${pessoa.apelido}.` : `Pedido enviado para ${escolhida!.nome}.`);
    aoEnviar(r.status === 'aceito');
  });

  if (escolhida) {
    return (
      <form className="pilha" onSubmit={(e) => { e.preventDefault(); void pedir.executar(); }}>
        <p style={{ margin: 0 }}>Pedir para <b>{escolhida.nome}</b> acompanhar {pessoa.apelido}. O que a empresa pode ver?</p>
        <EscolherPermissoesEmpresa valor={perm} aoMudar={setPerm} />
        <Campo rotulo="Recado (opcional)"><input value={recado} onChange={(e) => setRecado(e.target.value)} maxLength={200} placeholder="Ex.: precisamos de reforço nos fins de semana" /></Campo>
        <ErroGeral erro={pedir.erro} />
        <div className="linha-h"><button className="botao pequeno" disabled={pedir.enviando}>Enviar pedido</button>
          <button type="button" className="botao fantasma pequeno" onClick={() => setEscolhida(null)}>Voltar</button></div>
      </form>
    );
  }
  return (
    <div className="pilha">
      <Campo rotulo="Nome ou CNPJ da empresa"><input value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus placeholder="Ex.: CuidaBem" /></Campo>
      <ErroGeral erro={buscar.erro} />
      {texto.trim().length > 0 && texto.trim().length < 3 && <p className="sutil" style={{ margin: 0 }}>Escreva pelo menos 3 letras.</p>}
      {achadas?.length === 0 && <p className="sutil" style={{ margin: 0 }}>Não achamos. Confira o nome ou peça o convite à empresa.</p>}
      {achadas?.map((e) => (
        <div key={e.id} className="alerta-linha">
          <span className="cresce"><b>{e.nome}</b><br /><span className="sutil">{e.razao_social} · CNPJ {e.cnpj}</span></span>
          {e.pedido_pendente ? <span className="etiqueta atencao">Pedido enviado</span>
            : <button className="botao pequeno" onClick={() => setEscolhida({ id: e.id, nome: e.nome })}>Escolher</button>}
        </div>
      ))}
      <button className="botao fantasma pequeno" style={{ alignSelf: 'flex-start' }} onClick={aoFechar}>Cancelar</button>
    </div>
  );
}

export function nomes(perm: string[]): string {
  return perm.map((p) => FUNCOES.find((f) => f.chave === p)?.nome ?? p).join(', ') || 'nada (só o nome)';
}
