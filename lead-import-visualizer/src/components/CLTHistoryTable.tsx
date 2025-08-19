// src/components/CLTHistoryTable.tsx
import { useState } from "react";
import {
  Download,
  Loader2,
  ChevronLeft,
  ChevronRight,
  XCircle,
  ShieldAlert,
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

type Props = {
  items: CltConsultJobListItem[];
  loading?: boolean;
  onDownload: (id: number) => void;
  onCancel: (id: number) => Promise<void>;
  onRefresh?: () => void; // mantido por compatibilidade, não utilizado aqui

  // paginação
  page: number;
  lastPage: number;
  onPageChange: (p: number) => void;

  // util de formatação
  formatDateTimeBR: (iso?: string | null) => string;
};

function StatusBadge({ status }: { status: CltConsultJobListItem["status"] }) {
  switch (status) {
    case "concluido":
      return (
        <Badge className="bg-green-100 text-green-800 border-green-200">
          Concluído
        </Badge>
      )
    case "em_progresso":
      return (
        <Badge className="inline-flex items-center justify-center gap-1.5 bg-blue-100 text-blue-800 border-blue-200 whitespace-nowrap text-center">
          <Loader2 className="w-3 h-3 animate-spin shrink-0" />
          <span className="leading-none">Em andamento</span>
        </Badge>
      )
    case "falhou":
      return (
        <Badge className="bg-red-100 text-red-800 border-red-200">
          Falhou
        </Badge>
      )
    case "cancelado":
      return (
        <Badge className="bg-gray-100 text-gray-800 border-gray-200">
          Cancelado
        </Badge>
      )
    case "pendente":
    default:
      return <Badge variant="secondary">Pendente</Badge>
  }
}


export const CLTHistoryTable = ({
  items,
  loading,
  onDownload,
  onCancel,
  page,
  lastPage,
  onPageChange,
  formatDateTimeBR,
}: Props) => {
  const [cancelingId, setCancelingId] = useState<number | null>(null);
  const [confirmJob, setConfirmJob] = useState<CltConsultJobListItem | null>(
    null
  );

  const handlePrev = () => onPageChange(Math.max(1, page - 1));
  const handleNext = () => onPageChange(Math.min(lastPage || 1, page + 1));

  const canDownload = (i: CltConsultJobListItem) =>
    i.status === "concluido" && Boolean(i.file_path);

  const canCancel = (i: CltConsultJobListItem) =>
    i.status === "pendente" || i.status === "em_progresso";

  const openCancelDialog = (i: CltConsultJobListItem) => {
    if (!canCancel(i) || cancelingId !== null) return;
    setConfirmJob(i);
  };

  const executeCancel = async () => {
    if (!confirmJob) return;
    try {
      setCancelingId(confirmJob.id);
      await onCancel(confirmJob.id); // sem motivo
    } finally {
      setCancelingId(null);
      setConfirmJob(null);
    }
  };

  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      {/* Top bar somente com o contador (sem botão de atualizar e sem paginação) */}
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
              <TableHead className="text-center">Falhas</TableHead>
              <TableHead className="text-center">Ações</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center py-8 text-gray-500">
                  <div className="flex items-center gap-2 justify-center">
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Carregando...
                  </div>
                </TableCell>
              </TableRow>
            ) : items.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center py-8 text-gray-500">
                  Nenhuma consulta encontrada
                </TableCell>
              </TableRow>
            ) : (
              items.map((i) => (
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
                  <TableCell className="text-center text-red-600 font-medium">
                    {i.fail_count.toLocaleString()}
                  </TableCell>
                  <TableCell className="text-center">
                    <div className="flex items-center justify-center gap-2">
                      <Button
                        onClick={() => openCancelDialog(i)}
                        disabled={!canCancel(i) || cancelingId === i.id}
                        variant="destructive"
                        size="sm"
                        className={cn(
                          "flex items-center gap-2 px-3",
                          !canCancel(i) && "opacity-50 cursor-not-allowed"
                        )}
                        title={canCancel(i) ? "Cancelar consulta" : undefined}
                      >
                        {cancelingId === i.id ? (
                          <Loader2 className="w-4 h-4 animate-spin" />
                        ) : (
                          <XCircle className="w-4 h-4" />
                        )}
                        Cancelar
                      </Button>

                      <Button
                        onClick={() => onDownload(i.id)}
                        disabled={!canDownload(i)}
                        variant="outline"
                        size="sm"
                        className={cn(
                          "flex items-center gap-2 px-3",
                          canDownload(i)
                            ? "border-blue-300 text-blue-700 hover:bg-blue-50"
                            : "opacity-50 cursor-not-allowed"
                        )}
                      >
                        <Download className="w-4 h-4" />
                        Baixar planilha
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* Paginação no rodapé (estilo LeadsTable) */}
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

      {/* ===== MODAL DE CONFIRMAÇÃO DE CANCELAMENTO ===== */}
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
                e.preventDefault(); // evita fechar automaticamente
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
    </div>
  );
};
