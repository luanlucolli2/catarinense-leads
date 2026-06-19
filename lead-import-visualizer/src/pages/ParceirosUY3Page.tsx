import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { endOfDay, format, parseISO, startOfDay, subDays, subHours } from "date-fns";
import { ptBR } from "date-fns/locale";
import {
  AlertCircle,
  ArrowUpDown,
  Calendar,
  ChevronDown,
  ChevronUp,
  Clock,
  FileDown,
  Filter,
  Inbox,
  Info,
  Loader2,
  RefreshCw,
} from "lucide-react";

import { downloadUy3Export, getUy3ExportStatus, listUy3Posts, startUy3Export } from "@/api/uy3";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";

type SortDirection = "desc" | "asc";
type Uy3WindowMode = "rolling" | "fixed";
type Uy3PeriodPreset = "custom" | "today" | "yesterday" | "last7Days" | "last30Days";
type Uy3PersistedFilters = {
  from: string;
  to: string;
  sortDirection: SortDirection;
  windowMode: Uy3WindowMode;
  periodPreset: Uy3PeriodPreset;
};

const UY3_FILTERS_STORAGE_KEY = "uy3:filters:v1";

const sortOptions: Array<{ value: SortDirection; label: string }> = [
  { value: "desc", label: "Mais recentes" },
  { value: "asc", label: "Mais antigos" },
];

const windowModeOptions: Array<{ value: Uy3WindowMode; label: string }> = [
  { value: "rolling", label: "Janela móvel" },
  { value: "fixed", label: "Intervalo fixo" },
];

const periodPresetOptions: Array<{ value: Uy3PeriodPreset; label: string }> = [
  { value: "custom", label: "Personalizado" },
  { value: "today", label: "Hoje" },
  { value: "yesterday", label: "Ontem" },
  { value: "last7Days", label: "7 dias" },
  { value: "last30Days", label: "30 dias" },
];

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

const formatDateTime = (iso: string | null): string => {
  if (!iso) return "-";

  try {
    return format(parseISO(iso), "dd/MM/yyyy 'às' HH:mm:ss", { locale: ptBR });
  } catch {
    return "-";
  }
};

const sleep = (ms: number): Promise<void> => {
  return new Promise((resolve) => {
    window.setTimeout(resolve, ms);
  });
};

const getExportPollDelayMs = (attempt: number): number => {
  if (attempt < 10) return 2000;
  if (attempt < 30) return 3000;
  return 5000;
};

const buildDefaultFilters = (): Uy3PersistedFilters => ({
  from: toDateTimeLocalValue(subHours(new Date(), 24)),
  to: toDateTimeLocalValue(new Date()),
  sortDirection: "desc",
  windowMode: "rolling",
  periodPreset: "custom",
});

const isValidDateTimeLocal = (value: unknown): value is string => {
  if (typeof value !== "string" || value.trim() === "") return false;
  const parsed = new Date(value);
  return !Number.isNaN(parsed.getTime());
};

const loadPersistedFilters = (): Uy3PersistedFilters => {
  const fallback = buildDefaultFilters();
  if (typeof window === "undefined") return fallback;

  try {
    const raw = window.localStorage.getItem(UY3_FILTERS_STORAGE_KEY);
    if (!raw) return fallback;

    const parsed = JSON.parse(raw) as Partial<Uy3PersistedFilters>;

    const from = isValidDateTimeLocal(parsed.from) ? parsed.from : fallback.from;
    const to = isValidDateTimeLocal(parsed.to) ? parsed.to : fallback.to;
    const sortDirection =
      parsed.sortDirection === "asc" || parsed.sortDirection === "desc"
        ? parsed.sortDirection
        : fallback.sortDirection;
    const windowMode =
      parsed.windowMode === "fixed" || parsed.windowMode === "rolling"
        ? parsed.windowMode
        : fallback.windowMode;
    const periodPreset =
      parsed.periodPreset === "today" ||
      parsed.periodPreset === "yesterday" ||
      parsed.periodPreset === "last7Days" ||
      parsed.periodPreset === "last30Days" ||
      parsed.periodPreset === "custom"
        ? parsed.periodPreset
        : fallback.periodPreset;

    if (windowMode === "rolling") {
      const rolled = periodPreset === "custom" ? rollRangeToNow(from, to) : presetRangeToNow(periodPreset);
      return { from: rolled.from, to: rolled.to, sortDirection, windowMode, periodPreset };
    }

    return { from, to, sortDirection, windowMode, periodPreset };
  } catch {
    return fallback;
  }
};

