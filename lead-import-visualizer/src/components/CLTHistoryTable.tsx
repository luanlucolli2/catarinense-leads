// src/components/CLTHistoryTable.tsx
import { useState } from "react";
import {
  Download,
  Loader2,
  MoreHorizontal,
  X,
  Trash2,
  CheckCircle,
  XCircle,
  Clock,
  AlertCircle,
  ChevronLeft,
  ChevronRight,
  Wifi,
  Database,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { cn } from "@/lib/utils";
import { CltConsultJobListItem } from "@/api/clt";

type Props = {
  items: CltConsultJobListItem[];
  loading?: boolean;
  onDownload: (id: number, opts?: { preview?: boolean }) => void;
  onCancel: (id: number) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
  onRefresh?: () => void;

  // paginação
  page: number;
  lastPage: number;
  onPageChange: (p: number) => void;

  // util
  formatDateTimeBR: (iso?: string | null) => string;
};

type CltJobStatus = CltConsultJobListItem["status"];

function getStatusInfo(status: CltJobStatus) {
  switch (status) {
    case "concluido":
      return {
        icon: <CheckCircle className="w-4 h-4" />,
        className:
          "pointer-events-none select-none bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800",
        label: "Concluído",
      };
    case "em_progresso":
      return {
        icon: <Loader2 className="w-4 h-4 animate-spin" />,
        className:
          "pointer-events-none select-none bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:border-blue-800",
        label: "Em andamento",
      };
    case "falhou":
      return {
        icon: <XCircle className="w-4 h-4" />,
        className:
          "pointer-events-none select-none bg-red-100 text-red-800 border-red-200 dark:bg-red-900/20 dark:text-red-300 dark:border-red-800",
        label: "Falhou",
      };
    case "cancelado":
      return {
        icon: <X className="w-4 h-4" />,
        className:
          "pointer-events-none select-none bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600",
        label: "Cancelado",
      };
    case "pendente":
    default:
      return {
        icon: <Clock className="w-4 h-4" />,
        className:
          "pointer-events-none select-none bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-800/40 dark:text-gray-200 dark:border-gray-700",
        label: "Pendente",
      };
  }
}

function calcSegments(i: CltConsultJobListItem) {
  const total = i.total_cpfs || 0;
  if (!total) return { ok: 0, not: 0, err: 0, sum: 0, total: 0 };
  const ok = (i.success_count / total) * 100;
  const not = ((i.not_found_count ?? 0) / total) * 100;
  const err = (i.fail_count / total) * 100;
  const sum = ok + not + err;
  return { ok, not, err, sum, total };
}

function SegmentedProgressBar({ item }: { item: CltConsultJobListItem }) {
  const s = calcSegments(item);
  const total = item.total_cpfs || 0;
  const processed = (
    item.success_count +
    (item.not_found_count ?? 0) +
    item.fail_count
  ).toLocaleString();

  const isCounting =
    total === 0 &&
    (item.status === "pendente" || item.status === "em_progresso");

  const pulseWidthPct = Math.min(
    5,
    Math.max(item.total_cpfs ? (2 / item.total_cpfs) * 100 : 0, 0.8)
  );

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between text-xs sm:text-sm">
        <span className="text-muted-foreground">Progresso</span>
        <span className="font-medium text-card-foreground">
          {isCounting
            ? "Preparando/contando CPFs…"
            : `${processed} de ${total.toLocaleString()} CPFs`}
        </span>
      </div>

      <div
        className="relative h-3 bg-muted rounded-full overflow-hidden"
        aria-label="Barra de progresso segmentada"
      >
        {s.ok > 0 && (
          <div
            className="absolute left-0 top-0 h-full bg-emerald-500 dark:bg-emerald-400"
            style={{ width: `${s.ok}%` }}
          />
        )}
        {s.not > 0 && (
          <div
            className="absolute top-0 h-full bg-amber-500 dark:bg-amber-400"
            style={{ left: `${s.ok}%`, width: `${s.not}%` }}
          />
        )}
        {s.err > 0 && (
          <div
            className="absolute top-0 h-full bg-red-500 dark:bg-red-400"
            style={{ left: `${s.ok + s.not}%`, width: `${s.err}%` }}
          />
        )}
        {(item.status === "em_progresso" || isCounting) && s.sum < 100 && (
          <div
            className="absolute top-0 h-full bg-blue-300/60 dark:bg-blue-700/70 animate-pulse"
            style={{
              left: `${s.sum}%`,
              width: `${Math.min(pulseWidthPct, 100 - s.sum)}%`,
            }}
          />
        )}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] sm:text-xs">
        <div className="flex flex-wrap items-center gap-3">
          {s.ok > 0 && (
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 bg-emerald-500 dark:bg-emerald-400 rounded-full" />
              <span className="text-muted-foreground">Sucesso</span>
            </div>
          )}
          {s.not > 0 && (
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 bg-amber-500 dark:bg-amber-400 rounded-full" />
              <span className="text-muted-foreground">Não encontrados</span>
            </div>
          )}
          {s.err > 0 && (
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 bg-red-500 dark:bg-red-400 rounded-full" />
              <span className="text-muted-foreground">Falhas</span>
            </div>
          )}
        </div>
        <span className="text-muted-foreground">
          {isCounting ? "Preparando…" : `${s.sum.toFixed(1)}% completo`}
        </span>
      </div>
    </div>
  );
}

