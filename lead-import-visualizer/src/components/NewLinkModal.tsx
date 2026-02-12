import { useMemo, useState } from "react";
import { CheckCircle2, Clock, Copy, Link2, Plus } from "lucide-react";
import { toast } from "sonner";

import { generateC6AuthorizationLink } from "@/api/c6";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface GeneratedLinkResult {
  id: string;
  link: string;
  expiraEm: string;
  reused: boolean;
  message?: string;
}

interface NewLinkModalProps {
  isOpen: boolean;
  onClose: () => void;
  onLinkGenerated: (data: {
    id?: string;
    reused?: boolean;
    cpf: string;
    nome?: string;
    dataNasc?: string;
    telefone?: string;
    link: string;
    geradoEm: string;
    expiraEm: string;
  }) => void;
}

const formatCPF = (value: string) => {
  const digits = value.replace(/\D/g, "").slice(0, 11);
  if (digits.length <= 3) return digits;
  if (digits.length <= 6) return `${digits.slice(0, 3)}.${digits.slice(3)}`;
  if (digits.length <= 9) return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6)}`;
  return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9)}`;
};

const formatPhone = (value: string) => {
  const digits = value.replace(/\D/g, "").slice(0, 11);
  if (digits.length <= 2) return digits;
  if (digits.length <= 6) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
  if (digits.length <= 10) return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
  return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
};

const isValidCPF = (cpf: string) => cpf.replace(/\D/g, "").length === 11;

