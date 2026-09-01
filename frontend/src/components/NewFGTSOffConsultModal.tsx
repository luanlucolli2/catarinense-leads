// src/components/NewFgtsOffConsultModal.tsx
import { useState, useEffect } from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";

interface NewFgtsOffConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (
    titulo: string,
    cpfs: string,
    opts?: { runAt?: string | null; endAt?: string | null }
  ) => void;
}

export const NewFgtsOffConsultModal = ({
  isOpen,
  onClose,
  onSubmit,
}: NewFgtsOffConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [cpfs, setCpfs] = useState("");
  const [cpfCount, setCpfCount] = useState(0);
  const [submitting, setSubmitting] = useState(false);

  // agendamento (opcional)
  const [isAgendado, setIsAgendado] = useState(false);
  const [runAtLocal, setRunAtLocal] = useState<string>(""); // datetime-local
  const [endAtLocal, setEndAtLocal] = useState<string>(""); // datetime-local

  // Contagem de CPFs
  useEffect(() => {
    if (cpfs.trim()) {
      const cpfList = cpfs.split(/[\n,\s]+/).filter((v) => v.trim());
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
    if (!cpfs.trim()) {
      toast.error("Adicione pelo menos um CPF");
      return;
    }
    if (isAgendado) {
      if (!runAtLocal) {
        toast.error("Informe o horário inicial");
        return;
      }
      if (!endAtLocal) {
        toast.error("Informe o horário final");
        return;
      }
      if (endAtLocal <= runAtLocal) {
        toast.error("O horário final deve ser maior que o horário inicial");
        return;
      }
    }

    try {
      setSubmitting(true);
      await onSubmit(
        titulo,
        cpfs,
        isAgendado
          ? {
              runAt: runAtLocal, // formato do input datetime-local (ex.: 2025-09-01T09:00)
              endAt: endAtLocal,
            }
          : undefined
      );
      // Reset
      setTitulo("");
      setCpfs("");
      setCpfCount(0);
      setIsAgendado(false);
      setRunAtLocal("");
      setEndAtLocal("");
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
    setIsAgendado(false);
    setRunAtLocal("");
    setEndAtLocal("");
    onClose();
  };

  const minNow = new Date().toISOString().slice(0, 16); // yyyy-MM-ddTHH:mm

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">
            Nova consulta FGTS (Base Offline)
          </DialogTitle>
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
              placeholder="Ex.: Lote FGTS OFF – Campanha Setembro"
              className="w-full"
              disabled={submitting}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="cpfs" className="text-sm font-medium">
              CPFs (um por linha ou separados por vírgula/espaço)
            </Label>
            <Textarea
              id="cpfs"
              value={cpfs}
              onChange={(e) => setCpfs(e.target.value)}
              placeholder={`111.222.333-44\n55566677788\n01234567890, 98765432100`}
              className="min-h-[200px] w-full font-mono text-sm"
              disabled={submitting}
            />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-600">
              <span>
                Aceitamos quebras de linha, vírgulas ou espaços; removeremos
                pontos e traços.
              </span>
              <span className="font-medium text-blue-600">
                Detectados: {cpfCount} CPFs
              </span>
            </div>
          </div>

          {/* Seção de Agendamento (com dois campos: início e fim) */}
          <div className="space-y-3 border-t pt-4">
            <div className="flex items-center space-x-2">
              <Checkbox
                id="agendamento"
                checked={isAgendado}
                onCheckedChange={(checked) => setIsAgendado(!!checked)}
                disabled={submitting}
              />
              <Label
                htmlFor="agendamento"
                className="text-sm font-medium cursor-pointer"
              >
                Agendar execução em janela de tempo (início e fim)
              </Label>
            </div>

            {isAgendado && (
              <div className="ml-6 space-y-3">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div className="space-y-2">
                    <Label htmlFor="runAt" className="text-sm font-medium">
                      Início
                    </Label>
                    <Input
                      id="runAt"
                      type="datetime-local"
                      value={runAtLocal}
                      onChange={(e) => setRunAtLocal(e.target.value)}
                      min={minNow}
                      className="w-full max-w-xs"
                      disabled={submitting}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="endAt" className="text-sm font-medium">
                      Fim
                    </Label>
                    <Input
                      id="endAt"
                      type="datetime-local"
                      value={endAtLocal}
                      onChange={(e) => setEndAtLocal(e.target.value)}
                      min={runAtLocal || minNow}
                      className="w-full max-w-xs"
                      disabled={submitting}
                    />
                  </div>
                </div>
                <p className="text-xs text-gray-500">
                  A consulta será executada automaticamente dentro da janela
                  selecionada.
                </p>
              </div>
            )}
          </div>
        </div>

        <DialogFooter className="flex flex-col-reverse sm:flex-row gap-2">
          <Button variant="outline" onClick={handleClose} disabled={submitting}>
            Cancelar
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={submitting}
            className="bg-blue-600 hover:bg-blue-700"
          >
            {submitting
              ? "Criando..."
              : isAgendado
              ? "Agendar consulta"
              : "Criar consulta"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
