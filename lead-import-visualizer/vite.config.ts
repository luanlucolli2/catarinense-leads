import { defineConfig } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";
import { componentTagger } from "lovable-tagger";

export default defineConfig(({ mode }) => ({
  server: {
    host: "0.0.0.0",    // escuta em todas as interfaces IPv4
    port: 8080,
    strictPort: true,   // falha de vez se 8080 já estiver em uso
    // proxy para /api => Laravel (evita CORS no dev):
    proxy: {
      '/api': {
        target: 'http://laravel.test', // Aponta para o nome do serviço!
        changeOrigin: true,
        secure: false,
      },
    },
  },
  plugins: [
    react(),
    mode === "development" && componentTagger(),
  ].filter(Boolean),
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
}));