const toPtBrDateTime = (iso: string) => {
  const date = new Date(iso);
  return date.toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const parsePhoneToApi = (value: string): { codigo_area: string; numero: string } | null => {
  const rawDigits = value.replace(/\D/g, "");
  if (!rawDigits) return null;

  let digits = rawDigits;
  if ((digits.length === 12 || digits.length === 13) && digits.startsWith("55")) {
    digits = digits.slice(2);
  }

  if (digits.length !== 10 && digits.length !== 11) return null;

  return {
    codigo_area: digits.slice(0, 2),
    numero: digits.slice(2),
  };
};

const getApiErrorMessage = (error: any): string => {
  const data = error?.response?.data;
  const message = data?.message;

  if (typeof message === "string" && message.trim() !== "") {
    return message;
  }

  const errors = data?.errors;
  if (errors && typeof errors === "object") {
    for (const value of Object.values(errors)) {
      if (Array.isArray(value) && typeof value[0] === "string") {
        return value[0];
      }
      if (typeof value === "string") {
        return value;
      }
    }
  }

  return "Erro ao gerar link.";
};

export const NewLinkModal = ({ isOpen, onClose, onLinkGenerated }: NewLinkModalProps) => {
  const [cpf, setCpf] = useState("");
  const [nome, setNome] = useState("");
  const [dataNasc, setDataNasc] = useState("");
  const [telefone, setTelefone] = useState("");
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<GeneratedLinkResult | null>(null);

  const todayIsoDate = useMemo(() => {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const day = String(now.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
  }, []);

  const resetForm = () => {
    setCpf("");
    setNome("");
    setDataNasc("");
    setTelefone("");
    setLoading(false);
    setResult(null);
  };

  const handleClose = () => {
    resetForm();
    onClose();
  };

  const handleSubmit = async () => {
    if (!isValidCPF(cpf)) {
      toast.error("CPF inválido. Informe 11 dígitos.");
      return;
    }

    const parsedPhone = parsePhoneToApi(telefone);
    if (telefone.trim() !== "" && !parsedPhone) {
      toast.error("Telefone inválido. Informe no padrão (DDD) 99999-9999.");
      return;
    }

    if (dataNasc) {
      const birthDate = new Date(`${dataNasc}T00:00:00`);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      if (birthDate >= today) {
        toast.error("Data de nascimento deve ser anterior à data atual.");
        return;
      }
    }

    setLoading(true);

    try {
      const cpfDigits = cpf.replace(/\D/g, "");
      const response = await generateC6AuthorizationLink({
        cpf: cpfDigits,
        nomeCliente: nome.trim() || undefined,
        dataNascimento: dataNasc || undefined,
        telefone: parsedPhone
          ? {
              codigoArea: parsedPhone.codigo_area,
              numero: parsedPhone.numero,
            }
          : undefined,
      });

      const geradoEm = toPtBrDateTime(response.generated_at);
      const expiraEm = toPtBrDateTime(response.data_expiracao);

      setResult({
        id: String(response.id),
        link: response.link,
        expiraEm,
        reused: !!response.reused,
        message: response.message,
      });

      if (response.reused) {
        toast.info(response.message || "Já existia um link ativo para este CPF. Link reaproveitado.");
      }

      onLinkGenerated({
        id: String(response.id),
        reused: !!response.reused,
        cpf: formatCPF(cpfDigits),
        nome: nome.trim() || undefined,
        dataNasc: dataNasc || undefined,
        telefone: telefone.trim() || undefined,
        link: response.link,
        geradoEm,
        expiraEm,
      });
    } catch (error: any) {
      toast.error(getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const handleCopyLink = async () => {
    if (!result) return;

    try {
      await navigator.clipboard.writeText(result.link);
      toast.success("Link copiado!");
    } catch {
      toast.error("Não foi possível copiar o link.");
    }
  };

  const handleNewLink = () => {
    setCpf("");
    setNome("");
    setDataNasc("");
    setTelefone("");
    setResult(null);
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
      <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Link2 className="w-5 h-5" />
            Gerar Novo Link
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-4 pt-2">
          <div className="space-y-2">
            <Label htmlFor="modal-cpf">
              CPF <span className="text-red-500">*</span>
            </Label>
            <Input
              id="modal-cpf"
              placeholder="000.000.000-00"
              value={cpf}
              onChange={(e) => setCpf(formatCPF(e.target.value))}
              maxLength={14}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="modal-nome">Nome</Label>
            <Input
              id="modal-nome"
              placeholder="Nome do cliente (opcional)"
              value={nome}
              onChange={(e) => setNome(e.target.value)}
              maxLength={255}
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="modal-dataNasc">Data de Nascimento</Label>
              <Input
                id="modal-dataNasc"
                type="date"
                value={dataNasc}
                onChange={(e) => setDataNasc(e.target.value)}
                max={todayIsoDate}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="modal-telefone">Telefone</Label>
              <Input
                id="modal-telefone"
                placeholder="(00) 00000-0000"
                value={telefone}
                onChange={(e) => setTelefone(formatPhone(e.target.value))}
                maxLength={15}
              />
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            {result ? (
              <Button variant="outline" onClick={handleNewLink}>
                Limpar
              </Button>
            ) : null}
            <Button variant="outline" onClick={handleClose}>
              {result ? "Fechar" : "Cancelar"}
            </Button>
            <Button
              onClick={handleSubmit}
              disabled={loading || !cpf}
              className="bg-green-700 hover:bg-green-800 text-white"
            >
              {loading ? (
                <>
                  <Clock className="w-4 h-4 animate-spin" />
                  Gerando...
                </>
              ) : (
                <>
                  <Plus className="w-4 h-4" />
                  {result ? "Gerar Novamente" : "Gerar Link"}
                </>
              )}
            </Button>
          </div>

          {result ? (
            <div
              className={`rounded-lg p-4 space-y-3 animate-in fade-in-0 zoom-in-95 duration-200 ${
                result.reused
                  ? "bg-amber-50 border border-amber-200"
                  : "bg-green-50 border border-green-200"
              }`}
            >
              <div className={`flex items-center gap-2 ${result.reused ? "text-amber-700" : "text-green-700"}`}>
                <CheckCircle2 className="w-5 h-5" />
                <span className="font-medium">
                  {result.reused ? "Link ativo já existente" : "Link gerado com sucesso!"}
                </span>
              </div>
              <div className="bg-white rounded-md border p-3 flex items-center gap-2">
                <p className="text-sm text-blue-600 font-mono break-all flex-1">{result.link}</p>
                <Button variant="outline" size="sm" onClick={handleCopyLink} className="shrink-0">
                  <Copy className="w-4 h-4" />
                  Copiar
                </Button>
              </div>
              {result.message ? (
                <p className="text-xs text-gray-600">{result.message}</p>
              ) : null}
              <p className="text-xs text-gray-500">Expira em: {result.expiraEm}</p>
            </div>
          ) : null}
        </div>
      </DialogContent>
    </Dialog>
  );
};
