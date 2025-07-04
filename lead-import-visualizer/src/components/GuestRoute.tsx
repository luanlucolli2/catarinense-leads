// Em src/components/GuestRoute.tsx

import { Navigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";

interface GuestRouteProps {
  children: React.ReactNode;
}

const GuestRoute = ({ children }: GuestRouteProps) => {
  const { user } = useAuth();

  if (user) {
    // Se o usuário JÁ EXISTE (está logado), redireciona para a página inicial.
    return <Navigate to="/" replace />;
  }

  // Se não há usuário, renderiza a página filha (o Login).
  return <>{children}</>;
};

export default GuestRoute;