const persistFilters = (filters: Uy3PersistedFilters): void => {
  if (typeof window === "undefined") return;

  try {
    window.localStorage.setItem(UY3_FILTERS_STORAGE_KEY, JSON.stringify(filters));
  } catch {
    return;
  }
};

const rollRangeToNow = (fromValue: string, toValue: string, baseNow: Date = new Date()): { from: string; to: string } => {
  const fromDate = new Date(fromValue);
  const toDate = new Date(toValue);

  if (Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime()) || fromDate > toDate) {
    const now = baseNow;
    return {
      from: toDateTimeLocalValue(subHours(now, 24)),
      to: toDateTimeLocalValue(now),
    };
  }

  const durationMs = Math.max(60_000, toDate.getTime() - fromDate.getTime());
  const now = baseNow;
  const nextFrom = new Date(now.getTime() - durationMs);

  return {
    from: toDateTimeLocalValue(nextFrom),
    to: toDateTimeLocalValue(now),
  };
};

const presetRangeToNow = (preset: Exclude<Uy3PeriodPreset, "custom">, baseNow: Date = new Date()): { from: string; to: string } => {
  if (preset === "today") {
    return {
      from: toDateTimeLocalValue(startOfDay(baseNow)),
      to: toDateTimeLocalValue(baseNow),
    };
  }

  if (preset === "yesterday") {
    const previousDay = subDays(baseNow, 1);
    return {
      from: toDateTimeLocalValue(startOfDay(previousDay)),
      to: toDateTimeLocalValue(endOfDay(previousDay)),
    };
  }

  const days = preset === "last7Days" ? 6 : 29;
  return {
    from: toDateTimeLocalValue(startOfDay(subDays(baseNow, days))),
    to: toDateTimeLocalValue(baseNow),
  };
};

