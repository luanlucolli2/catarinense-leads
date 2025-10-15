import { useEffect, useMemo, useState } from "react";
import { X, CheckSquare } from "lucide-react";
import { Button } from "@/components/ui/button";

type Mode = "FGTS" | "CLT";

interface ColumnsModalProps {
  isOpen: boolean;
  onClose: () => void;
  mode: Mode;

  /** ids atualmente visíveis (persistidos) */
  visibleColumns: string[];
  /** salvar nova seleção (persistência fica no pai) */
  onSave: (columns: string[]) => void;

  /** defaults (para "Redefinir") */
  defaultVisibleColumns: string[];
}

/** Catálogo (somente colunas configuráveis; Ações é sempre fixa e não aparece aqui) */
const CATALOG: Record<Mode, { id: string; label: string; group: string; pinned?: boolean }[]> = {
  FGTS: [
    { id: "cpf", label: "CPF", group: "Cadastrais", pinned: true },
    { id: "nome", label: "Nome", group: "Cadastrais", pinned: true },
    { id: "data_nascimento", label: "Data de Nascimento", group: "Cadastrais" },
    { id: "telefone", label: "Telefone", group: "Cadastrais" },
    { id: "classe", label: "Classe do Telefone", group: "Cadastrais" },

    { id: "consulta", label: "Motivo (Consulta)", group: "Consulta" },

    { id: "saldo", label: "Saldo", group: "Financeiro" },
    { id: "libera", label: "Libera", group: "Financeiro" },

    { id: "data_atualizacao", label: "Data de Higienização", group: "Datas" },
    { id: "fgts_off_authorized", label: "FGTS OFF Autorizado", group: "FGTS OFF" },
    { id: "fgts_off_consultado_em", label: "FGTS OFF Consultado em", group: "FGTS OFF" },

    { id: "contratos", label: "Qtde de Contratos", group: "Histórico" },

    { id: "ultima_origem_cadastral", label: "Última Origem (Cadastral)", group: "Origens" },
    { id: "ultima_origem_higienizacao", label: "Última Origem (Higienização)", group: "Origens" },
  ],
  CLT: [
    { id: "cpf", label: "CPF", group: "Cadastrais", pinned: true },
    { id: "nome", label: "Nome", group: "Cadastrais", pinned: true },
    { id: "data_nascimento", label: "Data de Nascimento", group: "Cadastrais" },
    { id: "telefone", label: "Telefone", group: "Cadastrais" },
    { id: "classe", label: "Classe do Telefone", group: "Cadastrais" },
    { id: "idade", label: "Idade", group: "Cadastrais" },
    { id: "sexo", label: "Sexo", group: "Cadastrais" },

    { id: "elegivel", label: "Situação (Elegível/Não)", group: "Consulta CLT" },
    { id: "clt_consultado_em", label: "Consulta CLT — Data", group: "Consulta CLT" },

    { id: "data_admissao", label: "Admissão", group: "Vínculo" },
    { id: "meses_admissao", label: "Tempo de Casa (meses)", group: "Vínculo" },
    { id: "categoria_trabalhador_codigo", label: "Categoria Trab. (cód.)", group: "Vínculo" },

    { id: "valor_renda", label: "Renda", group: "Financeiro" },
    { id: "valor_base_margem", label: "Base de Margem", group: "Financeiro" },
    { id: "margem_disponivel", label: "Margem Disponível", group: "Financeiro" },
    { id: "valor_max_prestacao", label: "Prestação Máx.", group: "Financeiro" },

    { id: "qtd_emprestimos_ativos_suspensos", label: "Empréstimos Ativos/Suspensos", group: "Histórico" },
    { id: "emprestimos_legados", label: "Empréstimos Legados", group: "Histórico" },

    { id: "ultima_origem_cadastral", label: "Última Origem (Cadastral)", group: "Origens" }, // no final da tabela
  ],
};

