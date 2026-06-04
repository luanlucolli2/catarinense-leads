import { useState } from "react";
import {
  AlertCircle,
  CheckCircle,
  ChevronLeft,
  ChevronRight,
  Clock,
  Download,
  Loader2,
  MoreHorizontal,
  Trash2,
  X,
  XCircle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";
import { V8FgtsConsultJobListItem, V8FgtsJobPhase, V8FgtsJobStatus } from "@/api/v8Fgts";

type Props = {
  items: V8FgtsConsultJobListItem[];
  loading?: boolean;
  onDownload: (id: number, opts?: { preview?: boolean }) => void;
  onCancel: (id: number) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
  page: number;
  lastPage: number;
  onPageChange: (p: number) => void;
  formatDateTimeBR: (iso?: string | null) => string;
};

function getStatusInfo(status: V8FgtsJobStatus) {
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

function getPhaseInfo(phase: V8FgtsJobPhase) {
  if (phase === "iniciar_saldo") {
    return {
      className:
        "bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:border-indigo-800",
      label: "Fase 1 • Iniciar saldo",
    };
  }
  if (phase === "polling_e_simulacao") {
    return {
      className:
        "bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-900/20 dark:text-sky-300 dark:border-sky-800",
      label: "Fase 2 • Polling e simulação",
    };
  }
  return null;
}

function calcSegments(i: V8FgtsConsultJobListItem) {
  const total = i.total_cpfs || 0;
  if (!total) return { ok: 0, not: 0, err: 0, sum: 0, total: 0 };
  const ok = (i.success_count / total) * 100;
  const not = ((i.nao_elegivel_count ?? 0) / total) * 100;
  const err = (i.fail_count / total) * 100;
  const sum = ok + not + err;
  return { ok, not, err, sum, total };
}

function SegmentedProgressBar({ item }: { item: V8FgtsConsultJobListItem }) {
  const s = calcSegments(item);
  const total = item.total_cpfs || 0;
  const processed = (
    item.success_count +
    (item.nao_elegivel_count ?? 0) +
    item.fail_count
  ).toLocaleString();
  const isCounting = total === 0 && (item.status === "pendente" || item.status === "em_progresso");
  const pulseWidthPct = Math.min(5, Math.max(item.total_cpfs ? (2 / item.total_cpfs) * 100 : 0, 0.8));

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between text-xs sm:text-sm">
        <span className="text-muted-foreground">Progresso</span>
        <span className="font-medium text-card-foreground">
          {isCounting ? "Preparando/contando CPFs…" : `${processed} de ${total.toLocaleString()} CPFs`}
        </span>
      </div>

      <div className="relative h-3 overflow-hidden rounded-full bg-muted">
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
            className="absolute top-0 h-full animate-pulse bg-blue-300/60 dark:bg-blue-700/70"
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
              <span className="h-2 w-2 rounded-full bg-emerald-500 dark:bg-emerald-400" />
              <span className="text-muted-foreground">Sucesso</span>
            </div>
          )}
          {s.not > 0 && (
            <div className="flex items-center gap-1">
              <span className="h-2 w-2 rounded-full bg-amber-500 dark:bg-amber-400" />
              <span className="text-muted-foreground">Não elegível</span>
            </div>
          )}
          {s.err > 0 && (
            <div className="flex items-center gap-1">
              <span className="h-2 w-2 rounded-full bg-red-500 dark:bg-red-400" />
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

export const V8FgtsHistoryTable = ({
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
  const [confirmJob, setConfirmJob] = useState<V8FgtsConsultJobListItem | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [confirmDeleteJob, setConfirmDeleteJob] = useState<V8FgtsConsultJobListItem | null>(null);

  const canDownloadFinal = (i: V8FgtsConsultJobListItem) =>
    (i.status === "concluido" || i.status === "falhou" || i.status === "cancelado") &&
    Boolean(i.file_path);

  const canDownloadPreview = (i: V8FgtsConsultJobListItem) =>
    i.status === "pendente" ||
    i.status === "em_progresso" ||
    (i.status === "cancelado" && Number(i.spool_bytes ?? 0) > 0);

  const canCancel = (i: V8FgtsConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso";

  const isCancelStopPending = (i: V8FgtsConsultJobListItem) =>
    i.status === "cancelado" && !i.finished_at;

  const canDelete = (i: V8FgtsConsultJobListItem) =>
    !(i.status === "pendente" || i.status === "em_progresso" || isCancelStopPending(i));

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
          <CardContent className="flex items-center justify-center py-10 text-muted-foreground sm:py-12">
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            Carregando...
          </CardContent>
        </Card>
      ) : items.length === 0 ? (
        <Card>
          <CardContent className="flex items-center justify-center py-10 sm:py-12">
            <div className="text-center">
              <AlertCircle className="mx-auto mb-3 h-10 w-10 text-muted-foreground sm:mb-4 sm:h-12 sm:w-12" />
              <p className="text-sm text-muted-foreground sm:text-base">Nenhuma consulta encontrada</p>
            </div>
          </CardContent>
        </Card>
      ) : (
        items.map((i) => {
          const statusInfo = getStatusInfo(i.status);
          const phaseInfo = i.phase && (i.status === "pendente" || i.status === "em_progresso")
            ? getPhaseInfo(i.phase)
            : null;
          const finalReady = canDownloadFinal(i);
          const previewReady = canDownloadPreview(i);
          const downloadDisabled = !finalReady && !previewReady;

          return (
            <Card
              key={i.id}
              className={cn(
                "relative rounded-xl border border-slate-200/80 bg-gradient-to-b from-white to-neutral-50 shadow-md ring-1 ring-black/5 transition-shadow hover:shadow-lg",
                "dark:border-neutral-700/80 dark:from-neutral-900 dark:to-neutral-900/80 dark:ring-white/10"
              )}
            >
              <CardHeader className="pb-3">
                <div className="flex flex-col gap-2 sm:gap-3">
                  <div className="flex items-start justify-between">
                    <div className="min-w-0 flex-1">
                      <h3 className="mb-1 truncate text-base font-semibold text-card-foreground sm:text-lg">
                        {i.title}
                      </h3>
                      <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground sm:gap-4 sm:text-sm">
                        <span>Criado em {formatDateTimeBR(i.created_at)}</span>
                      </div>
                    </div>

                    <div className="ml-2 sm:hidden">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Mais ações</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                          {canCancel(i) && (
                            <DropdownMenuItem
                              onClick={() => setConfirmJob(i)}
                              className="text-orange-600 dark:text-orange-400"
                            >
                              <X className="mr-2 h-4 w-4" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => setConfirmDeleteJob(i)}
                            className={!canDelete(i) ? "cursor-not-allowed text-muted-foreground" : "text-destructive"}
                            disabled={!canDelete(i)}
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            {isCancelStopPending(i) ? "Finalizando cancelamento" : "Excluir"}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>

                    <div className="ml-4 hidden flex-wrap items-center justify-end gap-2 sm:flex sm:gap-3">
                      {phaseInfo && (
                        <Badge
                          className={cn(
                            "pointer-events-none select-none px-2.5 py-1 text-xs font-medium",
                            phaseInfo.className
                          )}
                        >
                          <span className="whitespace-nowrap">{phaseInfo.label}</span>
                        </Badge>
                      )}

                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", statusInfo.className)}>
                        {statusInfo.icon}
                        <span className="whitespace-nowrap">{statusInfo.label}</span>
                      </Badge>

                      <Button
                        onClick={() => onDownload(i.id, { preview: !finalReady && previewReady })}
                        disabled={downloadDisabled}
                        variant="outline"
                        size="sm"
                        className="h-8"
                        title={
                          finalReady
                            ? "Baixar relatório final"
                            : previewReady
                              ? "Baixar prévia"
                              : "Baixar indisponível"
                        }
                      >
                        <Download className="h-4 w-4" />
                        {!finalReady && previewReady && <span className="ml-1 hidden sm:inline">Prévia</span>}
                      </Button>

                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Mais ações</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                          {canCancel(i) && (
                            <DropdownMenuItem
                              onClick={() => setConfirmJob(i)}
                              className="text-orange-600 dark:text-orange-400"
                            >
                              <X className="mr-2 h-4 w-4" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => setConfirmDeleteJob(i)}
                            className={!canDelete(i) ? "cursor-not-allowed text-muted-foreground" : "text-destructive"}
                            disabled={!canDelete(i)}
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            {isCancelStopPending(i) ? "Finalizando cancelamento" : "Excluir"}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>

                  <div className="flex flex-wrap items-center justify-between gap-2 sm:hidden">
                    <div className="flex items-center gap-2">
                      {phaseInfo && (
                        <Badge
                          className={cn(
                            "pointer-events-none select-none px-2.5 py-1 text-xs font-medium",
                            phaseInfo.className
                          )}
                        >
                          <span className="whitespace-nowrap">{phaseInfo.label}</span>
                        </Badge>
                      )}

                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", statusInfo.className)}>
                        {statusInfo.icon}
                        <span className="whitespace-nowrap">{statusInfo.label}</span>
                      </Badge>
                    </div>

                    <Button
                      onClick={() => onDownload(i.id, { preview: !finalReady && previewReady })}
                      disabled={downloadDisabled}
                      variant="outline"
                      size="sm"
                      className="h-8"
                      title={
                        finalReady
                          ? "Baixar relatório final"
                          : previewReady
                            ? "Baixar prévia"
                            : "Baixar indisponível"
                      }
                    >
                      <Download className="h-4 w-4" />
                    </Button>
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
                    <div className="grid grid-cols-3 gap-3 border-t border-border pt-2 sm:gap-4">
                      <div className="text-center">
                        <div className="text-base font-semibold text-emerald-600 dark:text-emerald-400 sm:text-lg">
                          {i.success_count.toLocaleString()}
                        </div>
                        <div className="text-[11px] text-muted-foreground sm:text-xs">Sucesso</div>
                      </div>
                      <div className="text-center">
                        <div className="text-base font-semibold text-amber-600 dark:text-amber-400 sm:text-lg">
                          {(i.nao_elegivel_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] text-muted-foreground sm:text-xs">Não elegível</div>
                      </div>
                      <div className="text-center">
                        <div className="text-base font-semibold text-red-600 dark:text-red-400 sm:text-lg">
                          {i.fail_count.toLocaleString()}
                        </div>
                        <div className="text-[11px] text-muted-foreground sm:text-xs">Falhas</div>
                      </div>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })
      )}

      <div className="flex flex-col gap-3 rounded-md border border-border bg-white px-3 py-3 dark:bg-neutral-900 sm:flex-row sm:items-center sm:justify-between sm:px-4 lg:px-6">
        <div className="text-center text-xs text-muted-foreground sm:text-left sm:text-sm">
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
            <ChevronLeft className="h-4 w-4" />
            <span className="sr-only">Anterior</span>
          </Button>
          <Button
            onClick={() => onPageChange(Math.min(lastPage || 1, page + 1))}
            disabled={page >= (lastPage || 1) || !!loading}
            variant="outline"
            size="sm"
            className="w-full sm:w-auto"
          >
            <ChevronRight className="h-4 w-4" />
            <span className="sr-only">Próxima</span>
          </Button>
        </div>
      </div>

      <AlertDialog open={!!confirmJob} onOpenChange={(isOpen) => !isOpen && setConfirmJob(null)}>
        <AlertDialogContent className="sm:max-w-lg">
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-red-600">
              Cancelar consulta?
            </AlertDialogTitle>
          </AlertDialogHeader>
          <div className="text-sm text-gray-700 dark:text-gray-200">
            <p>Essa ação interromperá o processamento:</p>
            {confirmJob && (
              <p className="my-2 break-words rounded bg-gray-100 p-2 font-semibold dark:bg-neutral-800">
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
              className="w-full bg-red-600 hover:bg-red-700 sm:w-auto"
              disabled={cancelingId !== null}
              onClick={(e) => {
                e.preventDefault();
                void executeCancel();
              }}
            >
              {cancelingId === confirmJob?.id ? <Loader2 className="h-4 w-4 animate-spin" /> : "Sim, cancelar"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

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
            <p>Arquivos vinculados serão removidos:</p>
            {confirmDeleteJob && (
              <p className="my-2 break-words rounded bg-gray-100 p-2 font-semibold dark:bg-neutral-800">
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
              className="w-full bg-red-600 hover:bg-red-700 sm:w-auto"
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
