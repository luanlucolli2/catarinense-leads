import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AlertCircle, CheckCircle2, Copy, Link2, Plus, Search } from "lucide-react";
import { toast } from "sonner";

import { listC6AuthorizationLinks } from "@/api/c6";
import { NewLinkModal } from "@/components/NewLinkModal";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { formatCPF } from "@/lib/formatters";

interface GeneratedLink {
  id: string;
  cpf: string;
  nome?: string;
  link: string;
  geradoEm: string;
  expiraEm: string;
}

const isExpired = (expiraEm: string) => {
  if (!expiraEm || !expiraEm.includes(" ")) return false;

  const [date, time] = expiraEm.split(" ");
  if (!date || !time) return false;

  const [day, month, year] = date.split("/");
  const [hour, minute] = time.split(":");

  const parsedDay = Number(day);
  const parsedMonth = Number(month);
  const parsedYear = Number(year);
  const parsedHour = Number(hour);
  const parsedMinute = Number(minute);

  if (
    Number.isNaN(parsedDay) ||
    Number.isNaN(parsedMonth) ||
    Number.isNaN(parsedYear) ||
    Number.isNaN(parsedHour) ||
    Number.isNaN(parsedMinute)
  ) {
    return false;
  }

  const expDate = new Date(parsedYear, parsedMonth - 1, parsedDay, parsedHour, parsedMinute);
  return expDate < new Date();
};

