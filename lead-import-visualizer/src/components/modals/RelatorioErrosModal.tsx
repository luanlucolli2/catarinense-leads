
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ImportJob, ImportError } from "@/api/mockData";
import { Download } from "lucide-react";

interface RelatorioErrosModalProps {
  isOpen: boolean;
  onClose: () => void;
  job: ImportJob | null;
  errors: ImportError[];
}

export const RelatorioErrosModal = ({
  isOpen,
  onClose,
  job,
  errors,
}: RelatorioErrosModalProps) => {
  const handleExportCSV = () => {
    console.log('Exportando erros para CSV...');
  };

  if (!job) return null;

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-w-4xl max-h-[80vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">
            Relatório de Erros: {job.fileName}
          </DialogTitle>
          <DialogDescription className="text-sm text-gray-600">
            Foram encontrados {errors.length} erros nesta importação.
          </DialogDescription>
        </DialogHeader>

        <div className="flex-1 overflow-auto">
          {errors.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
              Nenhum erro encontrado nesta importação.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-20">Linha</TableHead>
                  <TableHead className="w-40">Coluna</TableHead>
                  <TableHead>Mensagem do Erro</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {errors.map((error) => (
                  <TableRow key={error.id}>
                    <TableCell className="font-medium">
                      {error.row_number}
                    </TableCell>
                    <TableCell className="font-mono text-sm">
                      {error.column_name}
                    </TableCell>
                    <TableCell className="text-red-600">
                      {error.error_message}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </div>

        <div className="flex justify-between items-center pt-4 border-t">
          <Button
            variant="outline"
            onClick={handleExportCSV}
            disabled={errors.length === 0}
            className="flex items-center gap-2"
          >
            <Download className="w-4 h-4" />
            Exportar Erros (.csv)
          </Button>
          
          <Button onClick={onClose} className="bg-blue-600 hover:bg-blue-700">
            Fechar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
};
