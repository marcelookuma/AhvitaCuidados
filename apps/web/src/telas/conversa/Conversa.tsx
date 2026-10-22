import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import {
  coposHoje, dia, dosesDeHoje, hora, itens as catalogo, periodo, reais, refeicaoDaHora,
  type Acao, type Atendimento, type Item, type Opcao,
} from '@cuidados/core';
import { useSessao } from '../../sessao';
import { useOnline } from '../../hooks';
import { falar } from '../../voz';
import { Folha, FolhaTopo, Ouvir, corDe, useLanche } from '../../componentes/ui';
import { Audio, Foto, prepararFoto, useGravador } from '../../componentes/midia';
import { IcCamera, IcDois, IcEnviar, IcMais, IcMic, IcRelogio, IcTelefone, IcUm, IcVoltar } from '../../componentes/icones';
import { Registrar, type Pedido, type Registro } from './Registrar';
import { Sos } from './Sos';
import { useConversa, type ItemTela } from './useConversa';

type FolhaAberta = null | 'finalizar' | 'como_cuidar' | 'foto' | { recusar: Atendimento } | { naoVou: Atendimento };

const FOTOS: { k: string; e: string; t: string; tipo?: string; rotulo?: string; alerta?: boolean }[] = [
  { k: 'comida', e: '🍽️', t: 'Comida' },
  { k: 'pele', e: '🩹', t: 'Pele ou ferida', tipo: 'pele', rotulo: 'Pele e feridas', alerta: true },
  { k: 'receita', e: '📄', t: 'Receita ou papel', tipo: 'consulta', rotulo: 'Receita nova' },
  { k: 'recibo', e: '🧾', t: 'Recibo de gasto', tipo: 'gasto', rotulo: 'Recibo' },
  { k: 'aparelho', e: '🩺', t: 'Aparelho de pressão ou açúcar', tipo: 'sinais', rotulo: 'Foto do aparelho' },
  { k: 'outra', e: '📷', t: 'Outra coisa' },
];

/**
 * Versão "Conversa" (padrão WhatsApp): cada pessoa cuidada é uma conversa e
 * registrar é mandar mensagem — pelo ＋, pelo 🎤 ou pelo 📷.
 * O mesmo componente aparece no painel do(a) gestor(a) (modo "painel").
 */
