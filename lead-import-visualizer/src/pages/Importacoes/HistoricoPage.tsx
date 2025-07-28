// src/pages/Importacoes/HistoricoPage.tsx
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Table, TableHeader, TableHead, TableRow,
  TableBody, TableCell,
} from "@/components/ui/table";
import { RelatorioErrosModal } from "@/components/modals/RelatorioErrosModal";
import {
  listImportJobs, fetchImportErrors, exportImportErrorsCsv,
  rollbackImportJob,
  ImportJob, ImportError,
} from "@/api/importJobs";
import { format } from "date-fns";
import { ptBR } from "date-fns/locale";
import { FileText, Eye, RotateCcw, ShieldAlert, Loader2 } from "lucide-react"; // Ícones adicionados
import { toast } from "sonner";
import {
  AlertDialog, AlertDialogContent,
  AlertDialogHeader, AlertDialogTitle, AlertDialogFooter,
  AlertDialogAction, AlertDialogCancel,
} from "@/components/ui/alert-dialog";
import { cn } from "@/lib/utils"; // Import `cn` para classes condicionais

const HistoricoPage = () => {
  const queryClient = useQueryClient();
  const [selectedJob, setSelectedJob] = useState<ImportJob | null>(null);
  const [errors, setErrors] = useState<ImportError[]>([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [loadingErrors, setLoadingErrors] = useState(false);
  const [loadingRollback, setLoadingRollback] = useState<number | null>(null);
  const [confirmJob, setConfirmJob] = useState<ImportJob | null>(null);

  const { data: jobs = [], isLoading } = useQuery({
    queryKey: ["importJobs"],
    queryFn: listImportJobs,
    staleTime: 0,
    refetchOnWindowFocus: true,
  });

  /* ---------------- helpers ---------------- */
  const lastJobId = Math.max(...jobs.map(j => j.id), 0);

  const handleViewReport = async (job: ImportJob) => {
    if (job.status === "pendente" || job.status === "em_progresso") return;
    setLoadingErrors(true);
    setSelectedJob(job);
    try {
      const jobErrors = await fetchImportErrors(job.id);
      setErrors(jobErrors);
      setIsModalOpen(true);
    } catch {
      toast.error("Não foi possível carregar relatório de erros.");
    } finally {
      setLoadingErrors(false);
    }
  };

  const executeRollback = async (job: ImportJob) => {
    if (loadingRollback) return;
    setLoadingRollback(job.id);
    try {
      await rollbackImportJob(job.id);
      toast.success("Rollback da importação concluído com sucesso.");
      await queryClient.invalidateQueries({ queryKey: ["importJobs"] });
      setConfirmJob(null); // Fecha o modal SÓ no sucesso
    } catch (err: any) {
      toast.error(err.response?.data?.error || "Erro ao executar rollback");
    } finally {
      setLoadingRollback(null);
    }
  };

  const formatDateStr = (date: string | null) =>
    date ? format(new Date(date), "dd/MM/yyyy HH:mm", { locale: ptBR }) : "-";

  const getStatusBadge = (status: ImportJob["status"]) => {
    const map = {
      concluido: "bg-green-100 text-green-800",
      revertido: "bg-gray-200 text-gray-800",
      falhou: "bg-red-100 text-red-800",
      pendente: "bg-blue-100 text-blue-800",
      em_progresso: "bg-blue-100 text-blue-800 animate-pulse",
    } as const;
    const label = {
      concluido: "Concluído", revertido: "Revertido",
      em_progresso: "Em progresso", pendente: "Pendente", falhou: "Falhou",
    } as const;
    return <Badge variant="outline" className={cn("border-transparent", map[status])}>{label[status]}</Badge>;
  };

  const getTypeBadge = (type: ImportJob["type"]) => {
    const colors = {
      cadastral: "bg-purple-100 text-purple-800",
      higienizacao: "bg-orange-100 text-orange-800",
    } as const;
    return (
      <Badge variant="outline" className={cn("border-transparent", colors[type])}>
        {type === "cadastral" ? "Cadastral" : "Higienização"}
      </Badge>
    );
  };

  if (isLoading) return <div className="p-4">Carregando histórico...</div>;

  /* ---------------- render ---------------- */
  return (
    <div className="p-4 lg:p-6 w-full">
      {/* título */}
      <div className="mb-6">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900">
          Histórico de importações
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          {jobs.length} importações encontradas
        </p>
      </div>

      {/* tabela */}
      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
        {/* desktop */}
        <div className="hidden lg:block overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Arquivo</TableHead>
                <TableHead>Origem</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Erros</TableHead>
                <TableHead>Início</TableHead>
                <TableHead>Término</TableHead>
                <TableHead>Usuário</TableHead>
                <TableHead>Ações</TableHead>
              </TableRow>
            </TableHeader>

            <TableBody>
              {jobs.map(job => {
                const isLast = job.id === lastJobId;
                const rollbackDisabled = job.status !== "concluido" || job.rolledBackAt !== null;

                return (
                  <TableRow key={job.id} className="hover:bg-gray-50">
                    <TableCell className="truncate">{job.fileName}</TableCell>
                    <TableCell className="truncate">{job.origin}</TableCell>
                    <TableCell>{getTypeBadge(job.type)}</TableCell>
                    <TableCell>{getStatusBadge(job.status)}</TableCell>
                    <TableCell className="font-semibold">
                      <span className={job.errorsCount > 0 ? "text-red-600" : "text-gray-500"}>
                        {job.errorsCount}
                      </span>
                    </TableCell>
                    <TableCell className="text-sm text-gray-600">{formatDateStr(job.startedAt)}</TableCell>
                    <TableCell className="text-sm text-gray-600">{formatDateStr(job.finishedAt)}</TableCell>
                    <TableCell className="text-sm">{job.user.name}</TableCell>
                    <TableCell className="flex gap-2">
                      <Button variant="outline" size="sm" onClick={() => handleViewReport(job)}>
                        <Eye className="w-4 h-4" />
                        <span className="hidden xl:inline ml-1">Ver</span>
                      </Button>
                      {isLast && (
                        <Button
                          variant="destructive" size="sm"
                          disabled={rollbackDisabled || loadingRollback === job.id}
                          onClick={() => setConfirmJob(job)}
                        >
                          <RotateCcw className="w-4 h-4" />
                          <span className="hidden xl:inline ml-1">Desfazer</span>
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>

        {/* mobile */}
        <div className="lg:hidden space-y-4 p-4">
          {jobs.map(job => {
            const isLast = job.id === lastJobId;
            const rollbackDisabled = job.status !== "concluido" || job.rolledBackAt !== null;

            return (
              <div key={job.id} className="bg-white border rounded-lg p-4 space-y-3">
                <div className="flex justify-between items-start">
                  <h3 className="font-medium truncate pr-4">{job.fileName}</h3>
                  <div className="flex gap-2 flex-shrink-0">
                    <Button size="sm" variant="outline" onClick={() => handleViewReport(job)} disabled={job.errorsCount === 0 || loadingErrors}>
                      <Eye className="w-4 h-4" />
                    </Button>
                    {isLast && (
                      <Button size="sm" variant="destructive" disabled={rollbackDisabled || loadingRollback === job.id} onClick={() => setConfirmJob(job)}>
                        <RotateCcw className="w-4 h-4" />
                      </Button>
                    )}
                  </div>
                </div>
                <div className="flex items-center flex-wrap gap-2">
                  {getTypeBadge(job.type)}
                  {getStatusBadge(job.status)}
                  <span className="text-sm text-gray-600">
                    {job.errorsCount} {job.errorsCount === 1 ? "erro" : "erros"}
                  </span>
                </div>
                <div className="text-xs text-gray-500 border-t pt-2 mt-2">
                  <p>Início: {formatDateStr(job.startedAt)}</p>
                  <p>Término: {formatDateStr(job.finishedAt)}</p>
                  <p>Por: <strong>{job.user.name}</strong></p>
                </div>
              </div>
            );
          })}
        </div>

        {jobs.length === 0 && !isLoading && (
          <div className="text-center py-12 text-gray-500">
            <FileText className="w-12 h-12 mx-auto mb-4 text-gray-300" />
            <p>Nenhuma importação encontrada</p>
          </div>
        )}
      </div>

      {/* ===== MODAL DE RELATÓRIO DE ERROS (SEM ALTERAÇÃO) ===== */}
      <RelatorioErrosModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        job={selectedJob}
        errors={errors}
        onExportCsv={() => selectedJob && exportImportErrorsCsv(selectedJob.id)}
      />

      {/* ===== MODAL DE CONFIRMAÇÃO DE ROLLBACK (CENTRALIZADO E ESTILIZADO) ===== */}
      <AlertDialog open={!!confirmJob} onOpenChange={(isOpen) => !isOpen && setConfirmJob(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-red-600">
              <ShieldAlert className="h-6 w-6" />
              Desfazer Importação?
            </AlertDialogTitle>
          </AlertDialogHeader>
          <div className="text-sm text-gray-700">
            <p>
              Esta ação é irreversível e irá remover todos os registros criados pela importação do arquivo:
            </p>
            <p className="font-semibold my-2 bg-gray-100 p-2 rounded">
              {confirmJob?.fileName}
            </p>
            <p>Deseja realmente continuar?</p>
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={loadingRollback !== null}>
              Cancelar
            </AlertDialogCancel>
            <AlertDialogAction
              className="bg-red-600 hover:bg-red-700"
              disabled={loadingRollback !== null}
              onClick={(e) => {
                e.preventDefault(); // Previne o fechamento automático do modal
                if (confirmJob) executeRollback(confirmJob);
              }}
            >
              {loadingRollback === confirmJob?.id ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                "Sim, desfazer importação"
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default HistoricoPage;