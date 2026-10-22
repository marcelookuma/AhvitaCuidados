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
 *   privado/    → FORA da pasta pública (mesmo nível de www)
 *     src/, bin/, config/configuracao.exemplo.php, db/, midias/
 */
import { cpSync, existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
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
writeFileSync(join(www, '.htaccess'), `# Ahvita Cuidados — app web
Options -Indexes
<IfModule mod_rewrite.c>
  RewriteEngine On
  # Sempre HTTPS (dados de saúde)
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
