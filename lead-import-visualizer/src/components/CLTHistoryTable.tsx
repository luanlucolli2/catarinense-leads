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
  BarChart3,
  RefreshCw,
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
  onRerunPhase2: (id: number) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
  onViewHttpCounters?: (id: number) => void;
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

function getPhaseInfo(phase: CltConsultJobListItem["phase"]) {
  if (phase === "fase_1") {
    return {
      className:
        "bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:border-indigo-800",
      label: "Fase 1 • Consulta",
    };
  }
  if (phase === "fase_2") {
    return {
      className:
        "bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-900/20 dark:text-sky-300 dark:border-sky-800",
      label: "Fase 2 • Política de crédito",
    };
  }
  return null;
}

function calcSegments(i: CltConsultJobListItem) {
  const total = i.total_cpfs || 0;
  const eligible = Math.max(0, i.elegivel_count ?? 0);
  const ineligible = Math.max(0, i.inelegivel_count ?? 0);
  const notFound = Math.max(0, i.not_found_count ?? 0);
  const fail = Math.max(0, i.fail_count ?? 0);

  if (!total) {
    return {
      eligible,
      ineligible,
      notFound,
      fail,
      eligiblePct: 0,
      ineligiblePct: 0,
      notFoundPct: 0,
      failPct: 0,
      sum: 0,
      total: 0,
    };
  }

  const eligiblePct = (eligible / total) * 100;
  const ineligiblePct = (ineligible / total) * 100;
  const notFoundPct = (notFound / total) * 100;
  const failPct = (fail / total) * 100;

  return {
    eligible,
    ineligible,
    notFound,
    fail,
    eligiblePct,
    ineligiblePct,
    notFoundPct,
    failPct,
    sum: eligiblePct + ineligiblePct + notFoundPct + failPct,
    total,
  };
}

type CltVariant = "online" | "offline" | "hybrid" | undefined;
type OnlinePhaseStatus = "Aguardando" | "Em andamento" | "Concluído" | "Falhou" | "Cancelada";

function resolveVariant(item: CltConsultJobListItem): CltVariant {
  if (item.variant === "online" || item.variant === "offline" || item.variant === "hybrid") {
    return item.variant;
  }

  const legacyItem = item as CltConsultJobListItem & {
    is_offline?: boolean;
    mode?: string;
    tipo?: string;
    type?: string;
  };

  if (typeof legacyItem.is_offline === "boolean") {
    return legacyItem.is_offline ? "offline" : "online";
  }

  const legacy = String(legacyItem.mode ?? legacyItem.tipo ?? legacyItem.type ?? "").toLowerCase();
  if (legacy === "offline" || legacy === "off") return "offline";
  if (legacy === "hybrid" || legacy === "hyb") return "hybrid";
  if (legacy === "online" || legacy === "on") return "online";
  return undefined;
}

function isTwoPhaseVariant(variant: CltVariant): boolean {
  return variant !== "offline";
}

function getOnlinePhaseStatusIcon(status: OnlinePhaseStatus) {
  switch (status) {
    case "Concluído":
      return <CheckCircle className="w-4 h-4 text-emerald-500" />;
    case "Em andamento":
      return <Loader2 className="w-4 h-4 text-blue-500 animate-spin" />;
    case "Falhou":
      return <XCircle className="w-4 h-4 text-red-500" />;
    case "Cancelada":
      return <X className="w-4 h-4 text-gray-500" />;
    case "Aguardando":
    default:
      return <Clock className="w-4 h-4 text-muted-foreground" />;
  }
}