export const CLTHistoryTable = ({
  items,
  loading,
  onDownload,
  onCancel,
  onDelete,
  page,
  lastPage,
  onPageChange,
  formatDateTimeBR,
}: Props) => {
  const [cancelingId, setCancelingId] = useState<number | null>(null);
  const [confirmJob, setConfirmJob] = useState<CltConsultJobListItem | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [confirmDeleteJob, setConfirmDeleteJob] =
    useState<CltConsultJobListItem | null>(null);

  const canDownloadFinal = (i: CltConsultJobListItem) =>
    (i.status === "concluido" || i.status === "falhou" || i.status === "cancelado") &&
    Boolean((i.has_file ?? null) || (i.file_path ?? null));

  const canDownloadPreview = (i: CltConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso";

  const canCancel = (i: CltConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso";

  const canDelete = (i: CltConsultJobListItem) =>
    !(i.status === "pendente" || i.status === "em_progresso");

  const openCancelDialog = (i: CltConsultJobListItem) => {
    if (!canCancel(i) || cancelingId !== null) return;
    setConfirmJob(i);
  };

  const executeCancel = async () => {
    if (!confirmJob) return;
    try {
      setCancelingId(confirmJob.id);
      await onCancel(confirmJob.id);
    } finally {
      setCancelingId(null);
      setConfirmJob(null);
    }
  };

  const openDeleteDialog = (i: CltConsultJobListItem) => {
    if (!canDelete(i) || deletingId !== null) return;
    setConfirmDeleteJob(i);
  };

  const executeDelete = async () => {
    if (!confirmDeleteJob) return;
    try {
      setDeletingId(confirmDeleteJob.id);
      await onDelete(confirmDeleteJob.id);
    } finally {
      setDeletingId(null);
      setConfirmDeleteJob(null);
    }
  };

  return (
    <div className="space-y-3 sm:space-y-4">
      {loading ? (
        <Card>
          <CardContent className="flex items-center justify-center py-10 sm:py-12 text-muted-foreground">
            <Loader2 className="w-4 h-4 animate-spin mr-2" />
            Carregando...
          </CardContent>
        </Card>
      ) : items.length === 0 ? (
        <Card>
          <CardContent className="flex items-center justify-center py-10 sm:py-12">
            <div className="text-center">
              <AlertCircle className="w-10 h-10 sm:w-12 sm:h-12 text-muted-foreground mx-auto mb-3 sm:mb-4" />
              <p className="text-sm sm:text-base text-muted-foreground">
                Nenhuma consulta encontrada
              </p>
            </div>
          </CardContent>
        </Card>
      ) : (
        items.map((i) => {
          const statusInfo = getStatusInfo(i.status as CltJobStatus);
          const finalReady = canDownloadFinal(i);
          const previewReady = canDownloadPreview(i);
          const downloadDisabled = !finalReady && !previewReady;

          // Resolve a variante com tipagem, mantendo compatibilidade com payloads antigos
          const variant: 'online' | 'offline' | undefined = ((): any => {
            if (i.variant) return i.variant;
            const anyI = i as any;
            if (typeof anyI.is_offline === 'boolean') {
              return anyI.is_offline ? 'offline' : 'online';
            }
            const legacy = String(anyI.mode ?? anyI.tipo ?? anyI.type ?? '').toLowerCase();
            if (legacy === 'offline' || legacy === 'off') return 'offline';
            if (legacy === 'online' || legacy === 'on') return 'online';
            return undefined;
          })();

          const type = variant === 'offline' ? 'OFF' : variant === 'online' ? 'ONLINE' : 'ONLINE';

          const modeBadge = type === "OFF"
            ? {
                icon: <Database className="w-3.5 h-3.5" />,
                className:
                  "bg-gradient-to-r from-slate-100 to-stone-50 text-slate-700 border-slate-300 dark:from-slate-800/30 dark:to-stone-800/20 dark:text-slate-300 dark:border-slate-700 shadow-sm",
                label: "Base Offline",
              }
            : {
                icon: <Wifi className="w-3.5 h-3.5" />,
                className:
                  "bg-gradient-to-r from-emerald-100 to-teal-50 text-emerald-700 border-emerald-300 dark:from-emerald-900/30 dark:to-teal-800/20 dark:text-emerald-300 dark:border-emerald-700 shadow-sm",
                label: "Online",
              };

          return (
            <Card
              key={i.id}
              className={cn(
                "relative rounded-xl border border-slate-200/80 dark:border-neutral-700/80",
                "bg-gradient-to-b from-white to-neutral-50 dark:from-neutral-900 dark:to-neutral-900/80",
                "shadow-md hover:shadow-lg ring-1 ring-black/5 dark:ring-white/10",
                "transition-shadow"
              )}
            >
              <CardHeader className="pb-3">
                {/* Header: linha superior com título/data e '...' no mobile; layout antigo no desktop */}
                <div className="flex flex-col gap-2 sm:gap-3">
                  <div className="flex items-start justify-between">
                    {/* Título + meta */}
                    <div className="flex-1 min-w-0">
                      <h3 className="font-semibold text-card-foreground truncate mb-1 text-base sm:text-lg">
                        {i.title}
                      </h3>
                      <div className="flex flex-wrap items-center gap-2 sm:gap-4 text-xs sm:text-sm text-muted-foreground">
                        <span>Criado em {formatDateTimeBR(i.created_at)}</span>
                      </div>
                    </div>

                    {/* Botão '...' somente no mobile ao nível do título/data */}
                    <div className="sm:hidden ml-2">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Mais ações</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                          {(i.status === "pendente" || i.status === "em_progresso") && (
                            <DropdownMenuItem
                              onClick={() => setConfirmJob(i)}
                              className="text-orange-600 dark:text-orange-400"
                            >
                              <X className="w-4 h-4 mr-2" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => setConfirmDeleteJob(i)}
                            className={
                              i.status === "em_progresso" || i.status === "pendente"
                                ? "text-muted-foreground cursor-not-allowed"
                                : "text-destructive"
                            }
                            disabled={i.status === "em_progresso" || i.status === "pendente"}
                          >
                            <Trash2 className="w-4 h-4 mr-2" />
                            Excluir
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>

                    {/* Ações originais no desktop: badges + download + '...' */}
                    <div className="hidden sm:flex flex-wrap items-center justify-end gap-2 sm:gap-3 ml-4">
                      <Badge
                        className={cn(
                          "flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium pointer-events-none select-none",
                          modeBadge.className
                        )}
                      >
                        {modeBadge.icon}
                        <span className="whitespace-nowrap">{modeBadge.label}</span>
                      </Badge>

                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", getStatusInfo(i.status as CltJobStatus).className)}>
                        {getStatusInfo(i.status as CltJobStatus).icon}
                        <span className="whitespace-nowrap">{getStatusInfo(i.status as CltJobStatus).label}</span>
                      </Badge>

                      {i.status !== "cancelado" && (
                        <Button
                          onClick={() =>
                            onDownload(i.id, { preview: !finalReady && previewReady })
                          }
                          disabled={downloadDisabled}
                          variant="outline"
                          size="sm"
                          className="h-8"
                          title={
                            finalReady
                              ? "Baixar planilha final"
                              : previewReady
                                ? "Baixar prévia"
                                : "Baixar indisponível"
                          }
                        >
                          <Download className="w-4 h-4" />
                          {!finalReady && previewReady && (
                            <span className="ml-1 hidden sm:inline">Prévia</span>
                          )}
                        </Button>
                      )}

                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Mais ações</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                          {(i.status === "pendente" || i.status === "em_progresso") && (
                            <DropdownMenuItem
                              onClick={() => setConfirmJob(i)}
                              className="text-orange-600 dark:text-orange-400"
                            >
                              <X className="w-4 h-4 mr-2" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => setConfirmDeleteJob(i)}
                            className={
                              i.status === "em_progresso" || i.status === "pendente"
                                ? "text-muted-foreground cursor-not-allowed"
                                : "text-destructive"
                            }
                            disabled={i.status === "em_progresso" || i.status === "pendente"}
                          >
                            <Trash2 className="w-4 h-4 mr-2" />
                            Excluir
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>

                  {/* Linha abaixo no mobile: badges e botão de download */}
                  <div className="sm:hidden flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <Badge
                        className={cn(
                          "flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium pointer-events-none select-none",
                          modeBadge.className
                        )}
                      >
                        {modeBadge.icon}
                        <span className="whitespace-nowrap">{modeBadge.label}</span>
                      </Badge>

                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", getStatusInfo(i.status as CltJobStatus).className)}>
                        {getStatusInfo(i.status as CltJobStatus).icon}
                        <span className="whitespace-nowrap">{getStatusInfo(i.status as CltJobStatus).label}</span>
                      </Badge>
                    </div>

                    {i.status !== "cancelado" && (
                      <Button
                        onClick={() =>
                          onDownload(i.id, { preview: !finalReady && previewReady })
                        }
                        disabled={downloadDisabled}
                        variant="outline"
                        size="sm"
                        className="h-8"
                        title={
                          finalReady
                            ? "Baixar planilha final"
                            : previewReady
                              ? "Baixar prévia"
                              : "Baixar indisponível"
                        }
                      >
                        <Download className="w-4 h-4" />
                      </Button>
                    )}
                  </div>
                </div>
              </CardHeader>

              <CardContent className="pt-0">
                <div className="space-y-4">
                  <SegmentedProgressBar item={i} />

                  {(i.status === "concluido" ||
                    i.status === "em_progresso" ||
                    i.status === "cancelado" ||
                    i.status === "falhou") && (
                    <div className="grid grid-cols-3 gap-3 sm:gap-4 pt-2 border-t border-border">
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-emerald-600 dark:text-emerald-400">
                          {i.success_count.toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">Sucesso</div>
                      </div>
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-amber-600 dark:text-amber-400">
                          {(i.not_found_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">
                          Não encontrados
                        </div>
                      </div>
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-red-600 dark:text-red-400">
                          {i.fail_count.toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">Falhas</div>
                      </div>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })
      )}

      {/* Paginação responsiva */}
      <div className="bg-white dark:bg-neutral-900 px-3 sm:px-4 lg:px-6 py-3 border border-border rounded-md flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="text-xs sm:text-sm text-muted-foreground text-center sm:text-left">
          Página {page} de {lastPage || 1}
        </div>
        <div className="flex items-center gap-2">
          <Button
            onClick={() => onPageChange(Math.max(1, page - 1))}
            disabled={page <= 1 || !!loading}
            variant="outline"
            size="sm"
            className="w-full sm:w-auto"
          >
            <ChevronLeft className="w-4 h-4" />
            <span className="sr-only">Anterior</span>
          </Button>
          <Button
            onClick={() => onPageChange(Math.min(lastPage || 1, page + 1))}
            disabled={page >= (lastPage || 1) || !!loading}
            variant="outline"
            size="sm"
            className="w-full sm:w-auto"
          >
            <ChevronRight className="w-4 h-4" />
            <span className="sr-only">Próxima</span>
          </Button>
        </div>
      </div>

      {/* Confirmar CANCELAMENTO */}
      <AlertDialog
        open={!!confirmJob}
        onOpenChange={(isOpen) => !isOpen && setConfirmJob(null)}
      >
        <AlertDialogContent className="sm:max-w-lg">
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-red-600">
              Cancelar consulta?
            </AlertDialogTitle>
          </AlertDialogHeader>
          <div className="text-sm text-gray-700 dark:text-gray-200">
            <p>Essa ação interromperá o processamento:</p>
            {confirmJob && (
              <p className="font-semibold my-2 bg-gray-100 dark:bg-neutral-800 p-2 rounded break-words">
                {confirmJob.title} (#{confirmJob.id})
              </p>
            )}
            <p>Deseja continuar?</p>
          </div>
          <AlertDialogFooter className="gap-2">
            <AlertDialogCancel disabled={cancelingId !== null} className="w-full sm:w-auto">
              Fechar
            </AlertDialogCancel>
            <AlertDialogAction
              className="w-full sm:w-auto bg-red-600 hover:bg-red-700"
              disabled={cancelingId !== null}
              onClick={(e) => {
                e.preventDefault();
                void executeCancel();
              }}
            >
              {cancelingId === confirmJob?.id ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                "Sim, cancelar"
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Confirmar EXCLUSÃO */}
      <AlertDialog
        open={!!confirmDeleteJob}
        onOpenChange={(isOpen) => !isOpen && setConfirmDeleteJob(null)}
      >
        <AlertDialogContent className="sm:max-w-lg">
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-red-600">
              Excluir definitivamente?
            </AlertDialogTitle>
          </AlertDialogHeader>
          <div className="text-sm text-gray-700 dark:text-gray-200">
            <p>Arquivos vinculados (final, prévia e spool) serão removidos:</p>
            {confirmDeleteJob && (
              <p className="font-semibold my-2 bg-gray-100 dark:bg-neutral-800 p-2 rounded break-words">
                {confirmDeleteJob.title} (#{confirmDeleteJob.id})
              </p>
            )}
            <p>Essa operação não pode ser desfeita.</p>
          </div>
          <AlertDialogFooter className="gap-2">
            <AlertDialogCancel disabled={deletingId !== null} className="w-full sm:w-auto">
              Fechar
            </AlertDialogCancel>
            <AlertDialogAction
              className="w-full sm:w-auto bg-red-600 hover:bg-red-700"
              disabled={deletingId !== null}
              onClick={(e) => {
                e.preventDefault();
                void executeDelete();
              }}
            >
              {deletingId === confirmDeleteJob?.id ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                "Sim, excluir"
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};
