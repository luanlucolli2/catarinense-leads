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
import { FileText, Eye, RotateCcw } from "lucide-react";
import { toast } from "sonner";
import {
  AlertDialog, AlertDialogTrigger, AlertDialogContent,
  AlertDialogHeader, AlertDialogTitle, AlertDialogFooter,
  AlertDialogAction, AlertDialogCancel,
} from "@/components/ui/alert-dialog";

const HistoricoPage = () => {
  const queryClient = useQueryClient();
  const [selectedJob, setSelectedJob] = useState<ImportJob | null>(null);
  const [errors, setErrors] = useState<ImportError[]>([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [loadingErrors, setLoadingErrors] = useState(false);
  const [loadingRollback, setLoadingRollback] = useState<number | null>(null);
  const [confirmJob, setConfirmJob] = useState<ImportJob | null>(null);   // 🔔

  const { data: jobs = [], isLoading } = useQuery({
    queryKey: ["importJobs"],
    queryFn: listImportJobs,
    staleTime: 0,               // ✅ sempre stale → refetch on mount
    refetchOnWindowFocus: true, // opcional: também refetch quando volta à aba
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
      toast.success("Rollback iniciado com sucesso.");
      await queryClient.invalidateQueries({ queryKey: ["importJobs"] });
    } catch (err: any) {
      toast.error(err.response?.data?.error || "Erro ao iniciar rollback");
    } finally {
      setLoadingRollback(null);
      setConfirmJob(null);
    }
  };

  const formatDateStr = (date: string | null) =>
    date ? format(new Date(date), "dd/MM/yyyy HH:mm", { locale: ptBR }) : "-";

  const getStatusBadge = (status: ImportJob["status"]) => {
    const map = {
      concluido: ["outline", "bg-green-100 text-green-800 hover:opacity-100"],
      revertido: ["secondary", "bg-gray-100  text-gray-800  hover:opacity-100"],
      falhou: ["destructive", "bg-red-100   text-red-800  hover:opacity-100"],
      pendente: ["secondary", "bg-blue-100  text-blue-800 hover:opacity-100"],
      em_progresso: ["secondary", "bg-blue-100  text-blue-800 hover:opacity-100"],
    } as const;
    const [variant, color] = map[status];
    const label =
      status === "concluido" ? "Concluído" :
        status === "revertido" ? "Revertido" :
          status === "em_progresso" ? "Em progresso" :
            status === "pendente" ? "Pendente" :
              "Falhou";
    return (
      <Badge variant={variant as any} className={color}>
        {label}
      </Badge>
    );
  };

  const getTypeBadge = (type: ImportJob["type"]) => {
    const colors = {
      cadastral: "bg-purple-100 text-purple-800",
      higienizacao: "bg-orange-100 text-orange-800",
    } as const;
    return (
      <Badge variant="outline" className={colors[type]}>
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
                <TableHead className="px-3 xl:px-6 py-3">Arquivo</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Origem</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Tipo</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Status</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Erros</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Início</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Término</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Usuário</TableHead>
                <TableHead className="px-3 xl:px-6 py-3">Ações</TableHead>
              </TableRow>
            </TableHeader>

            <TableBody>
              {jobs.map(job => {
                const isLast = job.id === lastJobId;
                const rollbackDisabled =
                  job.status !== "concluido" || job.rolledBackAt !== null;

                return (
                  <TableRow key={job.id} className="hover:bg-gray-50">
                    <TableCell className="px-3 xl:px-6 py-4 truncate">{job.fileName}</TableCell>
                    <TableCell className="px-3 xl:px-6 py-4 truncate">{job.origin}</TableCell>
                    <TableCell className="px-3 xl:px-6 py-4">{getTypeBadge(job.type)}</TableCell>
                    <TableCell className="px-3 xl:px-6 py-4">{getStatusBadge(job.status)}</TableCell>
                    <TableCell className="px-3 xl:px-6 py-4 font-semibold">
                      {job.errorsCount > 0 ? (
                        <span className="text-red-600">{job.errorsCount}</span>
                      ) : (
                        <span className="text-gray-500">0</span>
                      )}
                    </TableCell>
                    <TableCell className="px-3 xl:px-6 py-4 text-sm text-gray-600">
                      {formatDateStr(job.startedAt)}
                    </TableCell>
                    <TableCell className="px-3 xl:px-6 py-4 text-sm text-gray-600">
                      {formatDateStr(job.finishedAt)}
                    </TableCell>
                    <TableCell className="px-3 xl:px-6 py-4 text-sm">{job.user.name}</TableCell>
                    <TableCell className="px-3 xl:px-6 py-4 flex gap-2">
                      {/* Ver */}
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleViewReport(job)}
                        disabled={job.status === "pendente" || job.status === "em_progresso" || loadingErrors}
                        className="flex items-center gap-1"
                      >
                        <Eye className="w-4 h-4" />
                        <span className="hidden xl:inline">Ver</span>
                      </Button>

                      {/* Desfazer – renderiza SÓ no último job */}
                      {isLast && (
                        <AlertDialog>
                          <AlertDialogTrigger asChild>
                            <Button
                              variant="destructive"
                              size="sm"
                              disabled={rollbackDisabled || loadingRollback === job.id}
                              className="flex items-center gap-1"
                              onClick={() => setConfirmJob(job)}
                            >
                              <RotateCcw className="w-4 h-4" />
                              <span className="hidden xl:inline">Desfazer</span>
                            </Button>
                          </AlertDialogTrigger>

                          <AlertDialogContent>
                            <AlertDialogHeader>
                              <AlertDialogTitle>
                                Desfazer importação?
                              </AlertDialogTitle>
                            </AlertDialogHeader>
                            <p className="text-sm text-gray-600">
                              Esta ação reverterá todas as alterações feitas
                              por <strong>{job.fileName}</strong>. Deseja continuar?
                            </p>
                            <AlertDialogFooter>
                              <AlertDialogCancel>Cancelar</AlertDialogCancel>
                              <AlertDialogAction
                                disabled={loadingRollback === job.id}
                                onClick={() => executeRollback(job)}
                              >
                                Confirmar
                              </AlertDialogAction>
                            </AlertDialogFooter>
                          </AlertDialogContent>
                        </AlertDialog>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>

        {/* mobile – regras idênticas para exibição do botão */}
        <div className="lg:hidden space-y-4 p-4">
          {jobs.map(job => {
            const isLast = job.id === lastJobId;
            const rollbackDisabled =
              job.status !== "concluido" || job.rolledBackAt !== null;

            return (
              <div key={job.id} className="bg-white border rounded-lg p-4 space-y-2">
                <div className="flex justify-between">
                  <h3 className="font-medium truncate">{job.fileName}</h3>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleViewReport(job)}
                      disabled={job.status === "pendente" || job.status === "em_progresso" || loadingErrors}
                    >
                      <Eye className="w-4 h-4" />
                    </Button>
                    {isLast && (
                      <AlertDialog>
                        <AlertDialogTrigger asChild>
                          <Button
                            size="sm"
                            variant="destructive"
                            disabled={rollbackDisabled || loadingRollback === job.id}
                            onClick={() => setConfirmJob(job)}
                          >
                            <RotateCcw className="w-4 h-4" />
                          </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                          <AlertDialogHeader>
                            <AlertDialogTitle>Desfazer importação?</AlertDialogTitle>
                          </AlertDialogHeader>
                          <p className="text-sm text-gray-600">
                            Isso reverterá as alterações feitas por <strong>{job.fileName}</strong>.
                          </p>
                          <AlertDialogFooter>
                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                            <AlertDialogAction
                              disabled={loadingRollback === job.id}
                              onClick={() => executeRollback(job)}
                            >
                              Confirmar
                            </AlertDialogAction>
                          </AlertDialogFooter>
                        </AlertDialogContent>
                      </AlertDialog>
                    )}
                  </div>
                </div>

                <div className="flex items-center space-x-2">
                  {getTypeBadge(job.type)}
                  {getStatusBadge(job.status)}
                  <span className="text-sm">
                    {job.errorsCount} {job.errorsCount === 1 ? "erro" : "erros"}
                  </span>
                </div>

                <div className="text-xs text-gray-500">
                  Início: {formatDateStr(job.startedAt)}<br />
                  Término: {formatDateStr(job.finishedAt)}
                </div>
                <div className="text-xs text-gray-500">
                  Por: <strong>{job.user.name}</strong>
                </div>
              </div>
            );
          })}
        </div>

        {jobs.length === 0 && (
          <div className="text-center py-12 text-gray-500">
            <FileText className="w-12 h-12 mx-auto mb-4 text-gray-300" />
            <p>Nenhuma importação encontrada</p>
          </div>
        )}
      </div>

      {/* Modal de erros */}
      <RelatorioErrosModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        job={selectedJob}
        errors={errors}
        onExportCsv={() => selectedJob && exportImportErrorsCsv(selectedJob.id)}
      />
    </div>
  );
};

export default HistoricoPage;
