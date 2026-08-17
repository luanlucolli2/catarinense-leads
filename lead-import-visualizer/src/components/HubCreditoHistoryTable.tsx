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
  Pause,
  Play,
  AlertCircle,
  ChevronLeft,
  ChevronRight,
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
import { HubCreditoConsultJobListItem, HubCreditoJobStatus } from "@/api/hubcredito";

type Props = {
  items: HubCreditoConsultJobListItem[];
  loading?: boolean;
  onDownload: (id: number, opts?: { preview?: boolean }) => void;
  onCancel: (id: number) => Promise<void>;
  onPause: (id: number) => Promise<void>;
  onResume: (id: number) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
  onRefresh?: () => void;
  page: number;
  lastPage: number;
  onPageChange: (p: number) => void;
  formatDateTimeBR: (iso?: string | null) => string;
};

function getStatusInfo(status: HubCreditoJobStatus) {
  switch (status) {
    case "concluido":
      return {
        icon: <CheckCircle className="w-4 h-4" />,
        className: "pointer-events-none select-none bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800",
        label: "Concluído",
      };
    case "em_progresso":
      return {
        icon: <Loader2 className="w-4 h-4 animate-spin" />,
        className: "pointer-events-none select-none bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:border-blue-800",
        label: "Em andamento",
      };
    case "agendado":
      return { icon: <Clock className="w-4 h-4" />, className: "pointer-events-none select-none bg-violet-100 text-violet-800 border-violet-200", label: "Agendado" };
    case "pausado":
      return { icon: <Pause className="w-4 h-4" />, className: "pointer-events-none select-none bg-amber-100 text-amber-800 border-amber-200", label: "Pausado" };
    case "falhou":
      return {
        icon: <XCircle className="w-4 h-4" />,
        className: "pointer-events-none select-none bg-red-100 text-red-800 border-red-200 dark:bg-red-900/20 dark:text-red-300 dark:border-red-800",
        label: "Falhou",
      };
    case "cancelado":
      return {
        icon: <X className="w-4 h-4" />,
        className: "pointer-events-none select-none bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600",
        label: "Cancelado",
      };
    case "pendente":
    default:
      return {
        icon: <Clock className="w-4 h-4" />,
        className: "pointer-events-none select-none bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-800/40 dark:text-gray-200 dark:border-gray-700",
        label: "Pendente",
      };
  }
}

function getPhaseInfo(phase: HubCreditoConsultJobListItem["phase"]) {
  if (phase === "fase_1") {
    return {
      className:
        "bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:border-indigo-800",
      label: "Fase 1 • Pré-simulação",
    };
  }
  if (phase === "fase_2") {
    return {
      className:
        "bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-900/20 dark:text-sky-300 dark:border-sky-800",
      label: "Fase 2 • Consulta e simulação",
    };
  }
  return null;
}

function calcSegments(i: HubCreditoConsultJobListItem) {
  const total = i.total_cpfs || 0;
  if (!total) return { ok: 0, not: 0, fail: 0, sum: 0 };
  const ok = (i.aprovado_count / total) * 100;
  const not = (i.nao_aprovado_count / total) * 100;
  const fail = (i.fail_count / total) * 100;
  return { ok, not, fail, sum: ok + not + fail };
}

