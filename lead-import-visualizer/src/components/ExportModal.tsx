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

type ColumnDef = {
  id: string;
  label: string;
  selected: boolean;
  group: "Cadastrais" | "Produto" | "Registro";
};

/** Catálogo de colunas por modo (alinhado ao ALLOWED_COLUMNS do backend) com grupos */
const COLUMNS_FGTS: ColumnDef[] = [
  // Cadastrais
  { id: "cpf", label: "CPF", selected: true, group: "Cadastrais" },
  { id: "nome", label: "Nome", selected: true, group: "Cadastrais" },
  { id: "data_nascimento", label: "Data de Nascimento", selected: true, group: "Cadastrais" },
  { id: "fone1", label: "Telefone 1", selected: true, group: "Cadastrais" },
  { id: "fone2", label: "Telefone 2", selected: true, group: "Cadastrais" },
  { id: "fone3", label: "Telefone 3", selected: true, group: "Cadastrais" },
  { id: "fone4", label: "Telefone 4", selected: true, group: "Cadastrais" },
  { id: "classe_fone1", label: "Classe 1", selected: true, group: "Cadastrais" },
  { id: "classe_fone2", label: "Classe 2", selected: true, group: "Cadastrais" },
  { id: "classe_fone3", label: "Classe 3", selected: true, group: "Cadastrais" },
  { id: "classe_fone4", label: "Classe 4", selected: true, group: "Cadastrais" },

  // Produto (FGTS)
  { id: "consulta", label: "Motivo (Consulta)", selected: true, group: "Produto" },
  { id: "saldo", label: "Saldo", selected: true, group: "Produto" },
  { id: "libera", label: "Libera", selected: true, group: "Produto" },
  { id: "data_atualizacao", label: "Data de Atualização", selected: true, group: "Produto" },
  { id: "contracts_count", label: "Qtde de Contratos", selected: true, group: "Produto" },
  { id: "data_contrato_recente", label: "Data de Contrato (mais recente)", selected: true, group: "Produto" },
  { id: "vendedor", label: "Vendedor", selected: true, group: "Produto" },
  { id: "fgts_off_authorized", label: "Autorizado (FGTS OFF)", selected: true, group: "Produto" },
  { id: "fgts_off_consultado_em", label: "Data consulta (FGTS OFF)", selected: true, group: "Produto" },

  // Registro
  { id: "ultima_origem_cadastral", label: "Última Origem (Cadastral)", selected: true, group: "Registro" },
  { id: "ultima_origem_higienizacao", label: "Última Origem (Higienização)", selected: true, group: "Registro" },
];

const COLUMNS_CLT: ColumnDef[] = [
  // Cadastrais
  { id: "cpf", label: "CPF", selected: true, group: "Cadastrais" },
  { id: "nome", label: "Nome", selected: true, group: "Cadastrais" },
  { id: "data_nascimento", label: "Data de Nascimento", selected: true, group: "Cadastrais" },
  { id: "fone1", label: "Telefone 1", selected: true, group: "Cadastrais" },
  { id: "fone2", label: "Telefone 2", selected: true, group: "Cadastrais" },
  { id: "fone3", label: "Telefone 3", selected: true, group: "Cadastrais" },
  { id: "fone4", label: "Telefone 4", selected: true, group: "Cadastrais" },
  { id: "classe_fone1", label: "Classe 1", selected: true, group: "Cadastrais" },
  { id: "classe_fone2", label: "Classe 2", selected: true, group: "Cadastrais" },
  { id: "classe_fone3", label: "Classe 3", selected: true, group: "Cadastrais" },
  { id: "classe_fone4", label: "Classe 4", selected: true, group: "Cadastrais" },

  // Produto (snapshot CLT)
  { id: "elegivel", label: "CLT Elegível", selected: true, group: "Produto" },
  { id: "idade", label: "CLT Idade", selected: true, group: "Produto" },
  { id: "sexo", label: "CLT Sexo", selected: true, group: "Produto" },
  { id: "data_admissao", label: "CLT Data de Admissão", selected: true, group: "Produto" },
  { id: "meses_admissao", label: "CLT Tempo de Casa (meses)", selected: true, group: "Produto" },
  { id: "valor_renda", label: "CLT Renda Total", selected: true, group: "Produto" },
  { id: "valor_base_margem", label: "CLT Base de Margem", selected: true, group: "Produto" },
  { id: "margem_disponivel", label: "CLT Margem Disponível", selected: true, group: "Produto" },
  { id: "valor_max_prestacao", label: "CLT Valor Máx. Prestação", selected: true, group: "Produto" },
  { id: "categoria_trabalhador_codigo", label: "CLT Categoria do Trabalhador", selected: true, group: "Produto" },
  { id: "inicio_atividade_empregador", label: "CLT Início Atividade (Empregador)", selected: true, group: "Produto" },
  { id: "qtd_emprestimos_ativos_suspensos", label: "CLT Qtde Empréstimos Ativos/Suspensos", selected: true, group: "Produto" },
  { id: "emprestimos_legados", label: "CLT Empréstimos Legados", selected: true, group: "Produto" },
  { id: "not_found", label: "CLT Não Encontrado", selected: true, group: "Produto" },
  { id: "clt_consultado_em", label: "CLT Consultado em", selected: true, group: "Produto" },

  // Registro
  { id: "ultima_origem_cadastral", label: "Última Origem (Cadastral)", selected: true, group: "Registro" },
];

