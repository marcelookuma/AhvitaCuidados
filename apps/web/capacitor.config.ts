import type { CapacitorConfig } from '@capacitor/cli';

/**
 * App Android (Capacitor): as MESMAS telas da web, empacotadas num app nativo.
 * O build vem de `npm run build:android` (pasta dist-android, sem o painel /admin).
 * O identificador é permanente na Play Store: não troque depois de publicado.
 */
const config: CapacitorConfig = {
  appId: 'com.ahvita.cuidados',
  appName: 'Ahvita Cuidados',
  webDir: 'dist-android',
  android: {
    // Mantém a origem https://localhost (a API libera essa origem em cors.origens_app).
    allowMixedContent: false,
    captureInput: true,
    // Inspeção pelo Chrome só na versão de desenvolvimento (padrão do Capacitor); nunca na assinada.
  },
  plugins: {
    // Android 15+ é "de ponta a ponta": o CSS já usa env(safe-area-inset-*) com viewport-fit=cover.
    // 'native': WebView antigo (< 140) recebe margem automática; os novos seguem o CSS.
    SystemBars: { insetsHandling: 'native', style: 'DARK' },
    PushNotifications: { presentationOptions: ['badge', 'sound', 'alert'] },
  },
};

export default config;
