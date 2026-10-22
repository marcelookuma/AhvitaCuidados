import { useMemo, useState } from 'react';
import {
  MAIS, PRINCIPAIS, dosesDeHoje, febre, glicemiaForaDoComum, itens as catalogo, pressaoForaDoComum, reais, refeicaoDaHora,
  type Acao, type Item, type ItemFeed, type Opcao, type Pessoa, type Remedio,
} from '@cuidados/core';
import { Folha, FolhaTopo } from '../../componentes/ui';

export type Pedido =
  | { tela: 'grade' }
  | { tela: 'mais' }
  | { tela: 'item'; chave: string }
  | { tela: 'teclado'; tipo: 'temperatura' | 'pressao' | 'glicemia' | 'gasto'; contexto?: string }
  | { tela: 'grave'; texto: string }
  | null;

export interface Registro {
  tipo: string;
  rotulo: string;
  detalhe?: string;
  dados?: Record<string, unknown>;
  alerta?: boolean;
  remedio_id?: string;
}

interface Props {
  pedido: Pedido;
  setPedido: (p: Pedido) => void;
  pessoa: Pessoa;
  remedios: Remedio[];
  itensFeed: ItemFeed[];
  aoRegistrar: (r: Registro) => void;
  aoAcao: (a: Acao, item: Item, op: Opcao) => void;
}

/**
 * ＋ Registrar: grade com 9 ícones grandes → pergunta de 1 toque (como enquete).
 * Nunca mais de 5 opções por tela; "Mais coisas" leva às demais.
 */
export function Registrar({ pedido, setPedido, pessoa, remedios, itensFeed, aoRegistrar, aoAcao }: Props) {
  const cat = useMemo(() => catalogo({ apelido: pessoa.apelido, sexo: pessoa.sexo, refeicao: refeicaoDaHora() }), [pessoa.apelido, pessoa.sexo]);
  const fechar = () => setPedido(null);
  if (!pedido) return null;

  if (pedido.tela === 'grade' || pedido.tela === 'mais') {
    const lista = pedido.tela === 'grade' ? PRINCIPAIS : MAIS;
    return (
      <Folha aberta aoFechar={fechar} rotulo="Registrar">
        <FolhaTopo titulo={pedido.tela === 'grade' ? `O que você fez com ${pessoa.apelido}?` : 'Mais coisas'}
          aoVoltar={pedido.tela === 'mais' ? () => setPedido({ tela: 'grade' }) : undefined}
          ouvir={`O que você fez? ${lista.map((k) => cat[k].nome).join(', ')}.`} />
        <div className="grade-ic">
          {lista.map((k) => (
            <button key={k} className={`tile ${cat[k].vermelho ? 'vermelho' : ''}`} onClick={() => setPedido({ tela: 'item', chave: k })}>
              <span className="e" aria-hidden>{cat[k].e}</span>{cat[k].nome}
            </button>
          ))}
        </div>
        {pedido.tela === 'grade' && (
          <button className="botao secundario largo" style={{ marginTop: '.6em' }} onClick={() => setPedido({ tela: 'mais' })}>➕ Mais coisas</button>
        )}
      </Folha>
    );
  }

  if (pedido.tela === 'item') {
    const it = cat[pedido.chave];
    let opcoes = it.opcoes;
    let dica = it.dica;
    if (it.chave === 'remedio') {
      const doses = dosesDeHoje(remedios, itensFeed).filter((d) => !d.feita && (d.agora || d.atrasada || d.quando.getTime() - Date.now() < 3 * 3600_000));
      opcoes = doses.map((d) => ({
        e: '💊', texto: `${d.remedio.nome}${d.remedio.dose ? ' ' + d.remedio.dose : ''} · ${d.horario}`, rotulo: 'Deu o remédio',
        detalhe: `${d.remedio.nome}${d.remedio.dose ? ' ' + d.remedio.dose : ''}`, dados: { situacao: 'dado', horario: d.horario, remedio_id: d.remedio.id },
      }));
      if (!opcoes.length) {
        opcoes = [{ e: '💊', texto: 'Remédio fora dos lembretes', rotulo: 'Deu o remédio', detalhe: 'Remédio fora dos lembretes', dados: { situacao: 'dado' } }];
        dica = remedios.length ? 'Nenhum remédio para agora. Os próximos aparecem perto do horário.' : 'Ainda não há remédios cadastrados.';
      }
      const primeiro = doses[0];
      opcoes = [...opcoes, {
        e: '🚫', texto: 'Não quis tomar', rotulo: 'Remédio', detalhe: primeiro ? `Não quis tomar ${primeiro.remedio.nome}` : 'Não quis tomar',
        dados: { situacao: 'recusou', remedio_id: primeiro?.remedio.id }, alerta: true,
      }];
    }
    return (
      <Folha aberta aoFechar={fechar} rotulo={it.nome}>
        <FolhaTopo e={it.e} titulo={it.pergunta} ouvir={`${it.pergunta} ${opcoes.map((o) => o.texto).join(', ')}.`}
          aoVoltar={() => setPedido({ tela: (PRINCIPAIS as readonly string[]).includes(it.chave) ? 'grade' : 'mais' })} />
        {dica && <div className="nota">{dica}</div>}
        <div className="opcoes">
          {opcoes.map((o, i) => (
            <button key={i} className={`opcao ${o.acao === 'grave' ? 'vermelho' : ''}`} onClick={() => {
              if (o.acao) { aoAcao(o.acao, it, o); return; }
              const remedioId = (o.dados as any)?.remedio_id as string | undefined;
              const dados = o.dados ? { ...o.dados } : undefined;
              if (dados) delete (dados as any).remedio_id;
              aoRegistrar({ tipo: it.tipo, rotulo: o.rotulo ?? it.nome, detalhe: o.detalhe ?? o.texto, dados, alerta: o.alerta, remedio_id: remedioId });
            }}>
              <span className="e" aria-hidden>{o.e}</span><span>{o.texto}</span>
            </button>
          ))}
        </div>
      </Folha>
    );
  }

  if (pedido.tela === 'teclado') return <Teclado pedido={pedido} fechar={fechar} aoRegistrar={aoRegistrar} />;

  if (pedido.tela === 'grave') {
    return (
      <Folha aberta aoFechar={fechar} rotulo="Pode ser grave">
        <FolhaTopo e="⚠️" titulo="Pode ser grave" ouvir={`Pode ser grave. ${pedido.texto}. Quem acompanha já foi avisado. Se precisar, ligue 192.`} />
        <div className="nota" style={{ color: 'var(--alerta)', borderColor: 'var(--alerta)' }}>{pedido.texto}. Quem acompanha já foi avisado.</div>
        <div className="pilha">
          <a className="sos-botao cheio" href="tel:192"><span className="e">📞</span><span>Ligar 192<small>SAMU · ambulância</small></span></a>
          <button className="botao secundario largo" onClick={fechar}>Agora está tudo bem</button>
        </div>
      </Folha>
    );
  }
  return null;
}

