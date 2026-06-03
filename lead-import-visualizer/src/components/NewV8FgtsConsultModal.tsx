import { useEffect, useState } from "react";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface NewV8FgtsConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (titulo: string, cpfs: string) => void | Promise<void>;
}

export const NewV8FgtsConsultModal = ({
  isOpen,
  onClose,
  onSubmit,
}: NewV8FgtsConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [cpfs, setCpfs] = useState("");
  const [cpfCount, setCpfCount] = useState(0);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (cpfs.trim()) {
      const list = cpfs.split(/[\n,\s]+/).filter((v) => v.trim());
      setCpfCount(list.length);
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

    try {
      setSubmitting(true);
      await onSubmit(titulo, cpfs);
      setTitulo("");
      setCpfs("");
      setCpfCount(0);
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
    onClose();
  };

  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">Nova consulta FGTS V8</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="space-y-2">
            <Label htmlFor="v8-fgts-titulo" className="text-sm font-medium">
              Título da consulta *
            </Label>
            <Input
              id="v8-fgts-titulo"
              value={titulo}
              onChange={(e) => setTitulo(e.target.value)}
              placeholder="Ex.: Lote FGTS V8 – Campanha Janeiro"
              className={cn("w-full", noFocus)}
              disabled={submitting}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="v8-fgts-cpfs" className="text-sm font-medium">
              CPFs (um por linha ou separados por vírgula/espaço) *
            </Label>
            <Textarea
              id="v8-fgts-cpfs"
              value={cpfs}
              onChange={(e) => setCpfs(e.target.value)}
              placeholder={`111.222.333-44\n55566677788\n01234567890, 98765432100`}
              className={cn("min-h-[220px] w-full font-mono text-sm", noFocus)}
              disabled={submitting}
            />
            <div className="flex flex-col gap-2 text-xs text-gray-600 sm:flex-row sm:items-center sm:justify-between">
              <span>Envie apenas CPFs. Pontos e traços serão ignorados.</span>
              <span className="font-medium text-blue-600">Detectados: {cpfCount} CPFs</span>
            </div>
          </div>
        </div>

        <DialogFooter className="flex flex-col-reverse gap-2 sm:flex-row">
          <Button variant="outline" onClick={handleClose} disabled={submitting} className={noFocus}>
            Cancelar
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={submitting}
            className={cn("bg-blue-600 hover:bg-blue-700", noFocus)}
          >
            {submitting ? "Criando..." : "Criar consulta"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
