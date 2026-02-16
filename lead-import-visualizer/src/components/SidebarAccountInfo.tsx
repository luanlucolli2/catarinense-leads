import { UserCircle2 } from "lucide-react";

import { cn } from "@/lib/utils";

interface SidebarAccountInfoProps {
  isCollapsed: boolean;
  userName: string;
  userEmail: string;
  userInitials: string;
}

export const SidebarAccountInfo = ({
  isCollapsed,
  userName,
  userEmail,
  userInitials,
}: SidebarAccountInfoProps) => {
  const containerClass = isCollapsed
    ? "h-10 px-1 flex items-center justify-center overflow-hidden"
    : "p-3";
  const avatarClass = isCollapsed ? "h-8 w-8 text-[11px]" : "h-9 w-9 text-xs";
  const statusDotClass = isCollapsed ? "-bottom-0 -right-0 h-2 w-2" : "-bottom-0.5 -right-0.5 h-2.5 w-2.5";
  const detailsClass = isCollapsed
    ? "opacity-0 w-0 -translate-x-1 overflow-hidden"
    : "opacity-100 w-auto translate-x-0";

  return (
    <div
      className={cn("mt-3 w-full transition-all duration-200", containerClass)}
      title={`Conta logada: ${userEmail}`}
    >
      <div className={cn("flex items-center", isCollapsed ? "justify-center" : "gap-3")}>
        <div className="relative flex-shrink-0">
          <div
            className={cn(
              "rounded-full bg-emerald-600 text-white font-semibold flex items-center justify-center shadow-md shadow-emerald-900/40 select-none",
              avatarClass
            )}
          >
            {userInitials}
          </div>
          <span className={cn("absolute rounded-full bg-emerald-300 ring-2 ring-[#333]", statusDotClass)} />
        </div>

        <div
          className={cn("min-w-0 transition-[opacity,transform,width] duration-200", detailsClass)}
          aria-hidden={isCollapsed}
        >
          <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400 flex items-center gap-1">
            <UserCircle2 className="h-3 w-3" />
            Conta logada
          </p>
          <p className="text-sm font-semibold text-white truncate">{userName}</p>
          <p className="text-xs text-gray-400 truncate">{userEmail}</p>
        </div>
      </div>
    </div>
  );
};
