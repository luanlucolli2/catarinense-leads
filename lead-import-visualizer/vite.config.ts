import { defineConfig } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";

export default defineConfig(({ mode }) => ({
  server: {
    headers: {
      'Cache-Control': 'no-store',

    },
    host: "0.0.0.0",      // escuta em todas as interfaces
    port: 8080,
    strictPort: true,     // falha se 8080 já estiver em uso
    hmr: false,           // desabilita hot-reload (para testar se era HMR)
    proxy: {
      '/api': {
        target: 'http://laravel.test',
        changeOrigin: true,
        secure: false,
      },
      '/sanctum': {             // ← adiciona esta entrada
        target: 'http://laravel.test',
        changeOrigin: true,
        secure: false,
      },
    },
  },
  plugins: [
    react(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
}));
