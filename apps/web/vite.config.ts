import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// Em desenvolvimento a API PHP roda em http://127.0.0.1:8099 (php -S … dev-router.php).
// `vite build --mode android` gera dist-android/ para o app Android (Capacitor): só o app,
// sem o painel /admin (que entra com Google e fica só na web).
export default defineConfig(({ mode }) => {
  const android = mode === 'android';
  return {
    plugins: [react()],
    server: {
      port: 5173,
      proxy: { '/api': { target: 'http://127.0.0.1:8099', changeOrigin: false } },
    },
    build: {
      outDir: android ? 'dist-android' : 'dist', sourcemap: false, target: 'es2019', chunkSizeWarningLimit: 600,
      // Web: duas páginas, o app (/) e o painel de administração (/admin/), que vira a pasta www/admin/
      // (funciona no nginx da KingHost sem reescrita de URL: /admin abre admin/index.html).
      rollupOptions: {
        input: android
          ? { app: fileURLToPath(new URL('./index.html', import.meta.url)) }
          : {
              app: fileURLToPath(new URL('./index.html', import.meta.url)),
              admin: fileURLToPath(new URL('./admin/index.html', import.meta.url)),
            },
      },
    },
  };
});
