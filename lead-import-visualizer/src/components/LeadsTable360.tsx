import { useMemo, useState } from "react"
import { ChevronLeft, ChevronRight, Eye } from "lucide-react"
import { Button } from "@/components/ui/button"
import { LeadDetailsModal } from "./LeadDetailsModal"
import { cn } from "@/lib/utils"
import type { Telefone } from "./LeadsTable"
import factaLogo from "@/assets/factalogo.png"
import mercantilLogo from "@/assets/mercantilogo.png"
import uy3Logo from "@/assets/logouy3png.png"

const EMPTY = "--"

export interface ProcessedLead360 {
  id: number
  cpf: string
  nome: string
  created_at: string
  updated_at: string
  data_nascimento: string
  telefones: Telefone[]
  consulta: string
  saldo: string
  libera: string
  data_atualizacao: string
  contratos: number
  data_contrato_recente: string
  vendedor: string
  fgts_off_authorized: boolean | null
  fgts_off_consultado_em: string
  ultima_origem_cadastral: string
  ultima_origem_higienizacao: string
  elegivel: boolean | null
  not_found: boolean
  margem_disponivel: string
  politica_credito_aprovado: boolean | null
  clt_consultado_em: string
  clt_dados_atualizados_em: string
  mercantil_status: string
  mercantil_mensagem_erro: string
  mercantil_data_hora_origem: string
  mercantil_valor_financiado: string
  mercantil_valor_iof: string
  mercantil_data_primeiro_vencimento: string
  mercantil_valor_emprestimo: string
  mercantil_quantidade_parcelas: string | number
  mercantil_valor_liberado: string
  mercantil_taxa_juros_mes: string
  mercantil_valor_parcela: string
  ultima_origem_mercantil: string
  uy3_type_webhook: string
  uy3_status: string
  uy3_consultado_em: string
  uy3_data_admissao: string
  uy3_valor_liberado: string
  uy3_numero_parcelas: string | number
  uy3_codigo_requisicao: string
  uy3_margem_disponivel: string
  uy3_elegivel_emprestimo: boolean | null
  uy3_numero_inscricao_empregador: string
  uy3_pessoa_exposta_politicamente_codigo: string | number
  uy3_data_hora_validade_solicitacao: string
  uy3_is_mei: boolean | null
  uy3_is_judicial_recovery: boolean | null
}

type Props = {
  leads: ProcessedLead360[]
  currentPage: number
  totalPages: number
  onPageChange: (page: number) => void
  isLoading: boolean
  visibleColumns: string[]
  stickyIdentityColumns?: boolean
}

const boolLabel = (value: boolean | null | undefined) =>
  value === true ? "Sim" : value === false ? "Nao" : EMPTY

const display = (value?: string | number | null) =>
  value === undefined || value === null || value === "" ? EMPTY : String(value)

const phoneAt = (lead: ProcessedLead360, index: number) => lead.telefones[index]?.fone || EMPTY
const phoneClassAt = (lead: ProcessedLead360, index: number) => lead.telefones[index]?.classe || EMPTY

const getColumnTone = (columnId: string) => {
  if (["cpf", "nome", "data_nascimento", "telefone_1", "classe_1", "telefone_2", "classe_2", "telefone_3", "classe_3", "telefone_4", "classe_4", "ultima_origem_cadastral", "ultima_origem_higienizacao"].includes(columnId)) {
    return "border-gray-200 bg-slate-50 text-slate-600"
  }

  if (["consulta", "saldo", "libera", "data_atualizacao", "fgts_off_authorized", "fgts_off_consultado_em", "contratos", "data_contrato_recente", "vendedor"].includes(columnId)) {
    return "border-amber-200 bg-amber-50 text-amber-800"
  }

  if (["elegivel", "not_found", "margem_disponivel", "politica_credito_aprovado", "clt_consultado_em", "clt_dados_atualizados_em"].includes(columnId)) {
    return "border-[#d2782d]/30 bg-[#d2782d]/10 text-[#9a561f]"
  }

  if (["mercantil_status", "mercantil_mensagem_erro", "mercantil_data_hora_origem", "mercantil_valor_financiado", "mercantil_valor_iof", "mercantil_data_primeiro_vencimento", "mercantil_valor_emprestimo", "mercantil_quantidade_parcelas", "mercantil_valor_liberado", "mercantil_taxa_juros_mes", "mercantil_valor_parcela", "ultima_origem_mercantil"].includes(columnId)) {
    return "border-blue-200 bg-blue-50 text-blue-700"
  }

  if (["uy3_type_webhook", "uy3_status", "uy3_consultado_em", "uy3_data_admissao", "uy3_valor_liberado", "uy3_numero_parcelas", "uy3_codigo_requisicao", "uy3_margem_disponivel", "uy3_elegivel_emprestimo", "uy3_numero_inscricao_empregador", "uy3_pessoa_exposta_politicamente_codigo", "uy3_data_hora_validade_solicitacao", "uy3_is_mei", "uy3_is_judicial_recovery"].includes(columnId)) {
    return "border-[#f46c00]/30 bg-[#f46c00]/10 text-[#b44f00]"
  }

  return "border-gray-200 bg-slate-50 text-slate-600"
}

