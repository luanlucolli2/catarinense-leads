import { Calendar, Check, Clock, Filter, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { cn } from "@/lib/utils";

type WindowMode = "rolling" | "fixed";
type NewcorbanFilter = "all" | "sent";

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
  newcorbanFilter: NewcorbanFilter;
  windowModeOptions: WindowModeOption[];
  rangeError: string | null;
  onClose: () => void;
  onFromChange: (value: string) => void;
  onToChange: (value: string) => void;
  onWindowModeChange: (value: WindowMode) => void;
  onNewcorbanFilterChange: (value: NewcorbanFilter) => void;
  onReset: () => void;
  onApply: () => void;
};

const NO_FOCUS = "focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0 focus:shadow-none";

function Section({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-lg border border-blue-100 bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.04)]">
      <div className="mb-3">
        <h3 className="text-sm font-semibold text-blue-700">{title}</h3>
        {description ? <p className="mt-0.5 text-xs text-gray-500">{description}</p> : null}
      </div>
      <div className="space-y-3">{children}</div>
    </section>
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
  newcorbanFilter,
  windowModeOptions,
  rangeError,
  onClose,
  onFromChange,
  onToChange,
  onWindowModeChange,
  onNewcorbanFilterChange,
  onReset,
  onApply,
}: VendeaiFiltersModalProps) {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-[2px]">
      <div className="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
          <div className="min-w-0">
            <div className="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">
              <Filter className="h-3.5 w-3.5" />
              Filtros
            </div>
            <h2 className="mt-3 text-lg font-semibold text-gray-900">{title}</h2>
            <p className="mt-1 text-sm text-gray-500">{subtitle}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
            aria-label="Fechar filtros"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="overflow-y-auto px-6 py-5">
          <Section title="Período" description="Defina o intervalo usado na tabela e nas métricas.">
            <div>
              <Label text="Modo de intervalo" />
              <Select value={windowMode} onValueChange={(value) => onWindowModeChange(value as WindowMode)}>
                <SelectTrigger className={cn(NO_FOCUS, "mt-2 border-gray-300 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}>
                  <div className="flex items-center gap-2">
                    <Clock className="h-4 w-4 text-gray-400" />
                    <SelectValue />
                  </div>
                </SelectTrigger>
                <SelectContent className="shadow-lg">
                  {windowModeOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              <div>
                <Label text="Data inicial" />
                <div className="relative mt-2">
                  <Calendar className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                  <Input
                    type="datetime-local"
                    value={from}
                    onChange={(event) => onFromChange(event.target.value)}
                    className={cn(NO_FOCUS, "border-gray-300 pl-10 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                  />
                </div>
              </div>

              <div>
                <Label text="Data final" />
                <div className="relative mt-2">
                  <Calendar className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                  <Input
                    type="datetime-local"
                    value={to}
                    onChange={(event) => onToChange(event.target.value)}
                    className={cn(NO_FOCUS, "border-gray-300 pl-10 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                  />
                </div>
              </div>
            </div>

            {rangeError ? (
              <div className="rounded-md border border-rose-100 bg-rose-50/60 p-3 text-sm text-rose-700">{rangeError}</div>
            ) : null}
          </Section>

          <Section title="Proposta enviada NewCorban" description="Filtre os leads com envio realizado para a NewCorban.">
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
        </div>

        <div className="flex flex-col-reverse gap-2 border-t border-gray-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          <Button variant="ghost" onClick={onReset} className="text-blue-700 hover:bg-blue-50 hover:text-blue-800">
            Restaurar padrão
          </Button>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Button variant="outline" onClick={onClose} className="border-gray-200">
              Cancelar
            </Button>
            <Button onClick={onApply} className="bg-blue-600 hover:bg-blue-700">
              <Check className="mr-2 h-4 w-4" />
              Aplicar filtros
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
