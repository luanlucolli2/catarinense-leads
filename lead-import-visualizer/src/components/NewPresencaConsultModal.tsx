import { useState, useEffect } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface NewPresencaConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (titulo: string, lines: string) => void;
}

export const NewPresencaConsultModal = ({ isOpen, onClose, onSubmit }: NewPresencaConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [lines, setLines] = useState("");
  const [lineCount, setLineCount] = useState(0);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (lines.trim()) {
      const list = lines.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
      setLineCount(list.length);
    } else {
      setLineCount(0);
    }
  }, [lines]);

  const handleSubmit = async () => {
    if (!titulo.trim()) {
      toast.error("Título da consulta é obrigatório");
      return;
    }
    if (!lines.trim()) {
      toast.error("Adicione pelo menos uma linha");
      return;
    }

    try {
      setSubmitting(true);
      await onSubmit(titulo, lines);
      setTitulo("");
      setLines("");
      setLineCount(0);
      onClose();
    } finally {
      setSubmitting(false);
    }
  };

  const handleClose = () => {
    if (submitting) return;
    setTitulo("");
    setLines("");
    setLineCount(0);
    onClose();
  };

  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">Nova consulta Presença</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="space-y-2">
            <Label htmlFor="titulo-presenca" className="text-sm font-medium">
              Título da consulta *
            </Label>
            <Input
              id="titulo-presenca"
              value={titulo}
              onChange={(e) => setTitulo(e.target.value)}
              placeholder="Ex.: Lote Presença - Campanha Abril"
              className={cn("w-full", noFocus)}
              disabled={submitting}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="lines-presenca" className="text-sm font-medium">
              Linhas (CPF e Nome completo) *
            </Label>
            <Textarea
              id="lines-presenca"
              value={lines}
              onChange={(e) => setLines(e.target.value)}
              placeholder={`06367837159 DIEGO GONCALVES DE PAULA\n06263341440;KARLA SOUZA SANTIAGO DA SILVA`}
              className={cn("min-h-[220px] w-full font-mono text-sm", noFocus)}
              disabled={submitting}
            />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-600">
              <span>Uma pessoa por linha. Formatos aceitos: `cpf nome` ou `cpf;nome`.</span>
              <span className="font-medium text-blue-600">
                Detectadas: {lineCount} linhas
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
