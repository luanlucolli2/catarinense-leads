import { useQuery } from "@tanstack/react-query"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Separator } from "@/components/ui/separator"
import { ScrollArea } from "@/components/ui/scroll-area"

import {
  User,
  Phone,
  FileText,
  History,
  Calendar,
  DollarSign,
} from "lucide-react"

import {
  fetchLeadDetail,
  LeadDetailFromApi,
} from "@/api/leads"
import {
  formatCPF,
  formatCurrency,
  formatPhone,
  formatDateOnly,
} from "@/lib/formatters"
import { cn } from "@/lib/utils"

/* ---------------------------- types & helpers --------------------------- */
type UILead = {
  nome: string
  cpf: string
  dataNascimento: string
  status: "Elegível" | "Inelegível"
  tipoConsulta: string
  saldo: number
  libera: number
  telefones: { numero: string; classe: string | null }[]
  contratos: { dataContrato: string; vendedor: string }[]
  historicoimports: { tipo: string; origem: string; dataImportacao: string }[]
}

const mapApiToUi = (data: LeadDetailFromApi): UILead => {
  /* status */
  const isElegivel =
    data.consulta === "Saldo FACTA" &&
    parseFloat(data.libera ?? "0") > 0

  /* telefones */
  const telefones = [1, 2, 3, 4].flatMap((i) => {
    const numero = (data as any)[`fone${i}`] as string | null
    if (!numero) return []
    const classe = (data as any)[`classe_fone${i}`] as string | null
    return [{ numero, classe }]
  })

  /* contratos (já vêm com vendor carregado) */
  const contratos = data.contracts.map((c: any) => ({
    dataContrato: formatDateOnly(c.data_contrato),
    vendedor: c.vendor?.name ?? "Sem vendedor",
  }))

  /* imports */
  const historicoimports = (data as any).import_jobs.map((j: any) => ({
    tipo: j.type,
    origem: j.origin,
    dataImportacao: formatDateOnly(j.created_at),
  }))

  return {
    nome: data.nome,
    cpf: data.cpf,
    dataNascimento: formatDateOnly(data.data_nascimento),
    status: isElegivel ? "Elegível" : "Inelegível",
    tipoConsulta: data.consulta ?? "--",
    saldo: parseFloat(data.saldo ?? "0"),
    libera: parseFloat(data.libera ?? "0"),
    telefones,
    contratos,
    historicoimports,
  }
}

/* ----------------------------------------------------------------------- */
interface LeadDetailsModalProps {
  isOpen: boolean
  onClose: () => void
  leadId: number | null
}