export function Conversa({ pessoaId, modo, aoVoltar }: { pessoaId: string; modo: 'cuidador' | 'painel'; aoVoltar?: () => void }) {
  const { eu } = useSessao();
  const euId = eu!.usuario.id;
  const euNome = eu!.usuario.apelido || eu!.usuario.nome?.split(' ')[0] || 'Você';
  const c = useConversa(pessoaId, euId, euNome);
  const online = useOnline();
  const lanche = useLanche();
  const grav = useGravador();
  const [pedido, setPedido] = useState<Pedido>(null);
  const [folha, setFolha] = useState<FolhaAberta>(null);
  const [sos, setSos] = useState(false);
  const [texto, setTexto] = useState('');
  const [recado, setRecado] = useState('');
  const [dica, setDica] = useState(() => localStorage.getItem('cuidados:dica-mais') !== 'vista');
  const fotoRef = useRef<HTMLInputElement>(null);
  const fotoAlvo = useRef<(typeof FOTOS)[number] | null>(null);
  const rolagem = useRef<HTMLDivElement>(null);
  const qtdAnterior = useRef(0);

  const p = c.pessoa;
  const pode = (f: string) => !!p?.permissoes.includes(f);
  const cat = useMemo(() => (p ? catalogo({ apelido: p.apelido, sexo: p.sexo, refeicao: refeicaoDaHora() }) : null), [p]);
  const iconeDe = (tipo: string) => (cat && Object.values(cat).find((i) => i.tipo === tipo)?.e) || (tipo === 'nota' ? '📝' : tipo === 'procedimento' ? '🩺' : '📌');

  // Rola para o fim quando chega coisa nova; lê em voz alta mensagens recebidas (modo Ouvir).
  useEffect(() => {
    const el = rolagem.current;
    if (!el) return;
    const perto = el.scrollHeight - el.scrollTop - el.clientHeight < 160;
    if (perto || qtdAnterior.current === 0) el.scrollTop = el.scrollHeight;
    const novos = c.itens.slice(qtdAnterior.current);
    if (qtdAnterior.current > 0 && eu?.usuario.preferencias?.voz) {
      const m = novos.find((i) => i.tipo_item === 'mensagem' && i.tipo === 'texto' && i.autor?.id !== euId);
      if (m && m.tipo_item === 'mensagem') falar(`${m.autor?.nome} disse: ${m.texto}`);
    }
    qtdAnterior.current = c.itens.length;
  }, [c.itens.length]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!p || !cat) {
    return (
      <div className="chat">
        <header className="topo">{aoVoltar && <button className="ic" onClick={aoVoltar} aria-label="Voltar"><IcVoltar /></button>}<h1>{c.erro ? 'Não foi possível abrir' : 'Abrindo conversa…'}</h1></header>
        {c.erro && <div className="conteudo"><div className="erro-geral">{c.erro.message}</div></div>}
      </div>
    );
  }

  // ------------------------------------------------------------ ações
  const avisoEnvio = (tipo = 'registro') => lanche(
    online ? (tipo === 'registro' ? 'Enviado ✓' : 'Mensagem enviada') : '🕓 Guardado no celular. Vai quando a internet voltar.',
  );

  async function registrar(r: Registro, midia?: { blob: Blob; nome: string }) {
    setPedido(null);
    const id = await c.registrar({ ...r }, midia);
    lanche(online ? `Enviado para quem acompanha ${p!.apelido}` : '🕓 Guardado no celular. Vai quando a internet voltar.', () => void c.desfazer(id));
    if (r.alerta) lanche('⚠️ Quem acompanha foi avisado', () => void c.desfazer(id));
  }

  function acao(a: Acao, item: Item, op: Opcao) {
    if (a === 'teclado_temperatura') return setPedido({ tela: 'teclado', tipo: 'temperatura' });
    if (a === 'teclado_pressao') return setPedido({ tela: 'teclado', tipo: 'pressao' });
    if (a === 'teclado_glicemia') return setPedido({ tela: 'teclado', tipo: 'glicemia' });
    if (a === 'gasto') return setPedido({ tela: 'teclado', tipo: 'gasto', contexto: op.texto });
    if (a === 'audio') { setPedido(null); void gravar(); return; }
    if (a === 'foto') {
      setPedido(null);
      fotoAlvo.current = { k: item.chave, e: item.e, t: op.texto, tipo: item.tipo, rotulo: op.detalhe ?? item.nome, alerta: op.alerta };
      fotoRef.current?.click();
      return;
    }
    if (a === 'grave') {
      void c.registrar({ tipo: item.tipo, rotulo: item.nome, detalhe: op.texto, alerta: true });
      setPedido({ tela: 'grave', texto: `${p!.apelido}: ${op.texto.toLowerCase()}` });
    }
  }

  async function enviarTexto() {
    const t = texto.trim();
    if (!t) return;
    setTexto('');
    await c.mensagem({ tipo: 'texto', texto: t });
  }

  async function gravar() {
    setDica(false);
    if (!(await grav.comecar())) lanche('Permita o uso do microfone para mandar áudio.');
  }

  async function pararGravacao(enviar: boolean) {
    const b = await grav.parar(enviar);
    if (b) {
      await c.mensagem({ tipo: 'audio' }, { blob: b, nome: 'audio.webm' });
      avisoEnvio('mensagem');
    }
  }

  async function aoEscolherFoto(arquivo: File | undefined) {
    if (!arquivo) return;
    const alvo = fotoAlvo.current ?? FOTOS[FOTOS.length - 1];
    const blob = await prepararFoto(arquivo);
    const midia = { blob, nome: 'foto.jpg' };
    if (alvo.tipo && pode('registrar')) {
      await registrar({ tipo: alvo.tipo, rotulo: alvo.rotulo ?? alvo.t, detalhe: alvo.k === 'pele' ? 'Machucado ou ferida (com foto)' : 'Com foto', alerta: alvo.alerta }, midia);
      if (alvo.k === 'aparelho') lanche('Foto enviada. Quem acompanha vê os números.');
    } else {
      await c.mensagem({ tipo: 'foto', texto: alvo.k === 'outra' ? undefined : alvo.t }, midia);
      avisoEnvio('mensagem');
    }
    fotoAlvo.current = null;
    if (fotoRef.current) fotoRef.current.value = '';
  }

  // ------------------------------------------------------------ dados derivados
  const verRegistros = pode('ver_registros') || p.sou_cuidador;
  const doses = pode('registrar') && pode('remedios_ver') ? dosesDeHoje(c.remedios, c.itens).filter((d) => d.agora) : [];
  const meta = p.plano?.agua_meta_copos ?? 0;
  const copos = coposHoje(c.itens);
  const souCuidador = modo === 'cuidador' && p.sou_cuidador;
  const outrosEmServico = (c.extra?.em_servico ?? []).filter((s) => !s.minha);
  const visto = c.extra?.visto_por_outros_ate ?? '';
  const meusDesdeInicio = c.emServicoDesde
    ? c.itens.filter((i) => i.tipo_item === 'registro' && i.autor?.id === euId && !i.anulado && i.em >= c.emServicoDesde!)
    : [];

  const tique = (i: ItemTela) => {
    if (i.pendente) return <span className="tique relogio" title="Guardado no celular — vai quando a internet voltar" aria-label="Guardado no celular"><IcRelogio /></span>;
    if (visto && i.recebido_em <= visto) return <span className="tique visto" title="Quem acompanha viu" aria-label="Visto"><IcDois /></span>;
    return <span className="tique" title="Enviado" aria-label="Enviado"><IcUm /></span>;
  };

  function bolha(i: ItemTela) {
    const minha = i.autor?.id === euId;
    const meta = <div className="meta">{!minha && i.tipo_item === 'mensagem' && i.tipo === 'texto' && <Ouvir rotulo="" texto={`${i.autor?.nome} disse: ${i.texto}`} />}{hora(i.em)}{minha && tique(i)}</div>;
    if (i.tipo_item === 'mensagem') {
      if (i.tipo === 'sistema') return <div className={`sis ${i.texto?.startsWith('⚠️') ? 'aviso' : ''}`}>{i.texto}</div>;
      return (
        <div className={`bolha ${minha ? 'minha' : 'outra'}`}>
          {!minha && <div className="de">{i.autor?.nome}</div>}
          {i.tipo === 'foto' && <Foto midiaId={i.midia_id} blob={i.blob} alt={i.texto ?? 'Foto'} />}
          {i.tipo === 'audio' && <Audio midiaId={i.midia_id} blob={i.blob} />}
          {i.texto && <div>{i.texto}</div>}
          {meta}
        </div>
      );
    }
    const recente = minha && !i.anulado && Date.now() - new Date(i.em).getTime() < 30 * 60_000;
    return (
      <div className={`bolha ${minha ? 'minha' : 'outra'} ${i.alerta ? 'alerta' : ''} ${i.anulado ? 'anulada' : ''}`}>
        {!minha && <div className="de">{i.autor?.nome}</div>}
        {i.midia_id || i.blob ? <Foto midiaId={i.midia_id} blob={i.blob} alt={i.rotulo} /> : null}
        <div className="reg">
          <span className="e" aria-hidden>{iconeDe(i.tipo)}</span>
          <span><b>{i.rotulo}</b>{i.detalhe && <span className="d">{i.detalhe}</span>}</span>
        </div>
        {i.anulado && <div className="fraco">Desfeito</div>}
        <div className="meta">
          {recente && <button className="botao fantasma pequeno" style={{ minHeight: 0, padding: 0, fontSize: '1em' }} onClick={() => void c.desfazer(i.id)}>Desfazer</button>}
          {hora(i.em)}{minha && tique(i)}
        </div>
      </div>
    );
  }

  const cartaoPendencia = (pd: { tipo: 'proposta' | 'confirmar_ida'; atendimento: Atendimento }) => {
    const a = pd.atendimento;
    if (pd.tipo === 'proposta') {
      const fala = `Proposta de atendimento. ${periodo(a.inicio, a.fim)}. ${a.valor_centavos ? reais(a.valor_centavos) + ' pelo atendimento.' : ''} Você decide se aceita.`;
      return (
        <div className="cartao-chat" key={a.id}>
          <div className="cab"><span>📅 Proposta de atendimento · {a.solicitante?.nome}</span><Ouvir rotulo="" texto={fala} /></div>
          <div className="corpo"><span className="e" aria-hidden>🗓️</span><div><b>{periodo(a.inicio, a.fim)}</b>
            <span>{a.valor_centavos ? `${reais(a.valor_centavos)} pelo atendimento` : 'Valor a combinar'}{a.observacao ? ` · ${a.observacao}` : ''}</span>
            <span>Você decide. Recusar não tem nenhuma consequência.</span></div></div>
          <div className="acoes">
            <button className="sim" onClick={() => c.responder(a, true).then(() => lanche('✅ Combinado! Está na sua agenda.')).catch((e) => lanche(e.message))}>✓ Aceitar</button>
            <button className="nao" onClick={() => setFolha({ recusar: a })}>Recusar</button>
          </div>
        </div>
      );
    }
    return (
      <div className="cartao-chat lembrete" key={a.id}>
        <div className="cab"><span>🔔 Confirme o atendimento</span><span>responda até {hora(a.confirmar_ate)}</span></div>
        <div className="corpo"><span className="e" aria-hidden>🚶</span><div><b>Você vai {periodo(a.inicio, a.fim)}?</b>
          <span>Serve só para organizar a cobertura a tempo.</span></div></div>
        <div className="acoes">
          <button className="sim" onClick={() => c.confirmar(a, true).then(() => lanche('Obrigado por confirmar!')).catch((e) => lanche(e.message))}>✓ Vou</button>
          <button className="nao" onClick={() => setFolha({ naoVou: a })}>Não vou</button>
        </div>
      </div>
    );
  };

  // ------------------------------------------------------------ tela
  let diaAnterior = '';
  const contatoLigar = p.ficha?.contatos?.[0];

  return (
    <div className={`chat ${modo === 'cuidador' ? 'modo-cuidador' : ''}`}>
      <header className="topo">
        {aoVoltar && <button className="ic" onClick={aoVoltar} aria-label="Voltar"><IcVoltar /></button>}
        <button className="quem" onClick={() => setFolha('como_cuidar')} aria-label={`${p.apelido}: ver como cuidar`}>
          <span className="avatar p" style={{ background: corDe(p.id) }}>{p.apelido.replace(/^(Dona|Seu)\s+/, '')[0]}</span>
          <span style={{ minWidth: 0 }}><b>{p.apelido}</b><small>Toque para ver como cuidar</small></span>
        </button>
        {modo === 'cuidador' && contatoLigar && <a className="ic" href={`tel:${contatoLigar.telefone}`} aria-label={`Ligar para ${contatoLigar.nome}`}><IcTelefone /></a>}
        <button className="sos" onClick={() => setSos(true)} aria-label="Emergência">🚑 SOS</button>
      </header>

      {!online && <div className="faixa off">📵 Sem internet. Pode continuar: tudo fica guardado e vai sozinho.</div>}
      {c.erro && online && c.erro.status === 403 && <div className="faixa alerta">{c.erro.message}</div>}

      {souCuidador && (c.emServicoDesde ? (
        <div className="barra-servico">
          <span className="ponto" aria-hidden /><span className="cresce">Em serviço desde {hora(c.emServicoDesde)}</span>
          <button className="botao secundario" onClick={() => setFolha('finalizar')}>Finalizar</button>
        </div>
      ) : (
        <div className="barra-servico info">
          <button className="botao grande" onClick={() => { void c.iniciar(); lanche(`Bom atendimento, ${euNome}!`); }}>▶️ Iniciar serviço</button>
        </div>
      ))}
      {!souCuidador && outrosEmServico.length > 0 && (
        <div className="barra-servico"><span className="ponto" aria-hidden />{outrosEmServico.map((s) => `${s.cuidador.nome} em serviço desde ${hora(s.desde)}`).join(' · ')}</div>
      )}
      {p.plano?.importante && (
        <div className="fixado"><span aria-hidden>📌</span><span className="cresce"><b>Importante</b>{p.plano.importante}</span><Ouvir rotulo="" texto={`Importante: ${p.plano.importante}`} /></div>
      )}

      <div className="mensagens" ref={rolagem} aria-live="polite">
        {c.carregando && c.itens.length === 0 && <div className="sis">Carregando…</div>}
        {!verRegistros && <div className="sis">Você vê só as mensagens desta conversa.</div>}
        {c.itens.map((i) => {
          const d = dia(i.em);
          const sep = d !== diaAnterior;
          diaAnterior = d;
          return <Fragment key={i.id}>{sep && <div className="dia-sep">{d}</div>}{bolha(i)}</Fragment>;
        })}
        {modo === 'cuidador' && c.extra?.pendencias.map(cartaoPendencia)}
        {souCuidador && doses.map((d) => (
          <div className="cartao-chat lembrete" key={d.remedio.id + d.horario}>
            <div className="cab"><span>⏰ Hora do remédio</span><span>{d.horario}</span></div>
            <div className="corpo"><span className="e" aria-hidden>💊</span><div><b>{d.remedio.nome} {d.remedio.dose}</b><span>{d.remedio.como_dar}</span></div>
              <Ouvir rotulo="" texto={`Hora do remédio. ${d.remedio.nome} ${d.remedio.dose ?? ''}. ${d.remedio.como_dar ?? ''}`} /></div>
            <div className="acoes">
              <button className="sim" onClick={() => registrar({ tipo: 'remedio', rotulo: 'Deu o remédio', detalhe: `${d.remedio.nome}${d.remedio.dose ? ' ' + d.remedio.dose : ''}`, remedio_id: d.remedio.id, dados: { situacao: 'dado', horario: d.horario } })}>✓ Dei</button>
              <button className="nao" onClick={() => registrar({ tipo: 'remedio', rotulo: 'Remédio', detalhe: `Não quis tomar ${d.remedio.nome}`, remedio_id: d.remedio.id, dados: { situacao: 'recusou', horario: d.horario }, alerta: true })}>Não quis tomar</button>
            </div>
          </div>
        ))}
        {souCuidador && c.emServicoDesde && meta > 0 && copos < meta && (
          <div className="cartao-chat lembrete">
            <div className="cab"><span>💧 Água de hoje</span><span>{copos} de {meta} copos</span></div>
            <div className="corpo"><span className="e" aria-hidden>🥛</span><div><b>{copos} de {meta} copos</b>
              <div className="copos" aria-hidden>{Array.from({ length: meta }, (_, k) => <i key={k} className={k < copos ? 'cheio' : ''} />)}</div></div></div>
            <div className="acoes"><button className="sim" onClick={() => registrar({ tipo: 'agua', rotulo: 'Água', detalhe: 'Bebeu 1 copo', dados: { copos: 1 } })}>＋ Bebeu 1 copo</button></div>
          </div>
        )}
      </div>

      {modo === 'cuidador' && dica && pode('registrar') && !grav.gravando && (
        <div className="dica-compositor"><span>Toque no ＋ para contar o que você fez. Ou toque no 🎤 e fale.</span>
          <button aria-label="Fechar dica" onClick={() => { setDica(false); localStorage.setItem('cuidados:dica-mais', 'vista'); }}>✕</button></div>
      )}
      {(pode('mensagens') || pode('registrar')) && (
        <div className="compositor">
          {pode('registrar') && !grav.gravando && (
            <button className="redondo mais" aria-label="Registrar o que você fez" onClick={() => { setDica(false); setPedido({ tela: 'grade' }); }}><IcMais /></button>
          )}
          {grav.gravando ? (
            <div className="gravando"><span className="vermelho" aria-hidden />Gravando… {Math.floor(grav.segundos / 60)}:{String(grav.segundos % 60).padStart(2, '0')}
              <button onClick={() => void pararGravacao(false)}>🗑 Apagar</button></div>
          ) : (
            <div className="campo-msg">
              <input value={texto} onChange={(e) => setTexto(e.target.value)} placeholder={pode('mensagens') ? 'Escreva aqui' : 'Registre pelo ＋'}
                disabled={!pode('mensagens')} aria-label="Escrever mensagem" onKeyDown={(e) => e.key === 'Enter' && void enviarTexto()} />
              <button className="cam" aria-label="Tirar foto" onClick={() => setFolha('foto')}><IcCamera /></button>
            </div>
          )}
          {pode('mensagens') && (grav.gravando || !texto.trim()
            ? <button className="redondo mic" aria-label={grav.gravando ? 'Enviar áudio' : 'Gravar áudio'} onClick={() => (grav.gravando ? void pararGravacao(true) : void gravar())}>{grav.gravando ? <IcEnviar /> : <IcMic />}</button>
            : <button className="redondo mic" aria-label="Enviar" onClick={() => void enviarTexto()}><IcEnviar /></button>)}
        </div>
      )}
      <input ref={fotoRef} type="file" accept="image/*" capture="environment" hidden onChange={(e) => void aoEscolherFoto(e.target.files?.[0])} />

      <Registrar pedido={pedido} setPedido={setPedido} pessoa={p} remedios={c.remedios} itensFeed={c.itens} aoRegistrar={(r) => void registrar(r)} aoAcao={acao} />

      <Folha aberta={folha === 'foto'} aoFechar={() => setFolha(null)} rotulo="Foto">
        <FolhaTopo e="📷" titulo="O que você vai fotografar?" ouvir={`O que você vai fotografar? ${FOTOS.map((f) => f.t).join(', ')}.`} />
        <div className="nota">A foto vai só para esta conversa. Não fica na galeria do celular.</div>
        <div className="grade-ic">
          {FOTOS.map((f) => (
            <button key={f.k} className="tile" onClick={() => { fotoAlvo.current = f; setFolha(null); fotoRef.current?.click(); }}>
              <span className="e" aria-hidden>{f.e}</span>{f.t}
            </button>
          ))}
        </div>
      </Folha>

      <Folha aberta={folha === 'finalizar'} aoFechar={() => setFolha(null)} rotulo="Finalizar o serviço">
        <FolhaTopo e="👋" titulo="Finalizar o serviço" ouvir="Finalizar o serviço. O app fez o resumo do atendimento. Se quiser, deixe um recado." />
        <div className="nota">O app faz o resumo do atendimento e manda na conversa.</div>
        <div className="pilha" style={{ marginBottom: '.7em' }}>
          {Object.entries(meusDesdeInicio.reduce<Record<string, number>>((m, i) => { const k = i.tipo_item === 'registro' ? i.tipo : ''; m[k] = (m[k] ?? 0) + 1; return m; }, {}))
            .map(([t, n]) => <div key={t} className="linha-h"><span aria-hidden>{iconeDe(t)}</span><b className="cresce">{cat[t]?.nome ?? t}</b><span className="etiqueta ok">{n}× feito</span></div>)}
          {dosesDeHoje(c.remedios, c.itens).filter((d) => !d.feita && d.quando.getTime() < Date.now()).map((d) => (
            <div key={d.remedio.id + d.horario} className="linha-h"><span aria-hidden>⏳</span><span className="cresce">Ficou para depois: {d.remedio.nome} ({d.horario})</span></div>
          ))}
        </div>
        <div className="campo"><label>Recado para quem vem depois (opcional)<textarea value={recado} onChange={(e) => setRecado(e.target.value)} placeholder="Ex.: ela dormiu às 14h" /></label></div>
        <div className="rodape-folha" style={{ flexDirection: 'column' }}>
          <button className="botao largo" onClick={async () => { await c.finalizar(recado.trim() || undefined); setRecado(''); setFolha(null); lanche('✅ Serviço finalizado. Resumo enviado.'); }}>Finalizar e enviar o resumo</button>
          <button className="botao secundario largo" onClick={() => { setFolha(null); void gravar(); }}>🎤 Gravar recado</button>
        </div>
      </Folha>

      <Folha aberta={folha === 'como_cuidar'} aoFechar={() => setFolha(null)} rotulo="Como cuidar">
        <FolhaTopo e="📋" titulo={`Como cuidar de ${p.apelido}`} ouvir={`Como cuidar de ${p.apelido}. ${p.plano?.texto ?? ''}`} />
        {p.plano?.importante && <div className="nota" style={{ color: 'var(--alerta)' }}>📌 {p.plano.importante}</div>}
        {p.plano?.texto ? <p style={{ whiteSpace: 'pre-line', marginTop: 0 }}>{p.plano.texto}</p> : <p className="sutil">Ainda não há orientações.</p>}
        {(p.plano?.cuidados ?? []).map((x, i) => (
          <div key={i} className="cartao" style={{ marginBottom: '.5em' }}><b>{x.titulo}</b><p className="sutil">{x.como}</p><Ouvir texto={`${x.titulo}. ${x.como}`} /></div>
        ))}
        {c.remedios.filter((r) => r.ativo).length > 0 && <h4 style={{ margin: '1em 0 .4em' }}>Remédios</h4>}
        {c.remedios.filter((r) => r.ativo).map((r) => (
          <div key={r.id} className="linha-h" style={{ padding: '.4em 0' }}><span aria-hidden>💊</span>
            <span className="cresce"><b>{r.nome} {r.dose}</b><br /><span className="sutil">{r.horarios.join(' · ')}{r.como_dar ? ` — ${r.como_dar}` : ''}</span></span></div>
        ))}
        <p className="fraco" style={{ marginTop: '1em' }}>O plano descreve o que {p.apelido} precisa. Como organizar o serviço fica com você.</p>
      </Folha>

      <Folha aberta={!!folha && typeof folha === 'object'} aoFechar={() => setFolha(null)} rotulo="Motivo">
        {folha && typeof folha === 'object' && (() => {
          const a = 'recusar' in folha ? folha.recusar : folha.naoVou;
          const recusa = 'recusar' in folha;
          const enviar = (motivo?: string) => (recusa ? c.responder(a, false, motivo) : c.confirmar(a, false, motivo))
            .then(() => { setFolha(null); lanche(recusa ? 'Proposta recusada. Tudo bem!' : 'Avisado. Quem acompanha vai organizar a cobertura.'); })
            .catch((e) => lanche(e.message));
          return (
            <>
              <FolhaTopo e={recusa ? '🙅' : '🙏'} titulo={recusa ? 'Quer dizer o motivo?' : 'Avisar que não vai'} ouvir={recusa ? 'Quer dizer o motivo? É opcional.' : 'Avisar que não vai. Quer dizer o motivo? É opcional.'} />
              <div className="nota">É opcional. {recusa ? 'Recusar não tem nenhuma consequência.' : 'O aviso serve para organizar a cobertura a tempo.'}</div>
              <div className="opcoes">
                {(recusa ? ['Já tenho compromisso', 'Não posso nesse horário'] : ['Estou doente', 'Problema em casa', 'Problema de transporte']).map((m) => (
                  <button key={m} className="opcao" onClick={() => void enviar(m)}>{m}</button>
                ))}
                <button className="opcao" onClick={() => void enviar()}>Prefiro não dizer</button>
              </div>
            </>
          );
        })()}
      </Folha>

      {sos && <Sos pessoa={p} aoFechar={() => setSos(false)} aoAvisar={pode('registrar') ? () => {
        void c.registrar({ tipo: 'algo', rotulo: 'Emergência', detalhe: 'Pediu ajuda pelo SOS', alerta: true });
        lanche('📣 Quem acompanha foi avisado');
      } : undefined} />}
    </div>
  );
}
