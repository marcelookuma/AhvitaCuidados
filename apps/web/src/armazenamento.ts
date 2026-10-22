/**
 * IndexedDB: fila offline (com fotos e áudios em Blob) e cópia da ficha de
 * emergência para o botão SOS funcionar sem internet.
 * Ao sair da conta, tudo é apagado do aparelho.
 */
import type { Armazenamento, Operacao } from '@cuidados/core';

const BANCO = 'ahvita-cuidados';
let conexao: Promise<IDBDatabase> | null = null;

function abrir(): Promise<IDBDatabase> {
  conexao ??= new Promise((ok, falha) => {
    const r = indexedDB.open(BANCO, 1);
    r.onupgradeneeded = () => {
      r.result.createObjectStore('fila', { keyPath: 'id' });
      r.result.createObjectStore('guarda');
    };
    r.onsuccess = () => ok(r.result);
    r.onerror = () => falha(r.error);
  });
  return conexao;
}

async function tx<T>(loja: string, modo: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await abrir();
  return new Promise((ok, falha) => {
    const t = db.transaction(loja, modo);
    const req = fn(t.objectStore(loja));
    t.oncomplete = () => ok(req.result);
    t.onerror = () => falha(t.error);
  });
}

export const armazenamentoFila: Armazenamento = {
  async listar() {
    const todas = await tx<Operacao[]>('fila', 'readonly', (s) => s.getAll() as IDBRequest<Operacao[]>);
    return todas.sort((a, b) => (a.id < b.id ? -1 : 1)); // ULID = ordem de criação
  },
  async guardar(o) { await tx('fila', 'readwrite', (s) => s.put(o)); },
  async remover(id) { await tx('fila', 'readwrite', (s) => s.delete(id)); },
};

export async function guardar<T>(chave: string, valor: T): Promise<void> {
  try { await tx('guarda', 'readwrite', (s) => s.put(valor, chave)); } catch { /* aparelho sem espaço: segue */ }
}

export async function ler<T>(chave: string): Promise<T | undefined> {
  try { return await tx<T | undefined>('guarda', 'readonly', (s) => s.get(chave) as IDBRequest<T | undefined>); } catch { return undefined; }
}

export async function apagarTudo(): Promise<void> {
  try {
    await tx('guarda', 'readwrite', (s) => s.clear());
    await tx('fila', 'readwrite', (s) => s.clear());
  } catch { /* nada a apagar */ }
}
