import { useEffect } from "react";
import { useNavigate, Navigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";

interface ProtectedRouteProps {
  children: React.ReactNode;
}

const ProtectedRoute = ({ children }: ProtectedRouteProps) => {
  const { token } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    if (!token) {
      // Se não houver token, redireciona para a página de login.
      // O 'replace: true' impede que o usuário volte para a página protegida usando o botão "Voltar" do navegador.
      navigate("/login", { replace: true });
    }
  }, [token, navigate]);

  // Se o token não existir, não renderiza nada enquanto o useEffect redireciona.
  // Uma alternativa aqui seria mostrar um componente de "Carregando...".
  if (!token) {
    return null;
  }

  // Se o token existir, renderiza a página filha (Dashboard, Histórico, etc.).
  return <>{children}</>;
};

export default ProtectedRoute;