export const ColumnsModal = ({
  isOpen,
  onClose,
  mode,
  visibleColumns,
  onSave,
  defaultVisibleColumns,
}: ColumnsModalProps) => {
  const columnsSource = useMemo(() => CATALOG[mode], [mode]);
  const [selected, setSelected] = useState<Record<string, boolean>>({});

  useEffect(() => {
    if (isOpen) {
      const init = columnsSource.reduce((acc, col) => {
        acc[col.id] = visibleColumns.includes(col.id);
        return acc;
      }, {} as Record<string, boolean>);
      setSelected(init);
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [isOpen, columnsSource, visibleColumns]);

  if (!isOpen) return null;

  const toggle = (id: string) => {
    const col = columnsSource.find(c => c.id === id);
    if (col?.pinned) return; // não permite desmarcar fixas
    setSelected(prev => ({ ...prev, [id]: !prev[id] }));
  };

  const handleSelectAll = () => {
    const allSelected = columnsSource.every((c) => selected[c.id] || c.pinned);
    const newState = columnsSource.reduce((acc, c) => {
      acc[c.id] = c.pinned ? true : !allSelected;
      return acc;
    }, {} as Record<string, boolean>);
    setSelected(newState);
  };

  const handleReset = () => {
    const base = columnsSource.reduce((acc, c) => {
      acc[c.id] = c.pinned ? true : defaultVisibleColumns.includes(c.id);
      return acc;
    }, {} as Record<string, boolean>);
    setSelected(base);
  };

  const handleSave = () => {
    const cols = columnsSource
      .filter(c => selected[c.id] || c.pinned)
      .map(c => c.id);
    onSave(cols);
    onClose();
  };

  const selectedCount = columnsSource.filter(c => selected[c.id] || c.pinned).length;

  /** Agrupamento simples para UI */
  const groups = Array.from(new Set(columnsSource.map(c => c.group)));

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 animate-fade-in">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 animate-scale-in">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 className="text-xl font-semibold text-gray-900">
            Colunas visíveis — {mode}
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
            <div className="space-x-2">
              <Button onClick={handleSelectAll} variant="outline" size="sm" className="text-xs">
                {columnsSource.every((c) => selected[c.id] || c.pinned) ? "Desmarcar todas" : "Selecionar todas"}
              </Button>
              <Button onClick={handleReset} variant="outline" size="sm" className="text-xs">
                Redefinir
              </Button>
            </div>
            <div className="text-xs text-gray-600">
              <strong>{selectedCount}</strong> coluna(s) exibida(s)
            </div>
          </div>

          <div className="max-h-72 overflow-y-auto border border-gray-200 rounded-lg p-3">
            {groups.map((g) => (
              <div key={g} className="mb-3">
                <div className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{g}</div>
                <div className="space-y-1">
                  {columnsSource.filter(c => c.group === g).map((c) => (
                    <label key={c.id} className="flex items-center cursor-pointer hover:bg-gray-50 p-2 rounded">
                      <input
                        type="checkbox"
                        checked={!!selected[c.id] || !!c.pinned}
                        disabled={!!c.pinned}
                        onChange={() => toggle(c.id)}
                        className="mr-3 text-blue-600"
                      />
                      <span className="text-sm text-gray-700 flex items-center">
                        {c.label}
                        {c.pinned && (
                          <span className="ml-2 inline-flex items-center text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">
                            <CheckSquare className="w-3 h-3 mr-1" /> Fixa
                          </span>
                        )}
                      </span>
                    </label>
                  ))}
                </div>
              </div>
            ))}
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <p className="text-sm text-blue-800">
              As colunas <strong>CPF</strong> e <strong>Nome</strong> são sempre exibidas.
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
            onClick={handleSave}
            className="bg-blue-600 hover:bg-blue-700"
          >
            Salvar
          </Button>
        </div>
      </div>
    </div>
  );
};
