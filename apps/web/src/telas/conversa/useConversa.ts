import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ErroApi, ulid, type Atendimento, type Feed, type ItemFeed, type Operacao, type Pessoa, type Remedio } from '@cuidados/core';
import { api, fila } from '../../servicos';
import { armazenamentoFila, guardar, ler } from '../../armazenamento';
import { useRepetir } from '../../hooks';

/** Item na tela: o que veio do servidor ou o que ainda está na fila do aparelho. */
export type ItemTela = ItemFeed & { pendente?: boolean; blob?: Blob };

function daFila(o: Operacao, euId: string, euNome: string): ItemTela | null {
  const c = o.corpo as Record<string, any>;
  const autor = { id: euId, nome: euNome };
  const blob = 'midia' in o ? o.midia?.blob : undefined;
  if (o.op === 'registro') {
    return {
      tipo_item: 'registro', id: o.id, tipo: c.tipo, rotulo: c.rotulo, detalhe: c.detalhe ?? null, dados: c.dados ?? null,
      alerta: !!c.alerta, remedio_id: c.remedio_id ?? null, midia_id: c.midia_id ?? null, autor, em: c.em, recebido_em: c.em,
      anulado: false, anula_id: null, pendente: true, blob,
    };
  }
  if (o.op === 'mensagem') {
    return { tipo_item: 'mensagem', id: o.id, tipo: c.tipo, texto: c.texto ?? null, midia_id: c.midia_id ?? null, autor, em: c.em, recebido_em: c.em, pendente: true, blob };
  }
  if (o.op === 'iniciar') {
    return { tipo_item: 'mensagem', id: o.id, tipo: 'sistema', texto: '▶️ Serviço iniciado (guardado no aparelho)', midia_id: null, autor: null, em: c.em, recebido_em: c.em, pendente: true };
  }
  return null;
}

