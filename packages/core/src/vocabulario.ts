/**
 * Vocabulário proibido (risco de caracterizar vínculo empregatício e
 * terminologia do projeto). Fonte única: vocabulario.json, usada também por
 * scripts/verificar-termos.mjs, que falha o build se algum termo aparecer.
 */
import lista from './vocabulario.json';

export const PROIBIDOS: { termo: RegExp; use: string }[] = lista.map((t) => ({ termo: new RegExp(t.termo, t.flags), use: t.use }));
