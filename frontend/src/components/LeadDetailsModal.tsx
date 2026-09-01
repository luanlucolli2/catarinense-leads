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

import {
  User,
  Phone,
  FileText,
  History,
  Calendar,
  DollarSign,
  AlertTriangle,
  Briefcase
} from "lucide-react"

import { fetchLeadDetail, LeadDetailFromApi } from "@/api/leads"
import {
  formatCPF,
  formatCurrency,
  formatPhone,
  formatDateOnly,
} from "@/lib/formatters"
import { cn } from "@/lib/utils"
import { format } from "date-fns"
import { ptBR } from "date-fns/locale"

/* ------------------------------------------------------------------ */
/* helpers p/ formatação                                              */
const fmtDateTime = (iso?: string | null) =>
  iso ? format(new Date(iso), "dd/MM/yyyy HH:mm", { locale: ptBR }) : "--"

/* ---------------------------- tipos / mappers ----------------------*/
type UILead = {
  nome: string
  cpf: string
  dataNascimento: string
  tipoConsulta: string
  saldoDisplay: string
  saldoAlert: boolean
  liberaDisplay: string
  liberaAlert: boolean
  telefones: { numero: string; classe: string | null }[]
  contratos: { dataContrato: string; vendedor: string }[]
  historicoimports: { tipo: string; origem: string; dataImportacao: string }[]
  dataConsulta: string
  createdAt: string
  updatedAt: string
}

const mapApiToUi = (d: LeadDetailFromApi): UILead => {
  const rawSaldo = d.saldo ?? ""
  const numSaldo = parseFloat((rawSaldo as string).replace(",", "."))
  const saldoOk = !Number.isNaN(numSaldo)
  const saldoDisp = saldoOk ? formatCurrency(numSaldo) : (rawSaldo as string)

  const rawLib = d.libera ?? ""
  const numLib = parseFloat((rawLib as string).replace(",", "."))
  const liberaOk = !Number.isNaN(numLib)
  const liberaDisp = liberaOk ? formatCurrency(numLib) : (rawLib as string)

  const telefones = [1, 2, 3, 4].flatMap((i) => {
    const num = (d as any)[`fone${i}`] as string | null
    if (!num) return []
    const cls = (d as any)[`classe_fone${i}`] as string | null
    return [{ numero: num, classe: cls }]
  })

  const contractsArr = Array.isArray(d.contracts) ? d.contracts : []
  const contratos = contractsArr.map((c: any) => ({
    dataContrato: formatDateOnly(c.data_contrato),
    vendedor: c.vendor?.name ?? "Sem vendedor",
  }))

  const imports = (d as any).import_jobs ?? (d as any).importJobs ?? []
  const historicoimports = imports.map((j: any) => ({
    tipo: j.type,
    origem: j.origin,
    dataImportacao: formatDateOnly(j.created_at),
  }))

  return {
    nome: d.nome,
    cpf: d.cpf,
    dataNascimento: formatDateOnly(d.data_nascimento),
    tipoConsulta: d.consulta ?? "--",
    saldoDisplay: saldoDisp,
    saldoAlert: !saldoOk,
    liberaDisplay: liberaDisp,
    liberaAlert: !liberaOk,
    telefones,
    contratos,
    historicoimports,
    dataConsulta: fmtDateTime(d.data_atualizacao),
    createdAt: fmtDateTime(d.created_at),
    updatedAt: fmtDateTime(d.updated_at),
  }
}

