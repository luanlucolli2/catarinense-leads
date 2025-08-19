import { Download, Loader2, RefreshCw, ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { CltConsultJobListItem } from "@/api/clt";

type Props = {
  items: CltConsultJobListItem[];
  loading?: boolean;
  onDownload: (id: number) => void;
  onRefresh?: () => void;

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
      return <Badge className="bg-green-100 text-green-800 border-green-200">Concluído</Badge>;
    case "em_progresso":
      return (
        <Badge className="bg-blue-100 text-blue-800 border-blue-200 flex items-center gap-1">
          <Loader2 className="w-3 h-3 animate-spin" />
          Em andamento
        </Badge>
      );
    case "falhou":
      return <Badge className="bg-red-100 text-red-800 border-red-200">Falhou</Badge>;
    case "pendente":
    default:
      return <Badge variant="secondary">Pendente</Badge>;
  }
}

export const CLTHistoryTable = ({
  items,
  loading,
  onDownload,
  onRefresh,
  page,
  lastPage,
  onPageChange,
  formatDateTimeBR,
}: Props) => {
  const handlePrev = () => onPageChange(Math.max(1, page - 1));
  const handleNext = () => onPageChange(Math.min(lastPage || 1, page + 1));

  const canDownload = (i: CltConsultJobListItem) =>
    i.status === "concluido" && Boolean(i.file_path);

  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="px-4 py-3 flex items-center justify-between gap-3">
        <div className="text-sm text-gray-600">
          {loading ? "Carregando..." : `${items.length} itens na página`}
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={onRefresh}
            disabled={loading}
            className="flex items-center gap-2"
          >
            <RefreshCw className={cn("w-4 h-4", loading && "animate-spin")} />
            Atualizar
          </Button>

          <div className="flex items-center gap-1">
            <Button variant="outline" size="sm" onClick={handlePrev} disabled={page <= 1}>
              <ChevronLeft className="w-4 h-4" />
            </Button>
            <span className="text-sm text-gray-700 px-2">
              {page} / {lastPage || 1}
            </span>
            <Button variant="outline" size="sm" onClick={handleNext} disabled={page >= lastPage}>
              <ChevronRight className="w-4 h-4" />
            </Button>
          </div>
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
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};
