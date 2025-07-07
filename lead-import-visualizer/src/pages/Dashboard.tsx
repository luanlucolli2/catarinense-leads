import { useState, useMemo } from "react";
import { useQuery, keepPreviousData } from "@tanstack/react-query";
import { toast } from "sonner";

import { LeadsTable, ProcessedLead } from "@/components/LeadsTable";
import { LeadsControls } from "@/components/LeadsControls";
import { ImportModal } from "@/components/ImportModal";
import { ExportModal } from "@/components/ExportModal";
import axiosClient from "@/api/axiosClient";
import { formatCPF, formatCurrency, formatDate, formatPhone } from "@/lib/formatters";

interface LeadFromApi {
  id: number;
  cpf: string;
  nome: string | null;
  data_nascimento: string | null;
  fone1: string | null;
  classe_fone1: string | null;
  fone2: string | null;
  classe_fone2: string | null;
  fone3: string | null;
  classe_fone3: string | null;
  fone4: string | null;
  classe_fone4: string | null;
  origem_cadastro: string | null;
  data_importacao_cadastro: string | null;
  consulta: string | null;
  data_atualizacao: string | null;
  saldo: string | null;
  libera: string | null;
}

interface PaginatedLeadsResponse {
  data: LeadFromApi[];
  current_page: number;
  last_page: number;
  total: number;
}

const Dashboard = () => {
  const [searchValue, setSearchValue] = useState("");
  const [eligibleFilter, setEligibleFilter] = useState<"todos" | "elegiveis" | "nao-elegiveis">("todos");
  const [motivosFilter, setMotivosFilter] = useState<string[]>([]);
  const [origemFilter, setOrigemFilter] = useState<string[]>([]);
  const [dateFromFilter, setDateFromFilter] = useState("");
  const [dateToFilter, setDateToFilter] = useState("");
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [isExportModalOpen, setIsExportModalOpen] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);

  const { data: paginatedData, isLoading, isError } = useQuery<PaginatedLeadsResponse>({
    queryKey: ['leads', currentPage, searchValue, eligibleFilter],
    queryFn: async () => {
      const params = new URLSearchParams({ page: currentPage.toString() });
      if (searchValue) params.set('search', searchValue);
      if (eligibleFilter !== 'todos') params.set('status', eligibleFilter);
      const response = await axiosClient.get('/leads', { params });
      return response.data;
    },
    placeholderData: keepPreviousData,
  });

  const processedLeads: ProcessedLead[] = useMemo(() => {
    if (!paginatedData?.data) return [];

    return paginatedData.data.map(lead => {
      const liberaIsNumeric = lead.libera && !isNaN(parseFloat(lead.libera.replace(',', '.')));
      const liberaValue = liberaIsNumeric ? parseFloat(lead.libera!.replace(',', '.')) : 0;

      const status: "Elegível" | "Inelegível" =
        lead.consulta === "Saldo FACTA" && liberaValue > 0 ? "Elegível" : "Inelegível";

      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter(f => f.fone && f.fone !== '--');

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || '--',
        data_nascimento: formatDate(lead.data_nascimento),
        telefones,
        status,
        contratos: 0, // Placeholder
        saldo: formatCurrency(lead.saldo),
        libera: formatCurrency(lead.libera),
        data_atualizacao: formatDate(lead.data_atualizacao),
        consulta: lead.consulta || '--',
        origem_cadastro: lead.origem_cadastro,
      };
    });
  }, [paginatedData]);

  const availableMotivos = ["Aprovado", "Não autorizado", "Saldo insuficiente"];
  const availableOrigens = ["Sistema Interno", "Planilha Excel", "API Externa"];

  const handleApplyFilters = () => {
    setCurrentPage(1);
    toast.success("Filtros aplicados. Buscando dados...");
  };

  const handleClearFilters = () => {
    setSearchValue("");
    setEligibleFilter("todos");
    setMotivosFilter([]);
    setOrigemFilter([]);
    setDateFromFilter("");
    setDateToFilter("");
    setCurrentPage(1);
    toast.info("Filtros limpos.");
  };

  const handleImport = (type: string, file: File, origin?: string) => {
    console.log(`Importing ${type} from file:`, file.name, origin ? `with origin: ${origin}` : '');
    toast.success("Importação iniciada", { description: `Processando arquivo ${file.name}...` });
  };

  const handleExport = (columns: string[]) => {
    console.log("Exporting columns:", columns);
    toast.info("Exportação iniciada", { description: `Gerando arquivo Excel com ${columns.length} colunas selecionadas.` });
  };

  if (isLoading && !paginatedData) return <div className="p-6 text-center">Carregando leads...</div>;
  if (isError) return <div className="p-6 text-center text-red-500">Erro ao carregar os dados. Verifique a conexão com a API.</div>;

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">Dashboard</h1>
        <p className="text-gray-600 text-sm lg:text-base">
          Gerencie e visualize seus leads importados ({paginatedData?.total ?? 0} leads encontrados)
        </p>
      </div>

      <LeadsControls
        onImportClick={() => setIsImportModalOpen(true)}
        onExportClick={() => setIsExportModalOpen(true)}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        eligibleFilter={eligibleFilter}
        onEligibleFilterChange={setEligibleFilter}
        motivosFilter={motivosFilter}
        onMotivosFilterChange={setMotivosFilter}
        origemFilter={origemFilter}
        onOrigemFilterChange={setOrigemFilter}
        dateFromFilter={dateFromFilter}
        onDateFromFilterChange={setDateFromFilter}
        dateToFilter={dateToFilter}
        onDateToFilterChange={setDateToFilter}
        onApplyFilters={handleApplyFilters}
        onClearFilters={handleClearFilters}
        availableMotivos={availableMotivos}
        availableOrigens={availableOrigens}
        hasActiveFilters={searchValue !== "" || eligibleFilter !== "todos" || motivosFilter.length > 0 || origemFilter.length > 0 || dateFromFilter !== "" || dateToFilter !== ""}
        contractDateFromFilter="" onContractDateFromFilterChange={() => { }}
        contractDateToFilter="" onContractDateToFilterChange={() => { }}
        cpfMassFilter="" onCpfMassFilterChange={() => { }}
        namesMassFilter="" onNamesMassFilterChange={() => { }}
        phonesMassFilter="" onPhonesMassFilterChange={() => { }}
      />

      <LeadsTable
        leads={processedLeads}
        currentPage={paginatedData?.current_page ?? 1}
        totalPages={paginatedData?.last_page ?? 1}
        onPageChange={setCurrentPage}
        isLoading={isLoading}
      />

      <ImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        onImport={handleImport}
      />

      <ExportModal
        isOpen={isExportModalOpen}
        onClose={() => setIsExportModalOpen(false)}
        onExport={handleExport}
      />
    </div>
  );
};

export default Dashboard;