import { useState } from 'react';
import { ARRANJOS, type Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar } from '../../rota';
import { Campo, ErroGeral, useAcao, useLanche } from '../../componentes/ui';
import { BotaoBaixar } from '../../componentes/baixar';

/** Cadastro, ficha de emergência e direitos LGPD (cópia dos dados e exclusão). */
export function Dados({ pessoa, aoSalvar }: { pessoa: Pessoa; aoSalvar: () => void }) {
  const lanche = useLanche();
  const f = pessoa.ficha ?? {};
  const [d, setD] = useState({
    nome_completo: pessoa.nome_completo, apelido: pessoa.apelido, nascimento: pessoa.nascimento, sexo: pessoa.sexo,
    genero: pessoa.genero ?? '', cpf: pessoa.cpf ?? '', arranjo: pessoa.arranjo,
  });
  const [ficha, setFicha] = useState({
    alergias: f.alergias ?? '', condicoes: f.condicoes ?? '', convenio: f.convenio ?? '', endereco: f.endereco ?? '', observacoes: f.observacoes ?? '',
    contato_nome: f.contatos?.[0]?.nome ?? '', contato_tel: f.contatos?.[0]?.telefone ?? '',
  });
  const [confirmar, setConfirmar] = useState('');
  const salvar = useAcao(async () => {
    const { contato_nome, contato_tel, ...resto } = ficha;
    await api.atualizarPessoa(pessoa.id, {
      ...d, genero: d.genero || null, cpf: d.cpf || null,
      ficha: { ...resto, contatos: contato_tel ? [{ nome: contato_nome, telefone: contato_tel }] : [] },
    });
    lanche('Cadastro salvo');
    aoSalvar();
  });
  const excluir = useAcao(async () => {
    await api.excluirPessoa(pessoa.id, confirmar);
    lanche('Dados excluídos');
    navegar('/g', true);
    aoSalvar();
  });
  const er = salvar.erro?.campos ?? {};
  const m = (k: keyof typeof d) => (e: { target: { value: string } }) => setD({ ...d, [k]: e.target.value } as typeof d);
  const mf = (k: keyof typeof ficha) => (e: { target: { value: string } }) => setFicha({ ...ficha, [k]: e.target.value });

  return (
    <>
      <h1>Cadastro e privacidade</h1>
      <form className="cartao pilha" onSubmit={(e) => { e.preventDefault(); void salvar.executar(); }}>
        <h3 style={{ margin: 0 }}>Cadastro</h3>
        <div className="grade-2">
          <Campo rotulo="Nome completo" erro={er.nome_completo}><input value={d.nome_completo} onChange={m('nome_completo')} /></Campo>
          <Campo rotulo="Como gosta de ser chamado(a)" erro={er.apelido}><input value={d.apelido} onChange={m('apelido')} /></Campo>
          <Campo rotulo="Data de nascimento" erro={er.nascimento}><input type="date" value={d.nascimento} onChange={m('nascimento')} /></Campo>
          <Campo rotulo="Sexo"><select value={d.sexo} onChange={m('sexo')}><option value="feminino">Feminino</option><option value="masculino">Masculino</option><option value="intersexo">Intersexo</option><option value="nao_informar">Prefiro não informar</option></select></Campo>
          <Campo rotulo="Gênero" ajuda="Opcional"><input value={d.genero} onChange={m('genero')} /></Campo>
          <Campo rotulo="CPF" ajuda="Opcional" erro={er.cpf}><input value={d.cpf} onChange={m('cpf')} inputMode="numeric" /></Campo>
        </div>
        <Campo rotulo="Arranjo de cuidado"><select value={d.arranjo} onChange={m('arranjo')}>{ARRANJOS.map((a) => <option key={a.chave} value={a.chave}>{a.nome}</option>)}</select></Campo>
        <h3 style={{ margin: '.6em 0 0' }}>🚑 Ficha de emergência</h3>
        <div className="grade-2">
          <Campo rotulo="Alergias"><input value={ficha.alergias} onChange={mf('alergias')} /></Campo>
          <Campo rotulo="Doenças ou condições"><input value={ficha.condicoes} onChange={mf('condicoes')} /></Campo>
          <Campo rotulo="Convênio"><input value={ficha.convenio} onChange={mf('convenio')} /></Campo>
          <Campo rotulo="Endereço"><input value={ficha.endereco} onChange={mf('endereco')} /></Campo>
          <Campo rotulo="Contato de emergência"><input value={ficha.contato_nome} onChange={mf('contato_nome')} /></Campo>
          <Campo rotulo="Celular do contato"><input value={ficha.contato_tel} onChange={mf('contato_tel')} inputMode="tel" /></Campo>
        </div>
        <Campo rotulo="Observações"><input value={ficha.observacoes} onChange={mf('observacoes')} /></Campo>
        <ErroGeral erro={salvar.erro && !Object.keys(er).length ? salvar.erro : null} />
        <button className="botao" style={{ alignSelf: 'flex-start' }} disabled={salvar.enviando}>Salvar</button>
      </form>

      <section className="cartao pilha">
        <h3 style={{ margin: 0 }}>🔒 Seus direitos (LGPD)</h3>
        <p className="sutil" style={{ margin: 0 }}>Baixe uma cópia de tudo o que foi registrado sobre {pessoa.apelido}, em formato aberto (JSON).</p>
        <BotaoBaixar rotulo="Baixar cópia dos dados" nome={`ahvita-cuidados-${pessoa.id}.json`} baixar={() => api.exportarPessoa(pessoa.id)} className="botao secundario" style={{ alignSelf: 'flex-start' }} />
        <div className="separador" />
        <p style={{ margin: 0 }}><b>Excluir os dados de {pessoa.apelido}</b></p>
        <p className="sutil" style={{ margin: 0 }}>Apaga registros, mensagens, fotos, áudios e remédios. Fica só o registro de presença (quem atendeu e quando), sem dados de saúde, para comprovar os serviços prestados. Não dá para desfazer.</p>
        <Campo rotulo={`Para confirmar, digite: ${pessoa.apelido}`} erro={excluir.erro?.campos?.confirmar}><input value={confirmar} onChange={(e) => setConfirmar(e.target.value)} /></Campo>
        <ErroGeral erro={excluir.erro && !excluir.erro.campos?.confirmar ? excluir.erro : null} />
        <button className="botao perigo" style={{ alignSelf: 'flex-start' }} disabled={confirmar.trim().toLowerCase() !== pessoa.apelido.toLowerCase() || excluir.enviando} onClick={() => void excluir.executar()}>Excluir definitivamente</button>
      </section>
    </>
  );
}
