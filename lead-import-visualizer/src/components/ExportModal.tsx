import { useState, useEffect, useMemo } from "react";
import { X, Download } from "lucide-react";
import { Button } from "@/components/ui/button";

interface ExportModalProps {
  isOpen: boolean;
  onClose: () => void;
  onExport: (columns: string[]) => void;
  /** Define quais colunas exibir: BASE | FGTS | CLT | MERCANTIL */
  mode: "BASE" | "FGTS" | "CLT" | "MERCANTIL";
}

type Group = "Cadastral" | "Produto" | "Registro";

type ColumnDef = {
  id: string;
  label: string;
  selected: boolean;
  group: Group;
};

/** Catálogo de colunas por modo (alinhado ao backend) com a MESMA organização de grupos do ColumnsModal */
const COLUMNS_BASE: ColumnDef[] = [
  { id: "cpf", label: "CPF", selected: true, group: "Cadastral" },
  { id: "nome", label: "Nome", selected: true, group: "Cadastral" },
  { id: "created_at", label: "Criado em (Lead)", selected: true, group: "Cadastral" },
  { id: "updated_at", label: "Atualizado em (Lead)", selected: true, group: "Cadastral" },
  { id: "data_nascimento", label: "Data de nascimento", selected: true, group: "Cadastral" },
  { id: "fone1", label: "Telefone 1", selected: true, group: "Cadastral" },
  { id: "fone2", label: "Telefone 2", selected: true, group: "Cadastral" },
  { id: "fone3", label: "Telefone 3", selected: true, group: "Cadastral" },
  { id: "fone4", label: "Telefone 4", selected: true, group: "Cadastral" },
  { id: "classe_fone1", label: "Classe do telefone 1", selected: true, group: "Cadastral" },
  { id: "classe_fone2", label: "Classe do telefone 2", selected: true, group: "Cadastral" },
  { id: "classe_fone3", label: "Classe do telefone 3", selected: true, group: "Cadastral" },
  { id: "classe_fone4", label: "Classe do telefone 4", selected: true, group: "Cadastral" },
  { id: "ultima_origem_cadastral", label: "Origem cadastral", selected: true, group: "Registro" },
  { id: "ultima_origem_higienizacao", label: "Origem de higienização", selected: true, group: "Registro" },
];

const COLUMNS_FGTS: ColumnDef[] = [
  // Cadastral
  { id: "cpf", label: "CPF", selected: true, group: "Cadastral" },
  { id: "nome", label: "Nome", selected: true, group: "Cadastral" },
  { id: "created_at", label: "Criado em (Lead)", selected: true, group: "Cadastral" },
  { id: "updated_at", label: "Atualizado em (Lead)", selected: true, group: "Cadastral" },
  { id: "data_nascimento", label: "Data de nascimento", selected: true, group: "Cadastral" },
  { id: "fone1", label: "Telefone 1", selected: true, group: "Cadastral" },
  { id: "fone2", label: "Telefone 2", selected: true, group: "Cadastral" },
  { id: "fone3", label: "Telefone 3", selected: true, group: "Cadastral" },
  { id: "fone4", label: "Telefone 4", selected: true, group: "Cadastral" },
  { id: "classe_fone1", label: "Classe do telefone 1", selected: true, group: "Cadastral" },
  { id: "classe_fone2", label: "Classe do telefone 2", selected: true, group: "Cadastral" },
  { id: "classe_fone3", label: "Classe do telefone 3", selected: true, group: "Cadastral" },
  { id: "classe_fone4", label: "Classe do telefone 4", selected: true, group: "Cadastral" },

  // Produto (FGTS)
  { id: "consulta", label: "Motivo da consulta", selected: true, group: "Produto" },
  { id: "saldo", label: "Saldo", selected: true, group: "Produto" },
  { id: "libera", label: "Valor liberado", selected: true, group: "Produto" },
  { id: "data_atualizacao", label: "Data de higienização", selected: true, group: "Produto" },
  { id: "contracts_count", label: "Quantidade de contratos", selected: true, group: "Produto" },
  { id: "data_contrato_recente", label: "Contrato mais recente (data)", selected: true, group: "Produto" },
  { id: "vendedor", label: "Vendedor", selected: true, group: "Produto" },
  { id: "fgts_off_authorized", label: "Autorizado (FGTS Off)", selected: true, group: "Produto" },
  { id: "fgts_off_consultado_em", label: "Data consulta (FGTS Off)", selected: true, group: "Produto" },

  // Registro
  { id: "ultima_origem_cadastral", label: "Origem cadastral", selected: true, group: "Registro" },
  { id: "ultima_origem_higienizacao", label: "Origem de higienização", selected: true, group: "Registro" },
];