const toDateTimeLocalValue = (date: Date): string => {
  const pad = (value: number): string => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(
    date.getHours()
  )}:${pad(date.getMinutes())}`;
};

const toUtcIsoFromDateTimeLocal = (value: string): string | null => {
  if (!value) return null;
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return null;
  return parsed.toISOString();
};

const renderDados = (value: unknown, depth = 0): JSX.Element => {
  if (value === null || value === undefined) {
    return <span className="text-muted-foreground italic text-sm">Não informado</span>;
  }

  if (typeof value === "boolean") {
    return (
      <Badge variant={value ? "default" : "secondary"} className={value ? "bg-emerald-500 hover:bg-emerald-600" : ""}>
        {value ? "Sim" : "Não"}
      </Badge>
    );
  }

  if (typeof value === "number") {
    return <span className="font-medium text-blue-700">{value.toLocaleString("pt-BR")}</span>;
  }

  if (typeof value === "string") {
    return <span className="text-gray-800 break-words">{value}</span>;
  }

  if (Array.isArray(value)) {
    if (value.length === 0) return <span className="text-muted-foreground italic text-sm">Lista vazia</span>;

    return (
      <div className="space-y-3 mt-2">
        {value.map((item, idx) => (
          <div key={idx} className="bg-white border border-gray-200 rounded-md p-3 shadow-sm transition-all hover:border-blue-200">
            <div className="text-xs font-bold text-gray-400 mb-2 uppercase tracking-wider border-b border-gray-100 pb-1">
              Item {idx + 1}
            </div>
            <div>{renderDados(item, depth + 1)}</div>
          </div>
        ))}
      </div>
    );
  }

  if (isRecord(value)) {
    const entries = Object.entries(value);
    if (entries.length === 0) return <span className="text-muted-foreground italic text-sm">Sem informações</span>;

    return (
      <div className="flex flex-col w-full rounded-md">
        {entries.map(([key, itemValue], index) => {
          const isComplex = typeof itemValue === "object" && itemValue !== null;

          return (
            <div
              key={key}
              className={`flex ${
                isComplex ? "flex-col" : "flex-col sm:flex-row sm:items-center gap-1 sm:gap-4"
              } py-2 ${index !== entries.length - 1 ? "border-b border-gray-100" : ""}`}
            >
              <span className="text-sm font-semibold text-gray-500 min-w-[160px] md:min-w-[200px] shrink-0 capitalize">
                {key.replace(/_/g, " ")}
              </span>

              <div className={isComplex ? "mt-2 pl-4 border-l-2 border-indigo-200 w-full" : "text-sm text-gray-800 break-words flex-1"}>
                {renderDados(itemValue, depth + 1)}
              </div>
            </div>
          );
        })}
      </div>
    );
  }

  return <span className="text-gray-800">{String(value)}</span>;
};

const getPreviewTags = (dados: unknown): string[] => {
  if (isRecord(dados)) {
    return Object.entries(dados)
      .slice(0, 3)
      .map(([key, value]) => {
        const raw = typeof value === "object" ? "..." : String(value);
        const cleanKey = key.replace(/_/g, " ");
        return `${cleanKey}: ${raw.slice(0, 24)}`;
      });
  }

  if (Array.isArray(dados)) {
    return [`Lista com ${dados.length} item(ns)`];
  }

  return [`Valor: ${String(dados).slice(0, 24)}`];
};

const getDadosFieldCount = (dados: unknown): number => {
  if (isRecord(dados)) return Object.keys(dados).length;
  if (Array.isArray(dados)) return dados.length;
  return 1;
};

const ParceirosUY3Page = () => {
  const initialFilters = useMemo(() => loadPersistedFilters(), []);
  const [fromInput, setFromInput] = useState<string>(initialFilters.from);
  const [toInput, setToInput] = useState<string>(initialFilters.to);
  const [periodPresetInput, setPeriodPresetInput] = useState<Uy3PeriodPreset>(initialFilters.periodPreset);
  const [appliedRange, setAppliedRange] = useState<{ from: string; to: string }>(() => ({
    from: initialFilters.from,
    to: initialFilters.to,
  }));
  const [sortDirectionInput, setSortDirectionInput] = useState<SortDirection>(initialFilters.sortDirection);
  const [appliedSortDirection, setAppliedSortDirection] = useState<SortDirection>(initialFilters.sortDirection);
  const [windowModeInput, setWindowModeInput] = useState<Uy3WindowMode>(initialFilters.windowMode);
  const [appliedWindowMode, setAppliedWindowMode] = useState<Uy3WindowMode>(initialFilters.windowMode);
  const [appliedPeriodPreset, setAppliedPeriodPreset] = useState<Uy3PeriodPreset>(initialFilters.periodPreset);
  const [page, setPage] = useState(1);
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());
  const [isExporting, setIsExporting] = useState(false);
  const [rangeError, setRangeError] = useState<string | null>(null);
  const [nowTs, setNowTs] = useState(() => Date.now());

  const effectiveRange = useMemo(() => {
    if (appliedPeriodPreset !== "custom") {
      if (appliedWindowMode === "rolling") {
        return presetRangeToNow(appliedPeriodPreset, new Date(nowTs));
      }

      return appliedRange;
    }

    if (appliedWindowMode === "rolling") {
      return rollRangeToNow(appliedRange.from, appliedRange.to, new Date(nowTs));
    }

    return appliedRange;
  }, [appliedPeriodPreset, appliedRange, appliedWindowMode, nowTs]);

  const appliedFromIso = useMemo(() => toUtcIsoFromDateTimeLocal(effectiveRange.from), [effectiveRange.from]);
  const appliedToIso = useMemo(() => toUtcIsoFromDateTimeLocal(effectiveRange.to), [effectiveRange.to]);

  useEffect(() => {
    persistFilters({
      from: appliedRange.from,
      to: appliedRange.to,
      sortDirection: appliedSortDirection,
      windowMode: appliedWindowMode,
      periodPreset: appliedPeriodPreset,
    });
  }, [appliedRange.from, appliedRange.to, appliedSortDirection, appliedWindowMode, appliedPeriodPreset]);

  useEffect(() => {
    const timer = window.setInterval(() => setNowTs(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  const { data, isLoading, isError, isFetching, refetch } = useQuery({
    queryKey: ["uy3:posts", page, appliedFromIso, appliedToIso, appliedSortDirection],
    queryFn: ({ signal }) =>
      listUy3Posts(
        {
          page,
          perPage: 20,
          from: appliedFromIso || undefined,
          to: appliedToIso || undefined,
          sort: "received_at",
          direction: appliedSortDirection,
        },
        signal
      ),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
    gcTime: 120_000,
    retry: 1,
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
  });

  const posts = useMemo(() => data?.data ?? [], [data]);
  const currentPage = data?.current_page ?? page;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;

  const toggleExpand = (id: string) => {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const resetPage = () => {
    setPage(1);
    setExpandedIds(new Set());
  };

  const applyFilters = () => {
    if (periodPresetInput !== "custom") {
      const nextRange = presetRangeToNow(periodPresetInput);
      setRangeError(null);
      setFromInput(nextRange.from);
      setToInput(nextRange.to);
      setAppliedRange(nextRange);
      setAppliedSortDirection(sortDirectionInput);
      setAppliedWindowMode(windowModeInput);
      setAppliedPeriodPreset(periodPresetInput);
      resetPage();
      return;
    }

    const fromDate = new Date(fromInput);
    const toDate = new Date(toInput);

    if (!fromInput || !toInput || Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime())) {
      setRangeError("Preencha um intervalo válido com data e hora.");
      return;
    }

    if (fromDate.getTime() > toDate.getTime()) {
      setRangeError("A data/hora inicial não pode ser maior que a final.");
      return;
    }

    setRangeError(null);

    let nextFrom = fromInput;
    let nextTo = toInput;
    if (windowModeInput === "rolling") {
      const rolled = rollRangeToNow(fromInput, toInput);
      nextFrom = rolled.from;
      nextTo = rolled.to;
      setFromInput(nextFrom);
      setToInput(nextTo);
    }

    setAppliedRange({ from: nextFrom, to: nextTo });
    setAppliedSortDirection(sortDirectionInput);
    setAppliedWindowMode(windowModeInput);
    setAppliedPeriodPreset("custom");
    resetPage();
  };

  const applyPeriodPreset = (preset: Uy3PeriodPreset) => {
    setPeriodPresetInput(preset);

    if (preset === "custom") {
      setRangeError(null);
      return;
    }

    const nextRange = presetRangeToNow(preset);
    setFromInput(nextRange.from);
    setToInput(nextRange.to);
    setRangeError(null);
  };

  const handleExportCsv = async () => {
    if (isExporting) return;

    setIsExporting(true);
    const toastId = toast.loading("Gerando CSV da UY3...", { duration: Infinity });

    try {
      const { token } = await startUy3Export({
        from: appliedFromIso || undefined,
        to: appliedToIso || undefined,
        sort: "received_at",
        direction: appliedSortDirection,
      });

      const maxAttempts = 180;
      for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
        const status = await getUy3ExportStatus(token);

        if (status.status === "ready") {
          toast.success("CSV pronto. Baixando...", { id: toastId });
          await downloadUy3Export(token);
          toast.dismiss(toastId);
          return;
        }

        if (status.status === "error") {
          throw new Error(status.error || status.message || "Falha ao gerar export.");
        }

        if (status.status === "deleted") {
          throw new Error(status.message || "Export expirou antes do download.");
        }

        await sleep(getExportPollDelayMs(attempt));
      }

      throw new Error("O export demorou além do esperado. Tente novamente em instantes.");
    } catch (error: unknown) {
      const record = error as { response?: { data?: { message?: string } }; message?: string };
      const message =
        record.response?.data?.message ||
        record.message ||
        "Não foi possível exportar o CSV.";
      toast.error(message, { id: toastId });
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0 flex flex-col gap-6">
      {/* HEADER SECTION */}
      <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div>
          <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-1">Dados recebidos UY3</h1>
          <p className="text-gray-600 text-sm lg:text-base">
            Visualize e exporte os dados enviados pela UY3 via API.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            className="shrink-0 border-gray-200 px-4 hover:bg-gray-50"
            onClick={() => void handleExportCsv()}
            disabled={isExporting}
          >
            {isExporting ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <FileDown className="w-4 h-4 mr-2" />}
            Exportar CSV
          </Button>

          <Button className="shrink-0 bg-blue-600 px-4 text-white hover:bg-blue-700" onClick={() => void refetch()}>
            {isFetching ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <RefreshCw className="w-4 h-4 mr-2" />}
            Atualizar
          </Button>
        </div>
      </div>

      {/* FILTER SECTION - SEM CARD, COM DELIMITAÇÃO SUTIL E LABELS CLAROS */}
      <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col lg:flex-row lg:items-end gap-4">
          <div className="w-full lg:w-auto flex-1 space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <Calendar className="w-4 h-4 text-gray-400" />
              Período
            </label>
            <Select
              value={periodPresetInput}
              onValueChange={(value) => applyPeriodPreset(value as Uy3PeriodPreset)}
            >
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Selecione..." />
              </SelectTrigger>
              <SelectContent>
                {periodPresetOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          
          <div className="w-full lg:w-auto flex-1 space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <Calendar className="w-4 h-4 text-gray-400" />
              Data inicial
            </label>
            <Input
              type="datetime-local"
              value={fromInput}
              onChange={(e) => {
                setPeriodPresetInput("custom");
                setFromInput(e.target.value);
              }}
              className="w-full"
            />
          </div>

          <div className="w-full lg:w-auto flex-1 space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <Calendar className="w-4 h-4 text-gray-400" />
              Data final
            </label>
            <Input
              type="datetime-local"
              value={toInput}
              onChange={(e) => {
                setPeriodPresetInput("custom");
                setToInput(e.target.value);
              }}
              className="w-full"
            />
          </div>

          <div className="w-full lg:w-auto flex-1 space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <Clock className="w-4 h-4 text-gray-400" />
              Modo de intervalo
            </label>
            <Select
              value={windowModeInput}
              onValueChange={(value) => setWindowModeInput(value as Uy3WindowMode)}
            >
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Selecione..." />
              </SelectTrigger>
              <SelectContent>
                {windowModeOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="w-full lg:w-auto flex-1 space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <ArrowUpDown className="w-4 h-4 text-gray-400" />
              Ordenação
            </label>
            <Select
              value={sortDirectionInput}
              onValueChange={(value) => setSortDirectionInput(value as SortDirection)}
            >
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Selecione..." />
              </SelectTrigger>
              <SelectContent>
                {sortOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="w-full lg:w-auto pt-2 lg:pt-0">
            <Button 
              type="button" 
              onClick={applyFilters} 
              className="w-full lg:w-auto bg-blue-600 text-white hover:bg-blue-700"
            >
              <Filter className="w-4 h-4 mr-2" />
              Aplicar filtros
            </Button>
          </div>
        </div>

        {/* FEEDBACK DOS FILTROS (Dicas e Erros agrupados) */}
        <div className="mt-4 flex flex-col gap-2">
          {rangeError && (
            <p className="text-sm text-red-600 flex items-center gap-1.5">
              <AlertCircle className="w-4 h-4" />
              {rangeError}
            </p>
          )}

          {windowModeInput === "rolling" && (
            <p className="text-sm text-gray-500 flex items-center gap-1.5">
              <Info className="w-4 h-4 text-blue-600" />
              Janela móvel ativa: ao aplicar, o intervalo é recalculado usando o horário atual como base.
            </p>
          )}

        </div>
      </div>

      {/* LISTING SECTION */}
      <div>
        <div className="mb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <h2 className="text-lg font-semibold text-foreground">Posts Recebidos</h2>
            <p className="text-muted-foreground text-sm">
              {posts.length} nesta página • {total} no total
            </p>
          </div>
          <div className="text-sm text-gray-500 flex items-center gap-2">
            {isFetching ? (
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
            ) : (
              <span className="w-2 h-2 rounded-full bg-emerald-500" />
            )}
            {isFetching ? "Atualizando lista..." : "Atualizado em tempo real"}
          </div>
        </div>

        {isLoading ? (
          <Card className="border border-gray-200 shadow-sm">
            <CardContent className="py-16 flex flex-col items-center text-gray-500">
              <Loader2 className="w-8 h-8 mb-4 animate-spin text-gray-400" />
              <p className="font-medium text-gray-600">Carregando dados...</p>
              <p className="text-sm text-gray-400 mt-1">Buscando os registros mais recentes.</p>
            </CardContent>
          </Card>
        ) : isError ? (
          <Card className="border border-red-100 bg-red-50/50 shadow-sm">
            <CardContent className="py-16 flex flex-col items-center text-gray-500">
              <AlertCircle className="w-10 h-10 mb-3 text-red-400" />
              <p className="font-medium text-red-800">Falha ao carregar os dados</p>
              <p className="text-sm text-red-600/80 mt-1">Tente atualizar a página ou verificar a conexão.</p>
            </CardContent>
          </Card>
        ) : posts.length === 0 ? (
          <Card className="border border-gray-200 bg-gray-50/50 shadow-sm">
            <CardContent className="py-16 flex flex-col items-center text-muted-foreground">
              <Inbox className="w-10 h-10 mb-3 opacity-40" />
              <p className="font-medium text-gray-700">Nenhum registro encontrado</p>
              <p className="text-sm mt-1">Ajuste os filtros de data para visualizar mais resultados.</p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3">
            {posts.map((post) => {
              const isExpanded = expandedIds.has(post.id);
              const previewTags = getPreviewTags(post.dados);
              const fieldCount = getDadosFieldCount(post.dados);

              return (
                <Card key={post.id} className="border border-gray-200 shadow-sm transition-shadow hover:shadow-md">
                  <CardContent className="py-4">
                    <button
                      onClick={() => toggleExpand(post.id)}
                      className="w-full flex items-start md:items-center justify-between gap-3 text-left"
                    >
                      <div className="flex-1 min-w-0 space-y-1.5">
                        <div className="flex items-center gap-2 flex-wrap">
                          <Badge variant="outline" className="font-mono text-xs text-gray-600 bg-gray-50">
                            {post.id}
                          </Badge>
                          <Badge className="border-blue-200 bg-blue-50 text-xs text-blue-700 hover:bg-blue-100">
                            {fieldCount} campo{fieldCount !== 1 ? "s" : ""}
                          </Badge>
                        </div>

                        <div className="text-xs font-medium text-gray-500 flex items-center gap-1.5">
                          <Calendar className="w-3.5 h-3.5" />
                          {formatDateTime(post.received_at)}
                        </div>

                        {!isExpanded && (
                          <div className="flex gap-2 flex-wrap mt-2">
                            {previewTags.map((tag) => (
                              <span key={tag} className="rounded-md border border-blue-200 bg-blue-50 px-2 py-1 text-[11px] font-medium capitalize text-blue-800">
                                {tag}
                              </span>
                            ))}
                          </div>
                        )}
                      </div>

                      <div className="shrink-0 rounded-full border border-gray-100 bg-gray-50 p-2 transition-colors hover:bg-gray-100">
                        {isExpanded ? (
                          <ChevronUp className="w-5 h-5 text-gray-600" />
                        ) : (
                          <ChevronDown className="w-5 h-5 text-gray-600" />
                        )}
                      </div>
                    </button>

                    {isExpanded && (
                      <div className="mt-4 pt-4 border-t border-gray-100 animate-in fade-in slide-in-from-top-2 duration-200">
                        <div className="rounded-lg border border-gray-100 bg-gray-50/60 p-5 shadow-inner">
                          {renderDados(post.dados)}
                        </div>
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}

        {/* PAGINAÇÃO */}
        {lastPage > 1 && (
          <div className="mt-6 flex items-center justify-end gap-3 border-t border-gray-100 bg-white p-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setPage((current) => Math.max(1, current - 1))}
              disabled={currentPage <= 1 || isFetching}
            >
              Anterior
            </Button>
            <span className="text-sm font-medium text-gray-600 min-w-[100px] text-center">
              Pág. {currentPage} de {lastPage}
            </span>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
              disabled={currentPage >= lastPage || isFetching}
            >
              Próxima
            </Button>
          </div>
        )}
      </div>
    </div>
  );
};

export default ParceirosUY3Page;
