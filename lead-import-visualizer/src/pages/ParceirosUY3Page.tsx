import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { format, parseISO, subHours } from "date-fns";
import { ptBR } from "date-fns/locale";
import {
  AlertCircle,
  Calendar,
  ChevronDown,
  ChevronUp,
  FileDown,
  Inbox,
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
type Uy3PersistedFilters = {
  from: string;
  to: string;
  sortDirection: SortDirection;
};

const UY3_FILTERS_STORAGE_KEY = "uy3:filters:v1";

const sortOptions: Array<{ value: SortDirection; label: string }> = [
  { value: "desc", label: "Mais recentes" },
  { value: "asc", label: "Mais antigos" },
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
    const sortDirection = parsed.sortDirection === "asc" || parsed.sortDirection === "desc"
      ? parsed.sortDirection
      : fallback.sortDirection;

    return { from, to, sortDirection };
  } catch {
    return fallback;
  }
};

const persistFilters = (filters: Uy3PersistedFilters): void => {
  if (typeof window === "undefined") return;

  try {
    window.localStorage.setItem(UY3_FILTERS_STORAGE_KEY, JSON.stringify(filters));
  } catch {
  }
};

const toDateTimeLocalValue = (date: Date): string => {
  const pad = (value: number): string => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const toUtcIsoFromDateTimeLocal = (value: string): string | null => {
  if (!value) return null;
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return null;
  return parsed.toISOString();
};

// --- RENDERIZAÇÃO DE DADOS ATUALIZADA (SEM JUSTIFY-BETWEEN) ---
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
          const isComplex = typeof itemValue === 'object' && itemValue !== null;
          
          return (
            <div 
              key={key} 
              // A MUDANÇA PRINCIPAL ESTÁ AQUI: Alinhamento à esquerda com flex-row no desktop e flex-col no mobile
              className={`flex ${isComplex ? 'flex-col' : 'flex-col sm:flex-row sm:items-center gap-1 sm:gap-4'} 
              py-2 ${index !== entries.length - 1 ? 'border-b border-gray-100' : ''}`}
            >
              {/* Largura mínima fixada para a chave, empurrando o valor de forma alinhada */}
              <span className="text-sm font-semibold text-gray-500 min-w-[160px] md:min-w-[200px] shrink-0 capitalize">
                {key.replace(/_/g, ' ')}
              </span>
              
              {/* Texto alinhado à esquerda nativamente */}
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
        const cleanKey = key.replace(/_/g, ' ');
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
  const [appliedRange, setAppliedRange] = useState<{ from: string; to: string }>(() => ({
    from: initialFilters.from,
    to: initialFilters.to,
  }));
  const [sortDirectionInput, setSortDirectionInput] = useState<SortDirection>(initialFilters.sortDirection);
  const [appliedSortDirection, setAppliedSortDirection] = useState<SortDirection>(initialFilters.sortDirection);
  const [page, setPage] = useState(1);
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());
  const [isExporting, setIsExporting] = useState(false);
  const [rangeError, setRangeError] = useState<string | null>(null);

  const appliedFromIso = useMemo(
    () => toUtcIsoFromDateTimeLocal(appliedRange.from),
    [appliedRange.from]
  );
  const appliedToIso = useMemo(
    () => toUtcIsoFromDateTimeLocal(appliedRange.to),
    [appliedRange.to]
  );

  useEffect(() => {
    persistFilters({
      from: appliedRange.from,
      to: appliedRange.to,
      sortDirection: appliedSortDirection,
    });
  }, [appliedRange.from, appliedRange.to, appliedSortDirection]);

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
    setAppliedRange({ from: fromInput, to: toInput });
    setAppliedSortDirection(sortDirectionInput);
    resetPage();
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
    } catch (error: any) {
      const message =
        error?.response?.data?.message ||
        error?.message ||
        "Não foi possível exportar o CSV.";
      toast.error(message, { id: toastId });
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">Dados recebidos UY3</h1>
          <p className="text-gray-600 text-sm lg:text-base">
            Visualize os dados enviados pela UY3 via API.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            className="shrink-0"
            onClick={() => void handleExportCsv()}
            disabled={isExporting}
          >
            {isExporting ? <Loader2 className="w-4 h-4 animate-spin" /> : <FileDown className="w-4 h-4" />}
            Exportar CSV
          </Button>

          <Button variant="outline" className="shrink-0" onClick={() => void refetch()}>
            {isFetching ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
            Atualizar
          </Button>
        </div>
      </div>

      <div className="mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
        <Input
          type="datetime-local"
          value={fromInput}
          onChange={(e) => setFromInput(e.target.value)}
          aria-label="Data e hora inicial"
        />

        <Input
          type="datetime-local"
          value={toInput}
          onChange={(e) => setToInput(e.target.value)}
          aria-label="Data e hora final"
        />

        <Select
          value={sortDirectionInput}
          onValueChange={(value) => {
            setSortDirectionInput(value as SortDirection);
          }}
        >
          <SelectTrigger>
            <SelectValue placeholder="Ordenação" />
          </SelectTrigger>
          <SelectContent>
            {sortOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Button type="button" variant="outline" onClick={applyFilters}>
          Aplicar filtros
        </Button>
      </div>

      {rangeError ? (
        <p className="mb-3 text-xs text-red-600">{rangeError}</p>
      ) : null}

      <p className="mb-3 text-xs text-muted-foreground">
        O CSV exporta somente registros com <span className="font-semibold">typeWebook = LEADS_CLT</span>.
      </p>

      <div className="mb-4 flex items-center justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold text-foreground">Posts Recebidos</h2>
          <p className="text-muted-foreground text-sm">
            {posts.length} nesta página • {total} no total
          </p>
        </div>
        <div className="text-sm text-gray-500 flex items-center gap-2">
          {isFetching ? <Loader2 className="w-4 h-4 animate-spin" /> : <span className="w-2 h-2 rounded-full bg-emerald-500" />}
          {isFetching ? "Atualizando..." : "Atualizado"}
        </div>
      </div>

      {isLoading ? (
        <Card>
          <CardContent className="py-12 flex flex-col items-center text-gray-500">
            <Loader2 className="w-10 h-10 mb-3 animate-spin text-gray-300" />
            <p className="font-medium">Carregando dados...</p>
          </CardContent>
        </Card>
      ) : isError ? (
        <Card>
          <CardContent className="py-12 flex flex-col items-center text-gray-500">
            <AlertCircle className="w-10 h-10 mb-3 text-red-400" />
            <p className="font-medium">Falha ao carregar os dados</p>
            <p className="text-sm">Atualize a página para tentar novamente.</p>
          </CardContent>
        </Card>
      ) : posts.length === 0 ? (
        <Card>
          <CardContent className="py-12 flex flex-col items-center text-muted-foreground">
            <Inbox className="w-10 h-10 mb-3 opacity-40" />
            <p className="font-medium">Nenhum registro encontrado</p>
            <p className="text-sm">Ajuste os filtros para visualizar os dados.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {posts.map((post) => {
            const isExpanded = expandedIds.has(post.id);
            const previewTags = getPreviewTags(post.dados);
            const fieldCount = getDadosFieldCount(post.dados);

            return (
              <Card key={post.id} className="transition-shadow hover:shadow-md">
                <CardContent className="py-4">
                  <button
                    onClick={() => toggleExpand(post.id)}
                    className="w-full flex items-start md:items-center justify-between gap-3 text-left"
                  >
                    <div className="flex-1 min-w-0 space-y-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <Badge variant="outline" className="font-mono text-xs">
                          {post.id}
                        </Badge>
                        <Badge className="bg-blue-50 text-blue-700 hover:bg-blue-100 border-blue-200 text-xs">
                          {fieldCount} campo{fieldCount !== 1 ? "s" : ""}
                        </Badge>
                      </div>

                      <div className="text-xs text-muted-foreground flex items-center gap-1">
                        <Calendar className="w-3 h-3" />
                        {formatDateTime(post.received_at)}
                      </div>

                      {!isExpanded && (
                        <div className="flex gap-2 flex-wrap mt-2">
                          {previewTags.map((tag) => (
                            <span key={tag} className="text-xs text-gray-600 bg-gray-100 px-2 py-1 rounded-md capitalize">
                              {tag}
                            </span>
                          ))}
                        </div>
                      )}
                    </div>

                    <div className="shrink-0 p-2 rounded-full hover:bg-gray-100 transition-colors">
                      {isExpanded ? <ChevronUp className="w-5 h-5 text-gray-500" /> : <ChevronDown className="w-5 h-5 text-gray-500" />}
                    </div>
                  </button>

                  {isExpanded && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                      <div className="bg-gray-50/80 rounded-lg p-5 border border-gray-100">
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

      {lastPage > 1 ? (
        <div className="mt-5 flex items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={currentPage <= 1 || isFetching}
          >
            Anterior
          </Button>
          <span className="text-sm text-gray-600">
            Página {currentPage} de {lastPage}
          </span>
          <Button
            type="button"
            variant="outline"
            onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
            disabled={currentPage >= lastPage || isFetching}
          >
            Próxima
          </Button>
        </div>
      ) : null}
    </div>
  );
};

export default ParceirosUY3Page;