/* ------------------------------------------------------------------ */
/* componente                                                         */
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
  const { data, isLoading } = useQuery<LeadDetailFromApi>({
    queryKey: ["lead", leadId],
    queryFn: () => fetchLeadDetail(leadId as number),
    enabled: !!leadId,
  })

  if (!leadId || isLoading || !data) return null
  const lead = mapApiToUi(data)

  // helpers CLT
  const clt = data
  const mercantil = data
  const uy3 = data
  const cltStatus = clt.not_found
    ? "Não encontrado"
    : clt.elegivel === true
    ? "Elegível"
    : clt.elegivel === false
    ? "Não elegível"
    : "—"

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent className="max-w-5xl w-[96vw] p-4 sm:p-6 max-h-[90vh] sm:max-h-[92vh] overflow-hidden flex flex-col">
        {/* ---------- Cabeçalho ---------- */}
        <DialogHeader className="pb-2 sm:pb-4 flex-shrink-0">
          <DialogTitle className="text-lg sm:text-xl font-semibold flex flex-col gap-1">
            <span className="flex items-center gap-2">
              <User className="h-5 w-5" />
              Detalhes do Lead
            </span>

            <span className="text-xs text-gray-500 font-normal flex flex-wrap gap-2 mt-1">
              <span>· Criado: <strong>{lead.createdAt}</strong></span>
              <span>· Atualizado: <strong>{lead.updatedAt}</strong></span>
              <span>Consulta FGTS: <strong>{lead.dataConsulta}</strong></span>
            </span>
          </DialogTitle>
        </DialogHeader>

        {/* ---------- Tabs + Conteúdo com rolagem ---------- */}
        <Tabs defaultValue="dados" className="flex flex-col flex-1 min-h-0">
          <TabsBar />

          <div className="flex-1 min-h-0 overflow-y-auto pr-1">
            {/* === Dados (FGTS) === */}
            <TabsContent value="dados" className="space-y-4 sm:space-y-6">
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                <PersonalCard lead={lead} />
                <StatusCard lead={lead} />
              </div>

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
                      value={lead.saldoDisplay}
                      color="blue"
                      description="Valor total de FGTS retornado pelo robô"
                      alert={lead.saldoAlert}
                    />
                    <MetricBox
                      label="Valor Liberado"
                      value={lead.liberaDisplay}
                      color="green"
                      description="Valor disponível para liberação"
                      alert={lead.liberaAlert}
                    />
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            {/* === Telefones === */}
            <TabsContent value="telefones">
              <PhonesCard lead={lead} />
            </TabsContent>

            {/* === Contratos === */}
            <TabsContent value="contratos">
              <ContractsCard lead={lead} />
            </TabsContent>

            {/* === Histórico === */}
            <TabsContent value="historico">
              <HistoryCard lead={lead} />
            </TabsContent>

            {/* === CLT === */}
            <TabsContent value="clt">
              <Card className="mb-4">
                <CardHeader className="pb-3 sm:pb-4">
                  <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                    <Briefcase className="h-4 w-4 sm:h-5 sm:w-5" />
                    CLT (Consignado)
                  </CardTitle>
                </CardHeader>
                <CardContent className="pt-0 space-y-4">
                  {/* Situação da consulta */}
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Situação" value={cltStatus} />
                    <Info label="Já consultado?" value={clt.facta_consultado_em ? "Sim" : "Ainda não"} />
                    <Info label="Data da última consulta" value={clt.facta_consultado_em ? formatDateOnly(clt.facta_consultado_em) : "—"} />
                  </div>

                  {/* Vínculo de trabalho */}
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Data de admissão" value={formatDateOnly(clt.data_admissao)} />
                    <Info label="Tempo de casa (meses)" value={clt.meses_admissao != null ? String(clt.meses_admissao) : "—"} />
                    <Info label="Início atividade do empregador" value={formatDateOnly(clt.inicio_atividade_empregador)} />
                  </div>

                  {/* Perfil do cliente */}
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Idade" value={clt.idade != null ? String(clt.idade) : "—"} />
                    <Info label="Sexo" value={clt.sexo ?? "—"} />
                    <Info label="Categoria do trabalhador (cód.)" value={clt.categoria_trabalhador_codigo ?? "—"} />
                  </div>

                  {/* Renda e margem */}
                  <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <Info label="Renda total" value={formatCurrency(clt.valor_renda as any)} />
                    <Info label="Base de margem" value={formatCurrency(clt.valor_base_margem as any)} />
                    <Info label="Margem disponível" value={formatCurrency(clt.margem_disponivel as any)} />
                    <Info label="Valor máx. prestação" value={formatCurrency(clt.valor_max_prestacao as any)} />
                  </div>

                  {/* Histórico de crédito */}
                  <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <Info label="Qtd. empréstimos ativos/suspensos" value={clt.qtd_emprestimos_ativos_suspensos != null ? String(clt.qtd_emprestimos_ativos_suspensos) : "—"} />
                    <Info label="Tem ativos?" value={(clt.qtd_emprestimos_ativos_suspensos ?? 0) > 0 ? "Sim" : "Não"} />
                    <Info label="Qtd. legados" value={clt.emprestimos_legados != null ? String(clt.emprestimos_legados) : "—"} />
                    <Info label="Tem legados?" value={(clt.emprestimos_legados ?? 0) > 0 ? "Sim" : "Não"} />
                  </div>

                  {/* Origem (cad.) */}
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Última origem (cadastral)" value={(data as any).ultima_origem_cadastral ?? "—"} />
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="mercantil">
              <Card className="mb-4">
                <CardHeader className="pb-3 sm:pb-4">
                  <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                    <Briefcase className="h-4 w-4 sm:h-5 sm:w-5" />
                    Mercantil
                  </CardTitle>
                </CardHeader>
                <CardContent className="pt-0 space-y-4">
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Status" value={mercantil.mercantil_status ?? "—"} />
                    <Info label="Consulta" value={mercantil.mercantil_data_hora_origem ? fmtDateTime(mercantil.mercantil_data_hora_origem) : "—"} />
                    <Info label="Origem mercantil" value={mercantil.ultima_origem_mercantil ?? "—"} />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Valor empréstimo" value={formatCurrency(mercantil.mercantil_valor_emprestimo as any)} />
                    <Info label="Valor liberado" value={formatCurrency(mercantil.mercantil_valor_liberado as any)} />
                    <Info label="Valor parcela" value={formatCurrency(mercantil.mercantil_valor_parcela as any)} />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Valor financiado" value={formatCurrency(mercantil.mercantil_valor_financiado as any)} />
                    <Info label="IOF" value={formatCurrency(mercantil.mercantil_valor_iof as any)} />
                    <Info label="Taxa juros mês" value={mercantil.mercantil_taxa_juros_mes ? `${mercantil.mercantil_taxa_juros_mes}%` : "—"} />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Qtd. parcelas" value={mercantil.mercantil_quantidade_parcelas != null ? String(mercantil.mercantil_quantidade_parcelas) : "—"} />
                    <Info label="1º vencimento" value={formatDateOnly(mercantil.mercantil_data_primeiro_vencimento)} />
                    <Info label="Mensagem" value={mercantil.mercantil_mensagem_erro ?? "—"} />
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="uy3">
              <Card className="mb-4">
                <CardHeader className="pb-3 sm:pb-4">
                  <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
                    <Briefcase className="h-4 w-4 sm:h-5 sm:w-5" />
                    UY3
                  </CardTitle>
                </CardHeader>
                <CardContent className="pt-0 space-y-4">
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Status" value={uy3.uy3_status ?? "—"} />
                    <Info label="Tipo webhook" value={uy3.uy3_type_webhook ?? "—"} />
                    <Info label="Consulta" value={uy3.uy3_consultado_em ? fmtDateTime(uy3.uy3_consultado_em) : "—"} />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Data admissão" value={formatDateOnly(uy3.uy3_data_admissao)} />
                    <Info label="Margem disponível" value={formatCurrency(uy3.uy3_margem_disponivel as any)} />
                    <Info label="Valor liberado" value={formatCurrency(uy3.uy3_valor_liberado as any)} />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Qtd. parcelas" value={uy3.uy3_numero_parcelas != null ? String(uy3.uy3_numero_parcelas) : "—"} />
                    <Info label="Elegível empréstimo" value={uy3.uy3_elegivel_emprestimo === true ? "Sim" : uy3.uy3_elegivel_emprestimo === false ? "Não" : "—"} />
                    <Info label="Código requisição" value={uy3.uy3_codigo_requisicao ?? "—"} />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="Inscrição empregador" value={uy3.uy3_numero_inscricao_empregador ?? "—"} />
                    <Info label="PEP código" value={uy3.uy3_pessoa_exposta_politicamente_codigo != null ? String(uy3.uy3_pessoa_exposta_politicamente_codigo) : "—"} />
                    <Info label="Validade solicitação" value={uy3.uy3_data_hora_validade_solicitacao ? fmtDateTime(uy3.uy3_data_hora_validade_solicitacao) : "—"} />
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Info label="É MEI" value={uy3.uy3_is_mei === true ? "Sim" : uy3.uy3_is_mei === false ? "Não" : "—"} />
                    <Info label="Recuperação judicial" value={uy3.uy3_is_judicial_recovery === true ? "Sim" : uy3.uy3_is_judicial_recovery === false ? "Não" : "—"} />
                    <Info label="Origem cadastral" value={uy3.ultima_origem_cadastral ?? "—"} />
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            <div className="h-2" />
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

