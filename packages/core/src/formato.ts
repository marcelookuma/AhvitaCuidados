/** Datas e valores em linguagem simples ("Hoje", "Ontem", "sábado, 26/09"). */

const DIAS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
export const DIAS_CURTOS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

const pad = (n: number) => String(n).padStart(2, '0');

export function hora(iso: string | Date): string {
  const d = typeof iso === 'string' ? new Date(iso) : iso;
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function horaCurta(iso: string | Date): string {
  const d = typeof iso === 'string' ? new Date(iso) : iso;
  return d.getMinutes() === 0 ? `${pad(d.getHours())}h` : `${pad(d.getHours())}h${pad(d.getMinutes())}`;
}

export function dia(iso: string | Date, agora: Date = new Date()): string {
  const d = typeof iso === 'string' ? new Date(iso) : iso;
  const zero = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const dif = Math.round((zero(d) - zero(agora)) / 86_400_000);
  if (dif === 0) return 'Hoje';
  if (dif === -1) return 'Ontem';
  if (dif === 1) return 'Amanhã';
  return `${DIAS[d.getDay()]}, ${pad(d.getDate())}/${pad(d.getMonth() + 1)}`;
}

/** "sábado, 26/09, das 08h às 18h" */
export function periodo(inicio: string, fim: string): string {
  const i = new Date(inicio);
  const d = dia(i);
  return `${d === 'Hoje' || d === 'Amanhã' ? d.toLowerCase() : d}, das ${horaCurta(i)} às ${horaCurta(fim)}`;
}

export function reais(centavos: number | null | undefined): string {
  if (centavos == null) return '';
  return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

export function telefone(e164: string | null | undefined): string {
  if (!e164) return '';
  const d = e164.replace(/\D/g, '').replace(/^55/, '');
  if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  return e164;
}

export function cpf(v: string): string {
  const d = v.replace(/\D/g, '').slice(0, 11);
  return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
}

/** Mesma regra da API (Valida::cpfValido). */
export function cpfValido(v: string): boolean {
  const d = v.replace(/\D/g, '');
  if (!/^\d{11}$/.test(d) || /^(\d)\1{10}$/.test(d)) return false;
  for (let t = 9; t < 11; t++) {
    let soma = 0;
    for (let i = 0; i < t; i++) soma += Number(d[i]) * (t + 1 - i);
    if (Number(d[t]) !== ((10 * soma) % 11) % 10) return false;
  }
  return true;
}

/** Celular/telefone brasileiro com DDD (10 ou 11 dígitos, com ou sem +55). Mesma regra da API. */
export function telefoneValido(v: string): boolean {
  let d = v.replace(/\D/g, '');
  if (d.startsWith('55') && d.length >= 12) d = d.slice(2);
  return d.length === 10 || d.length === 11;
}

export function primeiroNome(n: string | null | undefined): string {
  return (n ?? '').trim().split(/\s+/)[0] ?? '';
}

/** Monta ISO local (com fuso do aparelho) a partir de data "AAAA-MM-DD" e hora "HH:MM". */
export function isoLocal(data: string, hhmm: string): string {
  const [a, m, d] = data.split('-').map(Number);
  const [h, mi] = hhmm.split(':').map(Number);
  return new Date(a, m - 1, d, h, mi).toISOString();
}

export function iniciais(n: string): string {
  const p = n.replace(/^(Dona|Seu|Sr\.?|Sra\.?)\s+/i, '').trim().split(/\s+/);
  return (p[0]?.[0] ?? '?').toUpperCase();
}
