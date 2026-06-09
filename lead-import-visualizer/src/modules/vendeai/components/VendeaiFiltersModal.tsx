import { useEffect } from "react";
import { Calendar, Check, Clock, Filter, Info, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { cn } from "@/lib/utils";

type WindowMode = "always" | "rolling" | "fixed";
type NewcorbanFilter = "all" | "sent";
type ProductFilter = "all" | "clt" | "fgts";
type SortDirection = "asc" | "desc";
type PeriodPreset = "always" | "today" | "yesterday" | "last7Days" | "last30Days" | "custom";

type WindowModeOption = {
  value: WindowMode;
  label: string;
};

type VendeaiFiltersModalProps = {
  isOpen: boolean;
  title: string;
  subtitle: string;
  from: string;
  to: string;
  windowMode: WindowMode;
  periodPreset: PeriodPreset;
  direction: SortDirection;
  newcorbanFilter: NewcorbanFilter;
  product: ProductFilter;
  windowModeOptions: WindowModeOption[];
  rangeError: string | null;
  onClose: () => void;
  onFromChange: (value: string) => void;
  onToChange: (value: string) => void;
  onWindowModeChange: (value: WindowMode) => void;
  onDirectionChange: (value: SortDirection) => void;
  onNewcorbanFilterChange: (value: NewcorbanFilter) => void;
  onProductChange: (value: ProductFilter) => void;
  onPeriodPresetChange: (value: PeriodPreset) => void;
  onClearFilters: () => void;
  onApply: () => void;
};

const NO_FOCUS = "focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0 focus:shadow-none";

function Section({
  title,
  description,
  active = false,
  children,
}: {
  title: string;
  description?: string;
  active?: boolean;
  children: React.ReactNode;
}) {
  return (
    <section
      className={cn(
        "rounded-lg border bg-white p-4 transition-all duration-200 shadow-[0_1px_2px_rgba(0,0,0,0.04)] hover:shadow-md",
        active ? "border-blue-300 ring-1 ring-blue-200 shadow-md" : "border-gray-200"
      )}
    >
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <h3 className={cn("text-sm font-semibold tracking-tight", active ? "text-blue-700" : "text-gray-800")}>{title}</h3>
          {description ? <p className="mt-0.5 text-xs text-gray-500">{description}</p> : null}
        </div>
        {active ? (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-50/80 px-2 py-0.5 text-[11px] font-medium text-blue-700 shadow-sm">
            <Check className="h-3 w-3" />
            ativo
          </span>
        ) : null}
      </div>
      <div className="space-y-3">{children}</div>
    </section>
  );
}

function Group({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="mb-6 last:mb-0">
      <div className="mb-3 border-b border-gray-200 pb-2">
        <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
      </div>
      <div
        className={cn(
          "grid grid-flow-dense gap-4",
          "[grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]",
          "xl:[grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]"
        )}
      >
        {children}
      </div>
    </div>
  );
}

const Label = ({ text }: { text: string }) => <label className="text-xs font-medium text-gray-700">{text}</label>;

export function VendeaiFiltersModal({
  isOpen,
  title,
  subtitle,
  from,
  to,
  windowMode,
  periodPreset,
  direction,
  newcorbanFilter,
  product,
  windowModeOptions,
  rangeError,
  onClose,
  onFromChange,
  onToChange,
  onWindowModeChange,
  onDirectionChange,
  onNewcorbanFilterChange,
  onProductChange,
  onPeriodPresetChange,
  onClearFilters,
  onApply,
}: VendeaiFiltersModalProps) {
  useEffect(() => {
    if (isOpen) document.body.style.overflow = "hidden";
    else document.body.style.overflow = "";

    return () => {
      document.body.style.overflow = "";
    };
  }, [isOpen]);

  if (!isOpen) return null;

  const chips = [
    ...(periodPreset === "always" ? [] : [`Período · ${periodPreset === "today" ? "Hoje" : periodPreset === "yesterday" ? "Ontem" : periodPreset === "last7Days" ? "7 dias" : periodPreset === "last30Days" ? "30 dias" : "Personalizado"}`]),
    ...(windowMode === "always" ? [] : [`Modo · ${windowMode === "rolling" ? "Janela móvel" : "Intervalo fixo"}`]),
    ...(product === "all" ? [] : [`Produto · ${product === "clt" ? "Crédito do Trabalhador" : "FGTS"}`]),
    ...(newcorbanFilter === "sent" ? ["Proposta enviada NewCorban"] : []),
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-[2px]">
      <div className="filters-modal flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/10">
        <header className="flex flex-shrink-0 flex-col gap-3 border-b bg-white/90 p-4 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/70 sm:p-6">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Filter className="h-5 w-5 text-gray-600" />
              <h2 className="text-lg font-semibold text-gray-900 sm:text-xl">{title}</h2>
            </div>
            <button
              type="button"
              onClick={onClose}
              className={cn("rounded p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700", NO_FOCUS)}
              aria-label="Fechar filtros"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
          <p className="text-sm text-gray-500">{subtitle}</p>
          <div className="flex flex-wrap gap-2">
            {chips.length === 0 ? (
              <span className="text-xs text-gray-500">Nenhum filtro ativo.</span>
            ) : (
              chips.map((chip) => (
                <span
                  key={chip}
                  className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-medium text-blue-700 shadow-[0_1px_0_rgba(0,0,0,0.05)]"
                >
                  <span className="inline-block h-1.5 w-1.5 rounded-full bg-blue-600 shadow-inner" />
                  {chip}
                </span>
              ))
            )}
          </div>
        </header>

        <div className="flex items-center gap-2 border-b bg-gray-50/90 px-4 py-2 shadow-[inset_0_-1px_0_rgba(0,0,0,0.03)] backdrop-blur sm:px-6">
          <Info className="h-4 w-4 text-gray-500" />
          <span className="text-xs text-gray-700 sm:text-sm">
            Ajuste <strong>visualização</strong> e <strong>filtros</strong> dos leads VendeAI
          </span>
        </div>

        <main className="flex-1 overflow-y-auto bg-gradient-to-b from-white to-gray-50 px-6 py-5">
          <Group title="Visualização">
            <Section title="Ordenação" description="Ajuste a ordem de exibição dos leads.">
              <div>
                <Label text="Ordem" />
                <Select value={direction} onValueChange={(value) => onDirectionChange(value as SortDirection)}>
                  <SelectTrigger className={cn(NO_FOCUS, "mt-2 border-gray-300 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}>
                    <div className="flex items-center gap-2">
                      <Clock className="h-4 w-4 text-gray-400" />
                      <SelectValue />
                    </div>
                  </SelectTrigger>
                  <SelectContent className="shadow-lg">
                    <SelectItem value="desc">Mais recentes</SelectItem>
                    <SelectItem value="asc">Mais antigos</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </Section>
          </Group>

          <Group title="Filtros">
            <div className="[grid-column:1/-1]">
              <Section title="Período" description="Defina o intervalo usado na tabela e nas métricas." active={periodPreset !== "always"}>
                <div>
                  <Label text="Período" />
                  <Select value={periodPreset} onValueChange={(value) => onPeriodPresetChange(value as PeriodPreset)}>
                    <SelectTrigger className={cn(NO_FOCUS, "mt-2 border-gray-300 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}>
                      <div className="flex items-center gap-2">
                        <Calendar className="h-4 w-4 text-gray-400" />
                        <SelectValue />
                      </div>
                    </SelectTrigger>
                    <SelectContent className="shadow-lg">
                      <SelectItem value="always">Sempre</SelectItem>
                      <SelectItem value="today">Hoje</SelectItem>
                      <SelectItem value="yesterday">Ontem</SelectItem>
                      <SelectItem value="last7Days">7 dias</SelectItem>
                      <SelectItem value="last30Days">30 dias</SelectItem>
                      <SelectItem value="custom">Personalizado</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {periodPreset === "always" ? null : (
                  <div>
                    <Label text="Modo do período" />
                    <Select value={windowMode} onValueChange={(value) => onWindowModeChange(value as WindowMode)}>
                      <SelectTrigger className={cn(NO_FOCUS, "mt-2 border-gray-300 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}>
                        <div className="flex items-center gap-2">
                          <Clock className="h-4 w-4 text-gray-400" />
                          <SelectValue />
                        </div>
                      </SelectTrigger>
                      <SelectContent className="shadow-lg">
                        {windowModeOptions
                          .filter((option) => option.value !== "always")
                          .map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                              {option.label}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}

                {periodPreset === "always" || windowMode === "always" ? null : (
                  <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <div className="min-w-0">
                      <Label text="Data inicial" />
                      <div className="relative mt-2">
                        <Calendar className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <Input
                          type="datetime-local"
                          value={from}
                          onChange={(event) => onFromChange(event.target.value)}
                          className={cn(NO_FOCUS, "w-full border-gray-300 pl-10 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                        />
                      </div>
                    </div>

                    <div className="min-w-0">
                      <Label text="Data final" />
                      <div className="relative mt-2">
                        <Calendar className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <Input
                          type="datetime-local"
                          value={to}
                          onChange={(event) => onToChange(event.target.value)}
                          className={cn(NO_FOCUS, "w-full border-gray-300 pl-10 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                        />
                      </div>
                    </div>
                  </div>
                )}

                {rangeError ? (
                  <div className="rounded-md border border-rose-100 bg-rose-50/60 p-3 text-sm text-rose-700">{rangeError}</div>
                ) : null}
              </Section>
            </div>

            <Section title="Proposta enviada NewCorban" description="Aplique um recorte opcional por envio." active={newcorbanFilter !== "all"}>
              <div>
                <Label text="Situação" />
                <Select value={newcorbanFilter} onValueChange={(value) => onNewcorbanFilterChange(value as NewcorbanFilter)}>
                  <SelectTrigger className={cn(NO_FOCUS, "mt-2 border-gray-300 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}>
                    <div className="flex items-center gap-2">
                      <Filter className="h-4 w-4 text-gray-400" />
                      <SelectValue />
                    </div>
                  </SelectTrigger>
                  <SelectContent className="shadow-lg">
                    <SelectItem value="all">Todos os leads</SelectItem>
                    <SelectItem value="sent">Proposta enviada NewCorban</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </Section>

            <Section title="Produto" description="Aplique um recorte opcional por produto." active={product !== "all"}>
              <div>
                <Label text="Produto" />
                <Select value={product} onValueChange={(value) => onProductChange(value as ProductFilter)}>
                  <SelectTrigger className={cn(NO_FOCUS, "mt-2 border-gray-300 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}>
                    <div className="flex items-center gap-2">
                      <Filter className="h-4 w-4 text-gray-400" />
                      <SelectValue />
                    </div>
                  </SelectTrigger>
                  <SelectContent className="shadow-lg">
                    <SelectItem value="all">Todos os produtos</SelectItem>
                    <SelectItem value="clt">Crédito do Trabalhador</SelectItem>
                    <SelectItem value="fgts">FGTS</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </Section>
          </Group>
        </main>

        <footer className="flex flex-shrink-0 flex-col-reverse gap-2 border-t bg-white/90 p-4 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/70 sm:flex-row sm:items-center sm:justify-end sm:gap-2">
          <Button
            variant="outline"
            className={cn("border-gray-300 text-gray-700 hover:bg-gray-50", NO_FOCUS)}
            onClick={() => {
              onClearFilters();
              onClose();
            }}
          >
            Limpar filtros
          </Button>

          <Button variant="outline" className={cn("border-gray-300 text-gray-700 hover:bg-gray-50", NO_FOCUS)} onClick={onClose}>
            Cancelar
          </Button>

          <Button className={cn("bg-blue-600 shadow-md transition-shadow hover:bg-blue-700 hover:shadow-lg", NO_FOCUS)} onClick={onApply}>
            Aplicar filtros
          </Button>
        </footer>
      </div>
    </div>
  );
}
