#!/usr/bin/env node
/**
 * Falha o build se aparecer vocabulário que sugere vínculo empregatício
 * ("trabalhando", "expediente", "jornada", "patrão"…) ou terminologia fora do
 * padrão ("Família" no lugar de "Rede de Apoio", "Cuidadora").
 * Linhas com "termos-ok" no fim são ignoradas (ex.: este próprio verificador).
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(fileURLToPath(new URL('.', import.meta.url)), '..');
const regras = JSON.parse(readFileSync(join(raiz, 'packages/core/src/vocabulario.json'), 'utf8'))
  .map((r) => ({ re: new RegExp(r.termo, r.flags), use: r.use, termo: r.termo }));
const pastas = ['apps/web/src', 'apps/web/public', 'apps/web/index.html', 'packages/core/src', 'api/src'];
const extensoes = /\.(tsx?|php|html|webmanifest|js|css)$/;
const ignorar = new Set(['vocabulario.json', 'vocabulario.ts']);

const arquivos = [];
const andar = (p) => {
  const s = statSync(p);
  if (s.isDirectory()) readdirSync(p).forEach((f) => andar(join(p, f)));
  else if (extensoes.test(p) && ![...ignorar].some((i) => p.endsWith(i))) arquivos.push(p);
};
pastas.forEach((p) => { try { andar(join(raiz, p)); } catch { /* pasta ausente */ } });

let erros = 0;
for (const arq of arquivos) {
  readFileSync(arq, 'utf8').split('\n').forEach((linha, i) => {
    if (linha.includes('termos-ok')) return;
    for (const r of regras) {
      const m = linha.match(r.re);
      if (m) {
        erros++;
        console.error(`✗ ${relative(raiz, arq)}:${i + 1}  "${m[0]}" → use ${r.use}`);
      }
    }
  });
}
if (erros) {
  console.error(`\n${erros} ocorrência(s). Ajuste o texto (regra permanente de risco trabalhista e terminologia).`);
  process.exit(1);
}
console.log(`✓ Vocabulário conferido em ${arquivos.length} arquivos.`);