export function useConversa(pessoaId: string, euId: string, euNome: string) {
  const [pessoa, setPessoa] = useState<Pessoa | null>(null);
  const [remedios, setRemedios] = useState<Remedio[]>([]);
  const [servidor, setServidor] = useState<Map<string, ItemFeed>>(new Map());
  const [ops, setOps] = useState<Operacao[]>(fila.pendentes());
  const [extra, setExtra] = useState<Omit<Feed, 'itens' | 'cursor' | 'tem_mais'> | null>(null);
  const [erro, setErro] = useState<ErroApi | null>(null);
  const [carregando, setCarregando] = useState(true);
  const cursor = useRef<string | undefined>();
  const vistoEnviado = useRef<string>('');

  // Cópia local (sem internet): pessoa (ficha p/ SOS), remédios e últimos itens.
  const chave = `conversa:${pessoaId}`;

  const buscar = useCallback(async (inicial = false) => {
    try {
      const f = await api.feed(pessoaId, inicial ? {} : { desde: cursor.current });
      cursor.current = f.cursor;
      setServidor((m) => {
        const n = inicial ? new Map<string, ItemFeed>() : new Map(m);
        f.itens.forEach((i) => n.set(i.id, i));
        if (inicial || f.itens.length) void guardar(chave + ':itens', [...n.values()].slice(-200));
        return n;
      });
      const { itens: _i, cursor: _c, tem_mais: _t, ...resto } = f;
      setExtra(resto);
      setErro(null);
      const ultimo = f.itens[f.itens.length - 1]?.recebido_em ?? '';
      if (ultimo && ultimo !== vistoEnviado.current && document.visibilityState === 'visible') {
        vistoEnviado.current = ultimo;
        api.visto(pessoaId).catch(() => {});
      }
    } catch (e) {
      setErro(e as ErroApi);
    }
  }, [pessoaId, chave]);

  useEffect(() => {
    let vivo = true;
    cursor.current = undefined;
    setCarregando(true);
    (async () => {
      const [p, r, itens] = await Promise.all([ler<Pessoa>(chave + ':pessoa'), ler<Remedio[]>(chave + ':remedios'), ler<ItemFeed[]>(chave + ':itens')]);
      if (!vivo) return;
      if (p) setPessoa(p);
      if (r) setRemedios(r);
      if (itens) setServidor(new Map(itens.map((i) => [i.id, i])));
      try {
        const pe = await api.pessoa(pessoaId);
        if (!vivo) return;
        setPessoa(pe);
        void guardar(chave + ':pessoa', pe);
        if (pe.permissoes.includes('remedios_ver')) {
          const rr = (await api.remedios(pessoaId)).remedios;
          if (vivo) { setRemedios(rr); void guardar(chave + ':remedios', rr); }
        }
      } catch (e) {
        if (vivo) setErro(e as ErroApi);
      }
      await buscar(true);
      if (vivo) setCarregando(false);
    })();
    return () => { vivo = false; };
  }, [pessoaId, buscar, chave]);

  useEffect(() => fila.ouvir((p, falhou) => {
    setOps(p);
    if (falhou) setErro(falhou.erro);
    // Quando a fila esvazia, busca o que o servidor confirmou.
    if (p.length === 0) void buscar(false);
  }), [buscar]);

  useRepetir(() => void buscar(false), 5000, !carregando);

  const itens: ItemTela[] = useMemo(() => {
    const lista: ItemTela[] = [...servidor.values()];
    for (const o of ops) {
      const alvo = 'pessoaId' in o ? o.pessoaId : undefined;
      if (alvo !== pessoaId || servidor.has(o.id)) continue;
      const it = daFila(o, euId, euNome);
      if (it) lista.push(it);
    }
    return lista.sort((a, b) => (a.em < b.em ? -1 : a.em > b.em ? 1 : a.id < b.id ? -1 : 1));
  }, [servidor, ops, pessoaId, euId, euNome]);

  // ---------------------------------------------------------------- ações
  const agora = () => new Date().toISOString();

  const registrar = useCallback(async (corpo: Record<string, unknown>, midia?: { blob: Blob; nome: string }) => {
    const id = ulid();
    await fila.adicionar({ op: 'registro', id, pessoaId, corpo: { id, em: agora(), ...corpo }, midia });
    return id;
  }, [pessoaId]);

  const mensagem = useCallback(async (corpo: { tipo: string; texto?: string }, midia?: { blob: Blob; nome: string }) => {
    const id = ulid();
    await fila.adicionar({ op: 'mensagem', id, pessoaId, corpo: { id, em: agora(), ...corpo }, midia });
    return id;
  }, [pessoaId]);

  const minhaPresenca = extra?.em_servico.find((s) => s.minha) ?? null;
  const presencaLocal = ops.find((o): o is Extract<Operacao, { op: 'iniciar' }> => o.op === 'iniciar' && o.pessoaId === pessoaId);
  const presencaId = minhaPresenca?.id ?? presencaLocal?.id ?? null;
  const finalizacaoLocal = ops.some((o) => o.op === 'finalizar' && o.presencaId === presencaId);
  const emServicoDesde = finalizacaoLocal ? null : minhaPresenca?.desde ?? presencaLocal?.corpo.em ?? null;

  const iniciar = useCallback(async () => {
    const id = ulid();
    await fila.adicionar({ op: 'iniciar', id, pessoaId, corpo: { id, em: agora() } });
  }, [pessoaId]);

  const finalizar = useCallback(async (recado?: string) => {
    if (!presencaId) return;
    await fila.adicionar({ op: 'finalizar', id: ulid(), presencaId, corpo: { em: agora(), recado } });
  }, [presencaId]);

  const desfazer = useCallback(async (id: string) => {
    const naFila = ops.find((o) => o.id === id);
    if (naFila) {
      await armazenamentoFila.remover(id);
      await fila.carregar();
      return;
    }
    await api.anular(id, 'Registro desfeito');
    await buscar(false);
    setServidor((m) => {
      const n = new Map(m);
      const r = n.get(id);
      if (r && r.tipo_item === 'registro') n.set(id, { ...r, anulado: true });
      return n;
    });
  }, [ops, buscar]);

  const responder = useCallback(async (a: Atendimento, aceitar: boolean, motivo?: string) => {
    await api.responderAtendimento(a.id, aceitar, motivo);
    await buscar(false);
  }, [buscar]);

  const confirmar = useCallback(async (a: Atendimento, vou: boolean, motivo?: string) => {
    await api.confirmarIda(a.id, vou, motivo);
    await buscar(false);
  }, [buscar]);

  return {
    pessoa, remedios, itens, extra, erro, carregando, pendentes: ops.filter((o) => 'pessoaId' in o && o.pessoaId === pessoaId).length,
    emServicoDesde, presencaId, registrar, mensagem, iniciar, finalizar, desfazer, responder, confirmar, recarregar: () => buscar(true),
  };
}
