import { Plus, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

type JobStatusFilterValue =
  | "todos"
  | "agendado"
  | "pendente"
  | "em_progresso"
  | "pausado"
  | "concluido"
  | "falhou"
  | "cancelado";

interface V8ControlsProps {
  onNewConsultClick: () => void;
  searchValue: string;
  onSearchChange: (value: string) => void;
  statusFilter?: JobStatusFilterValue;
  onStatusFilterChange?: (value: JobStatusFilterValue) => void;
  statusOptions?: Array<{ value: JobStatusFilterValue; label: string }>;
}

export const V8Controls = ({
  onNewConsultClick,
  searchValue,
  onSearchChange,
  statusFilter,
  onStatusFilterChange,
  statusOptions,
}: V8ControlsProps) => {
  const resolvedStatusOptions = statusOptions ?? [
    { value: "todos", label: "Todos os status" },
    { value: "agendado", label: "Agendado" },
    { value: "pendente", label: "Pendente" },
    { value: "em_progresso", label: "Em andamento" },
    { value: "pausado", label: "Pausado" },
    { value: "concluido", label: "Concluído" },
    { value: "falhou", label: "Falhou" },
    { value: "cancelado", label: "Cancelado" },
  ];

  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="px-4 py-4">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 justify-between">
          <div className="flex flex-col sm:flex-row items-stretch gap-3 flex-1 min-w-0">
            <div className="relative flex-1 min-w-0 sm:max-w-xs">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
              <Input
                type="text"
                placeholder="Buscar por título..."
                value={searchValue}
                onChange={(e) => onSearchChange(e.target.value)}
                className="pl-10 w-full"
              />
            </div>

            {statusFilter && onStatusFilterChange ? (
              <Select
                value={statusFilter}
                onValueChange={(value) => onStatusFilterChange(value as JobStatusFilterValue)}
              >
                <SelectTrigger className="w-full sm:w-[210px]">
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  {resolvedStatusOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : null}
          </div>

          <div className="flex items-center gap-2">
            <Button
              onClick={onNewConsultClick}
              size="sm"
              className="flex items-center gap-2 px-3 bg-blue-600 hover:bg-blue-700"
            >
              <Plus className="w-4 h-4" />
              Nova consulta
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};
