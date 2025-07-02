
import { Home, LogOut, Menu, FileText } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { useNavigate, useLocation } from "react-router-dom";
import { useEffect } from "react";
import catarinenselogo from "../../public/catainenseLogo.png";
import React from "react";
import { toast } from "sonner";

interface SidebarProps {
  className?: string;
  isCollapsed: boolean;
  onToggle: () => void;
}

const Sidebar = ({ className, isCollapsed, onToggle }: SidebarProps) => {
  const navigate = useNavigate();
  const location = useLocation();

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
  const firstRender = React.useRef(true);

  // Fechar sidebar em telas menores quando a rota mudar
  useEffect(() => {
    // Ignora a primeira execução para não fechar a sidebar ao carregar a página
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }

    // Fecha a sidebar se estiver aberta em mobile e a rota mudar
    if (window.innerWidth < 1024 && !isCollapsed) {
      onToggle();
    }
  }, [location.pathname]); // O hook agora só depende da mudança de rota
  
  const handleMenuClick = (item: typeof menuItems[0]) => {
    if (item.path) {
      navigate(item.path);
    } else if (item.name === "Sair") {
      // Lógica de logout
      localStorage.removeItem("isAuthenticated");
      toast.success("Logout realizado com sucesso!");
      navigate("/login", { replace: true });
    }
  };

  return (
    <>
      {/* Mobile overlay - só aparece quando sidebar está aberta em mobile */}
      {!isCollapsed && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 z-20 lg:hidden"
          onClick={onToggle}
        />
      )}

      {/* Sidebar */}
      <div className={cn(
        "fixed left-0 top-0 z-30 h-screen bg-[#333] transition-all duration-300 ease-in-out",
        // Em mobile: escondida quando collapsed, visível quando não collapsed
        // Em desktop: sempre visível, mas com largura diferente
        isCollapsed
          ? "lg:translate-x-0 lg:w-16 -translate-x-full"
          : "translate-x-0 w-60",
        className
      )}>
        {/* Header com altura alinhada ao header principal */}
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

        {/* Menu Items */}
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
