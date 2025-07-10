import { Toaster } from "@/components/ui/toaster"
import { Toaster as Sonner } from "@/components/ui/sonner"
import { TooltipProvider } from "@/components/ui/tooltip"
import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import { BrowserRouter, Routes, Route } from "react-router-dom"

import Index from "./pages/Index"
import HistoricoPage from "./pages/Importacoes/HistoricoPage"
import Login from "./pages/Login"
import NotFound from "./pages/NotFound"

import ProtectedRoute from "./components/ProtectedRoute"
import GuestRoute from "./components/GuestRoute"
import { AuthProvider } from "./contexts/AuthContext"
import { ImportProgressProvider } from "@/contexts/ImportProgressContext"

const queryClient = new QueryClient()

const App = () => (
  <QueryClientProvider client={queryClient}>
    <TooltipProvider>
      <AuthProvider>
        {/* Toasters globais */}
        <Toaster />
        <Sonner />

        {/* Provider global de progresso de importação — mantém toast vivo entre rotas */}
        <ImportProgressProvider>
          <BrowserRouter>
            <Routes>
              {/* Login (rota de convidado) */}
              <Route
                path="/login"
                element={
                  <GuestRoute>
                    <Login />
                  </GuestRoute>
                }
              />

              {/* Dashboard principal */}
              <Route
                path="/"
                element={
                  <ProtectedRoute>
                    <Index />
                  </ProtectedRoute>
                }
              />

              {/* Histórico de importações */}
              <Route
                path="/importacoes/historico"
                element={
                  <ProtectedRoute>
                    <HistoricoPage />
                  </ProtectedRoute>
                }
              />

              {/* Catch-all */}
              <Route path="*" element={<NotFound />} />
            </Routes>
          </BrowserRouter>
        </ImportProgressProvider>
      </AuthProvider>
    </TooltipProvider>
  </QueryClientProvider>
)

export default App