function resolveOnlinePhaseStatuses(
  item: CltConsultJobListItem,
  phase1Processed: number,
  phase2Total: number,
  phase2Resolved: number
): { phase1: OnlinePhaseStatus; phase2: OnlinePhaseStatus } {
  let phase1: OnlinePhaseStatus = "Aguardando";
  if (item.phase === "fase_1" && (item.status === "pendente" || item.status === "em_progresso")) {
    phase1 = "Em andamento";
  } else if (item.phase === "fase_2" || item.status === "concluido" || (item.total_cpfs > 0 && phase1Processed >= item.total_cpfs)) {
    phase1 = "Concluído";
  } else if (item.status === "falhou") {
    phase1 = (item.phase === "fase_1" || phase2Total <= 0) ? "Falhou" : "Concluído";
  } else if (item.status === "cancelado") {
    phase1 = (item.phase === "fase_1" || phase2Total <= 0) ? "Cancelada" : "Concluído";
  }

  let phase2: OnlinePhaseStatus = "Aguardando";
  if (phase2Total <= 0) {
    phase2 = "Aguardando";
  } else if (phase2Resolved >= phase2Total || item.status === "concluido") {
    phase2 = "Concluído";
  } else if (item.status === "falhou" && (item.phase === "fase_2" || phase2Resolved > 0)) {
    phase2 = "Falhou";
  } else if (item.status === "cancelado" && (item.phase === "fase_2" || phase2Resolved > 0)) {
    phase2 = "Cancelada";
  } else if (item.phase === "fase_2" && (item.status === "pendente" || item.status === "em_progresso")) {
    phase2 = "Em andamento";
  }

  return { phase1, phase2 };
}

