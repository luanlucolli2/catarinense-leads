import { useState, useEffect, useMemo } from "react";
import { X, Download } from "lucide-react";
import { Button } from "@/components/ui/button";

interface ExportModalProps {
  isOpen: boolean;
  onClose: () => void;
  onExport: (columns: string[]) => void;
  /** Define quais colunas exibir: FGTS | CLT */
  mode: "FGTS" | "CLT";
}

/** Catálogo de colunas por modo, alinhado ao ALLOWED_COLUMNS do backend */
const COLUMNS_FGTS = [
  { id: "cpf", label: "CPF", selected: true },
  { id: "nome", label: "Nome", selected: true },
  { id: "data_nascimento", label: "Data de Nascimento", selected: true },
  { id: "fone1", label: "Telefone 1", selected: true },
  { id: "fone2", label: "Telefone 2", selected: true },
  { id: "fone3", label: "Telefone 3", selected: true },
  { id: "fone4", label: "Telefone 4", selected: true },
  { id: "classe_fone1", label: "Classe 1", selected: true },
  { id: "classe_fone2", label: "Classe 2", selected: true },
  { id: "classe_fone3", label: "Classe 3", selected: true },
  { id: "classe_fone4", label: "Classe 4", selected: true },

  { id: "consulta", label: "Motivo (Consulta)", selected: true },
  { id: "saldo", label: "Saldo", selected: true },
  { id: "libera", label: "Libera", selected: true },

  { id: "ultima_origem_cadastral", label: "Última Origem (Cadastral)", selected: true },
  { id: "ultima_origem_higienizacao", label: "Última Origem (Higienização)", selected: true },

  { id: "data_atualizacao", label: "Data de Atualização", selected: true },
  { id: "contracts_count", label: "Qtde de Contratos", selected: true },
  { id: "data_contrato_recente", label: "Data de Contrato (mais recente)", selected: true },
  { id: "vendedor", label: "Vendedor", selected: true },

  // ➕ FGTS OFF
  { id: "fgts_off_authorized", label: "FGTS OFF Autorizado", selected: true },
  { id: "fgts_off_consultado_em", label: "FGTS OFF Consultado em", selected: true },
] as const

const COLUMNS_CLT = [
  // básicos
  { id: "cpf", label: "CPF", selected: true },
  { id: "nome", label: "Nome", selected: true },
  { id: "data_nascimento", label: "Data de Nascimento", selected: true },
  { id: "fone1", label: "Telefone 1", selected: true },
  { id: "fone2", label: "Telefone 2", selected: true },
  { id: "fone3", label: "Telefone 3", selected: true },
  { id: "fone4", label: "Telefone 4", selected: true },
  { id: "classe_fone1", label: "Classe 1", selected: true },
  { id: "classe_fone2", label: "Classe 2", selected: true },
  { id: "classe_fone3", label: "Classe 3", selected: true },
  { id: "classe_fone4", label: "Classe 4", selected: true },
  { id: "ultima_origem_cadastral", label: "Última Origem (Cadastral)", selected: true },

  // snapshot CLT
  { id: "elegivel", label: "CLT Elegível", selected: true },
  { id: "idade", label: "CLT Idade", selected: true },
  { id: "sexo", label: "CLT Sexo", selected: true },
  { id: "data_admissao", label: "CLT Data de Admissão", selected: true },
  { id: "meses_admissao", label: "CLT Tempo de Casa (meses)", selected: true },
  { id: "valor_renda", label: "CLT Renda Total", selected: true },
  { id: "valor_base_margem", label: "CLT Base de Margem", selected: true },
  { id: "margem_disponivel", label: "CLT Margem Disponível", selected: true },
  { id: "valor_max_prestacao", label: "CLT Valor Máx. Prestação", selected: true },
  { id: "categoria_trabalhador_codigo", label: "CLT Categoria do Trabalhador", selected: true },
  { id: "inicio_atividade_empregador", label: "CLT Início Atividade (Empregador)", selected: true },
  { id: "qtd_emprestimos_ativos_suspensos", label: "CLT Qtde Empréstimos Ativos/Suspensos", selected: true },
  { id: "emprestimos_legados", label: "CLT Empréstimos Legados", selected: true },
  { id: "not_found", label: "CLT Não Encontrado", selected: true },
  { id: "clt_consultado_em", label: "CLT Consultado em", selected: true },
] as const

export const ExportModal = ({
  isOpen,
  onClose,
  onExport,
  mode,
}: ExportModalProps) => {
  const columnsSource = useMemo(() => (mode === "FGTS" ? COLUMNS_FGTS : COLUMNS_CLT), [mode])

  const [selectedColumns, setSelectedColumns] = useState<Record<string, boolean>>({});

  useEffect(() => {
    if (isOpen) {
      const init = columnsSource.reduce((acc, col) => {
        acc[col.id] = col.selected;
        return acc;
      }, {} as Record<string, boolean>);
      setSelectedColumns(init);
    }
  }, [isOpen, columnsSource]);

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  const handleColumnToggle = (columnId: string) => {
    setSelectedColumns((prev) => ({
      ...prev,
      [columnId]: !prev[columnId],
    }));
  };

  const handleSelectAll = () => {
    const allSelected = columnsSource.every((col) => selectedColumns[col.id]);
    const newState = columnsSource.reduce((acc, col) => {
      acc[col.id] = !allSelected;
      return acc;
    }, {} as Record<string, boolean>);
    setSelectedColumns(newState);
  };

  const handleExportClick = () => {
    const columnsToExport = Object.keys(selectedColumns).filter(
      (key) => selectedColumns[key]
    );
    onExport(columnsToExport);
    onClose();
  };

  const selectedCount = Object.values(selectedColumns).filter(Boolean).length;

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 animate-fade-in">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 animate-scale-in">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 className="text-xl font-semibold text-gray-900">
            Exportar para Excel — {mode}
          </h2>
          <button
            onClick={onClose}
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
              {selectedCount === columnsSource.length
                ? "Desmarcar Todas"
                : "Selecionar Todas"}
            </Button>
          </div>

          <div className="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-3">
            <div className="space-y-2">
              {columnsSource.map((column) => (
                <label
                  key={column.id}
                  className="flex items-center cursor-pointer hover:bg-gray-50 p-2 rounded"
                >
                  <input
                    type="checkbox"
                    checked={!!selectedColumns[column.id]}
                    onChange={() => handleColumnToggle(column.id)}
                    className="mr-3 text-blue-600"
                  />
                  <span className="text-sm text-gray-700">
                    {column.label}
                  </span>
                </label>
              ))}
            </div>
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <p className="text-sm text-blue-800">
              <strong>{selectedCount}</strong> coluna(s) selecionada(s) para
              exportação
            </p>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
          <Button
            variant="outline"
            onClick={onClose}
            className="text-gray-700 border-gray-300 hover:bg-gray-50"
          >
            Cancelar
          </Button>
          <Button
            onClick={handleExportClick}
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
