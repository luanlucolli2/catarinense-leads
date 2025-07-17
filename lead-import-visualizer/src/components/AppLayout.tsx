import { useState, useEffect } from "react";
import { Menu } from "lucide-react";
import { Outlet } from "react-router-dom";
import { Sidebar } from "@/components/Sidebar";
import { Button } from "@/components/ui/button";

export const AppLayout = () => {
  const [sidebarCollapsed, setSidebarCollapsed] = useState<boolean>(() => {
    const saved = localStorage.getItem("sidebar-collapsed");
    return saved ? JSON.parse(saved) : false;
  });

  useEffect(() => {
    localStorage.setItem("sidebar-collapsed", JSON.stringify(sidebarCollapsed));
  }, [sidebarCollapsed]);

  const toggleSidebar = () => setSidebarCollapsed((v) => !v);

  return (
    <div className="min-h-screen bg-gray-50 flex w-full max-w-full overflow-x-hidden">
      <Sidebar isCollapsed={sidebarCollapsed} onToggle={toggleSidebar} />

      <div
        className={`flex-1 transition-all duration-300 min-w-0 max-w-full ${
          sidebarCollapsed ? "lg:ml-16" : "lg:ml-60"
        }`}
      >
        {/* Header */}
        <div className="bg-white border-b border-gray-200 w-full">
          <div className="px-4 py-4 flex items-center justify-between min-h-[73px]">
            <div className="flex items-center gap-4">
              <Button
                onClick={toggleSidebar}
                variant="outline"
                size="sm"
                className="lg:hidden flex items-center justify-center px-2 border-gray-300 hover:bg-gray-50"
              >
                <Menu className="w-4 h-4" />
                <span className="ml-2">Menu</span>
              </Button>

              <h1 className="text-lg font-semibold text-gray-900">
                Sistema de Leads
              </h1>
            </div>
          </div>
        </div>

        {/* Conteúdo da rota */}
        <div className="min-h-[calc(100vh-73px)] bg-gray-50">
          <Outlet />
        </div>
      </div>
    </div>
  );
};
