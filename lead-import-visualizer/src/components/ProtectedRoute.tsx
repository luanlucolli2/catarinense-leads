import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { isC6OnlyUser } from "@/lib/access";

interface ProtectedRouteProps {
  children: React.ReactNode;
}

const ProtectedRoute = ({ children }: ProtectedRouteProps) => {
  const location = useLocation();
  const { user, isAuthReady } = useAuth();

  if (!isAuthReady) {
    return null;
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (isC6OnlyUser(user) && !location.pathname.startsWith("/c6/links")) {
    return <Navigate to="/c6/links" replace />;
  }

  return <>{children}</>;
};

export default ProtectedRoute;
