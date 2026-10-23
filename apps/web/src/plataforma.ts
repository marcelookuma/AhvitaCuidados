/**
 * O que muda entre a web e o app Android (Capacitor). As telas são as mesmas;
 * só estas peças trocam de comportamento:
 *   sessão   → web: cookie httpOnly · app: token Bearer no cofre do aparelho (Android Keystore)
 *   API      → web: mesmo endereço (/api) · app: https://cuidados.ahvita.com/api
 *   arquivos → web: link de download · app: salva e abre "Compartilhar"
 *   voz      → web: speechSynthesis · app: leitura nativa do Android
 *   voltar   → app: botão/gesto "voltar" do Android navega dentro do app
 *   avisos   → app: push (Firebase), só quando o build tiver o google-services.json
 * Nenhum plugin nativo é carregado na web (imports dinâmicos).
 */
import { Capacitor } from '@capacitor/core';

export const nativo = Capacitor.isNativePlatform();
export const plataforma = Capacitor.getPlatform() as 'web' | 'android' | 'ios';

/** Endereço da API. No app aponta para o servidor; na web, para o mesmo domínio. */
export function baseApi(): string {
  if (import.meta.env.VITE_API_BASE) return import.meta.env.VITE_API_BASE;
  if (!nativo) return '/api/index.php?r=';
  const servidor = (import.meta.env.VITE_URL_APP as string | undefined) ?? 'https://cuidados.ahvita.com';
  return servidor.replace(/\/$/, '') + '/api/index.php?r=';
}

/** Endereço público do site (termos, privacidade, links de convite). */
export function urlSite(caminho = '/'): string {
  const servidor = nativo ? ((import.meta.env.VITE_URL_APP as string | undefined) ?? 'https://cuidados.ahvita.com') : location.origin;
  return servidor.replace(/\/$/, '') + caminho;
}

/* ---------------- Sessão (token no cofre do aparelho) ---------------- */

const CHAVE_TOKEN = 'sessao';
let tokenAtual: string | null = null;

export const tokenSessao = (): string | null => tokenAtual;

async function cofre() {
  return (await import('capacitor-secure-storage-plugin')).SecureStoragePlugin;
}

export async function guardarToken(token: string): Promise<void> {
  if (!nativo) return; // na web a sessão é o cookie httpOnly
  tokenAtual = token;
  try { await (await cofre()).set({ key: CHAVE_TOKEN, value: token }); } catch { /* segue só em memória */ }
}

export async function apagarToken(): Promise<void> {
  tokenAtual = null;
  if (!nativo) return;
  try { await (await cofre()).remove({ key: CHAVE_TOKEN }); } catch { /* já não existia */ }
}

/** Antes de abrir as telas: lê o token guardado e liga o "voltar" do Android. */
export async function iniciarPlataforma(): Promise<void> {
  if (!nativo) return;
  try { tokenAtual = (await (await cofre()).get({ key: CHAVE_TOKEN })).value || null; } catch { tokenAtual = null; }
  await ligarVoltar();
  ligarLinksExternos();
}

/* ---------------- Botão "voltar" do Android ---------------- */

/** Telas de entrada de cada perfil: "voltar" aqui minimiza o app (como o WhatsApp). */
function ehTelaInicial(): boolean {
  const c = (location.hash.slice(1) || '/').split('?')[0];
  return ['/', '/entrar', '/c', '/perfil', '/bem-vindo'].includes(c) || /^\/(g|e)\/[^/]+(\/hoje)?$/.test(c);
}

async function ligarVoltar() {
  const { App } = await import('@capacitor/app');
  await App.addListener('backButton', ({ canGoBack }) => {
    // Uma folha (menu de baixo) aberta fecha primeiro.
    const folha = document.querySelector<HTMLElement>('.fundo-folha');
    if (folha) { folha.click(); return; }
    if (canGoBack && !ehTelaInicial()) history.back();
    else void App.minimizeApp();
  });
  // Link de convite (https://cuidados.ahvita.com/convite/ABC) aberto com o app instalado.
  await App.addListener('appUrlOpen', ({ url }) => {
    try {
      const u = new URL(url);
      const caminho = u.hash.startsWith('#/') ? u.hash.slice(1) : u.pathname + u.search;
      if (/^\/convite\/[A-Za-z0-9-]+/.test(caminho)) {
        history.pushState(null, '', '#' + caminho);
        window.dispatchEvent(new Event('cuidados:rota'));
      }
    } catch { /* link estranho: ignora */ }
  });
}

/* ---------------- Links ---------------- */

/** No app, links "abrir em outra aba" (termos, WhatsApp, suporte) abrem fora, no navegador ou no app certo. */
function ligarLinksExternos() {
  document.addEventListener('click', (e) => {
    const a = (e.target as HTMLElement | null)?.closest?.('a');
    if (!a) return;
    const href = a.getAttribute('href') ?? '';
    if (!href || href.startsWith('#') || href.startsWith('tel:') || href.startsWith('mailto:')) return;
    const externo = /^https?:\/\//i.test(href) && !href.startsWith(location.origin);
    if (!externo && a.target !== '_blank') return;
    e.preventDefault();
    void abrirFora(externo ? href : urlSite(href.startsWith('/') ? href : '/' + href));
  }, true);
}

