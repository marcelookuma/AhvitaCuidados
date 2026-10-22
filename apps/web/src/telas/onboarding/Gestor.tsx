import { useEffect, useState } from 'react';
import { ARRANJOS, MODALIDADES, cpfValido, telefoneValido, type ConviteCriado, type Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar, PERFIL_ATIVO } from '../../rota';
import { useSessao } from '../../sessao';
import { Campo, ErroGeral, useAcao } from '../../componentes/ui';

/**
 * Onboarding do(a) Gestor(a): cadastro da pessoa cuidada.
 * Nascimento obrigatório (não faixa etária); "Somente eu" é a 1ª opção de arranjo;
 * menor de 18 exige o consentimento do responsável legal.
 */
export function OnboardingGestor() {
  const { recarregar } = useSessao();
  const [passo, setPasso] = useState(1);
  const [d, setD] = useState({
    nome_completo: '', apelido: '', nascimento: '', sexo: '', genero: '', cpf: '', relacao: 'Tutor(a)',
    arranjo: 'somente_eu', alergias: '', condicoes: '', convenio: '', endereco: '', contato_nome: '', contato_tel: '',
  });
  const [cons, setCons] = useState({ dados_saude: false, responsavel_legal: false });
  const [pessoa, setPessoa] = useState<Pessoa | null>(null);
  const [convite, setConvite] = useState<ConviteCriado | null>(null);
  const [conv, setConv] = useState({ nome: '', telefone: '', modalidade: 'mei' });
  const mudar = (k: keyof typeof d) => (e: { target: { value: string } }) => setD({ ...d, [k]: e.target.value });

  const idade = d.nascimento ? Math.floor((Date.now() - new Date(d.nascimento + 'T12:00').getTime()) / (365.25 * 86400_000)) : null;
  const menor = idade !== null && idade < 18;
  const comCuidador = ['so_cuidador', 'gestor_cuidador', 'gestor_rede_cuidador'].includes(d.arranjo);

  const criar = useAcao(async () => {
    const p = await api.criarPessoa({
      nome_completo: d.nome_completo, apelido: d.apelido, nascimento: d.nascimento, sexo: d.sexo, genero: d.genero || undefined,
      cpf: d.cpf || undefined, relacao: d.relacao, arranjo: d.arranjo,
      ficha: { alergias: d.alergias, condicoes: d.condicoes, convenio: d.convenio, endereco: d.endereco,
        contatos: d.contato_tel ? [{ nome: d.contato_nome, telefone: d.contato_tel }] : [] },
      consentimentos: cons,
    });
    setPessoa(p);
    await recarregar();
    setPasso(comCuidador ? 5 : 6);
  });

  const convidar = useAcao(async () => {
    if (!pessoa) return;
    setConvite(await api.convidar(pessoa.id, { papel: 'cuidador', nome: conv.nome, telefone: conv.telefone || undefined, modalidade: conv.modalidade, prazo_confirmacao_min: 60 }));
  });

  // Em qual passo fica cada campo (para voltar direto ao campo com problema)
  const PASSO_DO_CAMPO: Record<string, number> = {
    nome_completo: 1, apelido: 1, nascimento: 1, sexo: 1, genero: 1, cpf: 1, relacao: 1, arranjo: 2, ficha: 3, contato_tel: 3,
  };
  const [errosLocais, setErrosLocais] = useState<Record<string, string>>({});
  const errosApi = criar.erro?.campos ?? {};
  const erros: Record<string, string> = { ...errosApi, ...errosLocais };

  // A API recusou algum campo: volta ao passo dele, onde a mensagem aparece embaixo do campo.
  useEffect(() => {
    const campos = Object.keys(criar.erro?.campos ?? {}).filter((c) => c !== 'consentimentos');
    const p = Math.min(...campos.map((c) => PASSO_DO_CAMPO[c] ?? 99));
    if (p < 99) setPasso(p);
  }, [criar.erro]); // eslint-disable-line react-hooks/exhaustive-deps

  const continuar1 = () => {
    const e: Record<string, string> = {};
    if (d.cpf.trim() && !cpfValido(d.cpf)) e.cpf = 'CPF inválido. Confira os números ou deixe em branco.';
    setErrosLocais(e);
    criar.setErro(null);
    if (!Object.keys(e).length) setPasso(2);
  };
  const continuar3 = () => {
    const e: Record<string, string> = {};
    if (d.contato_tel.trim() && !telefoneValido(d.contato_tel)) e.contato_tel = 'Use o celular com DDD, ex.: 11 98888-7777';
    setErrosLocais(e);
    if (!Object.keys(e).length) setPasso(4);
  };

  const terminar = () => { localStorage.setItem(PERFIL_ATIVO, '/g'); navegar(pessoa ? `/g/${pessoa.id}` : '/g', true); };
  const total = comCuidador ? 5 : 4;

  return (
    <main className="acesso" style={{ justifyContent: 'flex-start' }}>
      <div className="passos" aria-label={`Passo ${Math.min(passo, total)} de ${total}`}>
        {Array.from({ length: total }, (_, i) => <i key={i} className={i < passo ? 'feito' : ''} />)}
      </div>

      {passo === 1 && (
        <form className="pilha" onSubmit={(e) => { e.preventDefault(); continuar1(); }}>
          <h1>Quem vai receber o cuidado?</h1>
          <Campo rotulo="Nome completo" erro={erros.nome_completo}><input value={d.nome_completo} onChange={mudar('nome_completo')} required /></Campo>
          <Campo rotulo="Como gosta de ser chamado(a)" erro={erros.apelido} ajuda='Ex.: "Dona Alzira", "Seu Joaquim", "Alice"'><input value={d.apelido} onChange={mudar('apelido')} required /></Campo>
          <Campo rotulo="Data de nascimento" erro={erros.nascimento}><input type="date" value={d.nascimento} onChange={mudar('nascimento')} required max={new Date().toISOString().slice(0, 10)} /></Campo>
          <div className="grade-2">
            <Campo rotulo="Sexo" erro={erros.sexo}>
              <select value={d.sexo} onChange={mudar('sexo')} required>
                <option value="">Escolha</option><option value="feminino">Feminino</option><option value="masculino">Masculino</option>
                <option value="intersexo">Intersexo</option><option value="nao_informar">Prefiro não informar</option>
              </select>
            </Campo>
            <Campo rotulo="Gênero" ajuda="Opcional" erro={erros.genero}><input value={d.genero} onChange={mudar('genero')} placeholder="Como se identifica" /></Campo>
          </div>
          <Campo rotulo="CPF da pessoa cuidada" ajuda="Opcional" erro={erros.cpf}><input value={d.cpf} onChange={mudar('cpf')} inputMode="numeric" /></Campo>
          <Campo rotulo="Sua relação com a pessoa" erro={erros.relacao}>
            <select value={d.relacao} onChange={mudar('relacao')}>
              {['Tutor(a)', 'Filho(a)', 'Cônjuge', 'Neto(a)', 'Irmão/Irmã', 'Mãe/Pai', 'Responsável legal', 'Outro'].map((r) => <option key={r}>{r}</option>)}
            </select>
          </Campo>
          <button className="botao largo">Continuar</button>
        </form>
      )}

      {passo === 2 && (
        <div className="pilha">
          <h1>Quem vai cuidar de {d.apelido || 'quem você cadastrou'}?</h1>
          <p className="sutil">Dá para mudar depois.</p>
          <div className="escolhas">
            {ARRANJOS.map((a) => (
              <button key={a.chave} className="escolha" aria-pressed={d.arranjo === a.chave} onClick={() => setD({ ...d, arranjo: a.chave })}>
                <span className="e">{a.chave === 'somente_eu' ? '🙋' : a.chave.includes('rede') ? '🤝' : '🤲'}</span>
                <span><b>{a.nome}</b><small>{a.desc}</small></span>
              </button>
            ))}
          </div>
          <button className="botao largo" onClick={() => setPasso(3)}>Continuar</button>
          <button className="botao fantasma" onClick={() => setPasso(1)}>Voltar</button>
        </div>
      )}

      {passo === 3 && (
        <form className="pilha" onSubmit={(e) => { e.preventDefault(); continuar3(); }}>
          <h1>Para uma emergência</h1>
          <p className="sutil">Tudo aqui é <b>opcional</b>: preencha o que souber agora e complete depois. Aparece na ficha que o(a) cuidador(a) mostra ao socorrista, mesmo sem internet.</p>
          {erros.ficha && <ErroGeral erro={erros.ficha} />}
          <Campo rotulo="Alergias" ajuda="Opcional"><input value={d.alergias} onChange={mudar('alergias')} placeholder="Ex.: dipirona" /></Campo>
          <Campo rotulo="Doenças ou condições" ajuda="Opcional"><input value={d.condicoes} onChange={mudar('condicoes')} /></Campo>
          <Campo rotulo="Convênio" ajuda="Opcional"><input value={d.convenio} onChange={mudar('convenio')} /></Campo>
          <Campo rotulo="Endereço" ajuda="Opcional"><input value={d.endereco} onChange={mudar('endereco')} autoComplete="street-address" /></Campo>
          <div className="grade-2">
            <Campo rotulo="Contato de emergência" ajuda="Opcional"><input value={d.contato_nome} onChange={mudar('contato_nome')} placeholder="Nome" /></Campo>
            <Campo rotulo="Celular" ajuda="Opcional, com DDD" erro={erros.contato_tel}><input value={d.contato_tel} onChange={mudar('contato_tel')} inputMode="tel" /></Campo>
          </div>
          <button className="botao largo">Continuar</button>
          <button type="button" className="botao fantasma" onClick={() => { setErrosLocais({}); setPasso(4); }}>Pular por agora</button>
          <button type="button" className="botao fantasma" onClick={() => setPasso(2)}>Voltar</button>
        </form>
      )}

      {passo === 4 && (
        <div className="pilha">
          <h1>Proteção dos dados</h1>
          <div className="cartao pilha sutil">
            <p>Os registros de saúde de {d.apelido} ficam guardados com segurança e só aparecem para quem você autorizar, com as permissões que você definir.</p>
            <p>Você pode baixar uma cópia ou excluir tudo quando quiser, em "Dados e privacidade".</p>
          </div>
          <label className="permissao" style={{ alignItems: 'flex-start' }}>
            <input type="checkbox" checked={cons.dados_saude} onChange={(e) => setCons({ ...cons, dados_saude: e.target.checked })} style={{ width: '1.4em', height: '1.4em' }} />
            <span><b>Autorizo</b> o uso dos dados de saúde de {d.apelido} para organizar o cuidado (LGPD, art. 11).</span>
          </label>
          {menor && (
            <label className="permissao" style={{ alignItems: 'flex-start', borderColor: 'var(--ambar)' }}>
              <input type="checkbox" checked={cons.responsavel_legal} onChange={(e) => setCons({ ...cons, responsavel_legal: e.target.checked })} style={{ width: '1.4em', height: '1.4em' }} />
              <span>{d.apelido} tem {idade} anos. <b>Confirmo que sou o(a) responsável legal</b> e autorizo em nome dele(a) (LGPD, art. 14).</span>
            </label>
          )}
          <ErroGeral erro={criar.erro && !Object.keys(errosApi).length ? criar.erro : errosApi.consentimentos} />
          <button className="botao largo" disabled={criar.enviando || !cons.dados_saude || (menor && !cons.responsavel_legal)} onClick={() => criar.executar()}>
            {criar.enviando ? 'Salvando…' : 'Salvar cadastro'}
          </button>
          <button className="botao fantasma" onClick={() => setPasso(3)}>Voltar</button>
        </div>
      )}

      {passo === 5 && pessoa && (
        <div className="pilha">
          <h1>Convide o(a) cuidador(a)</h1>
          {!convite ? (
            <form className="pilha" onSubmit={(e) => { e.preventDefault(); void convidar.executar(); }}>
              <Campo rotulo="Nome"><input value={conv.nome} onChange={(e) => setConv({ ...conv, nome: e.target.value })} required /></Campo>
              <Campo rotulo="Celular (WhatsApp)" ajuda="Para abrir a conversa no seu WhatsApp"><input value={conv.telefone} onChange={(e) => setConv({ ...conv, telefone: e.target.value })} inputMode="tel" /></Campo>
              <Campo rotulo="Forma de contratação" ajuda="MEI e autônomo(a): a agenda é do(a) cuidador(a). Você envia propostas dentro da disponibilidade dele(a).">
                <select value={conv.modalidade} onChange={(e) => setConv({ ...conv, modalidade: e.target.value })}>
                  {Object.entries(MODALIDADES).filter(([k]) => k !== 'empresa').map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Campo>
              <ErroGeral erro={convidar.erro} />
              <button className="botao largo" disabled={convidar.enviando}>Gerar convite</button>
              <button type="button" className="botao fantasma" onClick={() => setPasso(6)}>Fazer isso depois</button>
            </form>
          ) : (
            <div className="pilha">
              <div className="cartao" style={{ textAlign: 'center' }}>
                <p className="sutil">Código do convite</p>
                <p style={{ fontSize: '2em', fontWeight: 800, letterSpacing: '.2em', margin: '.2em 0' }}>{convite.codigo}</p>
                <p className="fraco">Vale por 7 dias</p>
              </div>
              <a className="botao largo" href={convite.whatsapp} target="_blank" rel="noreferrer">💬 Enviar pelo WhatsApp</a>
              <button className="botao secundario largo" onClick={() => void navigator.clipboard?.writeText(convite.link)}>Copiar link</button>
              <button className="botao fantasma" onClick={() => setPasso(6)}>Continuar</button>
            </div>
          )}
        </div>
      )}

      {passo === 6 && (
        <div className="pilha" style={{ textAlign: 'center' }}>
          <span style={{ fontSize: '3em' }} aria-hidden>🎉</span>
          <h1>Tudo pronto</h1>
          <p className="sutil">Agora cadastre os remédios e o "Como cuidar" de {d.apelido}. O que for registrado aparece na conversa e na linha do tempo.</p>
          <button className="botao largo" onClick={terminar}>Abrir o painel</button>
        </div>
      )}
    </main>
  );
}
