/**
 * Fila offline ("Guardado no celular — vai quando a internet voltar").
 * Cada envio tem um id gerado no aparelho; a API é idempotente, então
 * reenviar nunca duplica. O armazenamento é trocável:
 *   web → IndexedDB (apps/web/src/armazenamento.ts)
 *   app → AsyncStorage/SQLite (Expo)
 */
import { ErroApi, type Api } from './api';

export type Operacao =
  | { op: 'registro'; id: string; pessoaId: string; corpo: Record<string, unknown>; midia?: { blob: Blob; nome: string } }
  | { op: 'mensagem'; id: string; pessoaId?: string; conversaId?: string; corpo: Record<string, unknown>; midia?: { blob: Blob; nome: string } }
  | { op: 'iniciar'; id: string; pessoaId: string; corpo: { id: string; em: string } }
  | { op: 'finalizar'; id: string; presencaId: string; corpo: { em: string; recado?: string } };

export interface Armazenamento {
  listar(): Promise<Operacao[]>;
  guardar(o: Operacao): Promise<void>;
  remover(id: string): Promise<void>;
}

export type Ouvinte = (pendentes: Operacao[], falhou?: { op: Operacao; erro: ErroApi }) => void;

export function criarFila(api: Api, arm: Armazenamento) {
  const ouvintes = new Set<Ouvinte>();
  let rodando = false;
  let cache: Operacao[] = [];

  async function avisar(falhou?: { op: Operacao; erro: ErroApi }) {
    cache = await arm.listar();
    ouvintes.forEach((f) => f(cache, falhou));
  }

  async function enviarUma(o: Operacao): Promise<void> {
    let midiaId: string | undefined;
    if ('midia' in o && o.midia) {
      const alvo = o.op === 'registro' ? { pessoa_id: o.pessoaId } : o.pessoaId ? { pessoa_id: o.pessoaId } : { conversa_id: o.conversaId };
      midiaId = (await api.enviarMidia(o.midia.blob, o.midia.nome, alvo)).id;
      // Depois de subir a mídia, guarda sem o arquivo: se cair agora, não sobe de novo.
      const semArquivo = { ...o, midia: undefined, corpo: { ...o.corpo, midia_id: midiaId } } as Operacao;
      await arm.guardar(semArquivo);
      o = semArquivo;
    }
    switch (o.op) {
      case 'registro': await api.registrar(o.pessoaId, o.corpo); break;
      case 'mensagem':
        if (o.pessoaId) await api.mensagem(o.pessoaId, o.corpo as any);
        else await api.enviarDireta(o.conversaId!, o.corpo as any);
        break;
      case 'iniciar': await api.iniciarServico(o.pessoaId, o.corpo); break;
      case 'finalizar': await api.finalizarServico(o.presencaId, o.corpo); break;
    }
  }

  async function processar(): Promise<void> {
    if (rodando) return;
    rodando = true;
    try {
      // Em ordem: "Iniciar serviço" antes dos registros, que antes de "Finalizar".
      for (const o of await arm.listar()) {
        try {
          await enviarUma(o);
          await arm.remover(o.id);
          await avisar();
        } catch (e) {
          const erro = e instanceof ErroApi ? e : new ErroApi(0, 'sem_conexao', 'Sem internet');
          if (erro.semConexao || erro.status === 401 || erro.status === 429) break; // tenta depois
          // Erro definitivo (ex.: permissão removida): tira da fila e avisa a tela.
          await arm.remover(o.id);
          await avisar({ op: o, erro });
        }
      }
    } finally {
      rodando = false;
    }
  }

  return {
    async adicionar(o: Operacao) {
      await arm.guardar(o);
      await avisar();
      void processar();
    },
    processar,
    pendentes: () => cache,
    async carregar() { await avisar(); },
    ouvir(f: Ouvinte) { ouvintes.add(f); f(cache); return () => { ouvintes.delete(f); }; },
  };
}

export type Fila = ReturnType<typeof criarFila>;
