// src/components/FgtsOffHistoryTable.tsx
import { useState } from "react";
import {
  Download,
  Loader2,
  ChevronLeft,
  ChevronRight,
  XCircle,
  ShieldAlert,
  Trash2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { FgtsOffConsultJobListItem, FgtsOffJobStatus } from "@/api/fgtsOff";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

type Props = {
  items: FgtsOffConsultJobListItem[];
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

function StatusBadge({ status }: { status: FgtsOffJobStatus }) {
  switch (status) {
    case "concluido":
      return (
        <Badge className="bg-green-100 text-green-800 border-green-200">
          Concluído
        </Badge>
      );
    case "expirado":
      return (
        <Badge className="bg-amber-100 text-amber-800 border-amber-200">
          Expirado
        </Badge>
      );
    case "agendado":
      return <Badge className="bg-indigo-100 text-indigo-800 border-indigo-200">Agendado</Badge>;
    case "em_progresso":
      return (
        <Badge className="inline-flex items-center justify-center gap-1.5 bg-blue-100 text-blue-800 border-blue-200 whitespace-nowrap text-center">
          <Loader2 className="w-3 h-3 animate-spin shrink-0" />
          <span className="leading-none">Em andamento</span>
        </Badge>
      );
    case "falhou":
      return (
        <Badge className="bg-red-100 text-red-800 border-red-200">
          Falhou
        </Badge>
      );
    case "cancelado":
      return (
        <Badge className="bg-gray-100 text-gray-800 border-gray-200">
          Cancelado
        </Badge>
      );
    case "pendente":
    default:
      return <Badge variant="secondary">Pendente</Badge>;
  }
}

export const FgtsOffHistoryTable = ({
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
  const [confirmJob, setConfirmJob] = useState<FgtsOffConsultJobListItem | null>(null);

  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [confirmDeleteJob, setConfirmDeleteJob] = useState<FgtsOffConsultJobListItem | null>(null);

  const handlePrev = () => onPageChange(Math.max(1, page - 1));
  const handleNext = () => onPageChange(Math.min(lastPage || 1, page + 1));

  // FINAL disponível também em 'falhou' agora, desde que haja file_path
  const canDownloadFinal = (i: FgtsOffConsultJobListItem) =>
    (i.status === "concluido" || i.status === "expirado" || i.status === "falhou") && Boolean(i.file_path);

  // PRÉVIA é gerada sob demanda (spool + pendentes), então habilitamos em pendente/em_progresso
  const canDownloadPreview = (i: FgtsOffConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso";

  const canCancel = (i: FgtsOffConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso" || i.status === "agendado";

  const canDelete = (i: FgtsOffConsultJobListItem) =>
    !(i.status === "pendente" || i.status === "em_progresso" || i.status === "agendado");

  const openCancelDialog = (i: FgtsOffConsultJobListItem) => {
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

  const openDeleteDialog = (i: FgtsOffConsultJobListItem) => {
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
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="px-4 py-3">
        <div className="text-sm text-gray-600">
          {loading ? "Carregando..." : `${items.length} itens na página`}
        </div>
      </div>

      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="text-left">Título</TableHead>
              <TableHead className="text-left">Criado em</TableHead>
              <TableHead className="text-center">Status</TableHead>
              <TableHead className="text-center">Total de CPFs</TableHead>
              <TableHead className="text-center">Autorizado</TableHead>
              <TableHead className="text-center">Não autorizado</TableHead>
              <TableHead className="text-center">Falhas</TableHead>
              <TableHead className="text-center">Ações</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={8} className="text-center py-8 text-gray-500">
                  <div className="flex items-center gap-2 justify-center">
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Carregando...
                  </div>
                </TableCell>
              </TableRow>
            ) : items.length === 0 ? (
              <TableRow>
                <TableCell colSpan={8} className="text-center py-8 text-gray-500">
                  Nenhuma consulta encontrada
                </TableCell>
              </TableRow>
            ) : (
              items.map((i) => {
                const finalReady = canDownloadFinal(i);
                const previewReady = canDownloadPreview(i);
                const downloadDisabled = !finalReady && !previewReady;

                return (
                  <TableRow key={i.id} className="hover:bg-gray-50">
                    <TableCell className="font-medium">{i.title}</TableCell>

                    {/* Criado em + (se agendado) janela de agendamento */}
                    <TableCell className="text-gray-600">
                      <div>{formatDateTimeBR(i.created_at)}</div>
                      {i.status === "agendado" && i.scheduled_for && (
                        <div className="text-xs text-indigo-800 mt-0.5">
                          Agendado: {formatDateTimeBR(i.scheduled_for)}
                          {i.scheduled_until
                            ? ` – ${formatDateTimeBR(i.scheduled_until)}`
                            : ""}
                        </div>
                      )}
                    </TableCell>

                    <TableCell className="text-center">
                      <StatusBadge status={i.status} />
                    </TableCell>
                    <TableCell className="text-center font-medium">
                      {i.total_cpfs.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-center text-green-600 font-medium">
                      {i.success_count.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-center text-amber-600 font-medium">
                      {(i.not_authorized_count ?? 0).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-center text-red-600 font-medium">
                      {i.fail_count.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-center">
                      <div className="flex items-center justify-center gap-2">
                        {/* Cancelar */}
                        <Button
                          onClick={() => openCancelDialog(i)}
                          disabled={!canCancel(i) || cancelingId === i.id}
                          variant="outline"
                          size="icon"
                          className={cn(
                            "border-red-300 text-red-600 hover:bg-red-50",
                            (!canCancel(i) || cancelingId === i.id) && "opacity-50 cursor-not-allowed"
                          )}
                          title={canCancel(i) ? "Cancelar consulta" : "Cancelar indisponível"}
                        >
                          {cancelingId === i.id ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                          ) : (
                            <XCircle className="w-4 h-4" />
                          )}
                        </Button>

                        {/* Download (final ou prévia sob demanda) */}
                        <Button
                          onClick={() => onDownload(i.id, { preview: !finalReady && previewReady })}
                          disabled={downloadDisabled}
                          variant="outline"
                          size="sm"
                          className={cn(
                            "flex items-center gap-2 px-3",
                            !downloadDisabled
                              ? "border-blue-300 text-blue-700 hover:bg-blue-50"
                              : "opacity-50 cursor-not-allowed"
                          )}
                          title={
                            finalReady
                              ? "Baixar planilha final"
                              : previewReady
                                ? "Gerar & baixar prévia"
                                : "Baixar indisponível"
                          }
                        >
                          <Download className="w-4 h-4" />
                          {finalReady ? "Baixar planilha" : previewReady ? "Baixar planilha (prévia)" : "Baixar planilha"}
                        </Button>

                        {/* Excluir */}
                        <Button
                          onClick={() => openDeleteDialog(i)}
                          disabled={!canDelete(i) || deletingId === i.id}
                          variant="destructive"
                          size="icon"
                          className={cn(
                            "bg-red-600 hover:bg-red-700 text-white",
                            (!canDelete(i) || deletingId === i.id) && "opacity-50 cursor-not-allowed"
                          )}
                          title={canDelete(i) ? "Excluir definitivamente" : "Excluir indisponível enquanto processa"}
                        >
                          {deletingId === i.id ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                          ) : (
                            <Trash2 className="w-4 h-4" />
                          )}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>
      </div>

      {/* Paginação */}
      <div className="bg-white px-4 lg:px-6 py-3 border-t border-gray-200 flex items-center justify-between">
        <div className="text-sm text-gray-500">
          Página {page} de {lastPage || 1}
        </div>
        <div className="flex items-center space-x-2">
          <Button
            onClick={handlePrev}
            disabled={page <= 1 || !!loading}
            variant="outline"
            size="sm"
          >
            <ChevronLeft className="w-4 h-4" />
            <span className="sr-only">Anterior</span>
          </Button>
          <Button
            onClick={handleNext}
            disabled={page >= (lastPage || 1) || !!loading}
            variant="outline"
            size="sm"
          >
            <ChevronRight className="w-4 h-4" />
            <span className="sr-only">Próxima</span>
          </Button>
        </div>
      </div>

      {/* ===== MODAL: Confirmar CANCELAMENTO ===== */}
      <AlertDialog
        open={!!confirmJob}
        onOpenChange={(isOpen) => !isOpen && setConfirmJob(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-red-600">
              <ShieldAlert className="h-6 w-6" />
              Cancelar consulta?
            </AlertDialogTitle>
          </AlertDialogHeader>

          <div className="text-sm text-gray-700">
            <p>Essa ação irá interromper o processamento da consulta:</p>
            {confirmJob && (
              <p className="font-semibold my-2 bg-gray-100 p-2 rounded">
                {confirmJob.title} (#{confirmJob.id})
              </p>
            )}
            <p>Deseja realmente continuar?</p>
          </div>

          <AlertDialogFooter>
            <AlertDialogCancel disabled={cancelingId !== null}>
              Fechar
            </AlertDialogCancel>
            <AlertDialogAction
              className="bg-red-600 hover:bg-red-700"
              disabled={cancelingId !== null}
              onClick={(e) => {
                e.preventDefault();
                void executeCancel();
              }}
            >
              {cancelingId === confirmJob?.id ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                "Sim, cancelar consulta"
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* ===== MODAL: Confirmar EXCLUSÃO ===== */}
      <AlertDialog
        open={!!confirmDeleteJob}
        onOpenChange={(isOpen) => !isOpen && setConfirmDeleteJob(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-red-600">
              <Trash2 className="h-6 w-6" />
              Excluir definitivamente?
            </AlertDialogTitle>
          </AlertDialogHeader>

          <div className="text-sm text-gray-700">
            <p>Essa ação irá remover o registro e os arquivos vinculados (planilha final, prévia e spool, se houver):</p>
            {confirmDeleteJob && (
              <p className="font-semibold my-2 bg-gray-100 p-2 rounded">
                {confirmDeleteJob.title} (#{confirmDeleteJob.id})
              </p>
            )}
            <p>Essa operação não pode ser desfeita.</p>
          </div>

          <AlertDialogFooter>
            <AlertDialogCancel disabled={deletingId !== null}>
              Fechar
            </AlertDialogCancel>
            <AlertDialogAction
              className="bg-red-600 hover:bg-red-700"
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