/* ----------------------- sub-components ----------------------------- */
const TabsBar = () => (
  <div className="flex-shrink-0">
    <TabsList className="flex w-full h-auto p-1 bg-muted/50 overflow-x-auto">
      <TabButton value="dados" icon={<User className="h-3 w-3 sm:h-4 sm:w-4" />}>Dados</TabButton>
      <TabButton value="telefones" icon={<Phone className="h-3 w-3 sm:h-4 sm:w-4" />}>Telefones</TabButton>
      <TabButton value="contratos" icon={<FileText className="h-3 w-3 sm:h-4 sm:w-4" />}>Contratos</TabButton>
      <TabButton value="historico" icon={<History className="h-3 w-3 sm:h-4 sm:w-4" />}>Histórico</TabButton>
      <TabButton value="clt" icon={<Briefcase className="h-3 w-3 sm:h-4 sm:w-4" />}>CLT</TabButton>
      <TabButton value="mercantil" icon={<Briefcase className="h-3 w-3 sm:h-4 sm:w-4" />}>Mercantil</TabButton>
      <TabButton value="uy3" icon={<Briefcase className="h-3 w-3 sm:h-4 sm:w-4" />}>UY3</TabButton>
    </TabsList>
  </div>
)

/* —— Cartões —— */
const PersonalCard = ({ lead }: { lead: UILead }) => (
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
)

