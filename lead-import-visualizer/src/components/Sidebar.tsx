import { Home, LogOut, Menu, FileText } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { useNavigate, useLocation } from "react-router-dom";
import { useEffect, useRef } from "react";
import catarinenselogo from "../../public/catainenseLogo.png";
import { toast } from "sonner";
import { useAuth } from "@/contexts/AuthContext"; // 1. Importar o hook de autenticação
import axiosClient from "@/api/axiosClient"; // 2. Importar o cliente Axios

interface SidebarProps {
  className?: string;
  isCollapsed: boolean;
  onToggle: () => void;
}

const Sidebar = ({ className, isCollapsed, onToggle }: SidebarProps) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { setUser, setToken } = useAuth(); // 3. Usar o hook para acessar as funções do contexto

  const menuItems = [
    {
      name: "Dashboard",
      icon: Home,
      path: "/",
      active: location.pathname === "/"
    },
    {
      name: "Histórico de Importações",
      icon: FileText,
      path: "/importacoes/historico",
      active: location.pathname === "/importacoes/historico"
    },
    {
      name: "Sair",
      icon: LogOut,
      path: null,
      active: false
    },
  ];
  const firstRender = useRef(true);

  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    if (window.innerWidth < 1024 && !isCollapsed) {
      onToggle();
    }
  }, [location.pathname]);

  const handleMenuClick = async (item: typeof menuItems[0]) => {
    if (item.path) {
      navigate(item.path);
    } else if (item.name === "Sair") {
      // 4. Lógica de logout refatorada
      try {
        await axiosClient.post('/logout');
        toast.success("Logout realizado com sucesso!");
      } catch (error) {
        console.error("Falha ao fazer logout no backend:", error);
        toast.error("Não foi possível invalidar a sessão no servidor.");
      } finally {
        // 5. Independentemente do resultado da API, limpamos o estado do frontend
        setUser(null);
        setToken(null);
        navigate("/login", { replace: true });
      }
    }
  };

  return (
    <>
      {/* O resto do JSX continua exatamente o mesmo... */}
      {!isCollapsed && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 z-20 lg:hidden"
          onClick={onToggle}
        />
      )}
      <div className={cn(
        "fixed left-0 top-0 z-30 h-screen bg-[#333] transition-all duration-300 ease-in-out",
        isCollapsed
          ? "lg:translate-x-0 lg:w-16 -translate-x-full"
          : "translate-x-0 w-60",
        className
      )}>
        <div className="p-4 border-b border-gray-600 flex items-center justify-between min-h-[73px]">
          <Button
            variant="ghost"
            size="sm"
            onClick={onToggle}
            className="text-white hover:bg-gray-700 p-2 flex-shrink-0"
          >
            <Menu className="w-5 h-5" />
          </Button>
          {!isCollapsed && (
            <div className="flex-1 flex justify-center ml-2">
              <img
                src={catarinenselogo}
                alt="Logo Catarinense"
                className="h-10 object-contain"
              />
            </div>
          )}
        </div>
        <nav className="p-4 space-y-2">
          {menuItems.map((item) => (
            <button
              key={item.name}
              onClick={() => handleMenuClick(item)}
              className={cn(
                "w-full flex items-center px-3 py-3 rounded-lg text-left transition-colors duration-200",
                isCollapsed ? "justify-center" : "space-x-3",
                item.active
                  ? "bg-green-700 text-white"
                  : "text-gray-300 hover:bg-gray-700 hover:text-white"
              )}
              title={isCollapsed ? item.name : undefined}
            >
              <item.icon className={cn(
                "w-5 h-5 flex-shrink-0",
                item.active ? "text-white" : "text-gray-400"
              )} />
              {!isCollapsed && (
                <span className="font-medium text-sm">{item.name}</span>
              )}
            </button>
          ))}
        </nav>
      </div>
    </>
  );
};

export { Sidebar };
