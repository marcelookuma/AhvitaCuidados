/** Ícones de traço (SVG inline: sem fonte de ícones externa). Sempre acompanhados de rótulo. */
const p = { fill: 'none', stroke: 'currentColor', strokeWidth: 2.2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };

export const IcVoltar = () => <svg viewBox="0 0 24 24" {...p} strokeWidth={2.6}><path d="M15 5l-7 7 7 7" /></svg>;
export const IcMais = () => <svg viewBox="0 0 24 24" {...p} strokeWidth={2.8}><path d="M12 5v14M5 12h14" /></svg>;
export const IcMic = () => <svg viewBox="0 0 24 24" {...p}><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21" /></svg>;
export const IcEnviar = () => <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4l17.4-7.5a1 1 0 0 0 0-1.8L3.4 3.6a.8.8 0 0 0-1.1.9L4 11l9 1-9 1-1.7 6.5a.8.8 0 0 0 1.1.9z" /></svg>;
export const IcCamera = () => <svg viewBox="0 0 24 24" {...p} strokeWidth={2}><path d="M4 8h3l1.6-2.2h6.8L17 8h3v11H4z" /><circle cx="12" cy="13" r="3.4" /></svg>;
export const IcTelefone = () => <svg viewBox="0 0 24 24" {...p} strokeWidth={2}><path d="M5 4h3.5l1.8 4.4-2.3 1.4a11 11 0 0 0 6.2 6.2l1.4-2.3L20 15.5V19a1.5 1.5 0 0 1-1.6 1.5A16.5 16.5 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4z" /></svg>;
export const IcTocar = () => <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 4.5v15l12-7.5z" /></svg>;
export const IcPausar = () => <svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4.5" width="4" height="15" rx="1" /><rect x="14" y="4.5" width="4" height="15" rx="1" /></svg>;
export const IcRelogio = () => <svg viewBox="0 0 16 12" {...p} strokeWidth={1.5}><circle cx="7" cy="6" r="4.5" /><path d="M7 3.6V6l1.6 1" /></svg>;
export const IcUm = () => <svg viewBox="0 0 16 12" {...p} strokeWidth={1.7}><path d="M3 6.5l2.6 2.6L11.5 3" /></svg>;
export const IcDois = () => <svg viewBox="0 0 16 12" {...p} strokeWidth={1.7}><path d="M1 6.5l2.6 2.6L9.5 3M7 8.4l.7.7L13.6 3" /></svg>;
export const IcSair = () => <svg viewBox="0 0 24 24" {...p} strokeWidth={2}><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10" /></svg>;
