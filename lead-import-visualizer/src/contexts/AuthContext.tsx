import { createContext, useCallback, useContext, useEffect, useState, ReactNode } from "react";
import axiosClient from "@/api/axiosClient";

interface AuthContextType {
  user: any | null;
  isAuthReady: boolean;
  setUser: (user: any | null) => void;
}

const AuthContext = createContext<AuthContextType>({
  user: null,
  isAuthReady: false,
  setUser: () => {},
});

export const AuthProvider = ({ children }: { children: ReactNode }) => {
  const [user, _setUser] = useState<any | null>(() => {
    try {
      const storedUser = localStorage.getItem("USER");
      return storedUser ? JSON.parse(storedUser) : null;
    } catch {
      return null;
    }
  });
  const [isAuthReady, setIsAuthReady] = useState(false);

  const setUser = useCallback((newUser: any | null) => {
    _setUser(newUser);
    if (newUser) {
      localStorage.setItem("USER", JSON.stringify(newUser));
    } else {
      localStorage.removeItem("USER");
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    const parsedAuthTimeout = Number(import.meta.env.VITE_AUTH_READY_TIMEOUT_MS);
    const authReadyTimeoutMs =
      Number.isFinite(parsedAuthTimeout) && parsedAuthTimeout > 0
        ? parsedAuthTimeout
        : 12000;
    const watchdog = window.setTimeout(() => {
      if (!cancelled) {
        setUser(null);
        setIsAuthReady(true);
      }
    }, authReadyTimeoutMs);

    const hydrateFromBackend = async () => {
      try {
        const response = await axiosClient.get("/user");
        if (!cancelled) {
          setUser(response.data ?? null);
        }
      } catch {
        if (!cancelled) {
          setUser(null);
        }
      } finally {
        if (!cancelled) {
          window.clearTimeout(watchdog);
          setIsAuthReady(true);
        }
      }
    };

    void hydrateFromBackend();

    return () => {
      cancelled = true;
      window.clearTimeout(watchdog);
    };
  }, [setUser]);

  return (
    <AuthContext.Provider value={{ user, isAuthReady, setUser }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  return useContext(AuthContext);
};
