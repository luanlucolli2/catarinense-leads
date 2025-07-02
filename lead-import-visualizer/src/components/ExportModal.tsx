
import { useState } from "react";
import { X, Download } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

interface ExportModalProps {
  isOpen: boolean;
  onClose: () => void;
  onExport: (columns: string[]) => void;
}

const availableColumns = [
  { id: "cpf", label: "CPF", selected: true },
  { id: "nome", label: "Nome", selected: true },
  { id: "telefone", label: "Telefone", selected: true },
  { id: "classe", label: "Classe", selected: true },
  { id: "status", label: "Status", selected: true },
  { id: "contratos", label: "Contratos", selected: true },
  { id: "saldo", label: "Saldo", selected: true },
  { id: "libera", label: "Libera", selected: true },
  { id: "dataAtualizacao", label: "Data Atualização", selected: true },
  { id: "motivo", label: "Motivo", selected: true },
  { id: "origem", label: "Origem", selected: true },
];

export const ExportModal = ({ isOpen, onClose, onExport }: ExportModalProps) => {
  const [selectedColumns, setSelectedColumns] = useState(
    availableColumns.reduce((acc, col) => {
      acc[col.id] = col.selected;
      return acc;
    }, {} as Record<string, boolean>)
  );

  const handleColumnToggle = (columnId: string) => {
    setSelectedColumns(prev => ({
      ...prev,
      [columnId]: !prev[columnId]
    }));
  };

  const handleSelectAll = () => {
    const allSelected = availableColumns.every(col => selectedColumns[col.id]);
    const newState = availableColumns.reduce((acc, col) => {
      acc[col.id] = !allSelected;
      return acc;
    }, {} as Record<string, boolean>);
    setSelectedColumns(newState);
  };

  const handleExport = () => {
    const columnsToExport = Object.keys(selectedColumns).filter(key => selectedColumns[key]);
    onExport(columnsToExport);
    onClose();
  };

  const handleClose = () => {
    onClose();
  };

  const selectedCount = Object.values(selectedColumns).filter(Boolean).length;

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 animate-fade-in">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 animate-scale-in">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 className="text-xl font-semibold text-gray-900">Exportar para Excel</h2>
          <button
            onClick={handleClose}
            className="text-gray-400 hover:text-gray-600 transition-colors duration-200"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-700">
              Selecione as colunas para exportar
            </h3>
            <Button
              onClick={handleSelectAll}
              variant="outline"
              size="sm"
              className="text-xs"
            >
              {selectedCount === availableColumns.length ? "Desmarcar Todas" : "Selecionar Todas"}
            </Button>
          </div>

          <div className="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-3">
            <div className="space-y-2">
              {availableColumns.map((column) => (
                <label key={column.id} className="flex items-center cursor-pointer hover:bg-gray-50 p-2 rounded">
                  <input
                    type="checkbox"
                    checked={selectedColumns[column.id]}
                    onChange={() => handleColumnToggle(column.id)}
                    className="mr-3 text-blue-600"
                  />
                  <span className="text-sm text-gray-700">{column.label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <p className="text-sm text-blue-800">
              <strong>{selectedCount}</strong> coluna(s) selecionada(s) para exportação
            </p>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
          <Button
            variant="outline"
            onClick={handleClose}
            className="text-gray-700 border-gray-300 hover:bg-gray-50"
          >
            Cancelar
          </Button>
          <Button
            onClick={handleExport}
            disabled={selectedCount === 0}
            className="bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Download className="w-4 h-4 mr-2" />
            Exportar Excel
          </Button>
        </div>
      </div>
    </div>
  );
};
