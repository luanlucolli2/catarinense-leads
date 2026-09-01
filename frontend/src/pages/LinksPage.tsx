import { useEffect, useState } from "react";
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  BarChart3,
  CheckCircle,
  Copy,
  CopyPlus,
  ExternalLink,
  Link2,
  Loader2,
  MoreHorizontal,
  Pause,
  Pencil,
  PlayCircle,
  Plus,
  Search,
  Trash2,
  X,
  XCircle,
} from "lucide-react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import {
  apiErrorMessage,
  shortLinksApi,
  type ShortLink,
  type ShortLinkStatus,
} from "@/api/shortLinks";
import {
  ShortLinkDialog,
  type ShortLinkDialogValue,
} from "@/components/ShortLinkDialog";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

const strategyLabel: Record<string, string> = {
  sequential: "Sequencial",
  random: "Aleatória",
  weighted: "Ponderada",
  first: "Primeiro",
};

function statusBadge(status: ShortLinkStatus) {
  if (status === "active")
    return {
      icon: <CheckCircle className="h-4 w-4" />,
      label: "Ativo",
      className:
        "bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800",
    };
  if (status === "inactive")
    return {
      icon: <Pause className="h-4 w-4" />,
      label: "Desativado",
      className:
        "bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800",
    };
  return {
    icon: <XCircle className="h-4 w-4" />,
    label: "Excluído",
    className:
      "bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600",
  };
}