function SegmentedProgressBar({ item }: { item: HubCreditoConsultJobListItem }) {
  if (item.executor === "api") return <div className="grid grid-cols-1 gap-4">
    <Phase title="Consulta" processed={(item.phase1_submitted_count ?? 0) + (item.phase1_not_approved_count ?? 0) + (item.phase1_fail_count ?? 0)} total={item.total_cpfs} metrics={[[item.phase1_submitted_count ?? 0, "Enviados", "bg-blue-500"], [item.phase1_not_approved_count ?? 0, "Não aprovados", "bg-amber-500"], [item.phase1_fail_count ?? 0, "Falhas", "bg-red-500"]]} />
    <Phase title="Política de crédito" processed={(item.phase2_approved_count ?? 0) + (item.phase2_not_approved_count ?? 0) + (item.phase2_fail_count ?? 0)} total={item.phase1_submitted_count ?? 0} metrics={[[item.phase2_approved_count ?? 0, "Aprovados", "bg-emerald-500"], [item.phase2_not_approved_count ?? 0, "Não aprovados", "bg-amber-500"], [item.phase2_fail_count ?? 0, "Falhas", "bg-red-500"]]} />
  </div>;
  const s = calcSegments(item);
  const total = item.total_cpfs || 0;
  const processed = (item.aprovado_count + item.nao_aprovado_count + item.fail_count).toLocaleString();
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

      <div className="relative h-3 bg-muted rounded-full overflow-hidden">
        {s.ok > 0 && (
          <div className="absolute left-0 top-0 h-full bg-emerald-500 dark:bg-emerald-400" style={{ width: `${s.ok}%` }} />
        )}
        {s.not > 0 && (
          <div className="absolute top-0 h-full bg-amber-500 dark:bg-amber-400" style={{ left: `${s.ok}%`, width: `${s.not}%` }} />
        )}
        {s.fail > 0 && (
          <div className="absolute top-0 h-full bg-red-500 dark:bg-red-400" style={{ left: `${s.ok + s.not}%`, width: `${s.fail}%` }} />
        )}
        {(item.status === "em_progresso" || isCounting) && s.sum < 100 && (
          <div
            className="absolute top-0 h-full bg-blue-300/60 dark:bg-blue-700/70 animate-pulse"
            style={{ left: `${s.sum}%`, width: `${Math.min(pulseWidthPct, 100 - s.sum)}%` }}
          />
        )}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] sm:text-xs">
        <div className="flex flex-wrap items-center gap-3">
          {s.ok > 0 && (
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 bg-emerald-500 dark:bg-emerald-400 rounded-full" />
              <span className="text-muted-foreground">Aprovado</span>
            </div>
          )}
          {s.not > 0 && (
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 bg-amber-500 dark:bg-amber-400 rounded-full" />
              <span className="text-muted-foreground">Não Aprovado</span>
            </div>
          )}
          {s.fail > 0 && (
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 bg-red-500 dark:bg-red-400 rounded-full" />
              <span className="text-muted-foreground">Falhou</span>
            </div>
          )}
        </div>
        <span className="text-muted-foreground">
          {item.status === "pendente" && total === 0 ? "Preparando…" : `${s.sum.toFixed(1)}% completo`}
        </span>
      </div>
    </div>
  );
}

function Phase({ title, processed, total, metrics }: { title: string; processed: number; total: number; metrics: [number, string, string][] }) {
  const percent = total ? Math.min(100, Math.round((processed / total) * 100)) : 0;
  return <div className="rounded-xl border border-border/70 bg-muted/5 p-4 shadow-sm"><div className="mb-3 flex items-center justify-between"><span className="text-sm font-semibold">{title}</span><span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">{processed.toLocaleString()} de {total.toLocaleString()} · {percent}%</span></div><div className="mb-3 h-2.5 overflow-hidden rounded-full bg-muted"><div className="h-full bg-emerald-500 transition-all duration-500" style={{ width: `${percent}%` }} /></div><div className="flex flex-wrap items-center gap-3 text-xs">{metrics.map(([value, label, color]) => <span key={label} className="inline-flex items-center gap-1.5"><i className={`size-2 rounded-full ${color}`} /><span className="text-muted-foreground">{label}</span><b>{value.toLocaleString()}</b></span>)}</div></div>;
}