function OnlineTwoPhaseProgress({ item }: { item: CltConsultJobListItem }) {
  const totalCpfs = Math.max(0, item.total_cpfs || 0);
  const phase1Eligible = Math.max(0, item.elegivel_count ?? 0);
  const phase1Ineligible = Math.max(0, item.inelegivel_count ?? 0);
  const phase1NotFound = Math.max(0, item.not_found_count ?? 0);
  const phase1Fail = Math.max(0, item.fail_count ?? 0);
  const phase1Processed = phase1Eligible + phase1Ineligible + phase1NotFound + phase1Fail;
  const phase1Pending = totalCpfs > 0 ? Math.max(0, totalCpfs - Math.min(totalCpfs, phase1Processed)) : 0;

  const phase1EligiblePct = totalCpfs > 0 ? (phase1Eligible / totalCpfs) * 100 : 0;
  const phase1IneligiblePct = totalCpfs > 0 ? (phase1Ineligible / totalCpfs) * 100 : 0;
  const phase1NotFoundPct = totalCpfs > 0 ? (phase1NotFound / totalCpfs) * 100 : 0;
  const phase1FailPct = totalCpfs > 0 ? (phase1Fail / totalCpfs) * 100 : 0;
  const phase1TotalPct = phase1EligiblePct + phase1IneligiblePct + phase1NotFoundPct + phase1FailPct;

  const phase2Total = Math.max(0, item.phase2_total ?? 0);
  const phase2Approved = Math.max(0, Math.min(phase2Total, item.phase2_aprovado_count ?? 0));
  const phase2NotApproved = Math.max(
    0,
    Math.min(
      Math.max(0, phase2Total - phase2Approved),
      item.phase2_nao_aprovado_count ?? 0
    )
  );
  const phase2Resolved = Math.max(0, Math.min(phase2Total, phase2Approved + phase2NotApproved));
  const phase2Pending = Math.max(0, phase2Total - phase2Resolved);

  const phase2ApprovedPct = phase2Total > 0 ? (phase2Approved / phase2Total) * 100 : 0;
  const phase2NotApprovedPct = phase2Total > 0 ? (phase2NotApproved / phase2Total) * 100 : 0;
  const phase2TotalPct = phase2ApprovedPct + phase2NotApprovedPct;

  const statuses = resolveOnlinePhaseStatuses(item, phase1Processed, phase2Total, phase2Resolved);
  const phase1Done = statuses.phase1 === "Concluído" || statuses.phase1 === "Falhou" || statuses.phase1 === "Cancelada";
  const currentPhase = phase1Done ? 2 : 1;

  return (
    <div className="space-y-3">
      <div
        className={cn(
          "rounded-xl border p-4 transition-all",
          currentPhase === 1 ? "border-border/70 bg-muted/5 shadow-sm" : "border-border bg-muted/10"
        )}
      >
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            {getOnlinePhaseStatusIcon(statuses.phase1)}
            <span className="text-sm font-semibold">Consulta</span>
          </div>
          <span className="text-xs font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded-full">
            {phase1Processed.toLocaleString()} / {totalCpfs.toLocaleString()} CPFs
          </span>
        </div>

        <div className="relative h-2.5 bg-muted rounded-full overflow-hidden mb-3">
          {phase1EligiblePct > 0 && (
            <div className="absolute left-0 top-0 h-full bg-emerald-500 transition-all duration-500" style={{ width: `${phase1EligiblePct}%` }} />
          )}
          {phase1IneligiblePct > 0 && (
            <div className="absolute top-0 h-full bg-slate-400 transition-all duration-500" style={{ left: `${phase1EligiblePct}%`, width: `${phase1IneligiblePct}%` }} />
          )}
          {phase1NotFoundPct > 0 && (
            <div className="absolute top-0 h-full bg-amber-500 transition-all duration-500" style={{ left: `${phase1EligiblePct + phase1IneligiblePct}%`, width: `${phase1NotFoundPct}%` }} />
          )}
          {phase1FailPct > 0 && (
            <div className="absolute top-0 h-full bg-destructive transition-all duration-500" style={{ left: `${phase1EligiblePct + phase1IneligiblePct + phase1NotFoundPct}%`, width: `${phase1FailPct}%` }} />
          )}
          {statuses.phase1 === "Em andamento" && phase1TotalPct < 100 && (
            <div className="absolute top-0 h-full bg-primary/20 animate-pulse" style={{ left: `${phase1TotalPct}%`, width: `${Math.min(8, 100 - phase1TotalPct)}%` }} />
          )}
        </div>

        <div className="flex items-center gap-3 flex-wrap text-xs">
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="text-muted-foreground">Elegíveis</span>
            <span className="font-semibold text-foreground">{phase1Eligible.toLocaleString()}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-slate-400" />
            <span className="text-muted-foreground">Inelegíveis</span>
            <span className="font-semibold text-foreground">{phase1Ineligible.toLocaleString()}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-amber-500" />
            <span className="text-muted-foreground">Não encontrados</span>
            <span className="font-semibold text-foreground">{phase1NotFound.toLocaleString()}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-destructive" />
            <span className="text-muted-foreground">Falhas</span>
            <span className="font-semibold text-foreground">{phase1Fail.toLocaleString()}</span>
          </div>
          {phase1Pending > 0 && (
            <div className="flex items-center gap-1.5">
              <Clock className="w-3 h-3 text-muted-foreground" />
              <span className="text-muted-foreground">Pendentes</span>
              <span className="font-semibold text-foreground">{phase1Pending.toLocaleString()}</span>
            </div>
          )}
        </div>
      </div>

      <div
        className={cn(
          "rounded-xl border p-4 transition-all",
          currentPhase === 2 ? "border-border/70 bg-muted/5 shadow-sm" : "border-border bg-muted/10"
        )}
      >
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            {getOnlinePhaseStatusIcon(statuses.phase2)}
            <span className="text-sm font-semibold">Validação de Política</span>
          </div>
          <span className="text-xs font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded-full">
            {phase2Resolved.toLocaleString()} / {phase2Total.toLocaleString()} CPFs
          </span>
        </div>

        <div className="relative h-2.5 bg-muted rounded-full overflow-hidden mb-3">
          {phase2ApprovedPct > 0 && (
            <div className="absolute left-0 top-0 h-full bg-emerald-500 transition-all duration-500" style={{ width: `${phase2ApprovedPct}%` }} />
          )}
          {phase2NotApprovedPct > 0 && (
            <div className="absolute top-0 h-full bg-slate-400 transition-all duration-500" style={{ left: `${phase2ApprovedPct}%`, width: `${phase2NotApprovedPct}%` }} />
          )}
          {statuses.phase2 === "Em andamento" && phase2TotalPct < 100 && (
            <div className="absolute top-0 h-full bg-primary/20 animate-pulse" style={{ left: `${phase2TotalPct}%`, width: `${Math.min(8, 100 - phase2TotalPct)}%` }} />
          )}
        </div>

        <div className="flex items-center gap-3 flex-wrap text-xs">
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="text-muted-foreground">Aprovados</span>
            <span className="font-semibold text-foreground">{phase2Approved.toLocaleString()}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-slate-400" />
            <span className="text-muted-foreground">Não aprovados</span>
            <span className="font-semibold text-foreground">{phase2NotApproved.toLocaleString()}</span>
          </div>
          {phase2Pending > 0 && (
            <div className="flex items-center gap-1.5">
              <Clock className="w-3 h-3 text-muted-foreground" />
              <span className="text-muted-foreground">Pendentes</span>
              <span className="font-semibold text-foreground">{phase2Pending.toLocaleString()}</span>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function SegmentedProgressBar({ item }: { item: CltConsultJobListItem }) {
  const s = calcSegments(item);
  const total = item.total_cpfs || 0;
  const processedConsult = s.eligible + s.ineligible + s.notFound + s.fail;
  const processedConsultLabel = processedConsult.toLocaleString();

  const isCounting =
    total === 0 &&
    (item.status === "pendente" || item.status === "em_progresso");

  const pulseWidthPct = Math.min(
    5,
    Math.max(item.total_cpfs ? (2 / item.total_cpfs) * 100 : 0, 0.8)
  );

  let offlineMessage = "Aguardando início das consultas.";
  if (item.status === "concluido") {
    offlineMessage = "Consultas concluídas.";
  } else if (item.status === "cancelado") {
    offlineMessage = "Consultas canceladas.";
  } else if (item.status === "falhou") {
    offlineMessage = "Consultas finalizadas com falhas.";
  } else if (item.status === "em_progresso" || item.status === "pendente") {
    offlineMessage =
      total > 0
        ? `Consultas em andamento (${processedConsultLabel}/${total.toLocaleString()} CPFs).`
        : "Consultas em andamento: preparando lote.";
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between text-xs sm:text-sm">
        <span className="text-muted-foreground">Progresso da consulta</span>
        <span className="font-medium text-card-foreground">
          {isCounting
            ? "Preparando/contando CPFs..."
            : `${processedConsultLabel} de ${total.toLocaleString()} CPFs`}
        </span>
      </div>

      <div
        className="relative h-3 bg-muted rounded-full overflow-hidden"
        aria-label="Barra de progresso de consultas"
      >
        {s.eligiblePct > 0 && (
          <div
            className="absolute left-0 top-0 h-full bg-emerald-500 dark:bg-emerald-400"
            style={{ width: `${s.eligiblePct}%` }}
          />
        )}
        {s.ineligiblePct > 0 && (
          <div
            className="absolute top-0 h-full bg-slate-400 dark:bg-slate-500"
            style={{ left: `${s.eligiblePct}%`, width: `${s.ineligiblePct}%` }}
          />
        )}
        {s.notFoundPct > 0 && (
          <div
            className="absolute top-0 h-full bg-amber-500 dark:bg-amber-400"
            style={{ left: `${s.eligiblePct + s.ineligiblePct}%`, width: `${s.notFoundPct}%` }}
          />
        )}
        {s.failPct > 0 && (
          <div
            className="absolute top-0 h-full bg-red-500 dark:bg-red-400"
            style={{ left: `${s.eligiblePct + s.ineligiblePct + s.notFoundPct}%`, width: `${s.failPct}%` }}
          />
        )}
        {(item.status === "em_progresso" || item.status === "pendente" || isCounting) && s.sum < 100 && (
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
        <span className="text-muted-foreground">{offlineMessage}</span>
        <span className="text-muted-foreground">
          {isCounting ? "Preparando..." : `${s.sum.toFixed(1)}%`}
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
  onRerunPhase2,
  onDelete,
  onViewHttpCounters,
  page,
  lastPage,
  onPageChange,
  formatDateTimeBR,
}: Props) => {
  const [cancelingId, setCancelingId] = useState<number | null>(null);
  const [confirmJob, setConfirmJob] = useState<CltConsultJobListItem | null>(null);
  const [rerunningId, setRerunningId] = useState<number | null>(null);
  const [confirmRerunJob, setConfirmRerunJob] = useState<CltConsultJobListItem | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [confirmDeleteJob, setConfirmDeleteJob] =
    useState<CltConsultJobListItem | null>(null);

  const canDownloadFinal = (i: CltConsultJobListItem) =>
    (i.status === "concluido" || i.status === "falhou" || i.status === "cancelado") &&
    Boolean((i.has_file ?? null) || (i.file_path ?? null));

  const canDownloadPreview = (i: CltConsultJobListItem) =>
    (i.status === "pendente" || i.status === "em_progresso" || i.status === "cancelado")
    && Number(i.spool_bytes ?? 0) > 0;

  const canCancel = (i: CltConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso";

  const canRerunPhase2 = (i: CltConsultJobListItem) => {
    const variant = resolveVariant(i);
    const hasFinal = Boolean((i.has_file ?? null) || (i.file_path ?? null));
    return isTwoPhaseVariant(variant) && i.status === "concluido" && hasFinal;
  };

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

  const openRerunDialog = (i: CltConsultJobListItem) => {
    if (!canRerunPhase2(i) || rerunningId !== null) return;
    setConfirmRerunJob(i);
  };

  const executeRerun = async () => {
    if (!confirmRerunJob) return;
    try {
      setRerunningId(confirmRerunJob.id);
      await onRerunPhase2(confirmRerunJob.id);
    } finally {
      setRerunningId(null);
      setConfirmRerunJob(null);
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
          const phaseInfo =
            i.phase && (i.status === "pendente" || i.status === "em_progresso")
              ? getPhaseInfo(i.phase)
              : null;
          const finalReady = canDownloadFinal(i);
          const previewReady = canDownloadPreview(i);
          const downloadDisabled = !finalReady && !previewReady;

          const variant = resolveVariant(i);
          const isTwoPhase = isTwoPhaseVariant(variant);
          const canViewHttpCounters = isTwoPhase && typeof onViewHttpCounters === "function";
          const phaseAndStatusInfo =
            isTwoPhase && phaseInfo
              ? {
                  icon: statusInfo.icon,
                  className: statusInfo.className,
                  label: `${phaseInfo.label} • ${statusInfo.label}`,
                }
              : statusInfo;

          const modeBadge = variant === "offline"
            ? {
                icon: <Database className="w-3.5 h-3.5" />,
                className:
                  "bg-gradient-to-r from-slate-100 to-stone-50 text-slate-700 border-slate-300 dark:from-slate-800/30 dark:to-stone-800/20 dark:text-slate-300 dark:border-slate-700 shadow-sm",
                label: "Base Offline",
              }
            : variant === "hybrid"
              ? {
                  icon: (
                    <span className="inline-flex items-center gap-0.5">
                      <Database className="w-3 h-3" />
                      <Wifi className="w-3 h-3" />
                    </span>
                  ),
                  className:
                    "bg-gradient-to-r from-amber-100 to-orange-50 text-amber-800 border-amber-300 dark:from-amber-900/30 dark:to-orange-800/20 dark:text-amber-300 dark:border-amber-700 shadow-sm",
                  label: "Híbrido",
                }
            : {
                icon: <Wifi className="w-3.5 h-3.5" />,
                className:
                  "bg-gradient-to-r from-emerald-100 to-teal-50 text-emerald-700 border-emerald-300 dark:from-emerald-900/30 dark:to-teal-800/20 dark:text-emerald-300 dark:border-emerald-700 shadow-sm",
                label: "Online",
              };

          // Destaque visual para os items em andamento
          const isActive = i.status === "em_progresso" || i.status === "pendente";

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
                        <DropdownMenuContent align="end" className="w-56">
                          {canViewHttpCounters && (
                            <DropdownMenuItem onClick={() => onViewHttpCounters?.(i.id)}>
                              <BarChart3 className="w-4 h-4 mr-2" />
                              Ver chamadas API
                            </DropdownMenuItem>
                          )}
                          {canRerunPhase2(i) && (
                            <DropdownMenuItem
                              onClick={() => openRerunDialog(i)}
                              className="text-blue-700 dark:text-blue-400"
                              disabled={rerunningId !== null}
                            >
                              {rerunningId === i.id ? (
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                              ) : (
                                <RefreshCw className="w-4 h-4 mr-2" />
                              )}
                              Rodar fase 2 novamente
                            </DropdownMenuItem>
                          )}
                          {canCancel(i) && (
                            <DropdownMenuItem
                              onClick={() => openCancelDialog(i)}
                              className="text-orange-600 dark:text-orange-400"
                            >
                              <X className="w-4 h-4 mr-2" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => openDeleteDialog(i)}
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

                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", phaseAndStatusInfo.className)}>
                        {phaseAndStatusInfo.icon}
                        <span className="whitespace-nowrap">{phaseAndStatusInfo.label}</span>
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
                        <DropdownMenuContent align="end" className="w-56">
                          {canViewHttpCounters && (
                            <DropdownMenuItem onClick={() => onViewHttpCounters?.(i.id)}>
                              <BarChart3 className="w-4 h-4 mr-2" />
                              Ver chamadas API
                            </DropdownMenuItem>
                          )}
                          {canRerunPhase2(i) && (
                            <DropdownMenuItem
                              onClick={() => openRerunDialog(i)}
                              className="text-blue-700 dark:text-blue-400"
                              disabled={rerunningId !== null}
                            >
                              {rerunningId === i.id ? (
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                              ) : (
                                <RefreshCw className="w-4 h-4 mr-2" />
                              )}
                              Rodar fase 2 novamente
                            </DropdownMenuItem>
                          )}
                          {canCancel(i) && (
                            <DropdownMenuItem
                              onClick={() => openCancelDialog(i)}
                              className="text-orange-600 dark:text-orange-400"
                            >
                              <X className="w-4 h-4 mr-2" />
                              Cancelar
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => openDeleteDialog(i)}
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

                      <Badge className={cn("flex items-center gap-1.5 px-2.5 py-1 text-xs", phaseAndStatusInfo.className)}>
                        {phaseAndStatusInfo.icon}
                        <span className="whitespace-nowrap">{phaseAndStatusInfo.label}</span>
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
                  {isTwoPhase ? (
                    <OnlineTwoPhaseProgress item={i} />
                  ) : (
                    <SegmentedProgressBar item={i} />
                  )}

                  {!isTwoPhase && (i.status === "concluido" ||
                    i.status === "em_progresso" ||
                    i.status === "cancelado" ||
                    i.status === "falhou") && (
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 pt-2 border-t border-border">
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-emerald-600 dark:text-emerald-400">
                          {(i.elegivel_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">Elegíveis</div>
                      </div>
                      <div className="text-center">
                        <div className="text-base sm:text-lg font-semibold text-slate-600 dark:text-slate-300">
                          {(i.inelegivel_count ?? 0).toLocaleString()}
                        </div>
                        <div className="text-[11px] sm:text-xs text-muted-foreground">
                          Inelegíveis
                        </div>
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

      {/* Confirmar RERUN FASE 2 */}
      <AlertDialog
        open={!!confirmRerunJob}
        onOpenChange={(isOpen) => !isOpen && setConfirmRerunJob(null)}
      >
        <AlertDialogContent className="sm:max-w-lg">
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-blue-700 dark:text-blue-400">
              Rodar fase 2 novamente?
            </AlertDialogTitle>
          </AlertDialogHeader>
          <div className="text-sm text-gray-700 dark:text-gray-200">
            <p>O resultado de política de crédito atual será recalculado.</p>
            {confirmRerunJob && (
              <p className="font-semibold my-2 bg-gray-100 dark:bg-neutral-800 p-2 rounded break-words">
                {confirmRerunJob.title} (#{confirmRerunJob.id})
              </p>
            )}
            <p>Deseja continuar?</p>
          </div>
          <AlertDialogFooter className="gap-2">
            <AlertDialogCancel disabled={rerunningId !== null} className="w-full sm:w-auto">
              Fechar
            </AlertDialogCancel>
            <AlertDialogAction
              className="w-full sm:w-auto bg-blue-600 hover:bg-blue-700"
              disabled={rerunningId !== null}
              onClick={(e) => {
                e.preventDefault();
                void executeRerun();
              }}
            >
              {rerunningId === confirmRerunJob?.id ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                "Sim, reprocessar"
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

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