export const ExportModal = ({
  isOpen,
  onClose,
  onExport,
  mode,
}: ExportModalProps) => {
  const columnsSource = useMemo<ColumnDef[]>(
    () => (mode === "FGTS" ? COLUMNS_FGTS : COLUMNS_CLT),
    [mode]
  );

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
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
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

  const handleSelectGroup = (group: ColumnDef["group"]) => {
    const groupCols = columnsSource.filter(c => c.group === group);
    const allGroupSelected = groupCols.every(c => selectedColumns[c.id]);
    const newState = { ...selectedColumns };
    groupCols.forEach(c => {
      newState[c.id] = !allGroupSelected;
    });
    setSelectedColumns(newState);
  };

  const handleExportClick = () => {
    const columnsToExport = Object.keys(selectedColumns).filter((key) => selectedColumns[key]);
    onExport(columnsToExport);
    onClose();
  };

  const selectedCount = Object.values(selectedColumns).filter(Boolean).length;

  const groupsOrdered: ColumnDef["group"][] = ["Cadastrais", "Produto", "Registro"];

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 animate-fade-in">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 animate-scale-in">
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
            <div className="space-x-2">
              <Button
                onClick={handleSelectAll}
                variant="outline"
                size="sm"
                className="text-xs"
              >
                {selectedCount === columnsSource.length ? "Desmarcar todas" : "Selecionar todas"}
              </Button>
            </div>
          </div>

          <div className="max-h-80 overflow-y-auto border border-gray-200 rounded-lg p-3">
            {groupsOrdered.map((group) => {
              const items = columnsSource.filter((c) => c.group === group);
              if (!items.length) return null;
              const groupSelectedCount = items.filter(i => selectedColumns[i.id]).length;
              const allGroupSelected = groupSelectedCount === items.length;
              const someGroupSelected = groupSelectedCount > 0 && !allGroupSelected;

              return (
                <div key={group} className="mb-4">
                  <div className="flex items-center justify-between mb-2">
                    <div className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                      {group}
                    </div>
                    <button
                      onClick={() => handleSelectGroup(group)}
                      className={`text-[11px] px-2 py-1 rounded border transition
                        ${allGroupSelected ? "border-gray-300 text-gray-700 hover:bg-gray-50"
                          : someGroupSelected ? "border-blue-300 text-blue-700 hover:bg-blue-50"
                          : "border-gray-300 text-gray-700 hover:bg-gray-50"}`}
                      title={allGroupSelected ? "Desmarcar grupo" : "Selecionar grupo"}
                    >
                      {allGroupSelected ? "Desmarcar grupo" : "Selecionar grupo"}
                    </button>
                  </div>
                  <div className="space-y-1">
                    {items.map((column) => (
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
                        <span className="text-sm text-gray-700">{column.label}</span>
                      </label>
                    ))}
                  </div>
                </div>
              );
            })}
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