export const LeadDetailsModal = ({
  isOpen,
  onClose,
  leadId,
}: LeadDetailsModalProps) => {
  /* fetch */
  const { data, isLoading } = useQuery<LeadDetailFromApi>({
    queryKey: ["lead", leadId],
    queryFn: () => fetchLeadDetail(leadId as number),
    enabled: !!leadId,
  })

  /* early‑exit enquanto carrega ou não há id */
  if (!leadId || isLoading || !data) return null

  const lead = mapApiToUi(data)

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-w-4xl w-[95vw] p-4 sm:p-6 max-h-[90vh] flex flex-col">
        {/* ---------- Cabeçalho ---------- */}
        <DialogHeader className="pb-2 sm:pb-4">
          <DialogTitle className="text-lg sm:text-xl font-semibold flex items-center gap-2">
            <User className="h-5 w-5" />
            Detalhes do Lead
          </DialogTitle>
        </DialogHeader>

        {/* ---------- Tabs ---------- */}
        <Tabs defaultValue="dados" className="flex flex-col flex-1 min-h-0">
          {/* Tabs bar */}
          <div className="flex-shrink-0 -mx-4 sm:mx-0 px-4 sm:px-0">
            <TabsList className="w-full h-auto p-1 bg-muted/50 overflow-x-auto flex-nowrap">
              <div className="flex min-w-max gap-1 w-full sm:grid sm:grid-cols-4 sm:gap-0">
                <TabButton value="dados" icon={<User className="h-3 w-3 sm:h-4 sm:w-4" />}>
                  Dados
                </TabButton>
                <TabButton value="telefones" icon={<Phone className="h-3 w-3 sm:h-4 sm:w-4" />}>
                  Telefones
                </TabButton>
                <TabButton value="contratos" icon={<FileText className="h-3 w-3 sm:h-4 sm:w-4" />}>
                  Contratos
                </TabButton>
                <TabButton value="historico" icon={<History className="h-3 w-3 sm:h-4 sm:w-4" />}>
                  Histórico
                </TabButton>
              </div>
            </TabsList>
          </div>

          {/* ---------- Conteúdo ---------- */}
          <div className="flex-1 mt-4 sm:mt-6 min-h-0">
            <ScrollArea className="h-[60vh] sm:h-[65vh]">
              {/* === Aba Dados === */}
              <TabsContent value="dados" className="space-y-4 sm:space-y-6 mt-0 pr-4">
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                  {/* PESSOAL */}
                  <Card>
                    <CardHeader className="pb-3 sm:pb-4">
                      <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                        <User className="h-4 w-4 sm:h-5 sm:w-5" />
                        Informações Pessoais
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 sm:space-y-4 pt-0">
                      <Info label="Nome Completo" value={lead.nome} />
                      <Info label="CPF" value={formatCPF(lead.cpf)} mono />
                      <Info label="Data de Nascimento" value={lead.dataNascimento} />
                    </CardContent>
                  </Card>

                  {/* STATUS */}
                  <Card>
                    <CardHeader className="pb-3 sm:pb-4">
                      <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                        <FileText className="h-4 w-4 sm:h-5 sm:w-5" />
                        Status e Consulta
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 sm:space-y-4 pt-0">
                      <div>
                        <label className="text-xs sm:text-sm font-medium text-gray-600">Status</label>
                        <div className="mt-1">
                          <Badge
                            variant={lead.status === "Elegível" ? "default" : "secondary"}
                            className={cn(
                              "text-xs",
                              lead.status === "Elegível"
                                ? "bg-green-100 text-green-800 hover:bg-green-100"
                                : "bg-red-100 text-red-800 hover:bg-red-100",
                            )}
                          >
                            {lead.status}
                          </Badge>
                        </div>
                      </div>
                      <Info label="Resultado da Consulta" value={lead.tipoConsulta} />
                    </CardContent>
                  </Card>
                </div>

                {/* FINANCEIRO */}
                <Card>
                  <CardHeader className="pb-3 sm:pb-4">
                    <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                      <DollarSign className="h-4 w-4 sm:h-5 sm:w-5" />
                      Informações Financeiras
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="pt-0">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                      <MetricBox
                        label="Saldo Total"
                        value={formatCurrency(lead.saldo)}
                        color="blue"
                        description="Valor total de FGTS retornado pelo robô"
                      />
                      <MetricBox
                        label="Valor Liberado"
                        value={formatCurrency(lead.libera)}
                        color="green"
                        description="Valor disponível para liberação"
                      />
                    </div>
                  </CardContent>
                </Card>
              </TabsContent>

              {/* === Telefones === */}
              <TabsContent value="telefones" className="mt-0 pr-4">
                <Card>
                  <CardHeader className="pb-3 sm:pb-4">
                    <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                      <Phone className="h-4 w-4 sm:h-5 sm:w-5" />
                      Telefones de Contato
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="pt-0">
                    {lead.telefones.length ? (
                      <div className="grid grid-cols-1 gap-3 sm:gap-4">
                        {lead.telefones.map((t, i) => (
                          <div
                            key={i}
                            className="flex items-center justify-between p-3 border rounded-lg"
                          >
                            <div className="flex-1 min-w-0">
                              <p className="font-mono text-sm sm:text-base text-gray-900 truncate">
                                {formatPhone(t.numero)}
                              </p>
                              <p className="text-xs sm:text-sm text-gray-600">
                                Telefone {i + 1}
                              </p>
                            </div>
                            {t.classe && (
                              <Badge
                                variant="outline"
                                className={cn(
                                  "text-xs ml-2 flex-shrink-0",
                                  t.classe === "Quente"
                                    ? "border-red-200 text-red-700 bg-red-50"
                                    : "border-blue-200 text-blue-700 bg-blue-50",
                                )}
                              >
                                {t.classe}
                              </Badge>
                            )}
                          </div>
                        ))}
                      </div>
                    ) : (
                      <Empty text="Nenhum telefone cadastrado" />
                    )}
                  </CardContent>
                </Card>
              </TabsContent>

              {/* === Contratos === */}
              <TabsContent value="contratos" className="mt-0 pr-4">
                <Card>
                  <CardHeader className="pb-3 sm:pb-4">
                    <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                      <FileText className="h-4 w-4 sm:h-5 sm:w-5" />
                      Contratos
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="pt-0">
                    {lead.contratos.length ? (
                      <div className="space-y-3 sm:space-y-4">
                        {lead.contratos.map((c, i) => (
                          <div
                            key={i}
                            className="flex items-center justify-between p-3 sm:p-4 border rounded-lg bg-gray-50"
                          >
                            <div className="flex items-center gap-3 sm:gap-4 flex-1 min-w-0">
                              <div className="bg-primary/10 p-2 rounded-full flex-shrink-0">
                                <Calendar className="h-3 w-3 sm:h-4 sm:w-4 text-primary" />
                              </div>
                              <div className="min-w-0 flex-1">
                                <p className="font-medium text-sm sm:text-base text-gray-900">
                                  Contrato #{i + 1}
                                </p>
                                <p className="text-xs sm:text-sm text-gray-600 truncate">
                                  Data: {c.dataContrato}
                                </p>
                              </div>
                            </div>
                            <div className="text-right flex-shrink-0 ml-2">
                              <p className="font-medium text-sm text-gray-900 truncate max-w-[110px] sm:max-w-none">
                                {c.vendedor}
                              </p>
                              <p className="text-xs text-gray-600">Vendedor</p>
                            </div>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <Empty text="Nenhum contrato encontrado" />
                    )}
                  </CardContent>
                </Card>
              </TabsContent>

              {/* === Histórico === */}
              <TabsContent value="historico" className="mt-0 pr-4">
                <Card>
                  <CardHeader className="pb-3 sm:pb-4">
                    <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                      <History className="h-4 w-4 sm:h-5 sm:w-5" />
                      Histórico de Importações
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="pt-0">
                    {lead.historicoimports.length ? (
                      <div className="space-y-3">
                        {lead.historicoimports.map((h, i) => (
                          <div
                            key={i}
                            className="flex items-center gap-3 sm:gap-4 p-3 border rounded-lg"
                          >
                            <div className="bg-purple-100 p-2 rounded-full flex-shrink-0">
                              <History className="h-3 w-3 sm:h-4 sm:w-4 text-purple-600" />
                            </div>
                            <div className="flex-1 min-w-0">
                              <div className="flex items-center gap-2 flex-wrap">
                                <Badge variant="outline" className="text-xs">
                                  {h.tipo}
                                </Badge>
                                <span className="text-xs sm:text-sm font-medium text-gray-900 truncate">
                                  {h.origem}
                                </span>
                              </div>
                              <p className="text-xs text-gray-600 mt-1">
                                {h.dataImportacao}
                              </p>
                            </div>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <Empty text="Nenhuma importação encontrada" />
                    )}
                  </CardContent>
                </Card>
              </TabsContent>
            </ScrollArea>
          </div>
        </Tabs>

        <Separator className="my-3 sm:my-4 flex-shrink-0" />

        {/* ---------- Footer ---------- */}
        <div className="flex justify-end flex-shrink-0">
          <Button onClick={onClose} variant="outline" className="text-sm">
            Fechar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/* ----------------------- tiny presentational helpers -------------------- */
const Info = ({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) => (
  <div>
    <label className="text-xs sm:text-sm font-medium text-gray-600">{label}</label>
    <p className={cn("text-sm sm:text-base", mono && "font-mono")}>{value}</p>
  </div>
)

/* ⬇️ agora com `description` */
const MetricBox = ({
  label,
  value,
  color,
  description,
}: {
  label: string
  value: string
  color: "blue" | "green"
  description: string
}) => (
  <div className={cn(`bg-${color}-50`, "p-3 sm:p-4 rounded-lg")}>
    <label className={cn(`text-${color}-700`, "text-xs sm:text-sm font-medium")}>
      {label}
    </label>
    <p className={cn(`text-${color}-900`, "text-lg sm:text-2xl font-bold")}>{value}</p>
    <p className={cn(`text-${color}-600`, "text-xs mt-1")}>{description}</p>
  </div>
)
const Empty = ({ text }: { text: string }) => (
  <div className="flex items-center justify-center h-32">
    <p className="text-gray-600 text-center text-sm">{text}</p>
  </div>
)

const TabButton = ({
  value,
  icon,
  children,
}: {
  value: string
  icon: React.ReactNode
  children: React.ReactNode
}) => (
  <TabsTrigger
    value={value}
    className="flex items-center gap-1 sm:gap-2 text-xs sm:text-sm px-3 py-2 whitespace-nowrap flex-shrink-0 min-w-fit data-[state=active]:bg-background"
  >
    {icon}
    {children}
  </TabsTrigger>
)
