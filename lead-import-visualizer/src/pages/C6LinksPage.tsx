import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertCircle, CheckCircle2, Copy, Link2, Loader2, Plus, Search } from "lucide-react";
import { toast } from "sonner";

import { listC6AuthorizationLinks } from "@/api/c6";
import { NewLinkModal } from "@/components/NewLinkModal";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { usePersistedState } from "@/hooks/usePersistedState";
import { formatCPF } from "@/lib/formatters";

interface GeneratedLink {
  id: string;
  cpf: string;
  nome?: string;
  link: string;
  geradoEm: string;
  expiraEm: string;
  status: "ativo" | "expirado";
}

type C6FilterStatus = "todos" | "ativo" | "expirado";

const toPtBrDateTime = (iso: string | null) => {
  if (!iso) return "--";

  const date = new Date(iso);
  return date.toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const useDebouncedValue = <T,>(value: T, delayMs: number): T => {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebounced(value);
    }, delayMs);

    return () => {
      window.clearTimeout(timer);
    };
  }, [value, delayMs]);

  return debounced;
};

const C6LinksPage = () => {
  const queryClient = useQueryClient();
  const focusClass = "focus-visible:ring-0 focus-visible:ring-offset-0 focus-visible:border-green-700";
  const selectFocusClass = "focus:ring-0 focus:ring-offset-0 focus:border-green-700";

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [filterCpf, setFilterCpf] = usePersistedState<string>("c6-links:filter-cpf", "");
  const [filterNome, setFilterNome] = usePersistedState<string>("c6-links:filter-nome", "");
  const [filterStatus, setFilterStatus] = usePersistedState<C6FilterStatus>("c6-links:filter-status", "todos");
  const [page, setPage] = useState(1);
  const [copiedLinkId, setCopiedLinkId] = useState<string | null>(null);
  const [justUpdated, setJustUpdated] = useState(false);

  const normalizedCpfFilter = useMemo(() => filterCpf.replace(/\D/g, "").trim(), [filterCpf]);
  const normalizedNameFilter = useMemo(() => filterNome.trim(), [filterNome]);

  const debouncedCpfFilter = useDebouncedValue(normalizedCpfFilter, 550);
  const debouncedNameFilter = useDebouncedValue(normalizedNameFilter, 550);

  useEffect(() => {
    if (filterStatus !== "todos" && filterStatus !== "ativo" && filterStatus !== "expirado") {
      setFilterStatus("todos");
    }
  }, [filterStatus, setFilterStatus]);

  const { data, isLoading, isError, isFetching, dataUpdatedAt } = useQuery({
    queryKey: ["c6:links", page, debouncedCpfFilter, debouncedNameFilter, filterStatus],
    queryFn: ({ signal }) =>
      listC6AuthorizationLinks({
        page,
        perPage: 50,
        cpf: debouncedCpfFilter || undefined,
        nome: debouncedNameFilter || undefined,
        status: filterStatus === "todos" ? "" : filterStatus,
      }, signal),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    gcTime: 120_000,
    retry: 1,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  });

  useEffect(() => {
    if (!dataUpdatedAt) return;

    setJustUpdated(true);
    const timer = window.setTimeout(() => {
      setJustUpdated(false);
    }, 700);

    return () => {
      window.clearTimeout(timer);
    };
  }, [dataUpdatedAt]);

  const links = useMemo<GeneratedLink[]>(() => {
    const items = data?.data ?? [];
    return items.map((item) => ({
      id: String(item.id),
      cpf: formatCPF(item.cpf),
      nome: item.nome_cliente ?? undefined,
      link: item.link,
      geradoEm: toPtBrDateTime(item.generated_at),
      expiraEm: toPtBrDateTime(item.data_expiracao),
      status: item.status,
    }));
  }, [data]);

  const currentPage = data?.current_page ?? page;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;

  const handleLinkGenerated = async (payload: {
    id?: string;
    reused?: boolean;
    cpf: string;
    nome?: string;
    link: string;
    geradoEm: string;
    expiraEm: string;
  }) => {
    if (!payload.reused) {
      setPage(1);
    }

    await queryClient.invalidateQueries({ queryKey: ["c6:links"] });
  };

  const handleCopy = async (link: GeneratedLink) => {
    try {
      await navigator.clipboard.writeText(link.link);
      setCopiedLinkId(link.id);
      toast.success("Link copiado!");
      window.setTimeout(() => {
        setCopiedLinkId((current) => (current === link.id ? null : current));
      }, 1800);
    } catch {
      toast.error("Não foi possível copiar o link.");
    }
  };

  return (
    <>
      <div className="p-4 lg:p-6 max-w-full min-w-0">
        <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">Geração de Links C6</h1>
            <p className="text-gray-600 text-sm lg:text-base">
              Gere links de autorização de consulta de dados no banco C6. Links expiram em 48 horas.
            </p>
          </div>

          <Button
            onClick={() => setIsModalOpen(true)}
            className="bg-green-700 hover:bg-green-800 text-white shrink-0"
          >
            <Plus className="w-4 h-4" />
            Gerar Novo Link
          </Button>
        </div>

        <div className="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              placeholder="Filtrar por CPF..."
              value={filterCpf}
              onChange={(e) => {
                setFilterCpf(e.target.value);
                setPage(1);
              }}
              className={`pl-9 ${focusClass}`}
            />
          </div>

          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              placeholder="Filtrar por nome..."
              value={filterNome}
              onChange={(e) => {
                setFilterNome(e.target.value);
                setPage(1);
              }}
              className={`pl-9 ${focusClass}`}
            />
          </div>

          <Select
            value={filterStatus}
            onValueChange={(value) => {
              setFilterStatus(value as C6FilterStatus);
              setPage(1);
            }}
          >
            <SelectTrigger className={selectFocusClass}>
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="todos">Todos</SelectItem>
              <SelectItem value="ativo">Ativo</SelectItem>
              <SelectItem value="expirado">Expirado</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold text-foreground">Histórico de Links</h2>
            <p className="text-muted-foreground text-sm">
              {links.length} nesta página • {total} no total
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
              <Link2 className="w-10 h-10 mb-3 text-gray-300" />
              <p className="font-medium">Carregando links...</p>
            </CardContent>
          </Card>
        ) : isError ? (
          <Card>
            <CardContent className="py-12 flex flex-col items-center text-gray-500">
              <AlertCircle className="w-10 h-10 mb-3 text-red-400" />
              <p className="font-medium">Falha ao carregar links</p>
              <p className="text-sm">Atualize a página para tentar novamente.</p>
            </CardContent>
          </Card>
        ) : links.length === 0 ? (
          <Card>
            <CardContent className="py-12 flex flex-col items-center text-gray-500">
              <Link2 className="w-10 h-10 mb-3 text-gray-300" />
              <p className="font-medium">Nenhum link encontrado</p>
              <p className="text-sm">Ajuste os filtros ou gere um novo link.</p>
            </CardContent>
          </Card>
        ) : (
          <div
            className={`space-y-3 transition-all duration-500 ${justUpdated ? "bg-emerald-50/35 rounded-lg p-2" : ""}`}
          >
            {links.map((link) => {
              const expired = link.status === "expirado";

              return (
                <Card key={link.id} className={expired ? "opacity-60" : ""}>
                  <CardContent className="py-4 flex flex-col md:flex-row md:items-center gap-3 md:gap-6">
                    <div className="flex-1 min-w-0 space-y-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono text-sm font-medium text-gray-900">{link.cpf}</span>
                        {expired ? (
                          <Badge variant="destructive" className="text-xs">
                            <AlertCircle className="w-3 h-3 mr-1" />
                            Expirado
                          </Badge>
                        ) : (
                          <Badge className="bg-green-100 text-green-800 text-xs hover:bg-green-100">
                            <CheckCircle2 className="w-3 h-3 mr-1" />
                            Ativo
                          </Badge>
                        )}
                      </div>

                      {link.nome ? (
                        <p className="text-sm font-semibold text-gray-800 truncate">{link.nome}</p>
                      ) : (
                        <p className="text-xs text-gray-400">Nome não informado</p>
                      )}

                      <p className="text-sm text-blue-600 truncate font-mono">{link.link}</p>
                      <div className="flex gap-4 text-xs text-gray-400">
                        <span>Gerado: {link.geradoEm}</span>
                        <span>Expira: {link.expiraEm}</span>
                      </div>
                    </div>

                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleCopy(link)}
                      disabled={expired}
                      className="shrink-0"
                    >
                      <Copy className="w-4 h-4" />
                      {copiedLinkId === link.id ? "Copiado!" : "Copiar"}
                    </Button>
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

      <NewLinkModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        onLinkGenerated={handleLinkGenerated}
      />
    </>
  );
};

export default C6LinksPage;
