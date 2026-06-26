import { useEffect, useMemo, useState } from "react";
import { X, CheckSquare } from "lucide-react";
import { Button } from "@/components/ui/button";

type Mode = "BASE" | "FGTS" | "CLT" | "MERCANTIL" | "UY3";

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

type Group = "Cadastral" | "Produto" | "Registro";

type CatalogItem = {
  id: string;
  label: string;
  group: Group;
  pinned?: boolean;
};

/** Catálogo (somente colunas configuráveis; Ações é fixa) */
const CATALOG: Record<Mode, CatalogItem[]> = {
  BASE: [
    { id: "cpf", label: "CPF", group: "Cadastral", pinned: true },
    { id: "nome", label: "Nome", group: "Cadastral", pinned: true },
    { id: "created_at", label: "Criado em (Lead)", group: "Cadastral" },
    { id: "updated_at", label: "Atualizado em (Lead)", group: "Cadastral" },
    { id: "data_nascimento", label: "Data de nascimento", group: "Cadastral" },
    { id: "telefone_1", label: "Fone 1", group: "Cadastral" },
    { id: "classe_1", label: "Classe 1", group: "Cadastral" },
    { id: "telefone_2", label: "Fone 2", group: "Cadastral" },
    { id: "classe_2", label: "Classe 2", group: "Cadastral" },
    { id: "telefone_3", label: "Fone 3", group: "Cadastral" },
    { id: "classe_3", label: "Classe 3", group: "Cadastral" },
    { id: "telefone_4", label: "Fone 4", group: "Cadastral" },
    { id: "classe_4", label: "Classe 4", group: "Cadastral" },
    { id: "ultima_origem_cadastral", label: "Origem cadastral", group: "Registro" },
    { id: "ultima_origem_higienizacao", label: "Origem de higienização", group: "Registro" },
  ],

  FGTS: [
    // Cadastral
    { id: "cpf", label: "CPF", group: "Cadastral", pinned: true },
    { id: "nome", label: "Nome", group: "Cadastral", pinned: true },
    { id: "created_at", label: "Criado em (Lead)", group: "Cadastral" },
    { id: "updated_at", label: "Atualizado em (Lead)", group: "Cadastral" },
    { id: "data_nascimento", label: "Data de nascimento", group: "Cadastral" },

    // Telefones (pares fone/classe)
    { id: "telefone_1", label: "Fone 1", group: "Cadastral" },
    { id: "classe_1", label: "Classe 1", group: "Cadastral" },
    { id: "telefone_2", label: "Fone 2", group: "Cadastral" },
    { id: "classe_2", label: "Classe 2", group: "Cadastral" },
    { id: "telefone_3", label: "Fone 3", group: "Cadastral" },
    { id: "classe_3", label: "Classe 3", group: "Cadastral" },
    { id: "telefone_4", label: "Fone 4", group: "Cadastral" },
    { id: "classe_4", label: "Classe 4", group: "Cadastral" },

    // Produto (FGTS)
    { id: "consulta", label: "Motivo da consulta", group: "Produto" },
    { id: "saldo", label: "Saldo", group: "Produto" },
    { id: "libera", label: "Valor liberado", group: "Produto" },
    { id: "data_atualizacao", label: "Data de higienização", group: "Produto" },
    { id: "fgts_off_authorized", label: "Autorizado (FGTS Off)", group: "Produto" },
    { id: "fgts_off_consultado_em", label: "Data consulta (FGTS Off)", group: "Produto" },
    { id: "contratos", label: "Quantidade de contratos", group: "Produto" },
    /** 🆕 último contrato */
    { id: "data_contrato_recente", label: "Último contrato (data)", group: "Produto" },
    { id: "vendedor", label: "Vendedor do último contrato", group: "Produto" },

    // Registro
    { id: "ultima_origem_cadastral", label: "Origem cadastral", group: "Registro" },
    { id: "ultima_origem_higienizacao", label: "Origem de higienização", group: "Registro" },
  ],

  CLT: [
    // Cadastral
    { id: "cpf", label: "CPF", group: "Cadastral", pinned: true },
    { id: "nome", label: "Nome", group: "Cadastral", pinned: true },
    { id: "created_at", label: "Criado em (Lead)", group: "Cadastral" },
    { id: "updated_at", label: "Atualizado em (Lead)", group: "Cadastral" },
    { id: "data_nascimento", label: "Data de nascimento", group: "Cadastral" },

    // Telefones (pares fone/classe)
    { id: "telefone_1", label: "Fone 1", group: "Cadastral" },
    { id: "classe_1", label: "Classe 1", group: "Cadastral" },
    { id: "telefone_2", label: "Fone 2", group: "Cadastral" },
    { id: "classe_2", label: "Classe 2", group: "Cadastral" },
    { id: "telefone_3", label: "Fone 3", group: "Cadastral" },
    { id: "classe_3", label: "Classe 3", group: "Cadastral" },
    { id: "telefone_4", label: "Fone 4", group: "Cadastral" },
    { id: "classe_4", label: "Classe 4", group: "Cadastral" },

    // Produto (CLT)
    { id: "elegivel", label: "Elegível", group: "Produto" },
    { id: "clt_consultado_em", label: "Data consulta", group: "Produto" },
    { id: "clt_dados_atualizados_em", label: "Data dados", group: "Produto" }, // 🆕
    { id: "idade", label: "Idade", group: "Produto" },
    { id: "sexo", label: "Sexo", group: "Produto" },
    { id: "data_admissao", label: "Data admissão", group: "Produto" },
    { id: "meses_admissao", label: "Tempo de casa (meses)", group: "Produto" },
    { id: "categoria_trabalhador_codigo", label: "Categoria do trabalhador (cód.)", group: "Produto" },
    { id: "matricula", label: "Matrícula", group: "Produto" },
    { id: "inicio_atividade_empregador", label: "Início atividade (empregador)", group: "Produto" },
    { id: "valor_renda", label: "Renda", group: "Produto" },
    { id: "valor_base_margem", label: "Base de margem", group: "Produto" },
    { id: "margem_disponivel", label: "Margem disponível", group: "Produto" },
    { id: "valor_max_prestacao", label: "Prestação máxima", group: "Produto" },
    { id: "politica_credito_aprovado", label: "Política crédito aprovada", group: "Produto" },
    { id: "politica_credito_mensagem", label: "Política crédito mensagem", group: "Produto" },
    { id: "politica_credito_valor_maximo_disponivel", label: "Política crédito valor máx.", group: "Produto" },
    { id: "politica_credito_prazo_maximo_disponivel", label: "Política crédito prazo máx.", group: "Produto" },
    { id: "politica_credito_data_consulta", label: "Política crédito data consulta", group: "Produto" },
    { id: "politica_credito_tabela_aprovada", label: "Política crédito tabela", group: "Produto" },
    { id: "qtd_emprestimos_ativos_suspensos", label: "Empréstimos ativos/suspensos", group: "Produto" },
    { id: "emprestimos_legados", label: "Empréstimos legados", group: "Produto" },

    // Registro
    { id: "ultima_origem_cadastral", label: "Origem cadastral", group: "Registro" },
  ],

  MERCANTIL: [
    // Cadastral
    { id: "cpf", label: "CPF", group: "Cadastral", pinned: true },
    { id: "nome", label: "Nome", group: "Cadastral", pinned: true },
    { id: "created_at", label: "Criado em (Lead)", group: "Cadastral" },
    { id: "updated_at", label: "Atualizado em (Lead)", group: "Cadastral" },
    { id: "data_nascimento", label: "Data de nascimento", group: "Cadastral" },

    // Telefones (pares fone/classe)
    { id: "telefone_1", label: "Fone 1", group: "Cadastral" },
    { id: "classe_1", label: "Classe 1", group: "Cadastral" },
    { id: "telefone_2", label: "Fone 2", group: "Cadastral" },
    { id: "classe_2", label: "Classe 2", group: "Cadastral" },
    { id: "telefone_3", label: "Fone 3", group: "Cadastral" },
    { id: "classe_3", label: "Classe 3", group: "Cadastral" },
    { id: "telefone_4", label: "Fone 4", group: "Cadastral" },
    { id: "classe_4", label: "Classe 4", group: "Cadastral" },

    // Produto (Mercantil)
    { id: "mercantil_status", label: "Status", group: "Produto" },
    { id: "mercantil_data_hora_origem", label: "Data/hora consulta", group: "Produto" },
    { id: "mercantil_mensagem_erro", label: "Mensagem de erro", group: "Produto" },
    { id: "mercantil_valor_emprestimo", label: "Valor empréstimo", group: "Produto" },
    { id: "mercantil_valor_iof", label: "Valor IOF", group: "Produto" },
    { id: "mercantil_valor_financiado", label: "Valor financiado", group: "Produto" },
    { id: "mercantil_valor_liberado", label: "Valor liberado", group: "Produto" },
    { id: "mercantil_data_primeiro_vencimento", label: "Data 1º vencimento", group: "Produto" },
    { id: "mercantil_quantidade_parcelas", label: "Qtd. parcelas", group: "Produto" },
    { id: "mercantil_valor_parcela", label: "Valor parcela", group: "Produto" },
    { id: "mercantil_taxa_juros_mes", label: "Taxa juros (mês)", group: "Produto" },

    // Registro
    { id: "ultima_origem_cadastral", label: "Origem cadastral", group: "Registro" },
    { id: "ultima_origem_mercantil", label: "Origem mercantil", group: "Registro" },
  ],

  UY3: [
    { id: "cpf", label: "CPF", group: "Cadastral", pinned: true },
    { id: "nome", label: "Nome", group: "Cadastral", pinned: true },
    { id: "created_at", label: "Criado em (Lead)", group: "Cadastral" },
    { id: "updated_at", label: "Atualizado em (Lead)", group: "Cadastral" },
    { id: "data_nascimento", label: "Data de nascimento", group: "Cadastral" },
    { id: "telefone_1", label: "Fone 1", group: "Cadastral" },
    { id: "classe_1", label: "Classe 1", group: "Cadastral" },
    { id: "telefone_2", label: "Fone 2", group: "Cadastral" },
    { id: "classe_2", label: "Classe 2", group: "Cadastral" },
    { id: "telefone_3", label: "Fone 3", group: "Cadastral" },
    { id: "classe_3", label: "Classe 3", group: "Cadastral" },
    { id: "telefone_4", label: "Fone 4", group: "Cadastral" },
    { id: "classe_4", label: "Classe 4", group: "Cadastral" },

    { id: "uy3_type_webhook", label: "Tipo webhook", group: "Produto" },
    { id: "uy3_status", label: "Status", group: "Produto" },
    { id: "uy3_consultado_em", label: "Consultado em", group: "Produto" },
    { id: "uy3_data_admissao", label: "Data admissão", group: "Produto" },
    { id: "uy3_valor_liberado", label: "Valor liberado", group: "Produto" },
    { id: "uy3_numero_parcelas", label: "Qtd. parcelas", group: "Produto" },
    { id: "uy3_codigo_requisicao", label: "Código requisição", group: "Produto" },
    { id: "uy3_margem_disponivel", label: "Margem disponível", group: "Produto" },
    { id: "uy3_elegivel_emprestimo", label: "Elegível empréstimo", group: "Produto" },
    { id: "uy3_numero_inscricao_empregador", label: "Inscrição empregador", group: "Produto" },
    { id: "uy3_pessoa_exposta_politicamente_codigo", label: "PEP código", group: "Produto" },
    { id: "uy3_data_hora_validade_solicitacao", label: "Validade solicitação", group: "Produto" },
    { id: "uy3_is_mei", label: "É MEI", group: "Produto" },
    { id: "uy3_is_judicial_recovery", label: "Recuperação judicial", group: "Produto" },

    { id: "ultima_origem_cadastral", label: "Origem cadastral", group: "Registro" },
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
    if (col?.pinned) return;
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

  const groups: Group[] = ["Cadastral", "Produto", "Registro"];

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
