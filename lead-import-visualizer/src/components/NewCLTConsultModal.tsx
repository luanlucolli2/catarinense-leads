import { useState, useEffect } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface NewCLTConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (titulo: string, cpfs: string, modo: "OFF" | "ONLINE") => void;
}

export const NewCLTConsultModal = ({ isOpen, onClose, onSubmit }: NewCLTConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [cpfs, setCpfs] = useState("");
  const [cpfCount, setCpfCount] = useState(0);
  const [modo, setModo] = useState<"OFF" | "ONLINE" | "">("");
  const [submitting, setSubmitting] = useState(false);

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

    try {
      setSubmitting(true);
      await onSubmit(titulo, cpfs, modo as "OFF" | "ONLINE");
      setTitulo("");
      setCpfs("");
      setCpfCount(0);
      setModo("");
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
    onClose();
  };

  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";

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

          {/* Modo de Consulta – estilo de botões toggle */}
          <div className="space-y-3">
            <Label className="text-sm font-medium">Modo de Consulta *</Label>
            <div className="grid grid-cols-2 gap-2">
              <Button
                type="button"
                onClick={() => setModo("ONLINE")}
                disabled={submitting}
                className={cn(
                  noFocus,
                  "transition-colors duration-200",
                  modo === "ONLINE"
                    ? "bg-blue-600 text-white hover:bg-blue-700"
                    : "border border-gray-300 bg-white text-gray-700 hover:bg-gray-100"
                )}
              >
                ONLINE
              </Button>
              <Button
                type="button"
                onClick={() => setModo("OFF")}
                disabled={submitting}
                className={cn(
                  noFocus,
                  "transition-colors duration-200",
                  modo === "OFF"
                    ? "bg-blue-600 text-white hover:bg-blue-700"
                    : "border border-gray-300 bg-white text-gray-700 hover:bg-gray-100"
                )}
              >
                OFF (Base Offline)
              </Button>
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
        </div>

        <DialogFooter className="flex flex-col-reverse sm:flex-row gap-2">
          <Button variant="outline" onClick={handleClose} disabled={submitting} className={noFocus}>
            Cancelar
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={submitting || !modo}
            className={cn("bg-blue-600 hover:bg-blue-700", noFocus)}
          >
            {submitting ? "Criando..." : "Criar consulta"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
