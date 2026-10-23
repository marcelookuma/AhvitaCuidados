import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// Em desenvolvimento a API PHP roda em http://127.0.0.1:8099 (php -S … dev-router.php).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: { '/api': { target: 'http://127.0.0.1:8099', changeOrigin: false } },
  },
  build: {
    outDir: 'dist', sourcemap: false, target: 'es2019', chunkSizeWarningLimit: 600,
    // Duas páginas: o app (/) e o painel de administração (/admin/), que vira a pasta www/admin/
    // (funciona no nginx da KingHost sem reescrita de URL: /admin abre admin/index.html).
    rollupOptions: {
      input: {
        app: fileURLToPath(new URL('./index.html', import.meta.url)),
        admin: fileURLToPath(new URL('./admin/index.html', import.meta.url)),
      },
    },
  },
});