const getColumnIcon = (columnId: string) => {
  if (["elegivel", "not_found", "margem_disponivel", "politica_credito_aprovado", "clt_consultado_em", "clt_dados_atualizados_em"].includes(columnId)) {
    return { src: factaLogo, alt: "Facta" }
  }

  if (["mercantil_status", "mercantil_mensagem_erro", "mercantil_data_hora_origem", "mercantil_valor_financiado", "mercantil_valor_iof", "mercantil_data_primeiro_vencimento", "mercantil_valor_emprestimo", "mercantil_quantidade_parcelas", "mercantil_valor_liberado", "mercantil_taxa_juros_mes", "mercantil_valor_parcela", "ultima_origem_mercantil"].includes(columnId)) {
    return { src: mercantilLogo, alt: "Mercantil" }
  }

  if (["uy3_type_webhook", "uy3_status", "uy3_consultado_em", "uy3_data_admissao", "uy3_valor_liberado", "uy3_numero_parcelas", "uy3_codigo_requisicao", "uy3_margem_disponivel", "uy3_elegivel_emprestimo", "uy3_numero_inscricao_empregador", "uy3_pessoa_exposta_politicamente_codigo", "uy3_data_hora_validade_solicitacao", "uy3_is_mei", "uy3_is_judicial_recovery"].includes(columnId)) {
    return { src: uy3Logo, alt: "UY3" }
  }

  return null
}

const DesktopCell = ({
  children,
  sticky,
  className,
}: {
  children: React.ReactNode
  sticky?: "left-0" | "left-[160px]" | "right-0"
  className?: string
}) => (
  <td
    className={cn(
      "border-b border-gray-100 bg-white px-3 py-3 text-sm text-gray-900",
      sticky && "sticky z-10 bg-white",
      sticky,
      className
    )}
  >
    {children}
  </td>
)

const DesktopHead = ({
  children,
  sticky,
  className,
}: {
  children: React.ReactNode
  sticky?: "left-0" | "left-[160px]" | "right-0"
  className?: string
}) => (
  <th
    className={cn(
      "border-b px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide",
      sticky && "sticky z-20",
      sticky,
      className
    )}
  >
    {children}
  </th>
)

const Pagination = ({ currentPage, totalPages, onPageChange }: Pick<Props, "currentPage" | "totalPages" | "onPageChange">) => (
  <div className="flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-3">
    <Button variant="outline" size="sm" onClick={() => onPageChange(Math.max(1, currentPage - 1))} disabled={currentPage <= 1}>
      <ChevronLeft className="mr-1 h-4 w-4" />
      Anterior
    </Button>
    <span className="text-sm text-gray-600">
      Pagina {currentPage} de {Math.max(totalPages, 1)}
    </span>
    <Button variant="outline" size="sm" onClick={() => onPageChange(Math.min(totalPages, currentPage + 1))} disabled={currentPage >= totalPages}>
      Proxima
      <ChevronRight className="ml-1 h-4 w-4" />
    </Button>
  </div>
)

