import { Navigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";

interface ProtectedRouteProps {
  children: React.ReactNode;
}

const ProtectedRoute = ({ children }: ProtectedRouteProps) => {
  const { user } = useAuth();

  if (!user) {
    // Se não há objeto de usuário, redireciona para a página de login.
    // Usar o componente <Navigate> é a forma mais moderna no React Router v6.
    return <Navigate to="/login" replace />;
  }

  // Se o usuário existir, renderiza a página filha.
  return <>{children}</>;
};

export default ProtectedRoute;
