import { useState, useEffect } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface NewPresencaConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (
    titulo: string,
    lines: string,
    opts?: { runAt?: string | null; timezone?: string | null }
  ) => void;
}

export const NewPresencaConsultModal = ({ isOpen, onClose, onSubmit }: NewPresencaConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [lines, setLines] = useState("");
  const [lineCount, setLineCount] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [isAgendado, setIsAgendado] = useState(false);
  const [runAtLocal, setRunAtLocal] = useState("");

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
    if (isAgendado && !runAtLocal) {
      toast.error("Informe a data e hora do agendamento");
      return;
    }

    try {
      setSubmitting(true);
      await onSubmit(
        titulo,
        lines,
        isAgendado
          ? {
              runAt: runAtLocal,
              timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            }
          : undefined
      );
      setTitulo("");
      setLines("");
      setLineCount(0);
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
    setLines("");
    setLineCount(0);
    setIsAgendado(false);
    setRunAtLocal("");
    onClose();
  };

  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";
  const minNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);

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

          <div className="space-y-3 border-t border-gray-100 pt-4">
            <div className="flex items-center space-x-2">
              <Checkbox
                id="presenca-agendamento"
                checked={isAgendado}
                onCheckedChange={(checked) => setIsAgendado(!!checked)}
                disabled={submitting}
              />
              <Label htmlFor="presenca-agendamento" className="cursor-pointer text-sm font-medium">
                Agendar início
              </Label>
            </div>

            {isAgendado && (
              <div className="space-y-2 rounded-md border border-gray-200 bg-gray-50/70 p-3">
                <Label htmlFor="presenca-run-at" className="text-sm font-medium">
                  Iniciar em
                </Label>
                <Input
                  id="presenca-run-at"
                  type="datetime-local"
                  value={runAtLocal}
                  onChange={(e) => setRunAtLocal(e.target.value)}
                  min={minNow}
                  className={cn("max-w-xs", noFocus)}
                  disabled={submitting}
                />
                <p className="text-xs leading-5 text-gray-600">
                  O lote ficará agendado e entrará automaticamente na fila quando esse horário chegar.
                </p>
              </div>
            )}
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
            {submitting ? "Criando..." : isAgendado ? "Agendar consulta" : "Criar consulta"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
