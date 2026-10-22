#!/usr/bin/env node
/**
 * Monta o pacote para a hospedagem PHP da KingHost (plano de revenda).
 *   npm run pacote:kinghost
 *
 * pacote-kinghost/
 *   www/        → conteúdo da pasta pública do domínio (cuidados.ahvita.com)
 *     index.html, assets/, sw.js…   app web compilado
 *     .htaccess                     HTTPS + rotas do app
 *     api/index.php, api/.htaccess  porta de entrada da API
 *   privado/    → FORA da área pública (ex.: /cuidados_privado/ na raiz do FTP)
 *     src/, bin/, config/configuracao.exemplo.php, db/, midias/
 */
import { cpSync, existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, posix } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(fileURLToPath(new URL('.', import.meta.url)), '..');
const saida = join(raiz, 'pacote-kinghost');
const dist = join(raiz, 'apps/web/dist');
if (!existsSync(join(dist, 'index.html'))) {
  console.error('Rode "npm run build" antes.');
  process.exit(1);
}

rmSync(saida, { recursive: true, force: true });
const www = join(saida, 'www');
const privado = join(saida, 'privado');
mkdirSync(join(www, 'api'), { recursive: true });
mkdirSync(join(privado, 'midias'), { recursive: true });

cpSync(dist, www, { recursive: true });
cpSync(join(raiz, 'api/public/index.php'), join(www, 'api/index.php'));
cpSync(join(raiz, 'api/public/.htaccess'), join(www, 'api/.htaccess'));

// Onde fica a pasta privada em relação a www/api/ no servidor.
// Vem das variáveis do GitHub (KINGHOST_PASTA_WWW e KINGHOST_PASTA_PRIVADO).
// Ex.: www = /www/cuidados/ e privado = /cuidados_privado/ → ../../../cuidados_privado
const pastaWww = process.env.KINGHOST_PASTA_WWW || '/www/';
const pastaPrivado = process.env.KINGHOST_PASTA_PRIVADO || '/privado/';
const absWww = posix.resolve('/', pastaWww);
const absPrivado = posix.resolve('/', pastaPrivado);
const naAreaPublica = absPrivado === absWww || absPrivado.startsWith(absWww + '/');
const permitirNaAreaPublica = process.env.KINGHOST_PRIVADO_NA_AREA_PUBLICA === 'sim';
if (absPrivado === absWww) {
  console.error('✗ A pasta privada não pode ser a própria pasta pública.');
  process.exit(1);
}
if (naAreaPublica && !permitirNaAreaPublica) {
  console.error(`✗ A pasta privada (${pastaPrivado}) está dentro da pasta pública (${pastaWww}).`);
  console.error('  Use uma pasta fora da área pública ou, como solução temporária, defina KINGHOST_PRIVADO_NA_AREA_PUBLICA=sim.');
  process.exit(1);
}
// Bloqueio extra: a pasta privada dentro da pública nunca é servida pelo Apache.
const bloqueioPrivado = naAreaPublica
  ? `  # Pasta privada dentro da área pública (solução temporária): acesso proibido
  RewriteRule ^${posix.relative(absWww, absPrivado).replace(/[.]/g, '\\.')}(/|$) - [F,L]
`
  : '';
if (naAreaPublica) console.warn(`⚠ Pasta privada DENTRO da área pública (${absPrivado}), protegida por .htaccess. Solução temporária.`);
const relativo = posix.relative(posix.join(absWww, 'api'), absPrivado);
writeFileSync(join(www, 'api/privado.php'), `<?php\n// Gerado pelo empacotador: caminho da pasta privada a partir daqui.\nreturn '${relativo}';\n`);
console.log(`· pasta privada: ${absPrivado} (a partir de www/api: ${relativo})`);

// Só atende pelo endereço oficial (evita o app abrir por ahvita.com/cuidados/).
let hostOficial = '';
try { hostOficial = process.env.URL_APP ? new URL(process.env.URL_APP).host : ''; } catch { hostOficial = ''; }
const regraHost = hostOficial ? `  # Só pelo endereço oficial
  RewriteCond %{HTTP_HOST} !^${hostOficial.replace(/\./g, '\\.')}$ [NC]
  RewriteRule ^ https://${hostOficial}%{REQUEST_URI} [L,R=301]
` : '';
writeFileSync(join(www, '.htaccess'), `# Ahvita Cuidados — app web
Options -Indexes
<IfModule mod_rewrite.c>
  RewriteEngine On
${bloqueioPrivado}${regraHost}  # Sempre HTTPS (dados de saúde)
  RewriteCond %{HTTPS} !=on
  RewriteCond %{HTTP:X-Forwarded-Proto} !https
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  # A API tem o próprio .htaccess em /api
  RewriteRule ^api/ - [L]
  # Rotas do app (ex.: /c/p/123) abrem o index.html
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>
<Files ".ftp-deploy-sync-state.json">
  Require all denied
</Files>
<IfModule mod_headers.c>
  Header always set Strict-Transport-Security "max-age=31536000"
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "no-referrer"
  Header always set Permissions-Policy "geolocation=(), microphone=(self), camera=(self)"
  <FilesMatch "(index\\.html|sw\\.js)$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
  <FilesMatch "\\.(js|css|svg|woff2)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
`);

for (const p of ['src', 'bin']) cpSync(join(raiz, 'api', p), join(privado, p), { recursive: true });
mkdirSync(join(privado, 'config'));
cpSync(join(raiz, 'api/config/configuracao.exemplo.php'), join(privado, 'config/configuracao.exemplo.php'));
cpSync(join(raiz, 'db'), join(privado, 'db'), { recursive: true });
// Se a hospedagem não permitir pasta fora de www, este .htaccess bloqueia o acesso web à pasta privado.
writeFileSync(join(privado, '.htaccess'), 'Require all denied\n');
writeFileSync(join(privado, 'midias/.htaccess'), 'Require all denied\n');
cpSync(join(raiz, 'docs/PUBLICAR-KINGHOST.md'), join(saida, 'LEIA-ME.md'));
console.log('✓ Pacote pronto em pacote-kinghost/ (www/ e privado/)');
