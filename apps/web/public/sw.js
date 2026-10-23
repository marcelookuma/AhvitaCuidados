/* Service worker: o app abre sem internet (o que for registrado fica na fila do aparelho).
   API nunca é guardada aqui: dados de saúde não ficam no cache do navegador. */
const VERSAO = 'cuidados-v2';
self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSAO).then((c) => c.addAll(['/', '/manifest.webmanifest', '/icone.svg'])).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== VERSAO).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // O painel /admin nunca passa pelo cache (nem a página dele vira a do app).
  if (e.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin')) return;
  // Páginas: rede primeiro; sem internet, a última versão guardada.
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).then((r) => { const c = r.clone(); caches.open(VERSAO).then((k) => k.put('/', c)); return r; }).catch(() => caches.match('/')));
    return;
  }
  // Arquivos do app (têm hash no nome): cache primeiro.
  e.respondWith(caches.match(e.request).then((m) => m || fetch(e.request).then((r) => {
    if (r.ok) { const c = r.clone(); caches.open(VERSAO).then((k) => k.put(e.request, c)); }
    return r;
  })));
});
