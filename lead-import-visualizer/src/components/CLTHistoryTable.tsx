import { useState } from "react";
import { Search, Download, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils";

interface CLTConsult {
  id: string;
  titulo: string;
  criadoEm: string;
  status: "Concluído" | "Em andamento" | "Falhou";
  totalCPFs: number;
  sucesso: number;
  falhas: number;
}

interface CLTHistoryTableProps {
  consultas: CLTConsult[];
  onDownload: (consultaId: string) => void;
  searchValue: string;
}

export const CLTHistoryTable = ({ consultas, onDownload, searchValue }: CLTHistoryTableProps) => {

  const getStatusBadge = (status: CLTConsult["status"]) => {
    switch (status) {
      case "Concluído":
        return <Badge className="bg-green-100 text-green-800 border-green-200">Concluído</Badge>;
      case "Em andamento":
        return (
          <Badge className="bg-blue-100 text-blue-800 border-blue-200 flex items-center gap-1">
            <Loader2 className="w-3 h-3 animate-spin" />
            Em andamento
          </Badge>
        );
      case "Falhou":
        return <Badge className="bg-red-100 text-red-800 border-red-200">Falhou</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  const filteredConsultas = consultas.filter(consulta =>
    consulta.titulo.toLowerCase().includes(searchValue.toLowerCase())
  );

  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">

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
            {filteredConsultas.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center py-8 text-gray-500">
                  Nenhuma consulta encontrada
                </TableCell>
              </TableRow>
            ) : (
              filteredConsultas.map((consulta) => (
                <TableRow key={consulta.id} className="hover:bg-gray-50">
                  <TableCell className="font-medium">
                    {consulta.titulo}
                  </TableCell>
                  <TableCell className="text-gray-600">
                    {consulta.criadoEm}
                  </TableCell>
                  <TableCell className="text-center">
                    {getStatusBadge(consulta.status)}
                  </TableCell>
                  <TableCell className="text-center font-medium">
                    {consulta.totalCPFs.toLocaleString()}
                  </TableCell>
                  <TableCell className="text-center text-green-600 font-medium">
                    {consulta.sucesso.toLocaleString()}
                  </TableCell>
                  <TableCell className="text-center text-red-600 font-medium">
                    {consulta.falhas.toLocaleString()}
                  </TableCell>
                  <TableCell className="text-center">
                    <Button
                      onClick={() => onDownload(consulta.id)}
                      disabled={consulta.status === "Falhou" || consulta.status === "Em andamento"}
                      variant="outline"
                      size="sm"
                      className={cn(
                        "flex items-center gap-2 px-3",
                        consulta.status === "Concluído" 
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