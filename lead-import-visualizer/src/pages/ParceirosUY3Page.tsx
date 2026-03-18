import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { format, parseISO } from "date-fns";
import { ptBR } from "date-fns/locale";
import {
  AlertCircle,
  Calendar,
  ChevronDown,
  ChevronUp,
  Inbox,
  Loader2,
  RefreshCw,
  Search,
} from "lucide-react";

import { listUy3Posts } from "@/api/uy3";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

type TimeFilter = "all" | "24h" | "7d" | "30d" | "90d";
type SortDirection = "desc" | "asc";

const timeFilters: Array<{ value: TimeFilter; label: string }> = [
  { value: "24h", label: "Últimas 24h" },
  { value: "7d", label: "Últimos 7 dias" },
  { value: "30d", label: "Últimos 30 dias" },
  { value: "90d", label: "Últimos 90 dias" },
  { value: "all", label: "Todo o período" },
];

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

const useDebouncedValue = <T,>(value: T, delayMs: number): T => {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delayMs);
    return () => window.clearTimeout(timer);
  }, [value, delayMs]);

  return debounced;
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
  const [searchTerm, setSearchTerm] = useState("");
  const [timeFilter, setTimeFilter] = useState<TimeFilter>("30d");
  const [sortDirection, setSortDirection] = useState<SortDirection>("desc");
  const [page, setPage] = useState(1);
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());

  const debouncedSearch = useDebouncedValue(searchTerm.trim(), 450);
  const serverSearch = debouncedSearch.length >= 3 ? debouncedSearch : "";

  const { data, isLoading, isError, isFetching, refetch } = useQuery({
    queryKey: ["uy3:posts", page, serverSearch, timeFilter, sortDirection],
    queryFn: ({ signal }) =>
      listUy3Posts(
        {
          page,
          perPage: 20,
          q: serverSearch || undefined,
          period: timeFilter,
          sort: "received_at",
          direction: sortDirection,
        },
        signal
      ),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
    gcTime: 120_000,
    retry: 1,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: true,
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

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">Dados recebidos UY3</h1>
          <p className="text-gray-600 text-sm lg:text-base">
            Visualize os dados enviados pela UY3 via API.
          </p>
        </div>

        <Button variant="outline" className="shrink-0" onClick={() => void refetch()}>
          {isFetching ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
          Atualizar
        </Button>
      </div>

      <div className="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input
            placeholder="Buscar nos dados..."
            value={searchTerm}
            onChange={(e) => {
              setSearchTerm(e.target.value);
              resetPage();
            }}
            className="pl-9"
          />
        </div>

        <Select
          value={timeFilter}
          onValueChange={(value) => {
            setTimeFilter(value as TimeFilter);
            resetPage();
          }}
        >
          <SelectTrigger>
            <div className="flex items-center gap-2">
              <Calendar className="w-4 h-4 text-muted-foreground" />
              <SelectValue placeholder="Período" />
            </div>
          </SelectTrigger>
          <SelectContent>
            {timeFilters.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          value={sortDirection}
          onValueChange={(value) => {
            setSortDirection(value as SortDirection);
            resetPage();
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
      </div>

      {searchTerm.trim() !== "" && searchTerm.trim().length < 3 ? (
        <p className="mb-3 text-xs text-muted-foreground">Digite pelo menos 3 caracteres para aplicar a busca textual.</p>
      ) : null}

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