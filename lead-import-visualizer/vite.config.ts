import { defineConfig } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";

export default defineConfig(({ mode }) => {

  return {
    server: {
      headers: {
        "Cache-Control": "no-store",
      },
      host: "0.0.0.0", // escuta em todas as interfaces
      port: 8080,
      strictPort: true, // falha se 8080 ja estiver em uso
      hmr: mode === "development",
      proxy: {
        "/api": {
          target: "http://laravel.test",
          changeOrigin: true,
          secure: false,
        },
        "/sanctum": {
          target: "http://laravel.test",
          changeOrigin: true,
          secure: false,
        },
      },
    },
    plugins: [react()],
    resolve: {
      alias: {
        "@": path.resolve(__dirname, "./src"),
      },
    },
  };
});
