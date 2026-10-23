import { useEffect, useState } from 'react';
import { cpf as fmtCpf, telefone as fmtTel, type Usuario } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar, PERFIL_ATIVO } from '../../rota';
import { useSessao } from '../../sessao';
import { Campo, ErroGeral, Ouvir, Topo, useAcao, useLanche } from '../../componentes/ui';

/**
 * Meus dados — o mesmo para todos os perfis (cuidador(a), gestor(a), Rede de Apoio,
 * profissional de saúde e equipe da empresa). Nome, como gosta de ser chamado(a) e CPF
 * mudam na hora; celular e e-mail só mudam depois do código enviado para o contato novo.
 */
export function MeusDados() {
  const { eu, recarregar } = useSessao();
  const lanche = useLanche();
  const u = eu!.usuario;
  const grande = localStorage.getItem(PERFIL_ATIVO) === '/c' || (!eu!.papeis.acompanha && !eu!.papeis.empresa);
  const [nome, setNome] = useState(u.nome ?? '');
  const [apelido, setApelido] = useState(u.apelido ?? '');
  const [cpf, setCpf] = useState(u.cpf ? fmtCpf(u.cpf) : '');
  const mudou = nome !== (u.nome ?? '') || apelido !== (u.apelido ?? '') || cpf.replace(/\D/g, '') !== (u.cpf ?? '');

  const salvar = useAcao(async () => {
    await api.atualizarEu({ nome, apelido, cpf } as never);
    await recarregar();
    lanche('Dados salvos.');
  });
  const voltar = () => navegar(localStorage.getItem(PERFIL_ATIVO) ?? '/', true);
  const c = salvar.erro?.campos ?? {};
  // Celular confirmado pode entrar; celular só informado não conta como forma de entrar.
  const formasDeEntrar = [u.telefone && u.telefone_confirmado !== false ? 't' : null, u.email, u.google ? 'google' : null].filter(Boolean).length;
  const [temCelular, setTemCelular] = useState<boolean | null>(null);
  useEffect(() => {
    api.canais().then((c) => setTemCelular(c.canais.some((x) => ['whatsapp', 'sms', 'log'].includes(x)))).catch(() => setTemCelular(false));
  }, []);

  const corpo = (
    <div className="pilha meus-dados">
      <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void salvar.executar(); }}>
        <h2 className="titulo-cartao">Quem é você</h2>
        <Campo rotulo="Nome completo" erro={c.nome}>
          <input value={nome} onChange={(e) => setNome(e.target.value)} autoComplete="name" required />
        </Campo>
        <Campo rotulo="Como gosta de ser chamado(a)" ajuda="É o nome que aparece nas conversas" erro={c.apelido}>
          <input value={apelido} onChange={(e) => setApelido(e.target.value)} autoComplete="nickname" />
        </Campo>
        <Campo rotulo="CPF" ajuda="Identifica a sua conta. Não aparece para os outros." erro={c.cpf}>
          <input value={cpf} onChange={(e) => setCpf(fmtCpf(e.target.value))} inputMode="numeric" placeholder="000.000.000-00" />
        </Campo>
        {!c.nome && !c.apelido && !c.cpf && <ErroGeral erro={salvar.erro} />}
        <button className={`botao ${grande ? 'largo' : ''}`} disabled={!mudou || salvar.enviando || nome.trim().length < 3}>
          {salvar.enviando ? 'Salvando…' : 'Salvar'}
        </button>
      </form>

      <div className="cartao pilha">
        <h2 className="titulo-cartao">Como você entra no app</h2>
        <p className="sutil" style={{ margin: 0 }}>Para trocar, mandamos um código para o {temCelular === false ? 'e-mail' : 'número ou e-mail'} novo.</p>
        <Contato campo="telefone" u={u} grande={grande} podeTirar={formasDeEntrar > 1 || u.telefone_confirmado === false} semCodigo={temCelular === false} />
        <div className="separador" />
        <Contato campo="email" u={u} grande={grande} podeTirar={formasDeEntrar > 1} />
        <div className="separador" />
        <Google u={u} podeTirar={formasDeEntrar > 1} />
      </div>
    </div>
  );

  if (grande) {
    return (
      <div className="tela modo-cuidador">
        <Topo titulo="Meus dados" aoVoltar={voltar}
          direita={<Ouvir texto="Meus dados. Aqui você muda seu nome, como gosta de ser chamado, seu CPF, seu celular e seu e-mail." />} />
        <div className="rolagem conteudo">{corpo}</div>
      </div>
    );
  }
  return (
    <div className="painel modo-painel">
      <header className="painel-topo">
        <div className="marca"><img src="/icone.svg" alt="" /><b className="marca-nome">Ahvita Cuidados</b></div>
        <span className="cresce" />
        <button className="botao secundario pequeno" onClick={voltar}>← Voltar</button>
      </header>
      <main className="principal conta-principal">
        <h1>Meus dados</h1>
        {corpo}
      </main>
    </div>
  );
}

