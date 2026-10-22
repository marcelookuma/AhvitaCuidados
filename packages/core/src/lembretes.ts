/**
 * Lembretes do dia calculados no aparelho (funcionam sem internet):
 * remédios do horário que ainda não têm registro hoje.
 */
import type { ItemFeed, Registro, Remedio } from './tipos';

export interface Dose {
  remedio: Remedio;
  horario: string;     // "08:00"
  quando: Date;
  feita: Registro | null;
  atrasada: boolean;
  agora: boolean;      // janela de 1 h antes até 2 h depois
}

function hojeAs(hhmm: string, base: Date): Date {
  const [h, m] = hhmm.split(':').map(Number);
  const d = new Date(base);
  d.setHours(h, m, 0, 0);
  return d;
}

function mesmoDia(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export function dosesDeHoje(remedios: Remedio[], itens: ItemFeed[], agora: Date = new Date()): Dose[] {
  const registros = itens.filter(
    (i): i is Registro => i.tipo_item === 'registro' && i.tipo === 'remedio' && !i.anulado && mesmoDia(new Date(i.em), agora),
  );
  const usados = new Set<string>();
  const doses: Dose[] = [];
  for (const r of remedios.filter((x) => x.ativo)) {
    for (const h of r.horarios) {
      const quando = hojeAs(h, agora);
      // Casa o registro mais próximo do horário (até 3 h de diferença).
      let melhor: Registro | null = null;
      let dist = Infinity;
      for (const reg of registros) {
        if (reg.remedio_id !== r.id || usados.has(reg.id)) continue;
        const dd = Math.abs(new Date(reg.em).getTime() - quando.getTime());
        if (dd < dist && dd <= 3 * 3600_000) { melhor = reg; dist = dd; }
      }
      if (melhor) usados.add(melhor.id);
      const diff = agora.getTime() - quando.getTime();
      doses.push({
        remedio: r,
        horario: h,
        quando,
        feita: melhor,
        atrasada: !melhor && diff > 2 * 3600_000,
        agora: !melhor && diff >= -3600_000 && diff <= 2 * 3600_000,
      });
    }
  }
  return doses.sort((a, b) => a.quando.getTime() - b.quando.getTime());
}

export function coposHoje(itens: ItemFeed[], agora: Date = new Date()): number {
  return itens
    .filter((i): i is Registro => i.tipo_item === 'registro' && i.tipo === 'agua' && !i.anulado && mesmoDia(new Date(i.em), agora))
    .reduce((s, r) => s + Number((r.dados as any)?.copos ?? 0), 0);
}
