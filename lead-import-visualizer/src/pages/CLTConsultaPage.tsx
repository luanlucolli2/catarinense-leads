import { useState } from "react";
import { CLTControls } from "@/components/CLTControls";
import { CLTHistoryTable } from "@/components/CLTHistoryTable";
import { NewCLTConsultModal } from "@/components/NewCLTConsultModal";

interface CLTConsult {
  id: string;
  titulo: string;
  criadoEm: string;
  status: "Concluído" | "Em andamento" | "Falhou";
  totalCPFs: number;
  sucesso: number;
  falhas: number;
}

const CLTConsultaPage = () => {
  const [isNewConsultModalOpen, setIsNewConsultModalOpen] = useState(false);
  const [searchValue, setSearchValue] = useState("");
  const [consultas, setConsultas] = useState<CLTConsult[]>([
    {
      id: "1",
      titulo: "Lote CLT – Piloto Interno",
      criadoEm: "10/08/2025 09:12",
      status: "Concluído",
      totalCPFs: 120,
      sucesso: 118,
      falhas: 2,
    },
    {
      id: "2", 
      titulo: "Lote CLT – Teste Perform.",
      criadoEm: "11/08/2025 16:40",
      status: "Concluído",
      totalCPFs: 1000,
      sucesso: 998,
      falhas: 2,
    },
    {
      id: "3",
      titulo: "Lote CLT – Validação API",
      criadoEm: "12/08/2025 08:03",
      status: "Falhou",
      totalCPFs: 300,
      sucesso: 0,
      falhas: 300,
    },
    {
      id: "4",
      titulo: "Lote CLT – Processamento",
      criadoEm: "14/08/2025 10:15",
      status: "Em andamento",
      totalCPFs: 500,
      sucesso: 0,
      falhas: 0,
    },
  ]);

  const handleNewConsult = (titulo: string, cpfs: string) => {
    const newConsult: CLTConsult = {
      id: Date.now().toString(),
      titulo,
      criadoEm: new Date().toLocaleString("pt-BR", {
        day: "2-digit",
        month: "2-digit", 
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit"
      }),
      status: "Em andamento",
      totalCPFs: cpfs.split(/[\n,\s]+/).filter(cpf => cpf.trim()).length,
      sucesso: 0,
      falhas: 0,
    };
    
    setConsultas(prev => [newConsult, ...prev]);
    
    // Simular mudança para "Concluído" após 3 segundos
    setTimeout(() => {
      setConsultas(prev => prev.map(c => 
        c.id === newConsult.id 
          ? { ...c, status: "Concluído", sucesso: newConsult.totalCPFs - 2, falhas: 2 }
          : c
      ));
    }, 3000);
  };

  const handleDownload = (consultaId: string) => {
    // Mock download - no protótipo apenas simula
    console.log(`Baixando planilha da consulta ${consultaId}`);
  };

  return (
      <div className="p-4 lg:p-6 max-w-full min-w-0">
        <div className="mb-6 max-w-full">
          <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
            Consulta CLT (Consignado em Folha)
          </h1>
          <p className="text-gray-600 text-sm lg:text-base">
            As importações existentes são FGTS. Aqui você realiza consulta CLT em massa colando CPFs e baixa o resultado em Excel.
          </p>
        </div>

        <div className="space-y-6">
          {/* Controls */}
          <CLTControls 
            onNewConsultClick={() => setIsNewConsultModalOpen(true)}
            searchValue={searchValue}
            onSearchChange={setSearchValue}
          />

          {/* History Table */}
          <CLTHistoryTable 
            consultas={consultas}
            onDownload={handleDownload}
            searchValue={searchValue}
          />
        </div>

        {/* New Consult Modal */}
        <NewCLTConsultModal
          isOpen={isNewConsultModalOpen}
          onClose={() => setIsNewConsultModalOpen(false)}
          onSubmit={handleNewConsult}
        />
      </div>
  );
};

export default CLTConsultaPage;