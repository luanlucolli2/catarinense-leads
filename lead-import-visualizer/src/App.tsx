import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  BrowserRouter,
  Navigate,
  Routes,
  Route,
  useParams,
} from "react-router-dom";

import Dashboard from "./pages/Dashboard";
import HistoricoPage from "./pages/Importacoes/HistoricoPage";
import Login from "./pages/Login";
import NotFound from "./pages/NotFound";
import CLTConsultaPage from "./pages/CLTConsultaPage"; // 👈 nova página
import FGTSConsultaPage from "./pages/FGTSConsultaPage"; // 👈 nova página FGTS
import C6LinksPage from "./pages/C6LinksPage";
import ParceirosUY3Page from "./pages/ParceirosUY3Page";
import IntegracoesVendeaiPage from "./modules/vendeai/pages/IntegracoesVendeaiPage";
import LinksPage from "./pages/LinksPage";
import LinkMetricsPage from "./pages/LinkMetricsPage";

import ProtectedRoute from "./components/ProtectedRoute";
import GuestRoute from "./components/GuestRoute";
import { AuthProvider } from "./contexts/AuthContext";
import { ImportProgressProvider } from "@/contexts/ImportProgressContext";
import { AppLayout } from "@/components/AppLayout";

const queryClient = new QueryClient();

const App = () => (
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
                    <AppLayout /> {/* contém <Outlet/> */}
                  </ProtectedRoute>
                }
              >
                {/* página inicial (/ → Dashboard) */}
                <Route index element={<Navigate to="/leads" replace />} />

                <Route path="leads" element={<Dashboard />} />

                {/* histórico de importações */}
                <Route path="leads/importacoes" element={<HistoricoPage />} />

                {/* consulta CLT (Consignado em Folha) */}
                <Route path="consultas/clt" element={<CLTConsultaPage />} />

                {/* consulta FGTS (Base Offline) */}
                <Route path="consultas/fgts" element={<FGTSConsultaPage />} />

                <Route path="ferramentas/c6/links" element={<C6LinksPage />} />

                <Route path="integracoes/uy3" element={<ParceirosUY3Page />} />

                <Route
                  path="integracoes/vendeai"
                  element={<IntegracoesVendeaiPage />}
                />

                <Route path="ferramentas/links" element={<LinksPage />} />
                <Route
                  path="ferramentas/links/:id/metrics"
                  element={<LinkMetricsPage />}
                />
                <Route
                  path="links"
                  element={<Navigate to="/ferramentas/links" replace />}
                />
                <Route
                  path="links/:id/metrics"
                  element={<LegacyLinkMetricsRedirect />}
                />
                <Route
                  path="c6/links"
                  element={<Navigate to="/ferramentas/c6/links" replace />}
                />
                <Route
                  path="parceiros/uy3"
                  element={<Navigate to="/integracoes/uy3" replace />}
                />
                <Route
                  path="importacoes/historico"
                  element={<Navigate to="/leads/importacoes" replace />}
                />
                <Route
                  path="clt/consulta"
                  element={<Navigate to="/consultas/clt" replace />}
                />
                <Route
                  path="fgts-off/consulta"
                  element={<Navigate to="/consultas/fgts" replace />}
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

export default App;

function LegacyLinkMetricsRedirect() {
  const { id } = useParams();
  return <Navigate to={`/ferramentas/links/${id}/metrics`} replace />;
}
