// Em src/components/GuestRoute.tsx

import { Navigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";

interface GuestRouteProps {
  children: React.ReactNode;
}

const GuestRoute = ({ children }: GuestRouteProps) => {
  const { user, isAuthReady } = useAuth();

  if (!isAuthReady) {
    return null;
  }

  if (user) {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
};

export default GuestRoute;
