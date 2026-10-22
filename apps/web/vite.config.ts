import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Em desenvolvimento a API PHP roda em http://127.0.0.1:8099 (php -S … dev-router.php).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: { '/api': { target: 'http://127.0.0.1:8099', changeOrigin: false } },
  },
  build: { outDir: 'dist', sourcemap: false, target: 'es2019', chunkSizeWarningLimit: 600 },
});
