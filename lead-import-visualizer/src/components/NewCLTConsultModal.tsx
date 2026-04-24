import { useState, useEffect } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface NewCLTConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (
    titulo: string,
    cpfs: string,
    modo: "OFF" | "ONLINE" | "HYBRID",
    opts?: { runAt?: string | null; timezone?: string | null }
  ) => void;
}

const MODE_OPTIONS = [
  
  {
    value: "ONLINE" as const,
    label: "Online",
    helper: "Consulta direta",
    description:
      "Consulta direto no online. Indicado quando você quer forçar atualização sem reaproveitar a base offline.",
  },
  {
    value: "OFF" as const,
    label: "Offline",
    helper: "Base offline",
    description:
      "Consulta apenas a base offline. Não consome o limite do online e não executa a continuação da fase 2.",
  },
  {
    value: "HYBRID" as const,
    label: "Híbrido",
    helper: "Reaproveita offline recente",
    description:
      "Consulta primeiro a base offline. Reaproveita dados atualizados nos últimos 7 dias e envia ao online apenas CPFs antigos, incompletos ou não encontrados.",
  },
];

export const NewCLTConsultModal = ({ isOpen, onClose, onSubmit }: NewCLTConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [cpfs, setCpfs] = useState("");
  const [cpfCount, setCpfCount] = useState(0);
  const [modo, setModo] = useState<"OFF" | "ONLINE" | "HYBRID" | "">("");
  const [submitting, setSubmitting] = useState(false);
  const [isAgendado, setIsAgendado] = useState(false);
  const [runAtLocal, setRunAtLocal] = useState("");

  useEffect(() => {
    if (cpfs.trim()) {
      const cpfList = cpfs.split(/[\n,\s]+/).filter((cpf) => cpf.trim());
      setCpfCount(cpfList.length);
    } else {
      setCpfCount(0);
    }
  }, [cpfs]);

  const handleSubmit = async () => {
    if (!titulo.trim()) {
      toast.error("Título da consulta é obrigatório");
      return;
    }
    if (!modo) {
      toast.error("Selecione o modo de consulta");
      return;
    }
    if (!cpfs.trim()) {
      toast.error("Adicione pelo menos um CPF");
      return;
    }
    if (isAgendado && !runAtLocal) {
      toast.error("Informe a data e hora do agendamento");
      return;
    }

    try {
      setSubmitting(true);
      await onSubmit(
        titulo,
        cpfs,
        modo as "OFF" | "ONLINE" | "HYBRID",
        isAgendado
          ? {
              runAt: runAtLocal,
              timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            }
          : undefined
      );
      setTitulo("");
      setCpfs("");
      setCpfCount(0);
      setModo("");
      setIsAgendado(false);
      setRunAtLocal("");
      onClose();
    } finally {
      setSubmitting(false);
    }
  };

  const handleClose = () => {
    if (submitting) return;
    setTitulo("");
    setCpfs("");
    setCpfCount(0);
    setModo("");
    setIsAgendado(false);
    setRunAtLocal("");
    onClose();
  };

  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";
  const selectedMode = MODE_OPTIONS.find((option) => option.value === modo);
  const minNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">Nova consulta CLT</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="space-y-2">
            <Label htmlFor="titulo" className="text-sm font-medium">
              Título da consulta *
            </Label>
            <Input
              id="titulo"
              value={titulo}
              onChange={(e) => setTitulo(e.target.value)}
              placeholder="Ex.: Lote CLT – Campanha Agosto"
              className={cn("w-full", noFocus)}
              disabled={submitting}
            />
          </div>

          <div className="space-y-3">
            <Label className="text-sm font-medium">Modo de Consulta *</Label>
            <Tabs value={modo || ""} onValueChange={(value) => setModo(value as "OFF" | "ONLINE" | "HYBRID")}>
              {/* Ajustado: bg-transparent e gap maior entre os itens */}
              <TabsList className="grid h-auto w-full grid-cols-1 gap-3 bg-transparent p-0 sm:grid-cols-3">
                {MODE_OPTIONS.map((option) => (
                  <TabsTrigger
                    key={option.value}
                    value={option.value}
                    disabled={submitting}
                    className={cn(
                      noFocus,
                      "h-auto min-h-[72px] flex-col items-start gap-1 rounded-lg border-2 px-4 py-3 text-left transition-all duration-200 sm:items-center sm:text-center",
                      // Estado Inativo
                      "border-gray-100 bg-gray-50/50 text-gray-600 hover:border-gray-300 hover:bg-gray-100",
                      // Estado Ativo (Mesma cor do botão criar)
                      "data-[state=active]:border-blue-600 data-[state=active]:bg-blue-600 data-[state=active]:text-white data-[state=active]:shadow-md"
                    )}
                  >
                    <span className="text-sm font-bold">{option.label}</span>
                    <span 
                      className={cn(
                        "text-[10px] leading-tight transition-colors sm:text-xs",
                        modo === option.value ? "text-blue-100" : "text-gray-500"
                      )}
                    >
                      {option.helper}
                    </span>
                  </TabsTrigger>
                ))}
              </TabsList>
            </Tabs>

            <div className="rounded-md border border-gray-200 bg-gray-50/70 px-3 py-2">
              <p className="text-xs font-medium text-gray-700">
                {selectedMode?.label ?? "Selecione um modo"}{selectedMode ? " :" : ""}
              </p>
              <p className="mt-1 text-xs leading-5 text-gray-600">
                {selectedMode?.description ?? "Escolha como a consulta deve ser executada."}
              </p>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="cpfs" className="text-sm font-medium">
              CPFs (um por linha ou separados por vírgula/espaço) *
            </Label>
            <Textarea
              id="cpfs"
              value={cpfs}
              onChange={(e) => setCpfs(e.target.value)}
              placeholder={`111.222.333-44\n55566677788\n01234567890, 98765432100`}
              className={cn("min-h-[200px] w-full font-mono text-sm", noFocus)}
              disabled={submitting}
            />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-600">
              <span>Aceitamos quebras de linha, vírgulas ou espaços; removeremos pontos e traços.</span>
              <span className="font-medium text-blue-600">
                Detectados: {cpfCount} CPFs
              </span>
            </div>
          </div>

          <div className="space-y-3 border-t border-gray-100 pt-4">
            <div className="flex items-center space-x-2">
              <Checkbox
                id="clt-agendamento"
                checked={isAgendado}
                onCheckedChange={(checked) => setIsAgendado(!!checked)}
                disabled={submitting}
              />
              <Label htmlFor="clt-agendamento" className="cursor-pointer text-sm font-medium">
                Agendar início
              </Label>
            </div>

            {isAgendado && (
              <div className="space-y-2 rounded-md border border-gray-200 bg-gray-50/70 p-3">
                <Label htmlFor="clt-run-at" className="text-sm font-medium">
                  Iniciar em
                </Label>
                <Input
                  id="clt-run-at"
                  type="datetime-local"
                  value={runAtLocal}
                  onChange={(e) => setRunAtLocal(e.target.value)}
                  min={minNow}
                  className={cn("max-w-xs", noFocus)}
                  disabled={submitting}
                />
                <p className="text-xs leading-5 text-gray-600">
                  O lote ficará como agendado e entrará automaticamente na fila quando esse horário chegar.
                </p>
              </div>
            )}
          </div>
        </div>

        <DialogFooter className="flex flex-col-reverse sm:flex-row gap-2 border-t pt-4">
          <Button variant="outline" onClick={handleClose} disabled={submitting} className={noFocus}>
            Cancelar
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={submitting || !modo}
            className={cn("bg-blue-600 hover:bg-blue-700 text-white shadow-sm", noFocus)}
          >
            {submitting ? "Criando..." : isAgendado ? "Agendar consulta" : "Criar consulta"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
