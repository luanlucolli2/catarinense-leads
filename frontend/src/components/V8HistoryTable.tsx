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
  Pause,
  Play,
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
import { V8ConsultJobListItem, V8JobStatus } from "@/api/v8";

type Props = {
  items: V8ConsultJobListItem[];
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

function getStatusInfo(status: V8JobStatus) {
  switch (status) {
    case "agendado":
      return {
        icon: <Clock className="w-4 h-4" />,
        className:
          "pointer-events-none select-none bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:border-indigo-800",
        label: "Agendado",
      };
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
    case "pausado":
      return {
        icon: <Pause className="w-4 h-4" />,
        className:
          "pointer-events-none select-none bg-amber-100 text-amber-900 border-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800",
        label: "Pausado",
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

function getPhaseInfo(phase: V8ConsultJobListItem["phase"]) {
  if (phase === "fase_1") {
    return {
      className:
        "bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:border-indigo-800",
      label: "Fase 1 • Consentimento",
    };
  }
  if (phase === "fase_2") {
    return {
      className:
        "bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-900/20 dark:text-sky-300 dark:border-sky-800",
      label: "Fase 2 • Consulta",
    };
  }
  return null;
}

function calcSegments(i: V8ConsultJobListItem) {
  const total = i.total_cpfs || 0;
  if (!total) return { ok: 0, not: 0, err: 0, sum: 0, total: 0 };
  const ok = (i.success_count / total) * 100;
  const not = ((i.nao_elegivel_count ?? 0) / total) * 100;
  const err = (i.fail_count / total) * 100;
  const sum = ok + not + err;
  return { ok, not, err, sum, total };
}

function SegmentedProgressBar({ item }: { item: V8ConsultJobListItem }) {
  const s = calcSegments(item);
  const total = item.total_cpfs || 0;
  const processed = (
    item.success_count +
    (item.nao_elegivel_count ?? 0) +
    item.fail_count
  ).toLocaleString();

  const isCounting =
    total === 0 &&
    (item.status === "pendente" || item.status === "em_progresso");

  let statusMessage = `${s.sum.toFixed(1)}% completo`;
  if (item.status === "agendado") {
    statusMessage = "Consulta agendada";
  } else if (isCounting) {
    statusMessage = "Preparando…";
  }

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

      <div className="relative h-3 bg-muted rounded-full overflow-hidden">
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
              <span className="text-muted-foreground">Não elegível</span>
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
          {statusMessage}
        </span>
      </div>
    </div>
  );
}

type TwoPhaseStatus = "Aguardando" | "Em andamento" | "Pausada" | "Concluído" | "Falhou" | "Cancelada";

function phaseStatus(item: V8ConsultJobListItem, phase: "fase_1" | "fase_2", total: number, processed: number): TwoPhaseStatus {
  if (processed >= total && total > 0) return "Concluído";
  if (item.status === "concluido") return "Concluído";
  if (item.status === "falhou" && item.phase === phase) return "Falhou";
  if (item.status === "cancelado" && item.phase === phase) return "Cancelada";
  if (item.status === "pausado" && item.phase === phase) return "Pausada";
  if ((item.status === "pendente" || item.status === "em_progresso") && item.phase === phase) return "Em andamento";
  return "Aguardando";
}

function phaseStatusIcon(status: TwoPhaseStatus) {
  if (status === "Concluído") return <CheckCircle className="w-4 h-4 text-emerald-500" />;
  if (status === "Em andamento") return <Loader2 className="w-4 h-4 text-blue-500 animate-spin" />;
  if (status === "Pausada") return <Pause className="w-4 h-4 text-amber-500" />;
  if (status === "Falhou") return <XCircle className="w-4 h-4 text-red-500" />;
  if (status === "Cancelada") return <X className="w-4 h-4 text-gray-500" />;
  return <Clock className="w-4 h-4 text-muted-foreground" />;
}

function ExternalMetrics({ item }: { item: V8ConsultJobListItem }) {
  const total = Math.max(0, item.total_cpfs || 0);
  const submitted = Math.max(0, item.phase1_submitted_count ?? 0);
  const notEligible = Math.max(0, item.phase1_not_eligible_count ?? 0);
  const phase1Errors = Math.max(0, item.phase1_errors_count ?? 0);
  const phase1Processed = Math.min(total, submitted + notEligible + phase1Errors);
  const approved = Math.max(0, item.phase2_approved_count ?? 0);
  const notApproved = Math.max(0, item.phase2_not_approved_count ?? 0);
  const phase2Errors = Math.max(0, item.phase2_errors_count ?? 0);
  const phase2Total = submitted;
  const phase2Processed = Math.min(phase2Total, approved + notApproved + phase2Errors);
  const phase1Status = phaseStatus(item, "fase_1", total, phase1Processed);
  const phase2Status = phaseStatus(item, "fase_2", phase2Total, phase2Processed);
  const currentPhase = phase1Status === "Concluído" ? 2 : 1;
  const phase1Pct = total > 0 ? {
    submitted: (submitted / total) * 100,
    notEligible: (notEligible / total) * 100,
    errors: (phase1Errors / total) * 100,
  } : { submitted: 0, notEligible: 0, errors: 0 };
  const phase2Pct = phase2Total > 0 ? {
    approved: (approved / phase2Total) * 100,
    notApproved: (notApproved / phase2Total) * 100,
    errors: (phase2Errors / phase2Total) * 100,
  } : { approved: 0, notApproved: 0, errors: 0 };

  const renderMetric = (color: string, label: string, value: number) => (
    <div className="flex items-center gap-1.5" key={label}>
      <div className={`w-2 h-2 rounded-full ${color}`} />
      <span className="text-muted-foreground">{label}</span>
      <span className="font-semibold text-foreground">{value.toLocaleString()}</span>
    </div>
  );

  return (
    <div className="space-y-3">
      <div className={cn("rounded-xl border p-4 transition-all", currentPhase === 1 ? "border-border/70 bg-muted/5 shadow-sm" : "border-border bg-muted/10")}>
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">{phaseStatusIcon(phase1Status)}<span className="text-sm font-semibold">Consentimento</span></div>
          <span className="text-xs font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded-full">{phase1Processed.toLocaleString()} / {total.toLocaleString()} CPFs</span>
        </div>
        <div className="relative h-2.5 bg-muted rounded-full overflow-hidden mb-3">
          {phase1Pct.submitted > 0 && <div className="absolute left-0 top-0 h-full bg-emerald-500 transition-all duration-500" style={{ width: `${phase1Pct.submitted}%` }} />}
          {phase1Pct.notEligible > 0 && <div className="absolute top-0 h-full bg-amber-500 transition-all duration-500" style={{ left: `${phase1Pct.submitted}%`, width: `${phase1Pct.notEligible}%` }} />}
          {phase1Pct.errors > 0 && <div className="absolute top-0 h-full bg-destructive transition-all duration-500" style={{ left: `${phase1Pct.submitted + phase1Pct.notEligible}%`, width: `${phase1Pct.errors}%` }} />}
          {phase1Status === "Em andamento" && phase1Processed < total && <div className="absolute top-0 h-full bg-primary/20 animate-pulse" style={{ left: `${phase1Pct.submitted + phase1Pct.notEligible + phase1Pct.errors}%`, width: `${Math.min(8, 100 - phase1Pct.submitted - phase1Pct.notEligible - phase1Pct.errors)}%` }} />}
        </div>
        <div className="flex items-center gap-3 flex-wrap text-xs">
          {renderMetric("bg-emerald-500", "Enviados", submitted)}
          {renderMetric("bg-amber-500", "Não elegíveis", notEligible)}
          {renderMetric("bg-destructive", "Falhas", phase1Errors)}
          {total > phase1Processed && renderMetric("bg-slate-400", "Pendentes", total - phase1Processed)}
        </div>
      </div>

      <div className={cn("rounded-xl border p-4 transition-all", currentPhase === 2 ? "border-border/70 bg-muted/5 shadow-sm" : "border-border bg-muted/10")}>
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">{phaseStatusIcon(phase2Status)}<span className="text-sm font-semibold">Consulta e simulação</span></div>
          <span className="text-xs font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded-full">{phase2Processed.toLocaleString()} / {phase2Total.toLocaleString()} CPFs</span>
        </div>
        <div className="relative h-2.5 bg-muted rounded-full overflow-hidden mb-3">
          {phase2Pct.approved > 0 && <div className="absolute left-0 top-0 h-full bg-emerald-500 transition-all duration-500" style={{ width: `${phase2Pct.approved}%` }} />}
          {phase2Pct.notApproved > 0 && <div className="absolute top-0 h-full bg-amber-500 transition-all duration-500" style={{ left: `${phase2Pct.approved}%`, width: `${phase2Pct.notApproved}%` }} />}
          {phase2Pct.errors > 0 && <div className="absolute top-0 h-full bg-destructive transition-all duration-500" style={{ left: `${phase2Pct.approved + phase2Pct.notApproved}%`, width: `${phase2Pct.errors}%` }} />}
          {phase2Status === "Em andamento" && phase2Processed < phase2Total && <div className="absolute top-0 h-full bg-primary/20 animate-pulse" style={{ left: `${phase2Pct.approved + phase2Pct.notApproved + phase2Pct.errors}%`, width: `${Math.min(8, 100 - phase2Pct.approved - phase2Pct.notApproved - phase2Pct.errors)}%` }} />}
        </div>
        <div className="flex items-center gap-3 flex-wrap text-xs">
          {renderMetric("bg-emerald-500", "Aprovados", approved)}
          {renderMetric("bg-amber-500", "Não aprovados", notApproved)}
          {renderMetric("bg-destructive", "Falhas", phase2Errors)}
          {phase2Total > phase2Processed && renderMetric("bg-slate-400", "Pendentes", phase2Total - phase2Processed)}
        </div>
      </div>
    </div>
  );
}

export const V8HistoryTable = ({
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
  const [pausingId, setPausingId] = useState<number | null>(null);
  const [resumingId, setResumingId] = useState<number | null>(null);
  const [confirmJob, setConfirmJob] = useState<V8ConsultJobListItem | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [confirmDeleteJob, setConfirmDeleteJob] =
    useState<V8ConsultJobListItem | null>(null);

  const canDownloadFinal = (i: V8ConsultJobListItem) =>
    (i.status === "concluido" || i.status === "falhou" || i.status === "cancelado") &&
    Boolean(i.has_file ?? i.file_path);

  const canDownloadPreview = (i: V8ConsultJobListItem) =>
    (i.executor === "api" && (i.status === "pendente" || i.status === "em_progresso" || i.status === "pausado")) ||
    (i.executor !== "api" && (
      i.status === "pendente" ||
      i.status === "em_progresso" ||
      i.status === "pausado" ||
      (i.status === "cancelado" && (Boolean(i.spool_path) || Number(i.spool_bytes ?? 0) > 0))
    ));

  const canCancel = (i: V8ConsultJobListItem) =>
    i.status === "agendado" || i.status === "pendente" || i.status === "em_progresso" || i.status === "pausado";

  const canPause = (i: V8ConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso";

  const canResume = (i: V8ConsultJobListItem) =>
    i.status === "pausado";

  const isCancelStopPending = (i: V8ConsultJobListItem) =>
    i.status === "cancelado" && !i.finished_at;

  const canDelete = (i: V8ConsultJobListItem) =>
    !(i.status === "agendado" || i.status === "pendente" || i.status === "em_progresso" || i.status === "pausado" || isCancelStopPending(i));

  const openCancelDialog = (i: V8ConsultJobListItem) => {
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

  const executePause = async (i: V8ConsultJobListItem) => {
    if (!canPause(i) || pausingId !== null) return;
    try {
      setPausingId(i.id);
      await onPause(i.id);
    } finally {
      setPausingId(null);
    }
  };

  const executeResume = async (i: V8ConsultJobListItem) => {
    if (!canResume(i) || resumingId !== null) return;
    try {
      setResumingId(i.id);
      await onResume(i.id);
    } finally {
      setResumingId(null);
    }
  };

  const openDeleteDialog = (i: V8ConsultJobListItem) => {
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
          const statusInfo = getStatusInfo(i.status as V8JobStatus);
          const phaseInfo = i.phase && (i.status === "pendente" || i.status === "em_progresso" || i.status === "pausado")
            ? getPhaseInfo(i.phase)
            : null;
          const finalReady = canDownloadFinal(i);
          const previewReady = canDownloadPreview(i);
          const downloadDisabled = !finalReady && !previewReady;

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
                <div className="flex flex-col gap-2 sm:gap-3">
                  <div className="flex items-start justify-between">
                    <div className="flex-1 min-w-0">
                      <h3 className="font-semibold text-card-foreground truncate mb-1 text-base sm:text-lg">
                        {i.title}
                      </h3>
                      <div className="flex flex-wrap items-center gap-2 sm:gap-4 text-xs sm:text-sm text-muted-foreground">
                        <span>Criado em {formatDateTimeBR(i.created_at)}</span>
                        {i.scheduled_for && (
                          <span>Agendado para {formatDateTimeBR(i.scheduled_for)}</span>
                        )}
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
                        <DropdownMenuContent align="end" className="w-40">
                          {canPause(i) && (
                            <DropdownMenuItem
                              onClick={() => void executePause(i)}
                              className="text-amber-600 dark:text-amber-400"
                              disabled={pausingId === i.id}
                            >
                              {pausingId === i.id ? (
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                              ) : (
                                <Pause className="w-4 h-4 mr-2" />
                              )}
                              Pausar
                            </DropdownMenuItem>
                          )}
                          {canResume(i) && (
                            <DropdownMenuItem
                              onClick={() => void executeResume(i)}
                              className="text-emerald-600 dark:text-emerald-400"
                              disabled={resumingId === i.id}
                            >
                              {resumingId === i.id ? (
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                              ) : (
                                <Play className="w-4 h-4 mr-2" />
                              )}
                              Retomar
                            </DropdownMenuItem>
                          )}
                          {canCancel(i) && (
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
                              !canDelete(i)
                                ? "text-muted-foreground cursor-not-allowed"
                                : "text-destructive"
                            }
                            disabled={!canDelete(i)}
                          >
                            <Trash2 className="w-4 h-4 mr-2" />
                            {isCancelStopPending(i)
                              ? "Finalizando cancelamento"
                              : i.status === "pausado"
                                ? "Retome ou cancele para excluir"
                                : i.status === "agendado"
                                  ? "Cancele para excluir"
                                  : "Excluir"}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>

                    <div className="hidden sm:flex flex-wrap items-center justify-end gap-2 sm:gap-3 ml-4">
                      {phaseInfo && (
                        <Badge
                          className={cn(
                            "flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium pointer-events-none select-none",
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

                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Mais ações</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                          {canPause(i) && (
                            <DropdownMenuItem
                              onClick={() => void executePause(i)}
                              className="text-amber-600 dark:text-amber-400"
                              disabled={pausingId === i.id}
                            >
                              {pausingId === i.id ? (
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                              ) : (
                                <Pause className="w-4 h-4 mr-2" />
                              )}
                              Pausar
                            </DropdownMenuItem>
                          )}
                          {canResume(i) && (
                            <DropdownMenuItem
                              onClick={() => void executeResume(i)}
                              className="text-emerald-600 dark:text-emerald-400"
                              disabled={resumingId === i.id}
                            >
                              {resumingId === i.id ? (
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                              ) : (
                                <Play className="w-4 h-4 mr-2" />
                              )}
                              Retomar
                            </DropdownMenuItem>
                          )}
                          {canCancel(i) && (
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
                              !canDelete(i)
                                ? "text-muted-foreground cursor-not-allowed"
                                : "text-destructive"
                            }
                            disabled={!canDelete(i)}
                          >
                            <Trash2 className="w-4 h-4 mr-2" />
                            {isCancelStopPending(i)
                              ? "Finalizando cancelamento"
                              : i.status === "pausado"
                                ? "Retome ou cancele para excluir"
                                : i.status === "agendado"
                                  ? "Cancele para excluir"
                                  : "Excluir"}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>

                  <div className="sm:hidden flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      {phaseInfo && (
                        <Badge
                          className={cn(
                            "flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium pointer-events-none select-none",
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
                  </div>
                </div>
              </CardHeader>

              <CardContent className="pt-0">
                <div className="space-y-4">
                  {i.executor === "api" ? <ExternalMetrics item={i} /> : <SegmentedProgressBar item={i} />}

                  {i.executor !== "api" && (i.status === "concluido" ||
                    i.status === "em_progresso" ||
                    i.status === "pausado" ||
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
                          {(i.nao_elegivel_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">
                          Não elegível
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
            <p>{confirmJob?.status === "agendado" ? "Essa ação impedirá o início agendado:" : "Essa ação interromperá o processamento:"}</p>
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
