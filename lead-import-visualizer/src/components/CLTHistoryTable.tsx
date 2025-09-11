// src/components/CLTHistoryTable.tsx
import { useState } from "react";
import {
  Download,
  Loader2,
  ChevronLeft,
  ChevronRight,
  MoreVertical,
  Play,
  Pause,
  X,
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
import { CltConsultJobListItem } from "@/api/clt";
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

type Props = {
  items: CltConsultJobListItem[];
  loading?: boolean;

  onDownload: (id: number, opts?: { preview?: boolean }) => void;
  onPause: (id: number) => Promise<void>;
  onResume: (id: number) => Promise<void>;
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

function StatusBadge({ status }: { status: CltConsultJobListItem["status"] }) {
  switch (status) {
    case "concluido":
      return (
        <Badge className="bg-green-100 text-green-800 border-green-200">
          Concluído
        </Badge>
      );
    case "em_progresso":
      return (
        <Badge className="inline-flex items-center justify-center gap-1.5 bg-blue-100 text-blue-800 border-blue-200 whitespace-nowrap text-center">
          <Loader2 className="w-3 h-3 animate-spin shrink-0" />
          <span className="leading-none">Em andamento</span>
        </Badge>
      );
    case "pausado":
      return (
        <Badge className="bg-yellow-100 text-yellow-800 border-yellow-200">
          Pausado
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

export const CLTHistoryTable = ({
  items,
  loading,
  onDownload,
  onPause,
  onResume,
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
  const [confirmDeleteJob, setConfirmDeleteJob] = useState<CltConsultJobListItem | null>(null);

  const [pausingId, setPausingId] = useState<number | null>(null);
  const [resumingId, setResumingId] = useState<number | null>(null);

  const handlePrev = () => onPageChange(Math.max(1, page - 1));
  const handleNext = () => onPageChange(Math.min(lastPage || 1, page + 1));

  const canDownloadFinal = (i: CltConsultJobListItem) =>
    i.status === "concluido" && Boolean(i.file_path);

  // ✅ PRÉVIA é on-demand: habilita enquanto processa ou pausado, independentemente de preview_path
  const canDownloadPreview = (i: CltConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso" || i.status === "pausado";

  const canCancel = (i: CltConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso" || i.status === "pausado";

  // pode excluir quando NÃO está em processamento (inclui pausado)
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

  const doPause = async (i: CltConsultJobListItem) => {
    if (pausingId !== null) return;
    try {
      setPausingId(i.id);
      await onPause(i.id);
    } finally {
      setPausingId(null);
    }
  };

  const doResume = async (i: CltConsultJobListItem) => {
    if (resumingId !== null) return;
    try {
      setResumingId(i.id);
      await onResume(i.id);
    } finally {
      setResumingId(null);
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
              <TableHead className="text-center">Sucesso</TableHead>
              <TableHead className="text-center">Não encontrado</TableHead>
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
                const showDownload = i.status !== "cancelado" && (finalReady || previewReady);
                const downloadDisabled = !finalReady && !previewReady;

                const isPausing = pausingId === i.id;
                const isResuming = resumingId === i.id;

                return (
                  <TableRow key={i.id} className="hover:bg-gray-50">
                    <TableCell className="font-medium">{i.title}</TableCell>
                    <TableCell className="text-gray-600">
                      {formatDateTimeBR(i.created_at)}
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
                      {(i.not_found_count ?? 0).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-center text-red-600 font-medium">
                      {i.fail_count.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-center">
                      <div className="flex items-center justify-center gap-2">
                        {/* === Botão de Download (final ou prévia), como no protótipo === */}
                        {showDownload && (
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
                                  ? "Baixar planilha (prévia)"
                                  : "Baixar indisponível"
                            }
                          >
                            <Download className="w-4 h-4" />
                            {i.status === "em_progresso" && <span className="ml-1">Prévia</span>}
                            {i.status === "pausado" && <span className="ml-1">Prévia</span>}
                          </Button>
                        )}

                        {/* === Dropdown de Ações (Pausar/Retomar/Cancelar/Excluir) === */}
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 p-0 outline-none focus:outline-none focus-visible:outline-none ring-0 focus:ring-0 focus-visible:ring-0 focus-visible:ring-offset-0 data-[state=open]:bg-transparent"
                              aria-label="Mais ações"
                            >
                              <MoreVertical className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            {i.status === "em_progresso" && (
                              <DropdownMenuItem
                                onClick={() => void doPause(i)}
                                className={cn(
                                  "text-yellow-600",
                                  isPausing && "opacity-60 pointer-events-none"
                                )}
                              >
                                {isPausing ? (
                                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                                ) : (
                                  <Pause className="w-4 h-4 mr-2" />
                                )}
                                Pausar
                              </DropdownMenuItem>
                            )}

                            {i.status === "pausado" && (
                              <DropdownMenuItem
                                onClick={() => void doResume(i)}
                                className={cn(
                                  "text-blue-600",
                                  isResuming && "opacity-60 pointer-events-none"
                                )}
                              >
                                {isResuming ? (
                                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                                ) : (
                                  <Play className="w-4 h-4 mr-2" />
                                )}
                                Retomar
                              </DropdownMenuItem>
                            )}

                            {(i.status === "em_progresso" || i.status === "pausado" || i.status === "pendente") && (
                              <DropdownMenuItem
                                onClick={() => openCancelDialog(i)}
                                className={cn(
                                  "text-orange-600",
                                  cancelingId === i.id && "opacity-60 pointer-events-none"
                                )}
                              >
                                {cancelingId === i.id ? (
                                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                                ) : (
                                  <X className="w-4 h-4 mr-2" />
                                )}
                                Cancelar
                              </DropdownMenuItem>
                            )}

                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() => openDeleteDialog(i)}
                              className={i.status === "em_progresso" ? "text-gray-400 cursor-not-allowed" : "text-red-600"}
                              disabled={i.status === "em_progresso"}
                            >
                              <Trash2 className="w-4 h-4 mr-2" />
                              Excluir
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
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
              Excluir definitivamente?
            </AlertDialogTitle>
          </AlertDialogHeader>

          <div className="text-sm text-gray-700">
            <p>Essa ação irá remover o registro e os arquivos vinculados (planilha final e prévia, se houver):</p>
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
