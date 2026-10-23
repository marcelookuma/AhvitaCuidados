import { useState } from 'react';
import type { Pessoa } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar } from '../../rota';
import { useCarregar, useRepetir } from '../../hooks';
import { Carregando, ErroGeral, Vazio } from '../../componentes/ui';
import { Conversa } from '../conversa/Conversa';
import { LinhaDoTempo } from '../acompanhamento/LinhaDoTempo';
import { Remedios } from '../acompanhamento/Remedios';
import { Plano } from '../acompanhamento/Plano';
import { nomes } from '../acompanhamento/LigarEmpresa';

type Aba = { k: string; e: string; t: string; pode: (p: string[]) => boolean };

/** Uma aba para cada coisa que o(a) gestor(a) liberou. A API confere de novo (403 se não pode). */
const ABAS: Aba[] = [
  { k: 'linha', e: '🕑', t: 'Linha do tempo', pode: (p) => p.includes('ver_registros') },
  { k: 'conversa', e: '💬', t: 'Conversa', pode: (p) => p.includes('mensagens') },
  { k: 'remedios', e: '💊', t: 'Remédios', pode: (p) => p.includes('remedios_ver') },
  { k: 'plano', e: '📋', t: 'Como cuidar', pode: (p) => p.includes('plano_ver') },
  { k: 'ficha', e: '🚑', t: 'Ficha de emergência', pode: (p) => p.includes('ficha_emergencia') },
];

/**
 * Cliente da empresa (pessoa cuidada ligada a ela). As permissões vêm da própria pessoa
 * (GET /pessoas/{id}), então o que o(a) gestor(a) muda aparece aqui ao abrir, a cada 30 s ou ao tocar em "Atualizar".
 */
export function ClienteEmpresa({ empresaId, pessoaId, gestor }: { empresaId: string; pessoaId: string; gestor?: string }) {
  const p = useCarregar(() => api.pessoa(pessoaId), [pessoaId]);
  const [aba, setAba] = useState<string | null>(null);
  useRepetir(() => void p.recarregar(true), 30000); // mudança do(a) gestor(a) chega sem precisar sair da tela
  const voltar = <button className="botao fantasma pequeno" onClick={() => navegar(`/e/${empresaId}/clientes`)}>← Clientes</button>;
  if (p.erro) return <>{voltar}<ErroGeral erro={p.erro} /></>;
  if (!p.dados) return <Carregando />;
  const pessoa: Pessoa = p.dados;
  const perm = pessoa.permissoes;
  const abas = ABAS.filter((a) => a.pode(perm));
  const atual = abas.find((a) => a.k === aba) ?? abas[0];
  const soFotos = perm.includes('fotos') && !perm.includes('ver_registros') && !perm.includes('mensagens');

  return (
    <>
      <div className="linha-h" style={{ flexWrap: 'wrap' }}>
        {voltar}
        <span className="cresce" />
        <span className="sutil">Gestor(a): {gestor ?? '—'}</span>
        <button className="botao fantasma pequeno" onClick={() => void p.recarregar()}>↻ Atualizar</button>
      </div>
      <h1 style={{ margin: 0 }}>{pessoa.apelido}</h1>
      <p className="aviso-empresa">A empresa vê: <b>{nomes(perm)}</b>. Quem decide é o(a) gestor(a). Os registros são do cuidado de {pessoa.apelido}: não servem para controlar horário ou tarefas de cuidadores(as) MEI ou autônomos(as).</p>

      {abas.length > 1 && (
        <nav className="abas" aria-label={`O que a empresa vê de ${pessoa.apelido}`}>
          {abas.map((a) => (
            <button key={a.k} aria-current={a.k === atual?.k ? 'page' : undefined} onClick={() => setAba(a.k)}><span aria-hidden>{a.e}</span> {a.t}</button>
          ))}
        </nav>
      )}

      {!atual && <Vazio e="🔒">O(a) gestor(a) liberou só a organização de atendimentos de {pessoa.apelido}. Os atendimentos ficam em “Atendimentos”.</Vazio>}
      {atual?.k === 'linha' && <LinhaDoTempo pessoa={pessoa} />}
      {atual?.k === 'conversa' && <div className="chat-painel"><Conversa pessoaId={pessoa.id} modo="painel" /></div>}
      {atual?.k === 'remedios' && <Remedios pessoa={pessoa} />}
      {atual?.k === 'plano' && <Plano pessoa={pessoa} aoSalvar={() => void p.recarregar(true)} />}
      {atual?.k === 'ficha' && <Ficha pessoa={pessoa} />}
      {soFotos && <p className="sutil" style={{ margin: 0 }}>📷 As fotos aparecem na linha do tempo e na conversa. Para vê-las, o(a) gestor(a) precisa liberar também uma dessas.</p>}
    </>
  );
}

function Ficha({ pessoa }: { pessoa: Pessoa }) {
  const f = pessoa.ficha ?? {};
  return (
    <section className="cartao pilha">
      <div className="ficha">
        <div><b>Nome</b>{pessoa.nome_completo} · {pessoa.idade} anos</div>
        <div><b>Alergia</b><span className="alergia">{f.alergias ? f.alergias.toUpperCase() : 'Nenhuma informada'}</span></div>
        <div><b>Doenças</b>{f.condicoes || '—'}</div>
        <div><b>Convênio</b>{f.convenio || '—'}</div>
        <div><b>Endereço</b>{f.endereco || '—'}</div>
        {f.observacoes && <div><b>Observações</b>{f.observacoes}</div>}
        {(f.contatos ?? []).map((c, i) => <div key={i}><b>Contato</b>{c.nome} · <a href={`tel:${c.telefone}`}>{c.telefone}</a></div>)}
      </div>
      <p className="sutil" style={{ margin: 0 }}>Para usar numa emergência. Dados de saúde: não compartilhe fora da equipe que atende {pessoa.apelido}.</p>
    </section>
  );
}
