import { Download, FileDown, Filter, Loader2, RefreshCw, Search } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

type VendeaiControlsProps = {
  modeLabel: string;
  filteredCount: number;
  countLabel: string;
  searchValue: string;
  searchPlaceholder: string;
  exportLabel: string;
  exportLoading: boolean;
  exportIcon: "file" | "download";
  isRefreshing: boolean;
  refreshCountdown: number;
  controlLabels: string[];
  filterLabels: string[];
  hasActiveFilters?: boolean;
  onSearchChange: (value: string) => void;
  onFilterClick: () => void;
  onExportClick: () => void;
  onRefreshClick: () => void;
  onClearFilters: () => void;
};

export function VendeaiControls({
  modeLabel,
  filteredCount,
  countLabel,
  searchValue,
  searchPlaceholder,
  exportLabel,
  exportLoading,
  exportIcon,
  isRefreshing,
  refreshCountdown,
  controlLabels,
  filterLabels,
  hasActiveFilters = true,
  onSearchChange,
  onFilterClick,
  onExportClick,
  onRefreshClick,
  onClearFilters,
}: VendeaiControlsProps) {
  const ExportIcon = exportIcon === "file" ? FileDown : Download;

  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-gray-900">{modeLabel}</p>
          <p className="mt-1 text-sm text-gray-500">Controle a visualização e exporte somente os dados filtrados.</p>

          <label className="mt-3 block w-full max-w-md">
            <span className="mb-1 block text-xs font-medium text-gray-700">Busca rápida</span>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <Input
                type="text"
                placeholder={searchPlaceholder}
                value={searchValue}
                onChange={(event) => onSearchChange(event.target.value)}
                className="h-10 w-full pl-9"
              />
            </div>
          </label>
        </div>

        <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-row sm:items-end">
          <Button
            onClick={onFilterClick}
            variant="outline"
            className="relative h-9 justify-center gap-2 border-blue-500 bg-blue-50/50 px-4 text-blue-700 hover:bg-blue-50"
          >
            <Filter className="h-4 w-4" />
            Filtros
            <span className="absolute right-0 top-0 -mr-1 -mt-1 h-2.5 w-2.5 rounded-full bg-blue-500" />
          </Button>

          <Button
            onClick={onExportClick}
            variant="outline"
            disabled={exportLoading}
            className="h-9 justify-center gap-2 border-gray-200 px-4 hover:bg-gray-50"
          >
            {exportLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <ExportIcon className="h-4 w-4" />}
            {exportLabel}
          </Button>

          <Button
            onClick={onRefreshClick}
            disabled={isRefreshing || refreshCountdown > 0}
            className="h-9 justify-center gap-2 bg-blue-600 px-4 hover:bg-blue-700 text-white"
          >
            {isRefreshing ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
            {refreshCountdown > 0 ? `Atualizar (${refreshCountdown}s)` : "Atualizar"}
          </Button>
        </div>
      </div>

      <div className="mt-4 flex flex-col gap-3 rounded-lg border border-blue-100 bg-blue-50/40 p-3 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0 flex-1">
            <div className="mb-3 flex items-center gap-2">
              <Filter className="h-4 w-4 text-blue-600" />
              <span className="text-sm font-medium text-blue-900">
                Resultados atuais
                <span className="ml-1 font-normal text-blue-600">
                  · {filteredCount} {countLabel}
                </span>
              </span>
            </div>
            <div className="space-y-3">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-blue-700">Visualização</p>
                <div className="flex flex-wrap gap-2">
                  {controlLabels.map((label) => (
                    <span
                      key={label}
                      className={cn("inline-flex items-center rounded-md border border-indigo-200 bg-white px-2 py-1 text-xs font-medium text-indigo-800 shadow-sm")}
                    >
                      {label}
                    </span>
                  ))}
                </div>
              </div>
              {hasActiveFilters ? (
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-blue-700">Filtros</p>
                  <div className="flex flex-wrap gap-2">
                    {filterLabels.map((label) => (
                      <span
                        key={label}
                        className={cn("inline-flex items-center rounded-md border border-blue-200 bg-white px-2 py-1 text-xs font-medium text-blue-800 shadow-sm")}
                      >
                        {label}
                      </span>
                    ))}
                  </div>
                </div>
              ) : (
                <p className="text-xs text-blue-700">Sem filtros adicionais.</p>
              )}
            </div>
          </div>
          {hasActiveFilters ? (
            <Button
              onClick={onClearFilters}
              variant="ghost"
              size="sm"
              className="mt-2 h-8 w-full shrink-0 self-start text-xs text-blue-700 hover:bg-blue-100/50 hover:text-blue-800 sm:mt-0 sm:w-auto"
            >
              Limpar filtros
            </Button>
          ) : null}
        </div>
    </div>
  );
}