/** Celular ou e-mail: mostra o atual; trocar pede o novo, manda o código e confirma. */
function Contato({ campo, u, grande, podeTirar, semCodigo = false }: { campo: 'telefone' | 'email'; u: Usuario; grande: boolean; podeTirar: boolean; semCodigo?: boolean }) {
  const { recarregar } = useSessao();
  const lanche = useLanche();
  const atual = u[campo];
  const nomeCampo = campo === 'telefone' ? 'Celular' : 'E-mail';
  const [etapa, setEtapa] = useState<'ver' | 'novo' | 'codigo'>('ver');
  const [novo, setNovo] = useState('');
  const [codigo, setCodigo] = useState('');
  const [aviso, setAviso] = useState<{ canal: string; teste?: string } | null>(null);
  const [tirando, setTirando] = useState(false);

  const pedir = useAcao(async (destino: string = novo) => {
    const r = await api.pedirCodigoContato(destino);
    if (r.sem_codigo) {
      // Sem SMS/WhatsApp no servidor: o celular fica salvo como contato, sem código.
      await api.confirmarContato(destino);
      await recarregar();
      lanche('Celular salvo.');
      setEtapa('ver');
      setNovo('');
      return;
    }
    setNovo(destino);
    setAviso({ canal: r.canal ?? '', teste: r.codigo_teste });
    setCodigo('');
    setEtapa('codigo');
  });
  const naoConfirmado = campo === 'telefone' && !!atual && u.telefone_confirmado === false;
  const confirmar = useAcao(async () => {
    await api.confirmarContato(novo, codigo);
    await recarregar();
    lanche(`${nomeCampo} ${atual ? 'trocado' : 'cadastrado'}.`);
    setEtapa('ver');
    setNovo('');
  });
  const tirar = useAcao(async () => {
    await api.removerContato(campo);
    await recarregar();
    setTirando(false);
    lanche(`${nomeCampo} tirado da conta.`);
  });
  const onde = aviso?.canal === 'whatsapp' ? 'no WhatsApp' : aviso?.canal === 'sms' ? 'por SMS' : aviso?.canal === 'email' ? 'no e-mail' : 'no aparelho';

  return (
    <div className="pilha contato">
      <div className="linha-h" style={{ flexWrap: 'wrap' }}>
        <span aria-hidden>{campo === 'telefone' ? '📱' : '✉️'}</span>
        <span className="cresce"><b>{nomeCampo}</b><br /><span className={atual ? '' : 'sutil'}>{atual ? (campo === 'telefone' ? fmtTel(atual) : atual) : 'Não cadastrado'}</span>
          {naoConfirmado && <><br /><span className="etiqueta atencao">Não confirmado</span> <span className="sutil">{semCodigo ? 'Fica como contato. Para entrar, use o e-mail ou o Google.' : 'Confirme para poder entrar com ele.'}</span></>}</span>
        {etapa === 'ver' && !tirando && (
          <>
            {naoConfirmado && !semCodigo && <button className="botao pequeno" disabled={pedir.enviando} onClick={() => void pedir.executar(atual!)}>Confirmar</button>}
            <button className="botao secundario pequeno" onClick={() => { setNovo(''); setEtapa('novo'); }}>{atual ? 'Trocar' : 'Cadastrar'}</button>
            {atual && podeTirar && <button className="botao fantasma pequeno" onClick={() => setTirando(true)}>Tirar</button>}
          </>
        )}
      </div>

      {tirando && (
        <div className="pilha confirma">
          <p style={{ margin: 0 }}>Tirar o {nomeCampo.toLowerCase()} da conta? Você deixa de entrar por ele.</p>
          <ErroGeral erro={tirar.erro} />
          <div className="linha-h">
            <button className="botao perigo pequeno" disabled={tirar.enviando} onClick={() => void tirar.executar()}>Sim, tirar</button>
            <button className="botao fantasma pequeno" onClick={() => setTirando(false)}>Cancelar</button>
          </div>
        </div>
      )}

      {etapa === 'novo' && (
        <form className="pilha" onSubmit={(e) => { e.preventDefault(); void pedir.executar(novo); }}>
          <Campo rotulo={campo === 'telefone' ? 'Celular novo, com DDD' : 'E-mail novo'} erro={pedir.erro?.campos?.destino}>
            <input value={novo} onChange={(e) => setNovo(e.target.value)} autoFocus required
              inputMode={campo === 'telefone' ? 'tel' : 'email'} type={campo === 'telefone' ? 'tel' : 'email'}
              autoComplete={campo === 'telefone' ? 'tel' : 'email'} placeholder={campo === 'telefone' ? '(11) 90000-0000' : 'nome@exemplo.com'} />
          </Campo>
          {campo === 'telefone' && semCodigo && <p className="sutil" style={{ margin: 0 }}>No momento não mandamos código por celular. O número fica salvo como contato; para entrar, continue usando o e-mail ou o Google.</p>}
          {!pedir.erro?.campos?.destino && <ErroGeral erro={pedir.erro} />}
          <div className={grande ? 'pilha' : 'linha-h'}>
            <button className={`botao ${grande ? 'largo' : 'pequeno'}`} disabled={pedir.enviando || novo.trim().length < 5}>{campo === 'telefone' && semCodigo ? 'Salvar celular' : 'Mandar código'}</button>
            <button type="button" className={`botao fantasma ${grande ? 'largo' : 'pequeno'}`} onClick={() => setEtapa('ver')}>Cancelar</button>
          </div>
        </form>
      )}

      {etapa === 'codigo' && (
        <form className="pilha" onSubmit={(e) => { e.preventDefault(); void confirmar.executar(); }}>
          <p style={{ margin: 0 }}>Mandamos 6 números {onde}. Digite aqui:</p>
          {aviso?.teste && <p className="sutil" style={{ margin: 0 }}>Ambiente de teste: o código é {aviso.teste}</p>}
          <input className="codigo-unico" value={codigo} onChange={(e) => setCodigo(e.target.value.replace(/\D/g, '').slice(0, 6))}
            inputMode="numeric" autoComplete="one-time-code" aria-label="Código de 6 números" autoFocus />
          <ErroGeral erro={confirmar.erro} />
          <div className={grande ? 'pilha' : 'linha-h'}>
            <button className={`botao ${grande ? 'largo' : 'pequeno'}`} disabled={confirmar.enviando || codigo.length !== 6}>Confirmar</button>
            <button type="button" className={`botao fantasma ${grande ? 'largo' : 'pequeno'}`} onClick={() => setEtapa('novo')}>Voltar</button>
          </div>
        </form>
      )}
    </div>
  );
}

