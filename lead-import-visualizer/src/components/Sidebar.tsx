// src/components/Sidebar.tsx
import { useEffect, useMemo, useRef, useState } from "react";
import {
  Home,
  LogOut,
  Menu,
  FileText,
  Search,
  Briefcase,
  ChevronDown,
  PiggyBank,
  Link2,
  Loader2,
  Handshake,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { useNavigate, useLocation } from "react-router-dom";
import catarinenselogo from "../../public/catainenseLogo.png";
import logoUy3 from "@/assets/logouy3png.png";
import { toast } from "sonner";
import { useAuth } from "@/contexts/AuthContext";
import axiosClient from "@/api/axiosClient";
import { isC6OnlyUser } from "@/lib/access";
import { SidebarAccountInfo } from "@/components/SidebarAccountInfo";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

interface SidebarProps {
  className?: string;
  isCollapsed: boolean;
  onToggle: () => void;
}

type MenuItem = {
  name: string;
  icon: LucideIcon;
  imageSrc?: string;
  path: string | null;
  active: boolean;
};

type MenuGroup = {
  name: string;
  icon: LucideIcon;
  key: string;
  items: MenuItem[];
};

const getUserInitials = (name: string, email: string): string => {
  const source = (name || email || "U").trim();
  if (!source) return "U";

  const chunks = source
    .split(/[\s@._-]+/)
    .map((part) => part.trim())
    .filter(Boolean);

  if (chunks.length === 0) return "U";
  if (chunks.length === 1) return chunks[0].slice(0, 2).toUpperCase();

  return `${chunks[0][0]}${chunks[1][0]}`.toUpperCase();
};

const Sidebar = ({ className, isCollapsed, onToggle }: SidebarProps) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { user, setUser } = useAuth();
  const userName = typeof user?.name === "string" && user.name.trim() !== ""
    ? user.name.trim()
    : "Conta compartilhada";
  const userEmail = typeof user?.email === "string" && user.email.trim() !== ""
    ? user.email.trim().toLowerCase()
    : "sem-email";
  const userInitials = getUserInitials(userName, userEmail);

  const [expandedGroups, setExpandedGroups] = useState<string[]>([]);
  const firstRender = useRef(true);

  // AlertDialog: logout confirm
  const [confirmLogoutOpen, setConfirmLogoutOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  const isActive = (path: string) =>
    location.pathname === path || location.pathname.startsWith(path);

  const menuGroups: MenuGroup[] = useMemo(() => {
    const c6Group: MenuGroup = {
      name: "C6",
      icon: Link2,
      key: "c6",
      items: [
        {
          name: "Links C6",
          icon: Link2,
          path: "/c6/links",
          active: isActive("/c6/links"),
        },
      ],
    };

    if (isC6OnlyUser(user)) {
      return [c6Group];
    }

    return [
      {
        name: "Leads",
        icon: Home,
        key: "leads",
        items: [
          {
            name: "Dashboard (Leads)",
            icon: Home,
            path: "/",
            active: location.pathname === "/",
          },
          {
            name: "Importações (Leads)",
            icon: FileText,
            path: "/importacoes/historico",
            active: isActive("/importacoes/historico"),
          },
        ],
      },
      {
        name: "Consultas",
        icon: Search,
        key: "consultas",
        items: [
          {
            name: "FGTS",
            icon: PiggyBank,
            path: "/fgts-off/consulta",
            active: isActive("/fgts-off/consulta"),
          },
          {
            name: "CLT",
            icon: Briefcase,
            path: "/clt/consulta",
            active: isActive("/clt/consulta"),
          },
        ],
      },
      {
        name: "Parceiros",
        icon: Handshake,
        key: "parceiros",
        items: [
          {
            name: "UY3",
            icon: Handshake,
            imageSrc: logoUy3,
            path: "/parceiros/uy3",
            active: location.pathname === "/parceiros/uy3",
          },
        ],
      },
      c6Group,
    ];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, user]);

  const singleItems: MenuItem[] = useMemo(
    () => [
      {
        name: "Sair",
        icon: LogOut,
        path: null,
        active: false,
      },
    ],
    []
  );

  const expandForPath = (path: string) => {
    const group = menuGroups.find((g) => g.items.some((i) => i.path === path));
    if (!group) return;
    setExpandedGroups((prev) =>
      prev.includes(group.key) ? prev : [...prev, group.key]
    );
  };

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

  const executeLogout = async () => {
    if (loggingOut) return;

    try {
      setLoggingOut(true);
      await axiosClient.post("/logout");
      toast.success("Logout realizado com sucesso!");
    } catch (error) {
      console.error("Falha ao fazer logout no backend:", error);
      toast.error("Não foi possível invalidar a sessão no servidor.");
    } finally {
      setLoggingOut(false);
      setConfirmLogoutOpen(false);
      setUser(null);
      navigate("/login", { replace: true });
    }
  };

  const handleMenuClick = (item: MenuItem) => {
    // Se sidebar está "fechada" e o user clicar em itens do menu,
    // abre e expande o grupo da opção clicada.
    if (isCollapsed) {
      onToggle();
      if (item.path) expandForPath(item.path);
    }

    if (item.path) {
      navigate(item.path);
      return;
    }

    if (item.name === "Sair") {
      setConfirmLogoutOpen(true);
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
          "fixed left-0 top-0 z-30 h-screen bg-[#333]",
          "transition-[width,transform] duration-300 ease-in-out",
          isCollapsed
            ? "lg:translate-x-0 lg:w-16 -translate-x-full"
            : "translate-x-0 w-60",
          className
        )}
      >
        {/* Header */}
        <div className="p-4 flex items-center justify-between min-h-[73px]">
          <Button
            variant="ghost"
            size="sm"
            onClick={onToggle}
            className="text-white hover:bg-gray-700 p-2 flex-shrink-0"
          >
            <Menu className="w-5 h-5" />
          </Button>

          {/* Para evitar reflow durante a animação:
              - Mantém o container do logo sempre montado
              - Controla visibilidade com opacity/scale (sem mexer no layout) */}
          <div
            className={cn(
              "flex-1 flex justify-center ml-2",
              "transition-all duration-200",
              isCollapsed
                ? "opacity-0 scale-95 pointer-events-none select-none"
                : "opacity-100 scale-100"
            )}
            aria-hidden={isCollapsed}
          >
            <img
              src={catarinenselogo}
              alt="Logo Catarinense"
              className="h-10 object-contain"
            />
          </div>
        </div>

        {/* Menu */}
        <nav className={cn("space-y-2", isCollapsed ? "px-2 py-4" : "p-4")}>
          {/* Grupos expansíveis */}
          {menuGroups.map((group) => {
            const isExpanded = expandedGroups.includes(group.key);

            return (
              <div key={group.key}>
                {/* Cabeçalho do grupo */}
                <button
                  onClick={() => {
                    // Se estiver fechada, ao clicar no grupo abre a sidebar e expande o grupo.
                    if (isCollapsed) {
                      onToggle();
                      setExpandedGroups((prev) =>
                        prev.includes(group.key) ? prev : [...prev, group.key]
                      );
                      return;
                    }
                    toggleGroup(group.key);
                  }}
                  className={cn(
                    "w-full flex items-center px-3 py-3 rounded-lg text-left transition-colors duration-200",
                    isCollapsed ? "justify-center" : "justify-between",
                    "text-gray-300 hover:bg-gray-700 hover:text-white"
                  )}
                  title={isCollapsed ? group.name : undefined}
                >
                  <div className={cn("flex items-center", isCollapsed ? "justify-center" : "space-x-3")}>
                    <group.icon className="w-5 h-5 flex-shrink-0 text-gray-400" />

                    {/* Mantém o label montado e oculta via opacity/width (evita “reorganizar”) */}
                    <span
                      className={cn(
                        "font-medium text-sm whitespace-nowrap",
                        "transition-[opacity,transform,width] duration-200",
                        isCollapsed
                          ? "opacity-0 w-0 translate-x-1 overflow-hidden"
                          : "opacity-100 w-auto translate-x-0"
                      )}
                    >
                      {group.name}
                    </span>
                  </div>

                  {/* Chevron idem: sempre montado, mas invisível quando colapsada */}
                  <ChevronDown
                    className={cn(
                      "w-4 h-4 transition-all duration-300 ease-in-out",
                      isCollapsed
                        ? "opacity-0 w-0 overflow-hidden"
                        : "opacity-100 w-4",
                      isExpanded ? "rotate-180" : "rotate-0"
                    )}
                    aria-hidden={isCollapsed}
                  />
                </button>

                {/* Submenu (só renderiza quando expandido e sidebar aberta) */}
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
                        {item.imageSrc ? (
                          <img
                            src={item.imageSrc}
                            alt={`Logo ${item.name}`}
                            className="h-4 w-auto max-w-[22px] object-contain flex-shrink-0"
                          />
                        ) : (
                          <item.icon
                            className={cn(
                              "w-4 h-4 flex-shrink-0",
                              item.active ? "text-white" : "text-gray-400"
                            )}
                          />
                        )}
                        <span className="font-medium text-sm">{item.name}</span>
                      </button>
                    ))}
                  </div>
                )}

                {/* Quando colapsado: clique no ícone do grupo deve abrir e já expandir,
                    e também permitir “atalho” via item ativo ao abrir automaticamente.
                    O requisito “clicar na lupa/casinha” costuma estar no header do grupo (Search/Home),
                    então isso já fica atendido pelo onClick acima. */}
              </div>
            );
          })}

          <SidebarAccountInfo
            isCollapsed={isCollapsed}
            userName={userName}
            userEmail={userEmail}
            userInitials={userInitials}
          />

          {/* Separador */}
          <div className="border-t border-gray-600 my-4" />

          {/* Itens individuais */}
          {singleItems.map((item) => (
            <button
              key={item.name}
              onClick={() => {
                // não abre sidebar para "Sair", só confirma
                handleMenuClick(item);
              }}
              className={cn(
                "w-full flex items-center px-3 py-3 rounded-lg text-left transition-colors duration-200",
                isCollapsed ? "justify-center" : "space-x-3",
                item.active
                  ? "bg-green-700 text-white"
                  : "text-gray-300 hover:bg-gray-700 hover:text-white"
              )}
              title={isCollapsed ? item.name : undefined}
            >
              <item.icon className="w-5 h-5 flex-shrink-0 text-gray-400" />
              <span
                className={cn(
                  "font-medium text-sm whitespace-nowrap",
                  "transition-[opacity,transform,width] duration-200",
                  isCollapsed
                    ? "opacity-0 w-0 translate-x-1 overflow-hidden"
                    : "opacity-100 w-auto translate-x-0"
                )}
              >
                {item.name}
              </span>
            </button>
          ))}
        </nav>
      </div>

      {/* Confirm logout */}
      <AlertDialog open={confirmLogoutOpen} onOpenChange={setConfirmLogoutOpen}>
        <AlertDialogContent className="sm:max-w-lg">
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-red-600">
              Sair do sistema?
            </AlertDialogTitle>
          </AlertDialogHeader>

          <div className="text-sm text-gray-700 dark:text-gray-200">
            <p>Você será desconectado e voltará para a tela de login.</p>
            <p className="mt-2">Deseja continuar?</p>
          </div>

          <AlertDialogFooter className="gap-2">
            <AlertDialogCancel disabled={loggingOut} className="w-full sm:w-auto">
              Fechar
            </AlertDialogCancel>

            <AlertDialogAction
              className="w-full sm:w-auto bg-red-600 hover:bg-red-700"
              disabled={loggingOut}
              onClick={(e) => {
                e.preventDefault();
                void executeLogout();
              }}
            >
              {loggingOut ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                "Sim, sair"
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
};

export { Sidebar };