const LinksPage = () => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [status, setStatus] = useState<ShortLinkStatus | "">("");
  const [mode, setMode] = useState("");
  const [kind, setKind] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<ShortLink>();
  const [duplicating, setDuplicating] = useState<ShortLink>();
  const [selected, setSelected] = useState<ShortLink>();
  const [action, setAction] = useState<{
    link: ShortLink;
    kind: "disable" | "enable" | "delete";
  }>();
  const [loadingId, setLoadingId] = useState("");

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(search.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [search]);
  const links = useQuery({
    queryKey: ["short-links", page, debouncedSearch, status, mode, kind],
    queryFn: () =>
      shortLinksApi.list({
        page,
        per_page: 20,
        search: debouncedSearch,
        status: status || "all",
        mode,
        destination_kind: kind,
      }),
    placeholderData: keepPreviousData,
  });
  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ["short-links"] });
  const create = useMutation({
    mutationFn: shortLinksApi.create,
    onSuccess: async () => {
      setCreating(false);
      setDuplicating(undefined);
      await refresh();
      toast.success("Link criado.");
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });
  const update = useMutation({
    mutationFn: ({ id, input }: { id: string; input: ShortLinkDialogValue }) =>
      shortLinksApi.update(id, input),
    onSuccess: async () => {
      setEditing(undefined);
      await refresh();
      toast.success("Link atualizado.");
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });
  const changeStatus = useMutation({
    mutationFn: ({
      id,
      kind: actionKind,
    }: {
      id: string;
      kind: "disable" | "enable" | "delete";
    }) =>
      actionKind === "delete"
        ? shortLinksApi.remove(id)
        : actionKind === "disable"
          ? shortLinksApi.disable(id)
          : shortLinksApi.enable(id),
    onSuccess: async () => {
      setAction(undefined);
      await refresh();
      toast.success("Link atualizado.");
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const loadLink = async (
    link: ShortLink,
    target: "edit" | "duplicate" | "details",
  ) => {
    setLoadingId(link.id);
    try {
      const result = await shortLinksApi.get(link.id);
      if (target === "edit") setEditing(result);
      else if (target === "duplicate") setDuplicating(result);
      else setSelected(result);
    } catch (error) {
      toast.error(apiErrorMessage(error));
    } finally {
      setLoadingId("");
    }
  };
  const copy = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      toast.success("Link copiado.");
    } catch {
      toast.error("Não foi possível copiar o link.");
    }
  };
  const clearFilters = () => {
    setSearch("");
    setStatus("");
    setMode("");
    setKind("");
    setPage(1);
  };
  const hasFilters = Boolean(search || status || mode || kind);

  return (
    <div className="p-4 lg:p-6">
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900 lg:text-2xl">Links</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Crie destinos únicos, de WhatsApp ou rotativos e acompanhe os
            acessos.
          </p>
        </div>
        <Button
          className="bg-blue-600 text-white shadow-sm transition-colors hover:bg-blue-700"
          onClick={() => setCreating(true)}
        >
          <Plus className="h-4 w-4" />
          Criar link
        </Button>
      </div>
      <div className="mb-4 grid gap-3 md:grid-cols-4">
        <div className="relative">
          <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
          <Input
            className="pl-9"
            value={search}
            maxLength={200}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Rótulo, slug ou destino"
          />
          {search && (
            <button
              className="absolute right-2 top-2.5"
              onClick={() => setSearch("")}
              aria-label="Limpar busca"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={status}
          onChange={(event) => {
            setStatus(event.target.value as ShortLinkStatus | "");
            setPage(1);
          }}
        >
          <option value="">Todos os estados</option>
          <option value="active">Ativos</option>
          <option value="inactive">Desativados</option>
          <option value="deleted">Excluídos</option>
        </select>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={mode}
          onChange={(event) => {
            setMode(event.target.value);
            setPage(1);
          }}
        >
          <option value="">Todos os modos</option>
          <option value="single">Único</option>
          <option value="rotating">Rotativo</option>
        </select>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={kind}
          onChange={(event) => {
            setKind(event.target.value);
            setPage(1);
          }}
        >
          <option value="">Todos os destinos</option>
          <option value="url">URL</option>
          <option value="whatsapp">WhatsApp</option>
        </select>
      </div>
      {hasFilters && (
        <Button
          className="mb-4"
          variant="outline"
          size="sm"
          onClick={clearFilters}
        >
          Limpar filtros
        </Button>
      )}
      {links.isLoading ? (
        <Card>
          <CardContent className="flex min-h-48 items-center justify-center text-muted-foreground">
            <Loader2 className="mr-2 h-5 w-5 animate-spin" />
            Carregando links...
          </CardContent>
        </Card>
      ) : links.isError ? (
        <Card>
          <CardContent className="py-12 text-center">
            <p>Não foi possível carregar os links.</p>
            <Button
              className={cn(
                "mt-3",
                hasFilters
                  ? "border-gray-300 text-gray-700 hover:bg-gray-50"
                  : "bg-blue-600 text-white shadow-sm transition-colors hover:bg-blue-700",
              )}
              variant="outline"
              onClick={() => links.refetch()}
            >
              Tentar novamente
            </Button>
          </CardContent>
        </Card>
      ) : links.data?.items.length ? (
        <div className="grid gap-3">
          {links.data.items.map((link) => (
            <LinkCard
              key={link.id}
              link={link}
              loading={loadingId === link.id}
              onMetrics={() =>
                navigate(`/ferramentas/links/${link.id}/metrics`)
              }
              onCopy={() => copy(link.short_url)}
              onOpen={() =>
                window.open(link.short_url, "_blank", "noopener,noreferrer")
              }
              onEdit={() => loadLink(link, "edit")}
              onDuplicate={() => loadLink(link, "duplicate")}
              onDetails={() => loadLink(link, "details")}
              onDisable={() => setAction({ link, kind: "disable" })}
              onEnable={() => setAction({ link, kind: "enable" })}
              onDelete={() => setAction({ link, kind: "delete" })}
            />
          ))}
        </div>
      ) : (
        <Card>
          <CardContent className="py-12 text-center">
            <Link2 className="mx-auto mb-3 h-10 w-10 text-muted-foreground" />
            <p className="font-medium">
              {hasFilters ? "Nenhum link encontrado" : "Nenhum link criado"}
            </p>
            <Button
              className="mt-3"
              onClick={() => (hasFilters ? clearFilters() : setCreating(true))}
            >
              {hasFilters ? "Limpar filtros" : "Criar link"}
            </Button>
          </CardContent>
        </Card>
      )}
      {(links.data?.pagination.total_pages ?? 0) > 1 && (
        <div className="mt-5 flex items-center justify-center gap-3">
          <Button
            variant="outline"
            disabled={page <= 1 || links.isFetching}
            onClick={() => setPage((value) => value - 1)}
          >
            Anterior
          </Button>
          <span className="text-sm text-muted-foreground">
            Página {page} de {links.data?.pagination.total_pages}
          </span>
          <Button
            variant="outline"
            disabled={
              page >= (links.data?.pagination.total_pages ?? 1) ||
              links.isFetching
            }
            onClick={() => setPage((value) => value + 1)}
          >
            Próxima
          </Button>
        </div>
      )}
      <ShortLinkDialog
        open={creating}
        pending={create.isPending}
        error={create.isError ? apiErrorMessage(create.error) : undefined}
        onOpenChange={(open) => {
          setCreating(open);
          if (!open) create.reset();
        }}
        onSubmit={(value) => create.mutate(value)}
      />
      <ShortLinkDialog
        open={Boolean(duplicating)}
        link={duplicating}
        duplicate
        pending={create.isPending}
        error={create.isError ? apiErrorMessage(create.error) : undefined}
        onOpenChange={(open) => {
          if (!open) {
            setDuplicating(undefined);
            create.reset();
          }
        }}
        onSubmit={(value) => create.mutate(value)}
      />
      <ShortLinkDialog
        open={Boolean(editing)}
        link={editing}
        pending={update.isPending}
        error={update.isError ? apiErrorMessage(update.error) : undefined}
        onOpenChange={(open) => {
          if (!open) {
            setEditing(undefined);
            update.reset();
          }
        }}
        onSubmit={(value) =>
          editing && update.mutate({ id: editing.id, input: value })
        }
      />
      <AlertDialog
        open={Boolean(action)}
        onOpenChange={(open) => !open && setAction(undefined)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {action?.kind === "delete"
                ? "Excluir link permanentemente?"
                : action?.kind === "disable"
                  ? "Desativar link?"
                  : "Reativar link?"}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {action?.kind === "delete"
                ? "O link deixará de redirecionar e será marcado como excluído."
                : action?.kind === "disable"
                  ? "O redirecionamento será interrompido até a reativação."
                  : "O endereço curto voltará a redirecionar imediatamente."}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={changeStatus.isPending}>
              Cancelar
            </AlertDialogCancel>
            <AlertDialogAction
              disabled={changeStatus.isPending}
              onClick={(event) => {
                event.preventDefault();
                if (action)
                  changeStatus.mutate({
                    id: action.link.id,
                    kind: action.kind,
                  });
              }}
            >
              {changeStatus.isPending ? "Salvando..." : "Confirmar"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
      <AlertDialog
        open={Boolean(selected)}
        onOpenChange={(open) => !open && setSelected(undefined)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Detalhes do link</AlertDialogTitle>
            <AlertDialogDescription className="break-all whitespace-pre-line">
              {selected
                ? `${selected.short_url}\n${selected.destination_summary}`
                : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogAction>Fechar</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

function LinkCard({
  link,
  loading,
  onMetrics,
  onCopy,
  onOpen,
  onEdit,
  onDuplicate,
  onDetails,
  onDisable,
  onEnable,
  onDelete,
}: {
  link: ShortLink;
  loading: boolean;
  onMetrics: () => void;
  onCopy: () => void;
  onOpen: () => void;
  onEdit: () => void;
  onDuplicate: () => void;
  onDetails: () => void;
  onDisable: () => void;
  onEnable: () => void;
  onDelete: () => void;
}) {
  const active = link.status === "active";
  const deleted = link.status === "deleted";
  const badge = statusBadge(link.status);
  return (
    <Card className={!active ? "bg-muted/30" : ""}>
      <CardContent className="p-4">
        <div className="flex flex-col justify-between gap-3 sm:flex-row">
          <div className="min-w-0">
            <a
              className={
                active
                  ? "block truncate font-mono text-sm font-semibold text-green-700 hover:underline"
                  : "block truncate font-mono text-sm font-semibold text-muted-foreground"
              }
              href={active ? link.short_url : undefined}
              target="_blank"
              rel="noreferrer"
            >
              {link.short_url}
            </a>
            <p className="mt-1 text-sm text-muted-foreground">
              {link.label || "Sem rótulo"}
            </p>
          </div>
          <Badge
            className={cn(
              "flex w-fit items-center gap-1.5 px-2.5 py-1 text-xs font-medium pointer-events-none select-none",
              badge.className,
            )}
          >
            {badge.icon}
            <span className="whitespace-nowrap">{badge.label}</span>
          </Badge>
        </div>
        <p className="mt-4 break-all text-sm text-gray-700">
          {link.destination_summary || "Destino indisponível"}
        </p>
        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
          <span>
            {link.mode === "single"
              ? "Único"
              : `Rotativo · ${strategyLabel[link.strategy ?? ""]}`}
          </span>
          <span>{link.destination_count} destino(s)</span>
          <span>{link.real_clicks} clique(s)</span>
          <span>{new Date(link.created_at).toLocaleDateString("pt-BR")}</span>
        </div>
        <div className="mt-4 flex items-center justify-between gap-2">
          <Button size="sm" variant="outline" onClick={onMetrics}>
            <BarChart3 className="h-4 w-4" />
            Métricas
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button size="icon" variant="outline" aria-label="Mais ações">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={onCopy}>
                <Copy className="mr-2 h-4 w-4" />
                Copiar link
              </DropdownMenuItem>
              {active && (
                <DropdownMenuItem onSelect={onOpen}>
                  <ExternalLink className="mr-2 h-4 w-4" />
                  Abrir link
                </DropdownMenuItem>
              )}
              <DropdownMenuItem disabled={loading} onSelect={onDuplicate}>
                <CopyPlus className="mr-2 h-4 w-4" />
                Duplicar
              </DropdownMenuItem>
              {!deleted && (
                <DropdownMenuItem disabled={loading} onSelect={onEdit}>
                  <Pencil className="mr-2 h-4 w-4" />
                  Editar
                </DropdownMenuItem>
              )}
              {deleted ? (
                <DropdownMenuItem disabled={loading} onSelect={onDetails}>
                  Detalhes
                </DropdownMenuItem>
              ) : active ? (
                <DropdownMenuItem onSelect={onDisable}>
                  <Pause className="mr-2 h-4 w-4" />
                  Desativar
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem onSelect={onEnable}>
                  <PlayCircle className="mr-2 h-4 w-4" />
                  Reativar
                </DropdownMenuItem>
              )}
              {!deleted && (
                <DropdownMenuItem
                  className="text-destructive focus:text-destructive"
                  onSelect={onDelete}
                >
                  <Trash2 className="mr-2 h-4 w-4" />
                  Excluir
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </CardContent>
    </Card>
  );
}

export default LinksPage;
