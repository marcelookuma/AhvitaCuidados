/** ULID gerado no aparelho: permite registrar sem internet e reenviar sem duplicar. */
const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

let ultimoMs = 0;
let ultimoAleatorio: number[] = [];

function aleatorios(n: number): number[] {
  const bytes = new Uint8Array(n);
  globalThis.crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b % 32);
}

export function ulid(ms: number = Date.now()): string {
  let tempo = '';
  let t = ms;
  for (let i = 0; i < 10; i++) {
    tempo = ALFABETO[t % 32] + tempo;
    t = Math.floor(t / 32);
  }
  // Monotônico no mesmo milissegundo: mantém a ordem dos envios da fila.
  if (ms === ultimoMs) {
    const r = [...ultimoAleatorio];
    for (let i = r.length - 1; i >= 0; i--) {
      if (r[i] < 31) { r[i]++; break; }
      r[i] = 0;
    }
    ultimoAleatorio = r;
  } else {
    ultimoMs = ms;
    ultimoAleatorio = aleatorios(16);
  }
  return tempo + ultimoAleatorio.map((i) => ALFABETO[i]).join('');
}
