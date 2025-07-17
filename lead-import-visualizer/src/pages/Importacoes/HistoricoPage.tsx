// src/pages/Importacoes/HistoricoPage.tsx
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableHeader,
  TableHead,
  TableRow,
  TableBody,
  TableCell,
} from "@/components/ui/table";
import { RelatorioErrosModal } from "@/components/modals/RelatorioErrosModal";
import {
  listImportJobs,
  fetchImportErrors,
  exportImportErrorsCsv,
  ImportJob,
  ImportError,
} from "@/api/importJobs";
import { format } from "date-fns";
import { ptBR } from "date-fns/locale";
import { FileText, Eye } from "lucide-react";

const HistoricoPage = () => {
  const [selectedJob, setSelectedJob] = useState<ImportJob | null>(null);
  const [errors, setErrors] = useState<ImportError[]>([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [loadingErrors, setLoadingErrors] = useState(false);

  // Busca os jobs para histórico
  const { data: jobs = [], isLoading } = useQuery({
    queryKey: ["importJobs"],
    queryFn: listImportJobs,
    staleTime: 1000 * 60 * 5,
  });

  // Abre modal e carrega erros
  const handleViewReport = async (job: ImportJob) => {
    if (job.status === "pendente" || job.status === "em_progresso") return;
    setLoadingErrors(true);
    setSelectedJob(job);
    try {
      const jobErrors = await fetchImportErrors(job.id);
      setErrors(jobErrors);
      setIsModalOpen(true);
    } catch {
      console.error("Erro ao buscar erros");
    } finally {
      setLoadingErrors(false);
    }
  };

  // Formata data ou retorna '-'
  const formatDateStr = (date: string | null) =>
    date ? format(new Date(date), "dd/MM/yyyy HH:mm", { locale: ptBR }) : "-";

  // Badge de status
  const getStatusBadge = (status: ImportJob["status"]) => {
    const map = {
      concluido: ["default", "bg-green-100 text-green-800"],
      falhou: ["destructive", "bg-red-100 text-red-800"],
      pendente: ["secondary", "bg-blue-100 text-blue-800"],
      em_progresso: ["secondary", "bg-blue-100 text-blue-800"],
    } as const;
    const key = status === "concluido" ? "concluido" : status;
    const [variant, color] = map[key];
    const label =
      status === "concluido"
        ? "Concluído"
        : status === "em_progresso"
        ? "Em progresso"
        : status === "pendente"
        ? "Pendente"
        : "Falhou";
    return (
      <Badge variant={variant as any} className={color}>
        {label}
      </Badge>
    );
  };

  // Badge de tipo
  const getTypeBadge = (type: ImportJob["type"]) => {
    const colors = {
      cadastral: "bg-purple-100 text-purple-800",
      higienizacao: "bg-orange-100 text-orange-800",
    } as const;
    const label = type === "cadastral" ? "Cadastral" : "Higienização";
    return (
      <Badge variant="outline" className={colors[type]}>
        {label}
      </Badge>
    );
  };

  if (isLoading) {
    return <div className="p-4">Carregando histórico...</div>;
  }

  return (
    <div className="p-4 lg:p-6 w-full">
      <div className="mb-6">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900">
          Histórico de importações
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          {jobs.length} importações encontradas
        </p>
      </div>

      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
        {/* versão desktop */}
        <div className="hidden lg:block overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Arquivo importado
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                 Origem
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Tipo
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Status
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Erros
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Início
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Término
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Usuário
                </TableHead>
                <TableHead className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">
                  Ações
                </TableHead>
              </TableRow>
            </TableHeader>

            <TableBody>
              {jobs.map((job) => (
                <TableRow
                  key={job.id}
                  className="hover:bg-gray-50 transition-colors duration-150"
                >
                  <TableCell className="px-3 xl:px-6 py-4 text-sm text-gray-900 font-medium whitespace-nowrap truncate">
                    {job.fileName}
                  </TableCell>
                   <TableCell className="px-3 xl:px-6 py-4 text-sm text-gray-900 font-medium whitespace-nowrap truncate">
                    {job.origin}
                  </TableCell>
                  <TableCell className="px-3 xl:px-6 py-4 whitespace-nowrap">
                    {getTypeBadge(job.type)}
                  </TableCell>
                  <TableCell className="px-3 xl:px-6 py-4 whitespace-nowrap">
                    {getStatusBadge(job.status)}
                  </TableCell>
                  <TableCell className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-semibold">
                    {job.errorsCount > 0 ? (
                      <span className="text-red-600">{job.errorsCount}</span>
                    ) : (
                      <span className="text-gray-500">0</span>
                    )}
                  </TableCell>
                  <TableCell className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    {formatDateStr(job.startedAt)}
                  </TableCell>
                  <TableCell className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    {formatDateStr(job.finishedAt)}
                  </TableCell>
                  <TableCell className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {job.user.name}
                  </TableCell>
                  <TableCell className="px-3 xl:px-6 py-4 whitespace-nowrap">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleViewReport(job)}
                      disabled={
                        job.status === "pendente" ||
                        job.status === "em_progresso" ||
                        loadingErrors
                      }
                      className="flex items-center gap-1"
                    >
                      <Eye className="w-4 h-4" />
                      <span className="hidden xl:inline">Ver</span>
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>

        {/* versão mobile */}
        <div className="lg:hidden space-y-4 p-4">
          {jobs.map((job) => (
            <div
              key={job.id}
              className="bg-white border border-gray-200 rounded-lg p-4 space-y-2"
            >
              <div className="flex justify-between items-center">
                <h3 className="font-medium truncate">{job.fileName}</h3>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => handleViewReport(job)}
                  disabled={
                    job.status === "pendente" ||
                    job.status === "em_progresso" ||
                    loadingErrors
                  }
                >
                  <Eye className="w-4 h-4" />
                  <span>Ver</span>
                </Button>
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
          ))}
        </div>

        {jobs.length === 0 && (
          <div className="text-center py-12 text-gray-500">
            <FileText className="w-12 h-12 mx-auto mb-4 text-gray-300" />
            <p>Nenhuma importação encontrada</p>
          </div>
        )}
      </div>

      <RelatorioErrosModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        job={selectedJob}
        errors={errors}
        onExportCsv={() => {
          if (selectedJob) exportImportErrorsCsv(selectedJob.id);
        }}
      />
    </div>
  );
};

export default HistoricoPage;