export async function abrirFora(url: string): Promise<void> {
  if (!nativo) { window.open(url, '_blank', 'noopener'); return; }
  const { Browser } = await import('@capacitor/browser');
  await Browser.open({ url });
}

/* ---------------- Arquivos (cópia dos dados) ---------------- */

/**
 * Entrega um arquivo baixado da API. Web: download normal. App: grava na pasta
 * temporária do app (fora da galeria) e abre "Compartilhar" para a pessoa escolher
 * onde guardar. A cópia temporária é apagada depois.
 */
export async function entregarArquivo(blob: Blob, nome: string): Promise<void> {
  if (!nativo) {
    const u = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = u; a.download = nome; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(u), 10_000);
    return;
  }
  const { Filesystem, Directory } = await import('@capacitor/filesystem');
  const { Share } = await import('@capacitor/share');
  const dados = await paraBase64(blob);
  const { uri } = await Filesystem.writeFile({ path: nome, data: dados, directory: Directory.Cache });
  try {
    await Share.share({ title: nome, files: [uri] });
  } finally {
    setTimeout(() => void Filesystem.deleteFile({ path: nome, directory: Directory.Cache }).catch(() => undefined), 60_000);
  }
}

function paraBase64(b: Blob): Promise<string> {
  return new Promise((ok, falha) => {
    const r = new FileReader();
    r.onload = () => ok(String(r.result).split(',')[1] ?? '');
    r.onerror = () => falha(r.error);
    r.readAsDataURL(b);
  });
}

/* ---------------- Voz ---------------- */

export async function falarNativo(texto: string): Promise<boolean> {
  if (!nativo) return false;
  try {
    const { TextToSpeech } = await import('@capacitor-community/text-to-speech');
    await TextToSpeech.stop().catch(() => undefined);
    await TextToSpeech.speak({ text: texto, lang: 'pt-BR', rate: 0.92 });
    return true;
  } catch {
    return false;
  }
}

/* ---------------- Avisos no celular (push) ---------------- */

/** O build só liga o push quando tem o google-services.json (sem ele, o Firebase derrubaria o app). */
export const pushDisponivel = nativo && import.meta.env.VITE_PUSH === '1';
const CHAVE_PERGUNTOU = 'cuidados:avisos-perguntado';
const CHAVE_TOKEN_PUSH = 'cuidados:push';

/** Já perguntamos se a pessoa quer avisos neste aparelho? (pergunta uma vez só) */
export function jaPerguntouAvisos(): boolean {
  return localStorage.getItem(CHAVE_PERGUNTOU) === '1';
}

export function marcarPerguntouAvisos(): void {
  localStorage.setItem(CHAVE_PERGUNTOU, '1');
}

/**
 * Pede a permissão (Android 13+) e registra o aparelho na API.
 * `registrar` recebe o token do Firebase e manda para POST /dispositivos.
 */
export async function ativarAvisos(registrar: (token: string) => Promise<void>, pedirPermissao: boolean): Promise<boolean> {
  if (!pushDisponivel) return false;
  const { PushNotifications } = await import('@capacitor/push-notifications');
  let perm = await PushNotifications.checkPermissions();
  if (perm.receive === 'prompt' && pedirPermissao) perm = await PushNotifications.requestPermissions();
  if (perm.receive !== 'granted') return false;
  await PushNotifications.removeAllListeners();
  await PushNotifications.addListener('registration', (t) => {
    localStorage.setItem(CHAVE_TOKEN_PUSH, t.value);
    void registrar(t.value).catch(() => undefined);
  });
  // Toque no aviso: abre a tela indicada (só caminhos do próprio app).
  await PushNotifications.addListener('pushNotificationActionPerformed', (a) => {
    const destino = String(a.notification.data?.abrir ?? '');
    if (destino.startsWith('/')) {
      history.pushState(null, '', '#' + destino);
      window.dispatchEvent(new Event('cuidados:rota'));
    }
  });
  await PushNotifications.register();
  return true;
}

/** Ao sair: o aparelho deixa de receber avisos desta conta. */
export async function desativarAvisos(remover: (token: string) => Promise<void>): Promise<void> {
  const t = localStorage.getItem(CHAVE_TOKEN_PUSH);
  if (t) { try { await remover(t); } catch { /* a API também apaga ao encerrar a sessão */ } }
  localStorage.removeItem(CHAVE_TOKEN_PUSH);
  if (!pushDisponivel) return;
  try {
    const { PushNotifications } = await import('@capacitor/push-notifications');
    await PushNotifications.removeAllListeners();
    await PushNotifications.unregister();
  } catch { /* segue */ }
}
