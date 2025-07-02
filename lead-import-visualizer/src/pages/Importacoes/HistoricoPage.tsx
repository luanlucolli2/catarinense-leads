
import { useState, useEffect } from "react";
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
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { RelatorioErrosModal } from "@/components/modals/RelatorioErrosModal";
import { AppLayout } from "@/components/AppLayout";
import {
  mockImportJobs,
  fetchErrorsForJob,
  ImportJob,
  ImportError,
} from "@/api/mockData";
import { format } from "date-fns";
import { ptBR } from "date-fns/locale";
import { FileText, Eye } from "lucide-react";

const HistoricoPage = () => {
  const [jobs, setJobs] = useState<ImportJob[]>([]);
  const [selectedJob, setSelectedJob] = useState<ImportJob | null>(null);
  const [errors, setErrors] = useState<ImportError[]>([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [loadingErrors, setLoadingErrors] = useState(false);

  useEffect(() => {
    // Simula busca de dados
    setJobs(mockImportJobs);
  }, []);

  const handleViewReport = async (job: ImportJob) => {
    if (job.status === 'Em Progresso') return;

    setLoadingErrors(true);
    setSelectedJob(job);
    
    try {
      const jobErrors = await fetchErrorsForJob(job.id);
      setErrors(jobErrors);
      setIsModalOpen(true);
    } catch (error) {
      console.error('Erro ao buscar erros:', error);
    } finally {
      setLoadingErrors(false);
    }
  };

  const getStatusBadge = (status: ImportJob['status']) => {
    const variants = {
      'Concluído': 'default',
      'Falhou': 'destructive',
      'Em Progresso': 'secondary',
    } as const;

    const colors = {
      'Concluído': 'bg-green-100 text-green-800 hover:bg-green-100',
      'Falhou': 'bg-red-100 text-red-800 hover:bg-red-100',
      'Em Progresso': 'bg-blue-100 text-blue-800 hover:bg-blue-100',
    };

    return (
      <Badge variant={variants[status]} className={colors[status]}>
        {status}
      </Badge>
    );
  };

  const getTypeBadge = (type: ImportJob['type']) => {
    const colors = {
      'Cadastral': 'bg-purple-100 text-purple-800 hover:bg-purple-100',
      'Higienização': 'bg-orange-100 text-orange-800 hover:bg-orange-100',
    };

    return (
      <Badge variant="outline" className={colors[type]}>
        {type}
      </Badge>
    );
  };

  const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    return format(new Date(dateString), "dd/MM/yyyy HH:mm", { locale: ptBR });
  };

  return (
    <AppLayout>
      <div className="p-4 lg:p-6 max-w-full min-w-0">
        <div className="mb-6 max-w-full">
          <div className="flex items-center gap-2 mb-2">
          
            <h1 className="text-xl lg:text-2xl font-bold text-gray-900">Histórico de Importações</h1>
          </div>
          <p className="text-gray-600 text-sm lg:text-base">
            Visualize e gerencie o histórico de importações de leads ({jobs.length} importações encontradas)
          </p>
        </div>

        <div className="bg-white rounded-lg border border-gray-200 overflow-hidden w-full max-w-full">
          {/* Desktop Table */}
          <div className="hidden lg:block w-full max-w-full">
            <div className="overflow-x-auto max-w-full">
              <table className="w-full min-w-[1000px]">
                <thead className="bg-gray-50 sticky top-0">
                  <tr>
                    <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[140px]">
                      Arquivo Importado
                    </th>
                    <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]">
                      Tipo
                    </th>
                    <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]">
                      Status
                    </th>
                    <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[80px]">
                      Erros
                    </th>
                    <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[120px]">
                      Data
                    </th>
                    <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[120px]">
                      Importado por
                    </th>
                    <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]">
                      Ações
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {jobs.map((job) => (
                    <tr key={job.id} className="hover:bg-gray-50 transition-colors duration-150">
                      <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium max-w-[200px] truncate align-top">
                        {job.fileName}
                      </td>
                      <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
                        {getTypeBadge(job.type)}
                      </td>
                      <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
                        {getStatusBadge(job.status)}
                      </td>
                      <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-semibold align-top">
                        <span className={job.errorCount > 0 ? "text-red-600" : "text-gray-500"}>
                          {job.errorCount}
                        </span>
                      </td>
                      <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-600 align-top">
                        {formatDate(job.finishedAt)}
                      </td>
                      <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-top">
                        {job.user.name}
                      </td>
                      <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => handleViewReport(job)}
                          disabled={job.status === 'Em Progresso' || loadingErrors}
                          className="flex items-center space-x-1"
                        >
                          <Eye className="w-4 h-4" />
                          <span className="hidden xl:inline">Ver</span>
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Mobile/Tablet Cards */}
          <div className="lg:hidden space-y-4 p-4 max-w-full">
            {jobs.map((job) => (
              <div key={job.id} className="bg-white border border-gray-200 rounded-lg p-4 space-y-3 max-w-full">
                {/* Header */}
                <div className="flex justify-between items-start min-w-0">
                  <div className="min-w-0 flex-1">
                    <h3 className="font-medium text-gray-900 truncate">{job.fileName}</h3>
                    <p className="text-sm text-gray-600">Importado por: {job.user.name}</p>
                  </div>
                  <Button
                    onClick={() => handleViewReport(job)}
                    variant="outline"
                    size="sm"
                    disabled={job.status === 'Em Progresso' || loadingErrors}
                    className="flex items-center space-x-1 flex-shrink-0 ml-2"
                  >
                    <Eye className="w-4 h-4" />
                    <span>Ver</span>
                  </Button>
                </div>

                {/* Status and Type */}
                <div className="flex items-center justify-between flex-wrap gap-2 min-w-0">
                  <div className="flex items-center space-x-2 flex-wrap min-w-0">
                    {getTypeBadge(job.type)}
                    {getStatusBadge(job.status)}
                  </div>
                  <span className="text-sm text-gray-500 flex-shrink-0">
                    {job.errorCount > 0 ? (
                      <span className="text-red-600 font-semibold">{job.errorCount} erros</span>
                    ) : (
                      <span>Sem erros</span>
                    )}
                  </span>
                </div>

                {/* Bottom Info */}
                <div className="flex justify-between items-center text-xs text-gray-500 pt-2 border-t min-w-0">
                  <span className="flex-shrink-0">Data: {formatDate(job.finishedAt)}</span>
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
          onClose={() => {
            setIsModalOpen(false);
            setSelectedJob(null);
            setErrors([]);
          }}
          job={selectedJob}
          errors={errors}
        />
      </div>
    </AppLayout>
  );
};

export default HistoricoPage;