const StatusCard = ({ lead }: { lead: UILead }) => (
  <Card>
    <CardHeader className="pb-3 sm:pb-4">
      <CardTitle className="text-base sm:text-lg font-medium flex items-center gap-2">
        <FileText className="h-4 w-4 sm:h-5 sm:w-5" />
        Consulta
      </CardTitle>
    </CardHeader>
    <CardContent className="space-y-3 sm:space-y-4 pt-0">
      <Info label="Resultado da Consulta" value={lead.tipoConsulta} />
    </CardContent>
  </Card>
)

/* —— Métricas (Saldo / Libera) —— */
const MetricBox = ({
  label,
  value,
  color,
  description,
  alert = false,
}: {
  label: string
  value: string
  color: "blue" | "green"
  description: string
  alert?: boolean
}) => {
  const palette = {
    blue: {
      bg: "bg-blue-50",
      text: "text-blue-900",
      label: "text-blue-700",
      desc: "text-blue-600",
    },
    green: {
      bg: "bg-green-50",
      text: "text-green-900",
      label: "text-green-700",
      desc: "text-green-600",
    },
  }[color]

  const bg = alert ? "bg-yellow-50 border-yellow-300 border" : palette.bg
  const textMain = alert ? "text-yellow-800" : palette.text
  const textLabel = alert ? "text-yellow-700" : palette.label
  const textDesc = alert ? "text-yellow-600" : palette.desc

  return (
    <div className={cn(bg, "p-3 sm:p-4 rounded-lg relative")}>
      <label className={cn(textLabel, "text-xs sm:text-sm font-medium")}>
        {label}
      </label>

      <div className="flex items-center mt-1">
        <p
          className={cn(
            textMain,
            alert ? "text-base font-semibold" : "text-lg sm:text-2xl font-bold",
            "whitespace-pre-wrap max-h-20 overflow-auto break-words"
          )}
        >
          {value}
        </p>
        {alert && (
          <Badge
            variant="secondary"
            className="ml-2 bg-yellow-100 text-yellow-800 hover:bg-yellow-100"
          >
            <AlertTriangle className="h-4 w-4" />
          </Badge>
        )}
      </div>

      <p className={cn(textDesc, "text-xs mt-1")}>{description}</p>
    </div>
  )
}

const PhonesCard = ({ lead }: { lead: UILead }) => (
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
)

const ContractsCard = ({ lead }: { lead: UILead }) => (
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
                <p className="font-medium text-sm text-gray-900 truncate max-w-[120px] sm:max-w-none">
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
)

const HistoryCard = ({ lead }: { lead: UILead }) => (
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
)

/* —— itens utilitários —— */
const Info = ({
  label,
  value,
  mono = false,
}: {
  label: string
  value: string
  mono?: boolean
}) => (
  <div>
    <label className="text-xs sm:text-sm font-medium text-gray-600">
      {label}
    </label>
    <p className={cn("text-sm sm:text-base break-words", mono && "font-mono")}>
      {value}
    </p>
  </div>
)

const Empty = ({ text }: { text: string }) => (
  <div className="flex items-center justify-center h-24">
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