const COLUMNS_CLT: ColumnDef[] = [
  // Cadastral
  { id: "cpf", label: "CPF", selected: true, group: "Cadastral" },
  { id: "nome", label: "Nome", selected: true, group: "Cadastral" },
  { id: "created_at", label: "Criado em (Lead)", selected: true, group: "Cadastral" },
  { id: "updated_at", label: "Atualizado em (Lead)", selected: true, group: "Cadastral" },
  { id: "data_nascimento", label: "Data de nascimento", selected: true, group: "Cadastral" },
  { id: "fone1", label: "Telefone 1", selected: true, group: "Cadastral" },
  { id: "fone2", label: "Telefone 2", selected: true, group: "Cadastral" },
  { id: "fone3", label: "Telefone 3", selected: true, group: "Cadastral" },
  { id: "fone4", label: "Telefone 4", selected: true, group: "Cadastral" },
  { id: "classe_fone1", label: "Classe do telefone 1", selected: true, group: "Cadastral" },
  { id: "classe_fone2", label: "Classe do telefone 2", selected: true, group: "Cadastral" },
  { id: "classe_fone3", label: "Classe do telefone 3", selected: true, group: "Cadastral" },
  { id: "classe_fone4", label: "Classe do telefone 4", selected: true, group: "Cadastral" },

  // Produto (CLT)
  { id: "elegivel", label: "Elegível", selected: true, group: "Produto" },
  { id: "clt_consultado_em", label: "Data consulta", selected: true, group: "Produto" },
  // 🆕 novo campo no export
  { id: "clt_dados_atualizados_em", label: "Data dados", selected: true, group: "Produto" },

  { id: "idade", label: "Idade", selected: true, group: "Produto" },
  { id: "sexo", label: "Sexo", selected: true, group: "Produto" },
  { id: "data_admissao", label: "Data admissão", selected: true, group: "Produto" },
  { id: "meses_admissao", label: "Tempo de casa (meses)", selected: true, group: "Produto" },
  { id: "categoria_trabalhador_codigo", label: "Categoria do trabalhador (cód.)", selected: true, group: "Produto" },
  { id: "matricula", label: "Matrícula", selected: true, group: "Produto" },
  { id: "inicio_atividade_empregador", label: "Início atividade (empregador)", selected: true, group: "Produto" },
  { id: "valor_renda", label: "Renda", selected: true, group: "Produto" },
  { id: "valor_base_margem", label: "Base de margem", selected: true, group: "Produto" },
  { id: "margem_disponivel", label: "Margem disponível", selected: true, group: "Produto" },
  { id: "valor_max_prestacao", label: "Prestação máxima", selected: true, group: "Produto" },
  { id: "qtd_emprestimos_ativos_suspensos", label: "Empréstimos ativos/suspensos", selected: true, group: "Produto" },
  { id: "emprestimos_legados", label: "Empréstimos legados", selected: true, group: "Produto" },
  { id: "not_found", label: "Não encontrado", selected: true, group: "Produto" },
  { id: "politica_credito_aprovado", label: "Política de crédito aprovada", selected: true, group: "Produto" },
  { id: "politica_credito_mensagem", label: "Política de crédito mensagem", selected: true, group: "Produto" },
  { id: "politica_credito_valor_maximo_disponivel", label: "Política de crédito valor máximo disponível", selected: true, group: "Produto" },
  { id: "politica_credito_prazo_maximo_disponivel", label: "Política de crédito prazo máximo disponível", selected: true, group: "Produto" },
  { id: "politica_credito_data_consulta", label: "Política de crédito data consulta", selected: true, group: "Produto" },
  { id: "politica_credito_tabela_aprovada", label: "Política de crédito tabela aprovada", selected: true, group: "Produto" },

  // Registro
  { id: "ultima_origem_cadastral", label: "Origem cadastral", selected: true, group: "Registro" },
];

const COLUMNS_MERCANTIL: ColumnDef[] = [
  // Cadastral
  { id: "cpf", label: "CPF", selected: true, group: "Cadastral" },
  { id: "nome", label: "Nome", selected: true, group: "Cadastral" },
  { id: "created_at", label: "Criado em (Lead)", selected: true, group: "Cadastral" },
  { id: "updated_at", label: "Atualizado em (Lead)", selected: true, group: "Cadastral" },
  { id: "data_nascimento", label: "Data de nascimento", selected: true, group: "Cadastral" },
  { id: "fone1", label: "Telefone 1", selected: true, group: "Cadastral" },
  { id: "fone2", label: "Telefone 2", selected: true, group: "Cadastral" },
  { id: "fone3", label: "Telefone 3", selected: true, group: "Cadastral" },
  { id: "fone4", label: "Telefone 4", selected: true, group: "Cadastral" },
  { id: "classe_fone1", label: "Classe do telefone 1", selected: true, group: "Cadastral" },
  { id: "classe_fone2", label: "Classe do telefone 2", selected: true, group: "Cadastral" },
  { id: "classe_fone3", label: "Classe do telefone 3", selected: true, group: "Cadastral" },
  { id: "classe_fone4", label: "Classe do telefone 4", selected: true, group: "Cadastral" },

  // Produto (Mercantil)
  { id: "mercantil_status", label: "Status", selected: true, group: "Produto" },
  { id: "mercantil_data_hora_origem", label: "Data/hora consulta", selected: true, group: "Produto" },
  { id: "mercantil_mensagem_erro", label: "Mensagem", selected: true, group: "Produto" },
  { id: "mercantil_valor_emprestimo", label: "Valor empréstimo", selected: true, group: "Produto" },
  { id: "mercantil_valor_iof", label: "Valor IOF", selected: true, group: "Produto" },
  { id: "mercantil_valor_financiado", label: "Valor financiado", selected: true, group: "Produto" },
  { id: "mercantil_valor_liberado", label: "Valor liberado", selected: true, group: "Produto" },
  { id: "mercantil_data_primeiro_vencimento", label: "Data 1º vencimento", selected: true, group: "Produto" },
  { id: "mercantil_quantidade_parcelas", label: "Qtd. parcelas", selected: true, group: "Produto" },
  { id: "mercantil_valor_parcela", label: "Valor parcela", selected: true, group: "Produto" },
  { id: "mercantil_taxa_juros_mes", label: "Taxa juros (mês)", selected: true, group: "Produto" },

  // Registro
  { id: "ultima_origem_cadastral", label: "Origem cadastral", selected: true, group: "Registro" },
  { id: "ultima_origem_mercantil", label: "Origem mercantil", selected: true, group: "Registro" },
];