function Google({ u, podeTirar }: { u: Usuario; podeTirar: boolean }) {
  const { recarregar } = useSessao();
  const lanche = useLanche();
  const [tirando, setTirando] = useState(false);
  const tirar = useAcao(async () => {
    await api.removerContato('google');
    await recarregar();
    setTirando(false);
    lanche('Conta Google desligada.');
  });
  return (
    <div className="pilha contato">
      <div className="linha-h" style={{ flexWrap: 'wrap' }}>
        <span aria-hidden>🔑</span>
        <span className="cresce"><b>Google</b><br /><span className={u.google ? '' : 'sutil'}>
          {u.google ? 'Ligado: você pode entrar com o Google' : 'Não ligado. Para ligar, use "Entrar com Google" na tela de entrar com o mesmo e-mail.'}</span></span>
        {u.google && podeTirar && !tirando && <button className="botao fantasma pequeno" onClick={() => setTirando(true)}>Desligar</button>}
      </div>
      {tirando && (
        <div className="pilha confirma">
          <p style={{ margin: 0 }}>Desligar o Google? Você continua entrando com código no celular ou no e-mail.</p>
          <ErroGeral erro={tirar.erro} />
          <div className="linha-h">
            <button className="botao perigo pequeno" disabled={tirar.enviando} onClick={() => void tirar.executar()}>Sim, desligar</button>
            <button className="botao fantasma pequeno" onClick={() => setTirando(false)}>Cancelar</button>
          </div>
        </div>
      )}
    </div>
  );
}