const LoadingRows = () => (
  <tbody>
    {Array.from({ length: 8 }).map((_, index) => (
      <tr key={index}>
        {Array.from({ length: 8 }).map((__, cellIndex) => (
          <td key={cellIndex} className="border-b border-gray-100 px-3 py-4">
            <div className="h-4 animate-pulse rounded bg-gray-100" />
          </td>
        ))}
      </tr>
    ))}
  </tbody>
)

export const LeadsTable360 = ({
  leads,
  currentPage,
  totalPages,
  onPageChange,
  isLoading,
  visibleColumns,
  stickyIdentityColumns = true,
}: Props) => {
  const [selectedLeadId, setSelectedLeadId] = useState<number | null>(null)
  const [isModalOpen, setIsModalOpen] = useState(false)

  const columns = useMemo(
    () => [
      { id: "cpf", label: "CPF", render: (lead: ProcessedLead360) => lead.cpf },
      { id: "nome", label: "Nome", render: (lead: ProcessedLead360) => lead.nome },
      { id: "data_nascimento", label: "Nascimento", render: (lead: ProcessedLead360) => display(lead.data_nascimento) },
      { id: "telefone_1", label: "Fone 1", render: (lead: ProcessedLead360) => phoneAt(lead, 0) },
      { id: "classe_1", label: "Classe 1", render: (lead: ProcessedLead360) => phoneClassAt(lead, 0) },
      { id: "telefone_2", label: "Fone 2", render: (lead: ProcessedLead360) => phoneAt(lead, 1) },
      { id: "classe_2", label: "Classe 2", render: (lead: ProcessedLead360) => phoneClassAt(lead, 1) },
      { id: "telefone_3", label: "Fone 3", render: (lead: ProcessedLead360) => phoneAt(lead, 2) },
      { id: "classe_3", label: "Classe 3", render: (lead: ProcessedLead360) => phoneClassAt(lead, 2) },
      { id: "telefone_4", label: "Fone 4", render: (lead: ProcessedLead360) => phoneAt(lead, 3) },
      { id: "classe_4", label: "Classe 4", render: (lead: ProcessedLead360) => phoneClassAt(lead, 3) },
      { id: "ultima_origem_cadastral", label: "Origem cadastral", render: (lead: ProcessedLead360) => display(lead.ultima_origem_cadastral) },
      { id: "ultima_origem_higienizacao", label: "Origem hig.", render: (lead: ProcessedLead360) => display(lead.ultima_origem_higienizacao) },
      { id: "consulta", label: "FGTS consulta", render: (lead: ProcessedLead360) => display(lead.consulta) },
      { id: "saldo", label: "FGTS saldo", render: (lead: ProcessedLead360) => display(lead.saldo) },
      { id: "libera", label: "FGTS libera", render: (lead: ProcessedLead360) => display(lead.libera) },
      { id: "data_atualizacao", label: "FGTS hig.", render: (lead: ProcessedLead360) => display(lead.data_atualizacao) },
      { id: "fgts_off_authorized", label: "FGTS Off", render: (lead: ProcessedLead360) => boolLabel(lead.fgts_off_authorized) },
      { id: "fgts_off_consultado_em", label: "FGTS Off consulta", render: (lead: ProcessedLead360) => display(lead.fgts_off_consultado_em) },
      { id: "contratos", label: "Contratos", render: (lead: ProcessedLead360) => display(lead.contratos) },
      { id: "data_contrato_recente", label: "Ult. contrato", render: (lead: ProcessedLead360) => display(lead.data_contrato_recente) },
      { id: "vendedor", label: "Vendedor", render: (lead: ProcessedLead360) => display(lead.vendedor) },
      { id: "politica_credito_aprovado", label: "Facta politica", render: (lead: ProcessedLead360) => boolLabel(lead.politica_credito_aprovado) },
      { id: "margem_disponivel", label: "Facta margem", render: (lead: ProcessedLead360) => display(lead.margem_disponivel) },
      { id: "clt_consultado_em", label: "Facta consulta", render: (lead: ProcessedLead360) => display(lead.clt_consultado_em) },
      { id: "mercantil_status", label: "Mercantil status", render: (lead: ProcessedLead360) => display(lead.mercantil_status) },
      { id: "mercantil_valor_liberado", label: "Mercantil liberado", render: (lead: ProcessedLead360) => display(lead.mercantil_valor_liberado) },
      { id: "mercantil_data_hora_origem", label: "Mercantil consulta", render: (lead: ProcessedLead360) => display(lead.mercantil_data_hora_origem) },
      { id: "uy3_elegivel_emprestimo", label: "UY3 elegivel", render: (lead: ProcessedLead360) => boolLabel(lead.uy3_elegivel_emprestimo) },
      { id: "uy3_valor_liberado", label: "UY3 liberado", render: (lead: ProcessedLead360) => display(lead.uy3_valor_liberado) },
      { id: "uy3_consultado_em", label: "UY3 consulta", render: (lead: ProcessedLead360) => display(lead.uy3_consultado_em) },
      { id: "elegivel", label: "Facta elegivel", render: (lead: ProcessedLead360) => boolLabel(lead.elegivel) },
      { id: "not_found", label: "Facta nao encontrado", render: (lead: ProcessedLead360) => boolLabel(lead.not_found) },
      { id: "clt_dados_atualizados_em", label: "Facta dados", render: (lead: ProcessedLead360) => display(lead.clt_dados_atualizados_em) },
      { id: "mercantil_mensagem_erro", label: "Mercantil msg", render: (lead: ProcessedLead360) => display(lead.mercantil_mensagem_erro) },
      { id: "mercantil_valor_financiado", label: "Mercantil financiado", render: (lead: ProcessedLead360) => display(lead.mercantil_valor_financiado) },
      { id: "mercantil_valor_iof", label: "Mercantil IOF", render: (lead: ProcessedLead360) => display(lead.mercantil_valor_iof) },
      { id: "mercantil_data_primeiro_vencimento", label: "Mercantil venc.", render: (lead: ProcessedLead360) => display(lead.mercantil_data_primeiro_vencimento) },
      { id: "mercantil_valor_emprestimo", label: "Mercantil emprestimo", render: (lead: ProcessedLead360) => display(lead.mercantil_valor_emprestimo) },
      { id: "mercantil_quantidade_parcelas", label: "Mercantil parcelas", render: (lead: ProcessedLead360) => display(lead.mercantil_quantidade_parcelas) },
      { id: "mercantil_taxa_juros_mes", label: "Mercantil juros", render: (lead: ProcessedLead360) => display(lead.mercantil_taxa_juros_mes) },
      { id: "mercantil_valor_parcela", label: "Mercantil parcela", render: (lead: ProcessedLead360) => display(lead.mercantil_valor_parcela) },
      { id: "ultima_origem_mercantil", label: "Origem mercantil", render: (lead: ProcessedLead360) => display(lead.ultima_origem_mercantil) },
      { id: "uy3_type_webhook", label: "UY3 tipo", render: (lead: ProcessedLead360) => display(lead.uy3_type_webhook) },
      { id: "uy3_status", label: "UY3 status", render: (lead: ProcessedLead360) => display(lead.uy3_status) },
      { id: "uy3_data_admissao", label: "UY3 admissao", render: (lead: ProcessedLead360) => display(lead.uy3_data_admissao) },
      { id: "uy3_numero_parcelas", label: "UY3 parcelas", render: (lead: ProcessedLead360) => display(lead.uy3_numero_parcelas) },
      { id: "uy3_codigo_requisicao", label: "UY3 req.", render: (lead: ProcessedLead360) => display(lead.uy3_codigo_requisicao) },
      { id: "uy3_margem_disponivel", label: "UY3 margem", render: (lead: ProcessedLead360) => display(lead.uy3_margem_disponivel) },
      { id: "uy3_numero_inscricao_empregador", label: "UY3 empregador", render: (lead: ProcessedLead360) => display(lead.uy3_numero_inscricao_empregador) },
      { id: "uy3_pessoa_exposta_politicamente_codigo", label: "UY3 PEP", render: (lead: ProcessedLead360) => display(lead.uy3_pessoa_exposta_politicamente_codigo) },
      { id: "uy3_data_hora_validade_solicitacao", label: "UY3 validade", render: (lead: ProcessedLead360) => display(lead.uy3_data_hora_validade_solicitacao) },
      { id: "uy3_is_mei", label: "UY3 MEI", render: (lead: ProcessedLead360) => boolLabel(lead.uy3_is_mei) },
      { id: "uy3_is_judicial_recovery", label: "UY3 rec. judicial", render: (lead: ProcessedLead360) => boolLabel(lead.uy3_is_judicial_recovery) },
    ],
    []
  )

  const visibleSet = useMemo(() => new Set(visibleColumns), [visibleColumns])
  const visibleColumnsData = useMemo(
    () => columns.filter((column) => column.id === "cpf" || column.id === "nome" || visibleSet.has(column.id)),
    [columns, visibleSet]
  )

  const viewLead = (leadId: number) => {
    setSelectedLeadId(leadId)
    setIsModalOpen(true)
  }

  const hasAny = (ids: string[]) => ids.some((id) => visibleSet.has(id))

  return (
    <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
      <div className="hidden overflow-x-auto lg:block">
        <table className="min-w-max border-collapse">
          <thead>
            <tr>
              <DesktopHead sticky={stickyIdentityColumns ? "left-0" : undefined} className="min-w-[160px] bg-slate-50 text-slate-600">CPF</DesktopHead>
              <DesktopHead sticky={stickyIdentityColumns ? "left-[160px]" : undefined} className="min-w-[240px] bg-slate-50 text-slate-600">Nome</DesktopHead>
              {visibleColumnsData
                .filter((column) => column.id !== "cpf" && column.id !== "nome")
                .map((column) => {
                  const icon = getColumnIcon(column.id)

                  return (
                    <DesktopHead key={column.id} className={cn("min-w-[160px]", getColumnTone(column.id))}>
                      <span className="flex items-center gap-2">
                        {icon ? (
                          <img
                            src={icon.src}
                            alt={icon.alt}
                            className="h-4 w-4 shrink-0 rounded-sm object-contain"
                          />
                        ) : null}
                        <span>{column.label}</span>
                      </span>
                    </DesktopHead>
                  )
                })}
              <DesktopHead sticky="right-0" className="min-w-[110px] bg-slate-50 text-center text-slate-600">Acoes</DesktopHead>
            </tr>
          </thead>
          {isLoading ? (
            <LoadingRows />
          ) : (
            <tbody>
              {leads.length === 0 ? (
                <tr>
                  <td colSpan={visibleColumnsData.length + 1} className="px-4 py-12 text-center text-sm text-gray-500">
                    Nenhum lead encontrado.
                  </td>
                </tr>
              ) : (
                leads.map((lead) => (
                  <tr key={lead.id} className="hover:bg-slate-50/60">
                    <DesktopCell sticky={stickyIdentityColumns ? "left-0" : undefined} className="min-w-[160px] font-mono">{lead.cpf}</DesktopCell>
                    <DesktopCell sticky={stickyIdentityColumns ? "left-[160px]" : undefined} className="min-w-[240px] font-medium">{display(lead.nome)}</DesktopCell>
                    {visibleColumnsData
                      .filter((column) => column.id !== "cpf" && column.id !== "nome")
                      .map((column) => (
                        <DesktopCell key={column.id} className="min-w-[160px]">
                          {column.render(lead)}
                        </DesktopCell>
                      ))}
                    <DesktopCell sticky="right-0" className="min-w-[110px] text-center">
                      <Button variant="outline" size="sm" onClick={() => viewLead(lead.id)}>
                        <Eye className="mr-2 h-4 w-4" />
                        Ver
                      </Button>
                    </DesktopCell>
                  </tr>
                ))
              )}
            </tbody>
          )}
        </table>
      </div>

      <div className="space-y-4 p-4 lg:hidden">
        {isLoading ? (
          Array.from({ length: 6 }).map((_, index) => (
            <div key={index} className="space-y-3 rounded-lg border border-gray-200 p-4">
              <div className="h-5 animate-pulse rounded bg-gray-100" />
              <div className="h-4 animate-pulse rounded bg-gray-100" />
              <div className="h-4 animate-pulse rounded bg-gray-100" />
            </div>
          ))
        ) : leads.length === 0 ? (
          <div className="py-8 text-center text-sm text-gray-500">Nenhum lead encontrado.</div>
        ) : (
          leads.map((lead) => (
            <div key={lead.id} className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="font-medium text-gray-900">{display(lead.nome)}</div>
                  <div className="font-mono text-sm text-gray-600">{lead.cpf}</div>
                </div>
                <Button variant="outline" size="sm" onClick={() => viewLead(lead.id)}>
                  <Eye className="mr-2 h-4 w-4" />
                  Ver
                </Button>
              </div>

              <div className="mt-4 space-y-4">
                <CardSection title="Cadastral">
                  {visibleSet.has("telefone_1") && <DataRow label="Telefone" value={phoneAt(lead, 0)} />}
                  {visibleSet.has("ultima_origem_cadastral") && <DataRow label="Origem" value={display(lead.ultima_origem_cadastral)} />}
                </CardSection>

                {hasAny(["consulta", "libera", "fgts_off_authorized", "data_atualizacao"]) && (
                  <CardSection title="FGTS">
                    {visibleSet.has("consulta") && <DataRow label="Consulta" value={display(lead.consulta)} />}
                    {visibleSet.has("libera") && <DataRow label="Libera" value={display(lead.libera)} />}
                    {visibleSet.has("fgts_off_authorized") && <DataRow label="FGTS Off" value={boolLabel(lead.fgts_off_authorized)} />}
                    {visibleSet.has("data_atualizacao") && <DataRow label="Hig." value={display(lead.data_atualizacao)} />}
                  </CardSection>
                )}

                {hasAny(["politica_credito_aprovado", "margem_disponivel", "clt_consultado_em", "elegivel"]) && (
                  <CardSection title="Facta">
                    {visibleSet.has("politica_credito_aprovado") && <DataRow label="Status" value={boolLabel(lead.politica_credito_aprovado)} />}
                    {visibleSet.has("elegivel") && <DataRow label="Elegível" value={boolLabel(lead.elegivel)} />}
                    {visibleSet.has("margem_disponivel") && <DataRow label="Margem" value={display(lead.margem_disponivel)} />}
                    {visibleSet.has("clt_consultado_em") && <DataRow label="Consulta" value={display(lead.clt_consultado_em)} />}
                  </CardSection>
                )}

                {hasAny(["mercantil_status", "mercantil_valor_liberado", "mercantil_data_hora_origem"]) && (
                  <CardSection title="Mercantil">
                    {visibleSet.has("mercantil_status") && <DataRow label="Status" value={display(lead.mercantil_status)} />}
                    {visibleSet.has("mercantil_valor_liberado") && <DataRow label="Liberado" value={display(lead.mercantil_valor_liberado)} />}
                    {visibleSet.has("mercantil_data_hora_origem") && <DataRow label="Consulta" value={display(lead.mercantil_data_hora_origem)} />}
                  </CardSection>
                )}

                {hasAny(["uy3_elegivel_emprestimo", "uy3_status", "uy3_valor_liberado", "uy3_consultado_em"]) && (
                  <CardSection title="UY3">
                    {visibleSet.has("uy3_elegivel_emprestimo") && <DataRow label="Status" value={boolLabel(lead.uy3_elegivel_emprestimo)} />}
                    {visibleSet.has("uy3_status") && <DataRow label="Status" value={display(lead.uy3_status)} />}
                    {visibleSet.has("uy3_valor_liberado") && <DataRow label="Liberado" value={display(lead.uy3_valor_liberado)} />}
                    {visibleSet.has("uy3_consultado_em") && <DataRow label="Consulta" value={display(lead.uy3_consultado_em)} />}
                  </CardSection>
                )}
              </div>
            </div>
          ))
        )}
      </div>

      <Pagination currentPage={currentPage} totalPages={totalPages} onPageChange={onPageChange} />

      <LeadDetailsModal
        isOpen={isModalOpen}
        onClose={() => {
          setIsModalOpen(false)
          setSelectedLeadId(null)
        }}
        leadId={selectedLeadId}
      />
    </div>
  )
}

const CardSection = ({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) => (
  <div className="space-y-2">
    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</div>
    <div className="space-y-1">{children}</div>
  </div>
)

const DataRow = ({
  label,
  value,
}: {
  label: string
  value: string
}) => (
  <div className="flex items-start justify-between gap-3 text-sm">
    <span className="text-gray-500">{label}</span>
    <span className="text-right text-gray-900">{value}</span>
  </div>
)