export const ExportModal = ({
  isOpen,
  onClose,
  onExport,
  mode,
}: ExportModalProps) => {
  const columnsSource = useMemo<ColumnDef[]>(
    () => (mode === "BASE" ? COLUMNS_BASE : mode === "FGTS" ? COLUMNS_FGTS : mode === "CLT" ? COLUMNS_CLT : COLUMNS_MERCANTIL),
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
    if (isOpen) document.body.style.overflow = "hidden";
    else document.body.style.overflow = "";
    return () => { document.body.style.overflow = ""; };
  }, [isOpen]);

  const handleColumnToggle = (columnId: string) => {
    setSelectedColumns(prev => ({ ...prev, [columnId]: !prev[columnId] }));
  };

  const handleSelectAll = () => {
    const allSelected = columnsSource.every(col => selectedColumns[col.id]);
    const newState = columnsSource.reduce((acc, col) => {
      acc[col.id] = !allSelected;
      return acc;
    }, {} as Record<string, boolean>);
    setSelectedColumns(newState);
  };

  const handleSelectGroup = (group: Group) => {
    const groupCols = columnsSource.filter(c => c.group === group);
    const allGroupSelected = groupCols.every(c => selectedColumns[c.id]);
    const newState = { ...selectedColumns };
    groupCols.forEach(c => { newState[c.id] = !allGroupSelected; });
    setSelectedColumns(newState);
  };

  const handleExportClick = () => {
    const columnsToExport = Object.keys(selectedColumns).filter(key => selectedColumns[key]);
    onExport(columnsToExport);
    onClose();
  };

  const selectedCount = Object.values(selectedColumns).filter(Boolean).length;
  const groupsOrdered: Group[] = ["Cadastral", "Produto", "Registro"];

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 animate-fade-in">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 animate-scale-in">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 className="text-xl font-semibold text-gray-900">
            Exportar para Excel — {mode}
          </h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 transition-colors duration-200">
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-700">Selecione as colunas para exportar</h3>
            <div className="space-x-2">
              <Button onClick={handleSelectAll} variant="outline" size="sm" className="text-xs">
                {selectedCount === columnsSource.length ? "Desmarcar todas" : "Selecionar todas"}
              </Button>
            </div>
          </div>

          <div className="max-h-80 overflow-y-auto border border-gray-200 rounded-lg p-3">
            {groupsOrdered.map((group) => {
              const items = columnsSource.filter(c => c.group === group);
              if (!items.length) return null;

              const groupSelectedCount = items.filter(i => selectedColumns[i.id]).length;
              const allGroupSelected = groupSelectedCount === items.length;
              const someGroupSelected = groupSelectedCount > 0 && !allGroupSelected;

              const groupTitle =
                group === "Produto" ? `Produto (${mode})` : group;

              return (
                <div key={group} className="mb-4">
                  <div className="flex items-center justify-between mb-2">
                    <div className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                      {groupTitle}
                    </div>
                    <button
                      onClick={() => handleSelectGroup(group)}
                      className={`text-[11px] px-2 py-1 rounded border transition
                        ${allGroupSelected
                          ? "border-gray-300 text-gray-700 hover:bg-gray-50"
                          : someGroupSelected
                            ? "border-blue-300 text-blue-700 hover:bg-blue-50"
                            : "border-gray-300 text-gray-700 hover:bg-gray-50"}`}
                      title={allGroupSelected ? "Desmarcar grupo" : "Selecionar grupo"}
                    >
                      {allGroupSelected ? "Desmarcar grupo" : "Selecionar grupo"}
                    </button>
                  </div>

                  <div className="space-y-1">
                    {items.map((column) => (
                      <label key={column.id} className="flex items-center cursor-pointer hover:bg-gray-50 p-2 rounded">
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
          <Button variant="outline" onClick={onClose} className="text-gray-700 border-gray-300 hover:bg-gray-50">
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