const toPtBrDateTime = (iso: string | null) => {
  if (!iso) return "--";

  const date = new Date(iso);
  return date.toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const C6LinksPage = () => {
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [filterCpf, setFilterCpf] = useState("");
  const [filterNome, setFilterNome] = useState("");
  const [filterStatus, setFilterStatus] = useState<"todos" | "ativo" | "expirado">("todos");
  const [localLinks, setLocalLinks] = useState<GeneratedLink[]>([]);
  const [copiedLinkId, setCopiedLinkId] = useState<string | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["c6:links:all"],
    queryFn: () => listC6AuthorizationLinks({ page: 1, perPage: 100 }),
    refetchOnWindowFocus: true,
  });

  const serverLinks = useMemo<GeneratedLink[]>(() => {
    const items = data?.data ?? [];
    return items.map((item) => ({
      id: String(item.id),
      cpf: formatCPF(item.cpf),
      nome: item.nome_cliente ?? undefined,
      link: item.link,
      geradoEm: toPtBrDateTime(item.generated_at),
      expiraEm: toPtBrDateTime(item.data_expiracao),
    }));
  }, [data]);

  const links = useMemo(() => {
    if (localLinks.length === 0) return serverLinks;

    const localIds = new Set(localLinks.map((item) => item.id));
    const dedupedServer = serverLinks.filter((item) => !localIds.has(item.id));

    return [...localLinks, ...dedupedServer];
  }, [localLinks, serverLinks]);

  const handleLinkGenerated = (data: {
    id?: string;
    reused?: boolean;
    cpf: string;
    nome?: string;
    link: string;
    geradoEm: string;
    expiraEm: string;
  }) => {
    if (data.reused) {
      return;
    }

    const newLink: GeneratedLink = {
      id: data.id ?? `local-${Date.now()}`,
      cpf: data.cpf,
      nome: data.nome,
      link: data.link,
      geradoEm: data.geradoEm,
      expiraEm: data.expiraEm,
    };

    setLocalLinks((prev) => {
      const withoutSameId = prev.filter((item) => item.id !== newLink.id);
      return [newLink, ...withoutSameId];
    });
  };

  const handleCopy = async (link: GeneratedLink) => {
    try {
      await navigator.clipboard.writeText(link.link);
      setCopiedLinkId(link.id);
      toast.success("Link copiado!");
      window.setTimeout(() => {
        setCopiedLinkId((current) => (current === link.id ? null : current));
      }, 1800);
    } catch {
      toast.error("Não foi possível copiar o link.");
    }
  };

  const filteredLinks = useMemo(() => {
    return links.filter((link) => {
      const cpfMatch =
        !filterCpf || link.cpf.replace(/\D/g, "").includes(filterCpf.replace(/\D/g, ""));
      const nomeMatch =
        !filterNome || link.nome?.toLowerCase().includes(filterNome.toLowerCase());
      const expired = isExpired(link.expiraEm);
      const statusMatch =
        filterStatus === "todos" ||
        (filterStatus === "ativo" && !expired) ||
        (filterStatus === "expirado" && expired);

      return cpfMatch && nomeMatch && statusMatch;
    });
  }, [links, filterCpf, filterNome, filterStatus]);

  return (
    <>
      <div className="p-4 lg:p-6 max-w-full min-w-0">
        <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">Geração de Links</h1>
            <p className="text-gray-600 text-sm lg:text-base">
              Gere links exclusivos para clientes. Links expiram em 48 horas.
            </p>
          </div>

          <Button
            onClick={() => setIsModalOpen(true)}
            className="bg-green-700 hover:bg-green-800 text-white shrink-0"
          >
            <Plus className="w-4 h-4" />
            Gerar Novo Link
          </Button>
        </div>

        <div className="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              placeholder="Filtrar por CPF..."
              value={filterCpf}
              onChange={(e) => setFilterCpf(e.target.value)}
              className="pl-9"
            />
          </div>

          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              placeholder="Filtrar por nome..."
              value={filterNome}
              onChange={(e) => setFilterNome(e.target.value)}
              className="pl-9"
            />
          </div>

          <Select
            value={filterStatus}
            onValueChange={(v) => setFilterStatus(v as "todos" | "ativo" | "expirado")}
          >
            <SelectTrigger>
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="todos">Todos</SelectItem>
              <SelectItem value="ativo">Ativo</SelectItem>
              <SelectItem value="expirado">Expirado</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="mb-4">
          <h2 className="text-lg font-semibold text-foreground">Histórico de Links</h2>
          <p className="text-muted-foreground text-sm">
            {filteredLinks.length} de {links.length} link(s)
          </p>
        </div>

        {isLoading ? (
          <Card>
            <CardContent className="py-12 flex flex-col items-center text-gray-500">
              <Link2 className="w-10 h-10 mb-3 text-gray-300" />
              <p className="font-medium">Carregando links...</p>
            </CardContent>
          </Card>
        ) : isError ? (
          <Card>
            <CardContent className="py-12 flex flex-col items-center text-gray-500">
              <AlertCircle className="w-10 h-10 mb-3 text-red-400" />
              <p className="font-medium">Falha ao carregar links</p>
              <p className="text-sm">Atualize a página para tentar novamente.</p>
            </CardContent>
          </Card>
        ) : filteredLinks.length === 0 ? (
          <Card>
            <CardContent className="py-12 flex flex-col items-center text-gray-500">
              <Link2 className="w-10 h-10 mb-3 text-gray-300" />
              <p className="font-medium">Nenhum link gerado</p>
              <p className="text-sm">Clique em "Gerar Novo Link" para começar.</p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3">
            {filteredLinks.map((link) => {
              const expired = isExpired(link.expiraEm);
              return (
                <Card key={link.id} className={expired ? "opacity-60" : ""}>
                  <CardContent className="py-4 flex flex-col md:flex-row md:items-center gap-3 md:gap-6">
                    <div className="flex-1 min-w-0 space-y-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono text-sm font-medium text-gray-900">{link.cpf}</span>
                        {link.nome && <span className="text-sm text-gray-500">- {link.nome}</span>}

                        {expired ? (
                          <Badge variant="destructive" className="text-xs">
                            <AlertCircle className="w-3 h-3 mr-1" />
                            Expirado
                          </Badge>
                        ) : (
                          <Badge className="bg-green-100 text-green-800 text-xs hover:bg-green-100">
                            <CheckCircle2 className="w-3 h-3 mr-1" />
                            Ativo
                          </Badge>
                        )}
                      </div>

                      <p className="text-sm text-blue-600 truncate font-mono">{link.link}</p>
                      <div className="flex gap-4 text-xs text-gray-400">
                        <span>Gerado: {link.geradoEm}</span>
                        <span>Expira: {link.expiraEm}</span>
                      </div>
                    </div>

                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleCopy(link)}
                      disabled={expired}
                      className="shrink-0"
                    >
                      <Copy className="w-4 h-4" />
                      {copiedLinkId === link.id ? "Copiado!" : "Copiar"}
                    </Button>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
      </div>

      <NewLinkModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        onLinkGenerated={handleLinkGenerated}
      />
    </>
  );
};

export default C6LinksPage;