/** Teclado na tela (sinais vitais e gasto): números grandes, sem teclado do celular. */
function Teclado({ pedido, fechar, aoRegistrar }: { pedido: Extract<Pedido, { tela: 'teclado' }>; fechar: () => void; aoRegistrar: (r: Registro) => void }) {
  const [v, setV] = useState('');
  const [sist, setSist] = useState<string | null>(null);
  const cfg = {
    temperatura: { e: '🌡️', t: 'Qual a temperatura?', un: '°C', max: 4, virgula: true },
    pressao: { e: '🩺', t: sist === null ? 'Pressão: o número de CIMA (maior)' : 'Agora o número de BAIXO (menor)', un: 'mmHg', max: 3, virgula: false },
    glicemia: { e: '🩸', t: 'Açúcar no sangue (mg/dL)', un: 'mg/dL', max: 3, virgula: false },
    gasto: { e: '💵', t: `Quanto você gastou${pedido.contexto ? ' — ' + pedido.contexto : ''}?`, un: '', max: 7, virgula: true },
  }[pedido.tipo];
  const tecla = (k: string) => {
    if (k === '⌫') return setV(v.slice(0, -1));
    if (k === ',') { if (cfg.virgula && !v.includes(',') && v) setV(v + ','); return; }
    if (v.replace(',', '').length >= cfg.max) return;
    let n = v + k;
    if (pedido.tipo === 'temperatura' && n.length === 2 && !n.includes(',')) n += ',';
    setV(n);
  };
  const num = parseFloat(v.replace(',', '.'));
  const enviar = () => {
    if (!num) return;
    if (pedido.tipo === 'temperatura') {
      aoRegistrar({ tipo: 'sinais', rotulo: 'Temperatura', detalhe: `${v} °C${febre(num) ? ' · febre' : ''}`, dados: { temperatura: num }, alerta: febre(num) });
    } else if (pedido.tipo === 'pressao') {
      if (sist === null) { setSist(v); setV(''); return; }
      const s = parseInt(sist, 10), d = parseInt(v, 10);
      aoRegistrar({ tipo: 'sinais', rotulo: 'Pressão', detalhe: `${s} por ${d}`, dados: { pressao_sist: s, pressao_diast: d }, alerta: pressaoForaDoComum(s, d) });
    } else if (pedido.tipo === 'glicemia') {
      aoRegistrar({ tipo: 'sinais', rotulo: 'Açúcar no sangue', detalhe: `${num} mg/dL`, dados: { glicemia: num }, alerta: glicemiaForaDoComum(num) });
    } else {
      const c = Math.round(num * 100);
      aoRegistrar({ tipo: 'gasto', rotulo: 'Gastei dinheiro', detalhe: `${pedido.contexto ?? 'Gasto'} · ${reais(c)}`, dados: { valor_centavos: c, onde: pedido.contexto } });
    }
  };
  return (
    <Folha aberta aoFechar={fechar} rotulo={cfg.t}>
      <FolhaTopo e={cfg.e} titulo={cfg.t} ouvir={cfg.t} />
      <div className="visor" aria-live="polite">{pedido.tipo === 'gasto' ? 'R$ ' : ''}{v || '—'} {cfg.un}</div>
      <div className="teclado">
        {['1', '2', '3', '4', '5', '6', '7', '8', '9', cfg.virgula ? ',' : '', '0', '⌫'].map((k, i) =>
          k ? <button key={i} onClick={() => tecla(k)} aria-label={k === '⌫' ? 'Apagar' : k}>{k}</button> : <span key={i} />)}
      </div>
      <div className="rodape-folha">
        <button className="botao largo" disabled={!num} onClick={enviar}>{pedido.tipo === 'pressao' && sist === null ? 'Próximo' : 'Enviar'}</button>
      </div>
    </Folha>
  );
}
