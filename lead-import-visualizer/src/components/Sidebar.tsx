// src/components/Sidebar.tsx
import {
  Home,
  LogOut,
  Menu,
  FileText,
  Search,
  Building,
  Briefcase,
  ChevronDown,
  PiggyBank,
  Pi
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { useNavigate, useLocation } from "react-router-dom";
import { useEffect, useRef, useState } from "react";
import catarinenselogo from "../../public/catainenseLogo.png";
import { toast } from "sonner";
import { useAuth } from "@/contexts/AuthContext";
import axiosClient from "@/api/axiosClient";

interface SidebarProps {
  className?: string;
  isCollapsed: boolean;
  onToggle: () => void;
}

type MenuItem = {
  name: string;
  icon: LucideIcon;
  path: string | null;
  active: boolean;
};

type MenuGroup = {
  name: string;
  icon: LucideIcon;
  key: string;
  items: MenuItem[];
};

const Sidebar = ({ className, isCollapsed, onToggle }: SidebarProps) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { setUser } = useAuth();

  const [expandedGroups, setExpandedGroups] = useState<string[]>([]);
  const firstRender = useRef(true);

  const isActive = (path: string) =>
    location.pathname === path || location.pathname.startsWith(path);

  const menuGroups: MenuGroup[] = [
    {
      name: "FGTS",
      icon: PiggyBank,
      key: "fgts",
      items: [
        {
          name: "Dashboard (Leads)",
          icon: Home,
          path: "/",
          active: location.pathname === "/",
        },
        {
          name: "Histórico de Importações (Leads)",
          icon: FileText,
          path: "/importacoes/historico",
          active: isActive("/importacoes/historico"),
        },
        {
          name: "Consulta FGTS Base Offline",
          icon: Search,
          path: "/fgts-off/consulta", // 👈 rota real do módulo
          active: isActive("/fgts-off/consulta"),
        },
      ],
    },
    {
      name: "CLT",
      icon: Briefcase,
      key: "clt",
      items: [
        {
          name: "Consulta CLT",
          icon: Search,
          path: "/clt/consulta",
          active: isActive("/clt/consulta"),
        },
      ],
    },
  ];

  const singleItems: MenuItem[] = [
    {
      name: "Sair",
      icon: LogOut,
      path: null,
      active: false,
    },
  ];

  const toggleGroup = (groupKey: string) => {
    setExpandedGroups((prev) =>
      prev.includes(groupKey)
        ? prev.filter((g) => g !== groupKey)
        : [...prev, groupKey]
    );
  };

  // Fecha a sidebar em mobile quando muda a rota (exceto no 1º render)
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    if (window.innerWidth < 1024 && !isCollapsed) {
      onToggle();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname]);

  // Auto-expand do grupo que contém o item ativo
  useEffect(() => {
    menuGroups.forEach((group) => {
      const hasActive = group.items.some((i) => i.active);
      if (hasActive && !expandedGroups.includes(group.key)) {
        setExpandedGroups((prev) => [...prev, group.key]);
      }
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname]);

  const handleMenuClick = async (item: MenuItem) => {
    if (item.path) {
      navigate(item.path);
      return;
    }
    if (item.name === "Sair") {
      try {
        await axiosClient.post("/logout");
        toast.success("Logout realizado com sucesso!");
      } catch (error) {
        console.error("Falha ao fazer logout no backend:", error);
        toast.error("Não foi possível invalidar a sessão no servidor.");
      } finally {
        setUser(null);
        navigate("/login", { replace: true });
      }
    }
  };

  return (
    <>
      {/* Mobile overlay */}
      {!isCollapsed && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 z-20 lg:hidden"
          onClick={onToggle}
        />
      )}

      {/* Sidebar */}
      <div
        className={cn(
          "fixed left-0 top-0 z-30 h-screen bg-[#333] transition-all duration-300 ease-in-out",
          isCollapsed
            ? "lg:translate-x-0 lg:w-16 -translate-x-full"
            : "translate-x-0 w-60",
          className
        )}
      >
        {/* Header (sem border-b, como no protótipo) */}
        <div className="p-4 flex items-center justify-between min-h-[73px]">
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

        {/* Menu */}
        <nav className="p-4 space-y-2">
          {/* Grupos expansíveis */}
          {menuGroups.map((group) => {
            const isExpanded = expandedGroups.includes(group.key);
            return (
              <div key={group.key}>
                {/* Cabeçalho do grupo */}
                <button
                  onClick={() => !isCollapsed && toggleGroup(group.key)}
                  className={cn(
                    "w-full flex items-center px-3 py-3 rounded-lg text-left transition-colors duration-200",
                    isCollapsed ? "justify-center" : "justify-between",
                    "text-gray-300 hover:bg-gray-700 hover:text-white"
                  )}
                  title={isCollapsed ? group.name : undefined}
                >
                  <div
                    className={cn(
                      "flex items-center",
                      isCollapsed ? "justify-center" : "space-x-3"
                    )}
                  >
                    <group.icon className="w-5 h-5 flex-shrink-0 text-gray-400" />
                    {!isCollapsed && (
                      <span className="font-medium text-sm">{group.name}</span>
                    )}
                  </div>
                  {!isCollapsed && (
                    <ChevronDown
                      className={cn(
                        "w-4 h-4 transition-transform duration-300 ease-in-out",
                        isExpanded ? "rotate-180" : "rotate-0"
                      )}
                    />
                  )}
                </button>

                {/* Submenu */}
                {!isCollapsed && isExpanded && (
                  <div className="ml-4 mt-1 space-y-1">
                    {group.items.map((item) => (
                      <button
                        key={item.name}
                        onClick={() => handleMenuClick(item)}
                        className={cn(
                          "w-full flex items-center px-3 py-2 rounded-lg text-left transition-colors duration-200 space-x-3",
                          item.active
                            ? "bg-green-700 text-white"
                            : "text-gray-300 hover:bg-gray-700 hover:text-white"
                        )}
                      >
                        <item.icon
                          className={cn(
                            "w-4 h-4 flex-shrink-0",
                            item.active ? "text-white" : "text-gray-400"
                          )}
                        />
                        <span className="font-medium text-sm">{item.name}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            );
          })}

          {/* Separador */}
          <div className="border-t border-gray-600 my-4" />

          {/* Itens individuais */}
          {singleItems.map((item) => (
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
              <item.icon
                className={cn(
                  "w-5 h-5 flex-shrink-0",
                  item.active ? "text-white" : "text-gray-400"
                )}
              />
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