export const HubCreditoHistoryTable = ({
  items,
  loading,
  onDownload,
  onCancel,
  onPause,
  onResume,
  onDelete,
  page,
  lastPage,
  onPageChange,
  formatDateTimeBR,
}: Props) => {
  const [cancelingId, setCancelingId] = useState<number | null>(null);
  const [confirmJob, setConfirmJob] = useState<HubCreditoConsultJobListItem | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [confirmDeleteJob, setConfirmDeleteJob] = useState<HubCreditoConsultJobListItem | null>(null);

  const canDownloadFinal = (i: HubCreditoConsultJobListItem) =>
    (i.status === "concluido" || i.status === "falhou" || i.status === "cancelado") && Boolean(i.has_file ?? i.file_path);
  const canDownloadPreview = (i: HubCreditoConsultJobListItem) =>
    (i.executor === "api" && ["pendente", "em_progresso", "pausado"].includes(i.status)) ||
    i.status === "pendente" ||
    i.status === "em_progresso" ||
    (i.status === "cancelado" && (Boolean(i.spool_path) || Number(i.spool_bytes ?? 0) > 0));
  const canPause = (i: HubCreditoConsultJobListItem) => i.executor === "api" && (i.status === "pendente" || i.status === "em_progresso");
  const canResume = (i: HubCreditoConsultJobListItem) => i.executor === "api" && i.status === "pausado";
  const canCancel = (i: HubCreditoConsultJobListItem) => ["agendado", "pendente", "em_progresso", "pausado"].includes(i.status);
  const isCancelStopPending = (i: HubCreditoConsultJobListItem) => i.status === "cancelado" && !i.finished_at;
  const canDelete = (i: HubCreditoConsultJobListItem) =>
    !(["agendado", "pendente", "em_progresso", "pausado"].includes(i.status) || isCancelStopPending(i));

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
              <p className="text-sm sm:text-base text-muted-foreground">Nenhuma consulta encontrada</p>
            </div>
          </CardContent>
        </Card>
      ) : (
        items.map((i) => {
          const statusInfo = getStatusInfo(i.status as HubCreditoJobStatus);
          const phaseInfo = i.phase && ["agendado", "pendente", "em_progresso", "pausado"].includes(i.status)
            ? getPhaseInfo(i.phase)
            : null;
          const phaseAndStatusInfo = phaseInfo
            ? { icon: statusInfo.icon, className: statusInfo.className, label: `${phaseInfo.label} • ${statusInfo.label}` }
            : statusInfo;
          const isActive = i.status === "pendente" || i.status === "em_progresso";
          const finalReady = canDownloadFinal(i);
          const previewReady = canDownloadPreview(i);
          const downloadDisabled = !finalReady && !previewReady;

          return (
            <Card
              key={i.id}
              className={cn(
                "relative rounded-xl border transition-all duration-500",
                isActive
                  ? "border-blue-400/60 dark:border-blue-500/50 shadow-[0_0_15px_rgba(59,130,246,0.15)] dark:shadow-[0_0_15px_rgba(59,130,246,0.1)] bg-blue-50/40 dark:bg-blue-900/10 ring-1 ring-blue-400/20"
                  : "border-slate-200/80 dark:border-neutral-700/80 bg-gradient-to-b from-white to-neutral-50 dark:from-neutral-900 dark:to-neutral-900/80 shadow-md hover:shadow-lg ring-1 ring-black/5 dark:ring-white/10"
              )}
            >
              <CardHeader className="pb-3">
                <div className="flex flex-col gap-2 sm:gap-3">
                  <div className="flex items-start justify-between">
                    <div className="flex-1 min-w-0">
                      <h3 className="font-semibold text-card-foreground truncate mb-1 text-base sm:text-lg">{i.title}</h3>
                      <div className="flex flex-wrap items-center gap-2 sm:gap-4 text-xs sm:text-sm text-muted-foreground">
                        <span>Criado em {formatDateTimeBR(i.created_at)}</span>
                        {i.scheduled_for && <span>Agendado para {formatDateTimeBR(i.scheduled_for)}</span>}
                      </div>
                    </div>

                    <div className="sm:hidden ml-2">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Mais ações</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          {canPause(i) && <DropdownMenuItem onClick={() => void onPause(i.id)}><Pause className="w-4 h-4 mr-2" />Pausar</DropdownMenuItem>}
                          {canResume(i) && <DropdownMenuItem onClick={() => void onResume(i.id)}><Play className="w-4 h-4 mr-2" />Retomar</DropdownMenuItem>}
                          {canCancel(i) && (
                            <DropdownMenuItem onClick={() => setConfirmJob(i)} className="text-orange-600 dark:text-orange-400">
                              <X className="w-4 h-4 mr-2" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => setConfirmDeleteJob(i)}
                            className={!canDelete(i) ? "text-muted-foreground cursor-not-allowed" : "text-destructive"}
                            disabled={!canDelete(i)}
                          >
                            <Trash2 className="w-4 h-4 mr-2" />
                            {isCancelStopPending(i) ? "Finalizando cancelamento" : "Excluir"}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>

                    <div className="hidden sm:flex flex-wrap items-center justify-end gap-2 sm:gap-3 ml-4">
                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", phaseAndStatusInfo.className)}>
                        {phaseAndStatusInfo.icon}
                        <span className="whitespace-nowrap">{phaseAndStatusInfo.label}</span>
                      </Badge>

                      <Button
                        onClick={() => onDownload(i.id, { preview: !finalReady && previewReady })}
                        disabled={downloadDisabled}
                        variant="outline"
                        size="sm"
                        className="h-8"
                        title={finalReady ? "Baixar planilha final" : previewReady ? "Baixar prévia" : "Baixar indisponível"}
                      >
                        <Download className="w-4 h-4" />
                        {!finalReady && previewReady && <span className="ml-1 hidden sm:inline">Prévia</span>}
                      </Button>

                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Mais ações</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          {canPause(i) && <DropdownMenuItem onClick={() => void onPause(i.id)}><Pause className="w-4 h-4 mr-2" />Pausar</DropdownMenuItem>}
                          {canResume(i) && <DropdownMenuItem onClick={() => void onResume(i.id)}><Play className="w-4 h-4 mr-2" />Retomar</DropdownMenuItem>}
                          {canCancel(i) && (
                            <DropdownMenuItem onClick={() => setConfirmJob(i)} className="text-orange-600 dark:text-orange-400">
                              <X className="w-4 h-4 mr-2" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => setConfirmDeleteJob(i)}
                            className={!canDelete(i) ? "text-muted-foreground cursor-not-allowed" : "text-destructive"}
                            disabled={!canDelete(i)}
                          >
                            <Trash2 className="w-4 h-4 mr-2" />
                            {isCancelStopPending(i) ? "Finalizando cancelamento" : "Excluir"}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>

                  <div className="sm:hidden flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", phaseAndStatusInfo.className)}>
                        {phaseAndStatusInfo.icon}
                        <span className="whitespace-nowrap">{phaseAndStatusInfo.label}</span>
                      </Badge>
                    </div>

                    <Button
                      onClick={() => onDownload(i.id, { preview: !finalReady && previewReady })}
                      disabled={downloadDisabled}
                      variant="outline"
                      size="sm"
                      className="h-8"
                      title={finalReady ? "Baixar planilha final" : previewReady ? "Baixar prévia" : "Baixar indisponível"}
                    >
                      <Download className="w-4 h-4" />
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
                    <div className="grid grid-cols-3 gap-3 sm:gap-4 pt-2 border-t border-border">
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-emerald-600 dark:text-emerald-400">
                          {(i.aprovado_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">Aprovado</div>
                      </div>
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-amber-600 dark:text-amber-400">
                          {(i.nao_aprovado_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">Não Aprovado</div>
                      </div>
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-red-600 dark:text-red-400">
                          {(i.fail_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">Falhou</div>
                      </div>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })
      )}

      <div className="bg-white dark:bg-neutral-900 px-3 sm:px-4 lg:px-6 py-3 border border-border rounded-md flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="text-xs sm:text-sm text-muted-foreground text-center sm:text-left">
          Página {page} de {lastPage || 1}
        </div>
        <div className="flex items-center gap-2">
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
      </div>

      <AlertDialog open={!!confirmJob} onOpenChange={(open) => !open && setConfirmJob(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Cancelar consulta?</AlertDialogTitle>
          </AlertDialogHeader>
          <p className="text-sm text-muted-foreground">
            A consulta será interrompida e a prévia seguirá disponível enquanto houver spool útil.
          </p>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={cancelingId !== null}>Voltar</AlertDialogCancel>
            <AlertDialogAction onClick={() => void executeCancel()} disabled={cancelingId !== null}>
              {cancelingId !== null ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
              Confirmar cancelamento
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={!!confirmDeleteJob} onOpenChange={(open) => !open && setConfirmDeleteJob(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Excluir consulta?</AlertDialogTitle>
          </AlertDialogHeader>
          <p className="text-sm text-muted-foreground">
            Essa ação remove o histórico e os arquivos associados a esta consulta.
          </p>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deletingId !== null}>Voltar</AlertDialogCancel>
            <AlertDialogAction onClick={() => void executeDelete()} disabled={deletingId !== null}>
              {deletingId !== null ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
              Confirmar exclusão
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};
