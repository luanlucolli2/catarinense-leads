
import { useEffect } from "react";
import { useNavigate } from "react-router-dom";

interface ProtectedRouteProps {
  children: React.ReactNode;
}

const ProtectedRoute = ({ children }: ProtectedRouteProps) => {
  const navigate = useNavigate();

  useEffect(() => {
    const isAuthenticated = localStorage.getItem("isAuthenticated");
    
    if (!isAuthenticated || isAuthenticated !== "true") {
      navigate("/login", { replace: true });
    }
  }, [navigate]);

  // Verificar se está autenticado antes de renderizar
  const isAuthenticated = localStorage.getItem("isAuthenticated");
  
  if (!isAuthenticated || isAuthenticated !== "true") {
    return null;
  }

  return <>{children}</>;
};

export default ProtectedRoute;
