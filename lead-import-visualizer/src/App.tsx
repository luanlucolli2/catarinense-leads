import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  BrowserRouter,
  Routes,
  Route,
  Outlet,
} from "react-router-dom";

import Dashboard from "./pages/Dashboard";
import HistoricoPage from "./pages/Importacoes/HistoricoPage";
import Login from "./pages/Login";
import NotFound from "./pages/NotFound";

import ProtectedRoute from "./components/ProtectedRoute";
import GuestRoute from "./components/GuestRoute";
import { AuthProvider } from "./contexts/AuthContext";
import { ImportProgressProvider } from "@/contexts/ImportProgressContext";
import { AppLayout } from "@/components/AppLayout";
import { useEffect } from "react";
import axiosClient from "./api/axiosClient";
const queryClient = new QueryClient();
import axios from 'axios';

// App.tsx (root)


const App = () => {

  useEffect(() => {
    axios.get('/sanctum/csrf-cookie', { withCredentials: true })
    .then(() => {
      // 2) só então faz o login via axiosClient (/api/login)
    });
  }, []);


  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <AuthProvider>
          {/* Toasters globais */}
          <Toaster />
          <Sonner />

          {/* Provider global de progresso das importações */}
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

                {/* ROTAS PROTEGIDAS COM LAYOUT ÚNICO */}
                <Route
                  element={
                    <ProtectedRoute>
                      <AppLayout />   {/* contém <Outlet/> */}
                    </ProtectedRoute>
                  }
                >
                  {/* página inicial (/ → Dashboard) */}
                  <Route index element={<Dashboard />} />

                  {/* histórico de importações */}
                  <Route
                    path="importacoes/historico"
                    element={<HistoricoPage />}
                  />
                </Route>

                {/* 404 */}
                <Route path="*" element={<NotFound />} />
              </Routes>
            </BrowserRouter>
          </ImportProgressProvider>
        </AuthProvider>
      </TooltipProvider>
    </QueryClientProvider>
  );
};

export default App;
