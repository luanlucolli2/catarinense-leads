import { useMemo, useRef, useState, type Dispatch, type ReactNode, type SetStateAction } from "react"
import { toast } from "sonner"
import { Check, ChevronRight, ClipboardList, Filter, Info, LoaderCircle, Send, Users } from "lucide-react"
import factaLogo from "@/assets/factalogo.png"
import mercantilLogo from "@/assets/mercantilogo.png"
import uy3Logo from "@/assets/logouy3png.png"
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@/components/ui/accordion"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { CAMPAIGN_PRODUCTS, type CampaignProduct, type OfficialInbox, type OfficialTemplate } from "@/features/disparosWhatsappVendeai/configurationFixtures"
import { listMailingInboxes } from "@/features/disparosWhatsappVendeai/mailingInboxes"
import { parseBrazilianMobilePhoneList } from "@/features/disparosWhatsappVendeai/phoneList"
import { CompactDispatchConfigurationStep } from "@/features/disparosWhatsappVendeai/CompactDispatchConfigurationStep"
import { DispatchConfirmationStep } from "@/features/disparosWhatsappVendeai/DispatchConfirmationStep"
import { previewRegisteredLeads } from "@/features/disparosWhatsappVendeai/registeredLeadsPreview"
import { cn } from "@/lib/utils"

type LeadSource = "pasted_numbers" | "registered_leads"
type BankKey = "facta" | "mercantil" | "uy3"
type CombinationMode = "any" | "all"
type BankFilters = Record<string, string>
type ParameterSource = "fixed" | "lead_field"
type RegisteredPreviewStatus = "idle" | "loading" | "ready" | "stale" | "error"

type RegisteredLeadFilters = { selectedBanks: BankKey[]; combinationMode: CombinationMode; birthMonth: string[]; facta: BankFilters; mercantil: BankFilters; uy3: BankFilters }
type RegisteredPreview = { status: RegisteredPreviewStatus; recipientCount: number | null; errorMessage?: string }
type SenderConfiguration = { inboxId: string; templateIds: string[]; sendLimitEnabled: boolean; maxSends: string }
type DispatchConfiguration = {
  product: CampaignProduct | ""
  campaign: string
  senders: SenderConfiguration[]
  intervalSeconds: string
  startMode: "now" | "scheduled"
  scheduledAt: string
  resendProtectionEnabled: boolean
  resendProtectionDays: string
  sendWindowEnabled: boolean
  sendWindowStart: string
  sendWindowEnd: string
  templateParameters: Record<string, Record<string, { source: ParameterSource; value: string }>>
  templateHeaders: Record<string, string>
}
type FilterField = { key: string; label: string; type: "date" | "number" | "situation"; placeholder?: string }

const PRIMARY_BUTTON_CLASS_NAME = "bg-blue-600 text-white shadow-sm hover:bg-blue-700"
const CHECKBOX_CLASS_NAME = "border-blue-300 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600"
const PANEL_CLASS_NAME = "rounded-lg border border-gray-200 bg-white shadow-sm"
const WIZARD_STEPS = ["Selecionar leads", "Configurar", "Revisar"]
const BANK_OPTIONS: Array<{ value: BankKey; label: string; imageSrc: string; alt: string }> = [
  { value: "facta", label: "Facta CLT", imageSrc: factaLogo, alt: "Facta" },
  { value: "mercantil", label: "CLT Mercantil", imageSrc: mercantilLogo, alt: "Mercantil" },
  { value: "uy3", label: "CLT UY3", imageSrc: uy3Logo, alt: "UY3" },
]
const BANK_FIELDS: Record<BankKey, FilterField[]> = {
  facta: [
    { key: "situacao", label: "Situação", type: "situation" }, { key: "consulta_from", label: "Consulta de", type: "date" }, { key: "consulta_to", label: "Consulta até", type: "date" },
    { key: "meses_admissao_min", label: "Meses admissão mín.", type: "number", placeholder: "Ex.: 1" }, { key: "meses_admissao_max", label: "Meses admissão máx.", type: "number", placeholder: "Ex.: 24" },
    { key: "margem_min", label: "Margem mínima", type: "number", placeholder: "Ex.: 100,00" }, { key: "margem_max", label: "Margem máxima", type: "number", placeholder: "Ex.: 500,00" },
    { key: "parcelas_min", label: "Parcelas mín.", type: "number", placeholder: "Ex.: 12" }, { key: "parcelas_max", label: "Parcelas máx.", type: "number", placeholder: "Ex.: 48" },
  ],
  mercantil: [
    { key: "situacao", label: "Situação", type: "situation" }, { key: "consulta_from", label: "Consulta de", type: "date" }, { key: "consulta_to", label: "Consulta até", type: "date" },
    { key: "valor_parcela_min", label: "Valor da parcela mín.", type: "number", placeholder: "Ex.: 100,00" }, { key: "valor_parcela_max", label: "Valor da parcela máx.", type: "number", placeholder: "Ex.: 500,00" },
    { key: "parcelas_min", label: "Parcelas mín.", type: "number", placeholder: "Ex.: 12" }, { key: "parcelas_max", label: "Parcelas máx.", type: "number", placeholder: "Ex.: 48" },
  ],
  uy3: [
    { key: "situacao", label: "Situação", type: "situation" }, { key: "consulta_from", label: "Consulta de", type: "date" }, { key: "consulta_to", label: "Consulta até", type: "date" },
    { key: "meses_admissao_min", label: "Meses admissão mín.", type: "number", placeholder: "Ex.: 1" }, { key: "meses_admissao_max", label: "Meses admissão máx.", type: "number", placeholder: "Ex.: 24" },
    { key: "margem_min", label: "Margem mínima", type: "number", placeholder: "Ex.: 100,00" }, { key: "margem_max", label: "Margem máxima", type: "number", placeholder: "Ex.: 500,00" },
    { key: "valor_liberado_min", label: "Valor liberado mín.", type: "number", placeholder: "Ex.: 1.000,00" }, { key: "valor_liberado_max", label: "Valor liberado máx.", type: "number", placeholder: "Ex.: 10.000,00" },
    { key: "parcelas_min", label: "Parcelas mín.", type: "number", placeholder: "Ex.: 12" }, { key: "parcelas_max", label: "Parcelas máx.", type: "number", placeholder: "Ex.: 48" },
  ],
}
const BIRTH_MONTH_OPTIONS = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"].map((label, index) => ({ value: String(index + 1), label }))
const createDefaultFilters = (): RegisteredLeadFilters => ({ selectedBanks: [], combinationMode: "any", birthMonth: [], facta: {}, mercantil: {}, uy3: {} })
const createDefaultConfiguration = (): DispatchConfiguration => ({ product: "", campaign: "", senders: [], intervalSeconds: "30", startMode: "now", scheduledAt: "", resendProtectionEnabled: false, resendProtectionDays: "", sendWindowEnabled: false, sendWindowStart: "08:00", sendWindowEnd: "18:00", templateParameters: {}, templateHeaders: {} })
const bankLabel = (bank: BankKey) => BANK_OPTIONS.find((option) => option.value === bank)?.label ?? bank
const bankHasOwnFilter = (filters: RegisteredLeadFilters, bank: BankKey) => Object.values(filters[bank]).some((value) => value.trim() !== "")
const isPositiveInteger = (value: string) => Number.isInteger(Number(value)) && Number(value) > 0
const isFutureDateTime = (value: string) => value !== "" && new Date(value).getTime() > Date.now()
const isPublicHttpUrl = (value: string) => /^https?:\/\/[^\s]+$/i.test(value)

function selectedTemplateDefinitions(configuration: DispatchConfiguration, inboxes: OfficialInbox[]): OfficialTemplate[] {
  const templates = new Map<string, OfficialTemplate>()
  configuration.senders.forEach((sender) => inboxes.find((inbox) => inbox.id === sender.inboxId)?.templates.filter((template) => sender.templateIds.includes(template.id)).forEach((template) => templates.set(template.id, template)))
  return Array.from(templates.values())
}

function configurationErrors(configuration: DispatchConfiguration, recipientCount: number, inboxes: OfficialInbox[]): string[] {
  const selectedTemplates = selectedTemplateDefinitions(configuration, inboxes)
  const allSendersHaveLimits = configuration.senders.length > 0 && configuration.senders.every((sender) => sender.sendLimitEnabled && isPositiveInteger(sender.maxSends))
  const totalCapacity = configuration.senders.reduce((total, sender) => total + (Number(sender.maxSends) || 0), 0)
  return [
    configuration.product === "" && "Selecione o produto da campanha.",
    configuration.senders.length === 0 && "Selecione ao menos um número remetente.",
    configuration.senders.some((sender) => sender.templateIds.length === 0) && "Escolha ao menos um template para cada número remetente.",
    configuration.senders.some((sender) => sender.sendLimitEnabled && !isPositiveInteger(sender.maxSends)) && "Informe um limite positivo para cada número com limite ativado.",
    allSendersHaveLimits && totalCapacity < recipientCount && `A capacidade total (${totalCapacity}) é menor que a base selecionada (${recipientCount}).`,
    (!Number.isInteger(Number(configuration.intervalSeconds)) || Number(configuration.intervalSeconds) <= 0) && "Informe um intervalo positivo entre mensagens.",
    configuration.startMode === "scheduled" && !isFutureDateTime(configuration.scheduledAt) && "Informe uma data e hora futura para o agendamento.",
    configuration.sendWindowEnabled && (!/^\d{2}:\d{2}$/.test(configuration.sendWindowStart) || !/^\d{2}:\d{2}$/.test(configuration.sendWindowEnd) || configuration.sendWindowStart >= configuration.sendWindowEnd) && "Informe uma janela de envio válida.",
    configuration.resendProtectionEnabled && !isPositiveInteger(configuration.resendProtectionDays) && "Informe um período positivo sem reenvio.",
    selectedTemplates.some((template) => template.parameters.some((parameter) => !configuration.templateParameters[template.id]?.[parameter.key]?.value.trim())) && "Preencha todas as variáveis dos templates selecionados.",
    selectedTemplates.some((template) => ["TEXT", "IMAGE", "VIDEO", "DOCUMENT"].includes(template.headerType ?? "") && !configuration.templateHeaders[template.id]?.trim()) && "Preencha o cabeçalho dos templates que exigem texto ou mídia.",
    selectedTemplates.some((template) => template.headerType && template.headerType !== "TEXT" && !isPublicHttpUrl(configuration.templateHeaders[template.id] ?? "")) && "Informe uma URL pública HTTP(S) válida para cada cabeçalho de mídia.",
  ].filter(Boolean) as string[]
}

function reconcileConfiguration(configuration: DispatchConfiguration, inboxes: OfficialInbox[]): DispatchConfiguration {
  const availableInboxIds = new Set(inboxes.map((inbox) => inbox.id))
  const availableTemplateIds = new Set(inboxes.flatMap((inbox) => inbox.templates.map((template) => template.id)))
  const senders = configuration.senders
    .filter((sender) => availableInboxIds.has(sender.inboxId))
    .map((sender) => {
      const inbox = inboxes.find((item) => item.id === sender.inboxId)
      const templateIds = sender.templateIds.filter((templateId) => inbox?.templates.some((template) => template.id === templateId && template.status === "APPROVED"))
      return { ...sender, templateIds }
    })

  return {
    ...configuration,
    senders,
    templateParameters: Object.fromEntries(Object.entries(configuration.templateParameters).filter(([templateId]) => availableTemplateIds.has(templateId))),
    templateHeaders: Object.fromEntries(Object.entries(configuration.templateHeaders).filter(([templateId]) => availableTemplateIds.has(templateId))),
  }
}

export default function DisparosWhatsappVendeaiPage() {
  const [isWizardOpen, setIsWizardOpen] = useState(false)
  const [currentStep, setCurrentStep] = useState<1 | 2 | 3>(1)
  const [showStepTwoValidation, setShowStepTwoValidation] = useState(false)
  const [source, setSource] = useState<LeadSource>("pasted_numbers")
  const [pastedNumbers, setPastedNumbers] = useState("")
  const [filters, setFilters] = useState<RegisteredLeadFilters>(createDefaultFilters)
  const [registeredPreview, setRegisteredPreview] = useState<RegisteredPreview>({ status: "idle", recipientCount: null })
  const [configuration, setConfiguration] = useState<DispatchConfiguration>(createDefaultConfiguration)
  const [mailingInboxes, setMailingInboxes] = useState<OfficialInbox[]>([])
  const [mailingStatus, setMailingStatus] = useState<"idle" | "loading" | "ready" | "error">("idle")
  const [mailingError, setMailingError] = useState<string | null>(null)
  const previewRevision = useRef(0)
  const phoneListSummary = useMemo(() => parseBrazilianMobilePhoneList(pastedNumbers), [pastedNumbers])
  const bankErrors = filters.selectedBanks.filter((bank) => !bankHasOwnFilter(filters, bank))
  const recipientCount = source === "pasted_numbers" ? phoneListSummary.validNumbers.length : registeredPreview.recipientCount ?? 0
  const canContinueFromStepOne = source === "pasted_numbers" ? phoneListSummary.validNumbers.length > 0 && phoneListSummary.invalidTokens.length === 0 : filters.selectedBanks.length > 0 && bankErrors.length === 0 && registeredPreview.status === "ready" && recipientCount > 0
  const stepTwoErrors = useMemo(() => configurationErrors(configuration, recipientCount, mailingInboxes), [configuration, recipientCount, mailingInboxes])

  const resetWizard = () => {
    previewRevision.current += 1
    setCurrentStep(1); setShowStepTwoValidation(false); setSource("pasted_numbers"); setPastedNumbers(""); setFilters(createDefaultFilters()); setRegisteredPreview({ status: "idle", recipientCount: null }); setConfiguration(createDefaultConfiguration()); setMailingInboxes([]); setMailingStatus("idle"); setMailingError(null)
  }
  const handleOpenChange = (open: boolean) => { setIsWizardOpen(open); if (!open) resetWizard() }
  const updateFilters = (updater: (current: RegisteredLeadFilters) => RegisteredLeadFilters) => {
    previewRevision.current += 1
    setFilters((current) => updater(current))
    setRegisteredPreview((current) => ({ status: current.status === "ready" || current.status === "stale" ? "stale" : "idle", recipientCount: null }))
  }
  const requestRegisteredPreview = async () => {
    if (filters.selectedBanks.length === 0 || bankErrors.length > 0 || registeredPreview.status === "loading") return
    const revision = previewRevision.current
    setRegisteredPreview({ status: "loading", recipientCount: null })
    try {
      const preview = await previewRegisteredLeads(filters)
      if (revision === previewRevision.current) setRegisteredPreview({ status: "ready", recipientCount: preview.recipient_count })
    } catch (error) {
      if (revision !== previewRevision.current) return
      const message = typeof error === "object" && error !== null && "response" in error
        ? (error as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
      const errorMessage = message ?? "Não foi possível consultar os resultados. Tente novamente."
      setRegisteredPreview({ status: "error", recipientCount: null, errorMessage })
      toast.error(errorMessage)
    }
  }
  const requestMailingInboxes = async (refresh = false) => {
    if (mailingStatus === "loading") return
    setMailingStatus("loading")
    setMailingError(null)
    try {
      const inboxes = await listMailingInboxes(refresh)
      setMailingInboxes(inboxes)
      setConfiguration((current) => reconcileConfiguration(current, inboxes))
      setMailingStatus("ready")
    } catch (error) {
      const message = typeof error === "object" && error !== null && "response" in error
        ? (error as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
      const errorMessage = message ?? "Não foi possível carregar inboxes e templates. Tente novamente."
      setMailingError(errorMessage)
      setMailingStatus("error")
      toast.error(errorMessage)
    }
  }

  return (
    <div className="p-4 lg:p-6">
      <div className="max-w-3xl">
        <div className="flex items-start gap-3">
          <div className="rounded-lg bg-blue-100 p-2.5 text-blue-700"><Send className="h-5 w-5" /></div>
          <div>
            <h1 className="text-xl font-bold text-gray-900 lg:text-2xl">Disparos WhatsApp VendeAI</h1>
            <p className="mt-1 text-sm text-gray-600 lg:text-base">Selecione a base, configure a campanha e revise antes do envio.</p>
          </div>
        </div>
        <Button className={cn("mt-6", PRIMARY_BUTTON_CLASS_NAME)} onClick={() => setIsWizardOpen(true)}>Novo disparo</Button>
      </div>
      <Dialog open={isWizardOpen} onOpenChange={handleOpenChange}>
        <DialogContent className="flex max-h-[92vh] max-w-6xl flex-col gap-0 overflow-hidden border-gray-200 p-0 shadow-xl">
          <DialogHeader className="shrink-0 border-b border-gray-200 px-5 py-5 pr-12 sm:px-6 sm:pr-14">
            <DialogTitle className="text-xl font-semibold text-gray-900">Novo disparo WhatsApp</DialogTitle>
            <DialogDescription className="sr-only">Configure uma nova campanha de disparo em três etapas.</DialogDescription>
          </DialogHeader>
          <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50/50 px-4 py-5 sm:px-6 sm:py-6">
            <div className="mx-auto max-w-none space-y-6">
              <WizardProgress currentStep={currentStep} />
              {currentStep === 1 ? (
                <LeadSelectionStep source={source} setSource={setSource} pastedNumbers={pastedNumbers} setPastedNumbers={setPastedNumbers} phoneListSummary={phoneListSummary} filters={filters} updateFilters={updateFilters} bankErrors={bankErrors} registeredPreview={registeredPreview} onRequestPreview={requestRegisteredPreview} />
              ) : currentStep === 2 ? (
                <>
                  <CampaignDataSection configuration={configuration} setConfiguration={setConfiguration} showProductError={showStepTwoValidation && configuration.product === ""} />
                  {mailingStatus === "loading" ? <LoadingInboxesState /> : mailingStatus === "error" ? <InboxesErrorState message={mailingError} onRetry={() => void requestMailingInboxes(true)} /> : <CompactDispatchConfigurationStep source={source} recipientCount={recipientCount} configuration={configuration} setConfiguration={setConfiguration} inboxes={mailingInboxes} onRefresh={() => void requestMailingInboxes(true)} isRefreshing={false} validationErrors={showStepTwoValidation ? stepTwoErrors : []} />}
                </>
              ) : (
                <DispatchConfirmationStep source={source} recipientCount={recipientCount} configuration={configuration} inboxes={mailingInboxes} />
              )}
            </div>
          </div>
          <DialogFooter className="shrink-0 border-t border-gray-200 bg-white px-4 py-4 sm:px-6">
            {currentStep === 1 ? (
              <>
                <Button variant="outline" onClick={() => handleOpenChange(false)}>Cancelar</Button>
                <Button className={PRIMARY_BUTTON_CLASS_NAME} disabled={!canContinueFromStepOne} onClick={() => { setCurrentStep(2); void requestMailingInboxes() }}>Continuar <ChevronRight className="ml-1 h-4 w-4" /></Button>
              </>
            ) : currentStep === 2 ? (
              <>
                <Button variant="outline" onClick={() => setCurrentStep(1)}>Voltar</Button>
                <Button className={PRIMARY_BUTTON_CLASS_NAME} disabled={mailingStatus !== "ready"} onClick={() => { if (stepTwoErrors.length > 0) { setShowStepTwoValidation(true); return }; setCurrentStep(3) }}>Continuar para revisão <ChevronRight className="ml-1 h-4 w-4" /></Button>
              </>
            ) : (
              <>
                <Button variant="outline" onClick={() => handleOpenChange(false)}>Fechar</Button>
                <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={() => setCurrentStep(2)}>Voltar e editar</Button>
              </>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function WizardProgress({ currentStep }: { currentStep: 1 | 2 | 3 }) {
  return <ol className="flex items-center" aria-label="Etapas do novo disparo">{WIZARD_STEPS.map((label, index) => { const number = index + 1; const isCurrent = number === currentStep; const isCompleted = number < currentStep; return <li key={label} className="flex min-w-0 flex-1 items-center last:flex-none"><div className="flex min-w-0 items-center gap-2"><span className={cn("flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold", isCurrent && "bg-blue-600 text-white", isCompleted && "bg-blue-100 text-blue-700", !isCurrent && !isCompleted && "bg-slate-100 text-slate-500")}>{number}</span><span className={cn("hidden truncate text-sm font-medium sm:block", isCurrent ? "text-blue-900" : isCompleted ? "text-blue-700" : "text-slate-500")}><span className="sr-only">Etapa {number}: </span>{label}</span></div>{number < WIZARD_STEPS.length ? <span aria-hidden="true" className={cn("mx-2 h-px min-w-3 flex-1 sm:mx-3", isCompleted ? "bg-blue-300" : "bg-slate-200")} /> : null}</li> })}</ol>
}

function LoadingInboxesState() {
  return <div className={cn(PANEL_CLASS_NAME, "flex items-center gap-3 p-5 text-sm text-gray-600")}><LoaderCircle className="h-5 w-5 animate-spin text-blue-600" />Carregando remetentes e templates...</div>
}

function InboxesErrorState({ message, onRetry }: { message: string | null; onRetry: () => void }) {
  return <div className={cn(PANEL_CLASS_NAME, "flex flex-wrap items-center justify-between gap-3 p-5")}><p className="text-sm text-red-800">{message ?? "Não foi possível carregar inboxes e templates."}</p><Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={onRetry}>Tentar novamente</Button></div>
}

function CampaignDataSection({ configuration, setConfiguration, showProductError }: { configuration: DispatchConfiguration; setConfiguration: Dispatch<SetStateAction<DispatchConfiguration>>; showProductError: boolean }) {
  return <section className="border-b border-gray-200 pb-5"><SectionLabel icon={<Info className="h-4 w-4" />} title="Identificação da campanha" description="Use para reconhecer este disparo internamente." /><div className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Produto *" id="dispatch-product"><Select value={configuration.product || undefined} onValueChange={(value) => setConfiguration((current) => ({ ...current, product: value as CampaignProduct }))}><SelectTrigger id="dispatch-product" aria-invalid={showProductError} className={cn("bg-white", showProductError ? "border-amber-500" : "border-gray-300")}><SelectValue placeholder="Selecione o produto" /></SelectTrigger><SelectContent>{CAMPAIGN_PRODUCTS.map((product) => <SelectItem key={product.value} value={product.value}>{product.label}</SelectItem>)}</SelectContent></Select>{showProductError ? <p role="alert" className="text-xs text-amber-800">Selecione o produto da campanha.</p> : null}</Field><Field label="Nome interno" id="dispatch-campaign"><Input id="dispatch-campaign" value={configuration.campaign} onChange={(event) => setConfiguration((current) => ({ ...current, campaign: event.target.value }))} placeholder="Ex.: campanha_agosto" className="border-gray-300 bg-white" /></Field></div></section>
}

type LeadSelectionStepProps = { source: LeadSource; setSource: Dispatch<SetStateAction<LeadSource>>; pastedNumbers: string; setPastedNumbers: Dispatch<SetStateAction<string>>; phoneListSummary: ReturnType<typeof parseBrazilianMobilePhoneList>; filters: RegisteredLeadFilters; updateFilters: (updater: (current: RegisteredLeadFilters) => RegisteredLeadFilters) => void; bankErrors: BankKey[]; registeredPreview: RegisteredPreview; onRequestPreview: () => void }
function LeadSelectionStep({ source, setSource, pastedNumbers, setPastedNumbers, phoneListSummary, filters, updateFilters, bankErrors, registeredPreview, onRequestPreview }: LeadSelectionStepProps) {
  return (
    <section className="space-y-5">
      <SectionHeading number="1" title="Selecionar leads" description="Escolha a origem e confirme a base antes de avançar." />

      <div className="grid gap-2 md:grid-cols-2">
        <SourceOption active={source === "pasted_numbers"} icon={<ClipboardList className="h-5 w-5" />} title="Lista de números" description="Cole celulares de uma base externa." onClick={() => setSource("pasted_numbers")} />
        <SourceOption active={source === "registered_leads"} icon={<Users className="h-5 w-5" />} title="Leads cadastrados" description="Filtre a base disponível no sistema." onClick={() => setSource("registered_leads")} />
      </div>

      {source === "pasted_numbers" ? (
        <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")} aria-labelledby="numbers-title">
          <SectionLabel icon={<ClipboardList className="h-4 w-4" />} title="Lista de números" description="Separe os celulares por linha, vírgula ou ponto e vírgula." />
          <Textarea id="pasted-numbers" value={pastedNumbers} onChange={(event) => setPastedNumbers(event.target.value)} placeholder={"+55 (11) 99999-0001\n11999990002, 21999990003"} className="mt-4 min-h-32 border-gray-300 bg-white font-mono text-sm focus-visible:ring-blue-500" />
          <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-600">
            <span><strong className="text-blue-700">{phoneListSummary.validNumbers.length.toLocaleString("pt-BR")}</strong> válidos</span>
            <span><strong className="text-amber-700">{phoneListSummary.duplicateCount.toLocaleString("pt-BR")}</strong> duplicados removidos</span>
            <span><strong className="text-red-700">{phoneListSummary.invalidTokens.length.toLocaleString("pt-BR")}</strong> inválidos</span>
          </div>
          {phoneListSummary.invalidTokens.length > 0 ? <InlineNotice tone="red" className="mt-3">Corrija ou remova os números inválidos para continuar.</InlineNotice> : null}
        </section>
      ) : (
        <RegisteredLeadsFilters filters={filters} updateFilters={updateFilters} bankErrors={bankErrors} preview={registeredPreview} onRequestPreview={onRequestPreview} />
      )}
    </section>
  )
}

function SectionHeading({ number, title, description }: { number: string; title: string; description: string }) { return <div><div className="flex items-center gap-2"><span className="flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">{number}</span><h2 className="text-base font-semibold text-gray-900">{title}</h2></div><p className="mt-1 text-sm text-gray-600">{description}</p></div> }
function SourceOption({ active, icon, title, description, onClick }: { active: boolean; icon: ReactNode; title: string; description: string; onClick: () => void }) { return <button type="button" onClick={onClick} className={cn("group flex min-w-0 items-center gap-3 rounded-lg border bg-white p-3 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500", active ? "border-blue-500 bg-blue-50 ring-1 ring-blue-500" : "border-gray-200 hover:border-blue-300 hover:bg-blue-50/30")}><span className={cn("flex h-8 w-8 shrink-0 items-center justify-center rounded-md", active ? "bg-blue-600 text-white" : "bg-blue-50 text-blue-700")}>{icon}</span><span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-gray-900">{title}</span><span className="mt-0.5 block truncate text-xs text-gray-600">{description}</span></span><span className={cn("flex h-4 w-4 shrink-0 items-center justify-center rounded-full border", active ? "border-blue-600 bg-blue-600 text-white" : "border-gray-300 bg-white")}>{active ? <Check className="h-3 w-3" /> : null}</span></button> }
function RegisteredLeadsFilters({ filters, updateFilters, bankErrors, preview, onRequestPreview }: { filters: RegisteredLeadFilters; updateFilters: (updater: (current: RegisteredLeadFilters) => RegisteredLeadFilters) => void; bankErrors: BankKey[]; preview: RegisteredPreview; onRequestPreview: () => void }) {
  const canRequestPreview = filters.selectedBanks.length > 0 && bankErrors.length === 0 && preview.status !== "loading"
  const [openBanks, setOpenBanks] = useState<string[]>([])
  const toggleBank = (bank: BankKey, checked: boolean) => {
    updateFilters((current) => ({ ...current, selectedBanks: checked ? [...new Set([...current.selectedBanks, bank])] : current.selectedBanks.filter((value) => value !== bank) }))
    setOpenBanks((current) => checked ? [...new Set([...current, bank])] : current.filter((value) => value !== bank))
  }
  const updateBankFilter = (bank: BankKey, field: string, value: string) => updateFilters((current) => ({ ...current, [bank]: { ...current[bank], [field]: value } }))

  return (
    <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
      <SectionLabel icon={<Filter className="h-4 w-4" />} title="Filtrar leads cadastrados" description="Selecione a base e consulte os resultados para continuar." />

      <div className="mt-4 divide-y divide-gray-100 border-t border-gray-100">
        <div className="py-4 first:pt-4">
          <div className="flex items-start justify-between gap-3">
            <div>
              <div className="text-sm font-semibold text-gray-900">Filtros gerais</div>
              <p className="mt-1 text-xs text-gray-500">Aplicados a toda a base selecionada.</p>
            </div>
            <Badge variant="outline" className="w-fit shrink-0 border-blue-200 bg-blue-50 text-blue-800">Somente com telefone</Badge>
          </div>
          <div className="mt-3">
            <Field label="Mês de aniversário" id="registered-birth-month">
              <Select value={filters.birthMonth[0] || "all"} onValueChange={(value) => updateFilters((current) => ({ ...current, birthMonth: value === "all" ? [] : [value] }))}>
                <SelectTrigger id="registered-birth-month" className="w-full border-gray-300 bg-white sm:w-80"><SelectValue placeholder="Todos os meses" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos os meses</SelectItem>
                  {BIRTH_MONTH_OPTIONS.map((month) => <SelectItem key={month.value} value={month.value}>{month.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
          </div>
        </div>

        <div className="py-4">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div className="text-sm font-semibold text-gray-900">Bancos da consulta *</div>
            <p className="text-xs text-gray-500">Informe ao menos um critério por banco.</p>
          </div>
          <div className="mt-3 grid gap-2 md:grid-cols-3">
            {BANK_OPTIONS.map((bank) => {
              const checked = filters.selectedBanks.includes(bank.value)
              return (
                <label key={bank.value} className={cn("flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2.5 text-sm transition-colors", checked ? "border-blue-500 bg-blue-50 text-blue-900" : "border-gray-200 bg-white text-gray-700 hover:border-blue-300")}>
                  <Checkbox checked={checked} onCheckedChange={(value) => toggleBank(bank.value, Boolean(value))} className={CHECKBOX_CLASS_NAME} />
                  <img src={bank.imageSrc} alt={bank.alt} className="h-5 w-5 object-contain" />
                  <span className="font-medium">{bank.label}</span>
                </label>
              )
            })}
          </div>

          {filters.selectedBanks.length > 1 ? (
            <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
              <Label htmlFor="bank-combination" className="shrink-0 text-sm font-medium text-gray-700">Combinar bancos</Label>
              <Select value={filters.combinationMode} onValueChange={(value) => updateFilters((current) => ({ ...current, combinationMode: value as CombinationMode }))}>
                <SelectTrigger id="bank-combination" className="w-full border-gray-300 bg-white sm:w-72"><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem value="any">Qualquer banco selecionado</SelectItem><SelectItem value="all">Todos os bancos selecionados</SelectItem></SelectContent>
              </Select>
            </div>
          ) : null}
          {filters.selectedBanks.length === 0 ? <InlineNotice tone="amber" className="mt-3">Selecione ao menos um banco.</InlineNotice> : null}
          {bankErrors.length > 0 ? <InlineNotice tone="amber" className="mt-3">Falta um critério em: {bankErrors.map(bankLabel).join(", ")}.</InlineNotice> : null}
        </div>

        {filters.selectedBanks.length > 0 ? (
          <div className="py-4">
            <div className="flex items-center justify-between gap-3">
              <div className="text-sm font-semibold text-gray-900">Critérios por banco</div>
              <span className="text-xs text-gray-500">Abra somente o que precisar ajustar.</span>
            </div>
            <Accordion type="multiple" value={openBanks} onValueChange={setOpenBanks} className="mt-3 divide-y rounded-md border border-gray-200">
              {filters.selectedBanks.map((bank) => {
                const active = bankHasOwnFilter(filters, bank)
                return (
                  <AccordionItem key={bank} value={bank} className="border-0 px-3">
                    <AccordionTrigger className="py-3 text-sm font-medium text-gray-800 hover:no-underline">
                      <span className="flex items-center gap-2">
                        <img src={BANK_OPTIONS.find((option) => option.value === bank)?.imageSrc} alt="" className="h-5 w-5 object-contain" />
                        {bankLabel(bank)}
                        <span className={cn("rounded-full px-2 py-0.5 text-[11px] font-medium", active ? "bg-blue-50 text-blue-700" : "bg-amber-50 text-amber-800")}>{active ? "ativo" : "pendente"}</span>
                      </span>
                    </AccordionTrigger>
                    <AccordionContent className="pb-4">
                      <div className="grid gap-3 border-t border-gray-100 pt-4 sm:grid-cols-2">
                        {BANK_FIELDS[bank].map((field) => (
                          <Field key={field.key} label={field.label} id={bank + "-" + field.key} className={field.type === "situation" ? "sm:col-span-2" : undefined}>
                            {field.type === "situation" ? (
                              <Select value={filters[bank][field.key] || "all"} onValueChange={(value) => updateBankFilter(bank, field.key, value === "all" ? "" : value)}>
                                <SelectTrigger id={bank + "-" + field.key} className="border-gray-300 bg-white"><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="all">Todas</SelectItem><SelectItem value="aprovado">Aprovado</SelectItem><SelectItem value="nao_aprovado">Não aprovado</SelectItem></SelectContent>
                              </Select>
                            ) : (
                              <Input id={bank + "-" + field.key} type={field.type} inputMode={field.type === "number" ? "decimal" : undefined} placeholder={field.placeholder} value={filters[bank][field.key] ?? ""} onChange={(event) => updateBankFilter(bank, field.key, event.target.value)} className="border-gray-300 bg-white" />
                            )}
                          </Field>
                        ))}
                      </div>
                    </AccordionContent>
                  </AccordionItem>
                )
              })}
            </Accordion>
          </div>
        ) : null}

        <div className="border-t border-gray-100 pt-4">
          <div className="rounded-lg border border-blue-200 bg-blue-50/60 p-3 sm:p-4">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div className="flex items-start gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-600 text-white"><Users className="h-4 w-4" /></span>
                <div>
                  <div className="text-sm font-semibold text-gray-900">Resultado da seleção</div>
                  <p className="mt-1 text-xs text-gray-600">Confira quantos leads atendem aos critérios.</p>
                </div>
              </div>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center lg:justify-end">
                {preview.status === "ready" && preview.recipientCount !== null ? <div aria-live="polite" className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">{preview.recipientCount.toLocaleString("pt-BR")} leads encontrados</div> : null}
                <Button type="button" className={PRIMARY_BUTTON_CLASS_NAME} disabled={!canRequestPreview} onClick={onRequestPreview}>{preview.status === "loading" ? <><LoaderCircle className="mr-2 h-4 w-4 animate-spin" />Consultando</> : "Ver resultados"}</Button>
              </div>
            </div>
          </div>
        </div>
        {preview.status === "stale" ? <InlineNotice tone="amber" className="mt-3">Os filtros foram alterados. Veja os resultados novamente.</InlineNotice> : null}
        {preview.status === "error" && preview.errorMessage ? <InlineNotice tone="red" className="mt-3">{preview.errorMessage}</InlineNotice> : null}
      </div>
    </section>
  )
}

/*
function DispatchConfigurationStep({ source, recipientCount, configuration, setConfiguration }: { source: LeadSource; recipientCount: number; configuration: DispatchConfiguration; setConfiguration: Dispatch<SetStateAction<DispatchConfiguration>> }) {
  const selectedTemplates = selectedTemplateDefinitions(configuration)
  const errors = configurationErrors(configuration, recipientCount)
  const leadFields = source === "registered_leads" ? [{ value: "name", label: "Nome" }, { value: "cpf", label: "CPF" }, { value: "birth_date", label: "Data de nascimento" }, { value: "phone", label: "Telefone" }] : [{ value: "phone", label: "Telefone" }]
  const toggleSender = (inbox: OfficialInbox) => setConfiguration((current) => current.senders.some((sender) => sender.inboxId === inbox.id) ? { ...current, senders: current.senders.filter((sender) => sender.inboxId !== inbox.id) } : { ...current, senders: [...current.senders, { inboxId: inbox.id, templateIds: [], sendLimitEnabled: false, maxSends: "" }] })
  const updateSender = (inboxId: string, updater: (sender: SenderConfiguration) => SenderConfiguration) => setConfiguration((current) => ({ ...current, senders: current.senders.map((sender) => sender.inboxId === inboxId ? updater(sender) : sender) }))
  const toggleSenderTemplate = (inboxId: string, template: OfficialTemplate) => {
    setConfiguration((current) => {
      const sender = current.senders.find((item) => item.inboxId === inboxId)
      if (!sender) return current
      const selected = sender.templateIds.includes(template.id)
      return { ...current, senders: current.senders.map((item) => item.inboxId === inboxId ? { ...item, templateIds: selected ? item.templateIds.filter((id) => id !== template.id) : [...item.templateIds, template.id] } : item), templateParameters: selected ? current.templateParameters : { ...current.templateParameters, [template.id]: current.templateParameters[template.id] ?? createTemplateParameters(template) }, templateHeaders: selected ? current.templateHeaders : { ...current.templateHeaders, [template.id]: current.templateHeaders[template.id] ?? "" } }
    })
  }
  const updateParameter = (templateId: string, key: string, update: Partial<{ source: ParameterSource; value: string }>) => setConfiguration((current) => ({ ...current, templateParameters: { ...current.templateParameters, [templateId]: { ...current.templateParameters[templateId], [key]: { ...current.templateParameters[templateId]?.[key], ...update } } } }))
  return <section className="space-y-5"><SectionHeading number="2" title="Configurar disparo" description="Configure os remetentes primeiro; depois ajuste ritmo, horário e proteções." /><section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}><SectionLabel icon={<Send className="h-4 w-4" />} title="Números remetentes" description="Selecione os números oficiais que participarão da distribuição automática." /><div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">{OFFICIAL_INBOXES.map((inbox) => { const selected = configuration.senders.some((sender) => sender.inboxId === inbox.id); const unavailable = inbox.templates.length === 0; const quality = QUALITY_LABELS[inbox.qualityRating]; return <button key={inbox.id} type="button" disabled={unavailable} onClick={() => toggleSender(inbox)} className={cn("rounded-md border p-4 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500", selected && "border-blue-500 bg-blue-50 ring-1 ring-blue-500", !selected && !unavailable && "border-gray-200 bg-white hover:border-blue-300", unavailable && "cursor-not-allowed border-gray-200 bg-slate-50 text-slate-500 opacity-75")}><div className="flex items-start justify-between gap-2"><span className="text-sm font-semibold text-gray-900">{inbox.name}</span>{selected ? <Check className="h-4 w-4 text-blue-700" /> : null}</div><div className="mt-3 text-sm font-medium text-gray-700">{inbox.phoneNumber}</div><div className="mt-1 text-xs text-gray-500">{inbox.verifiedName}</div><div className="mt-3 flex items-center justify-between gap-2"><QualityBadge label={quality.label} className={quality.className} /><span className="text-xs text-gray-500">{unavailable ? "Sem templates" : `${inbox.templates.length} template(s)`}</span></div></button> })}</div>{configuration.senders.length === 0 ? <InlineNotice tone="amber" className="mt-4">Selecione ao menos um número remetente para configurar o disparo.</InlineNotice> : null}</section>{configuration.senders.length > 0 ? <section className="space-y-4"><SectionLabel icon={<MessageSquareText className="h-4 w-4" />} title="Configuração por remetente" description="Cada número usa apenas os templates disponíveis na sua própria inbox." /><p className="text-sm text-gray-600">Os templates escolhidos em cada número serão alternados automaticamente de forma equilibrada.</p>{configuration.senders.map((sender) => { const inbox = findInbox(sender.inboxId); return inbox ? <SenderConfigurationCard key={sender.inboxId} inbox={inbox} sender={sender} recipientCount={recipientCount} onToggleTemplate={(template) => toggleSenderTemplate(sender.inboxId, template)} onUpdateSender={(updater) => updateSender(sender.inboxId, updater)} /> : null })}<SenderCapacitySummary senders={configuration.senders} recipientCount={recipientCount} /></section> : null}{selectedTemplates.length > 0 ? <section className="space-y-4"><SectionLabel icon={<MessageSquareText className="h-4 w-4" />} title="Personalizar mensagens" description="Preencha apenas as variáveis dos templates escolhidos." />{selectedTemplates.map((template) => <TemplateVariables key={template.id} template={template} configuration={configuration} leadFields={leadFields} updateParameter={updateParameter} />)}</section> : null}<div className="grid gap-5 lg:grid-cols-2"><section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}><SectionLabel icon={<Clock3 className="h-4 w-4" />} title="Ritmo e horário" description="Controle a velocidade e o momento de início da campanha." /><div className="mt-4 grid gap-4"><Field label="Intervalo entre mensagens (segundos) *" id="dispatch-interval"><Input id="dispatch-interval" type="number" min="1" value={configuration.intervalSeconds} onChange={(event) => setConfiguration((current) => ({ ...current, intervalSeconds: event.target.value }))} className="border-gray-300" /></Field><Field label="Início" id="dispatch-start"><Select value={configuration.startMode} onValueChange={(value) => setConfiguration((current) => ({ ...current, startMode: value as DispatchConfiguration["startMode"] }))}><SelectTrigger id="dispatch-start" className="border-gray-300 bg-white"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="now">Iniciar imediatamente</SelectItem><SelectItem value="scheduled">Agendar data e hora</SelectItem></SelectContent></Select></Field>{configuration.startMode === "scheduled" ? <Field label="Data e hora local *" id="dispatch-scheduled-at"><Input id="dispatch-scheduled-at" type="datetime-local" value={configuration.scheduledAt} onChange={(event) => setConfiguration((current) => ({ ...current, scheduledAt: event.target.value }))} className="border-gray-300" /></Field> : null}</div></section><section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}><SectionLabel icon={<ShieldCheck className="h-4 w-4" />} title="Proteções de envio" description="Evite novos contatos em um período definido." /><div className="mt-4 rounded-md border border-gray-200 bg-white p-4"><div className="flex items-start justify-between gap-3"><div><Label htmlFor="resend-protection" className="text-sm font-medium text-gray-800">Evitar reenvio ao mesmo telefone</Label><p className="mt-1 text-sm text-gray-600">Bloqueia novo disparo por um período configurado.</p></div><Switch id="resend-protection" checked={configuration.resendProtectionEnabled} onCheckedChange={(checked) => setConfiguration((current) => ({ ...current, resendProtectionEnabled: checked }))} /></div>{configuration.resendProtectionEnabled ? <div className="mt-4"><Field label="Período sem reenvio (dias) *" id="resend-protection-days"><Input id="resend-protection-days" type="number" min="1" value={configuration.resendProtectionDays} onChange={(event) => setConfiguration((current) => ({ ...current, resendProtectionDays: event.target.value }))} className="border-gray-300" /></Field></div> : null}</div></section></div>{errors.length > 0 ? <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><div className="font-medium">Para continuar, complete a configuração:</div><ul className="mt-1 list-disc space-y-1 pl-5">{errors.map((error) => <li key={error}>{error}</li>)}</ul></div> : <div className="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"><Check className="h-4 w-4" />Configuração preenchida.</div>}</section>
}

function SenderConfigurationCard({ inbox, sender, recipientCount, onToggleTemplate, onUpdateSender }: { inbox: OfficialInbox; sender: SenderConfiguration; recipientCount: number; onToggleTemplate: (template: OfficialTemplate) => void; onUpdateSender: (updater: (sender: SenderConfiguration) => SenderConfiguration) => void }) {
  const quality = QUALITY_LABELS[inbox.qualityRating ?? "NA"]
  const senderCapacity = Number(sender.maxSends) || 0
  return <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><div className="flex flex-wrap items-center gap-2"><h3 className="text-sm font-semibold text-gray-900">{inbox.name}</h3><QualityBadge label={quality.label} className={quality.className} /></div><p className="mt-1 text-sm text-gray-600">{inbox.phoneNumber} · {inbox.verifiedName}</p></div><Badge variant="outline" className="w-fit border-slate-200 text-slate-600">{sender.templateIds.length} template(s) selecionado(s)</Badge></div>{inbox.qualityRating === "RED" ? <div className="mt-4 flex gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-3 text-sm text-red-900"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /><span>Este número está com qualidade baixa. Revise o volume e o conteúdo da campanha antes da confirmação.</span></div> : null}<div className="mt-5"><div className="text-sm font-medium text-gray-800">Templates disponíveis *</div><div className="mt-1 text-sm text-gray-600">Selecione um ou mais templates para este número.</div><div className="mt-3 grid gap-3 lg:grid-cols-2">{inbox.templates.map((template) => <TemplateOption key={template.id} template={template} selected={sender.templateIds.includes(template.id)} onClick={() => onToggleTemplate(template)} />)}</div></div><div className="mt-5 rounded-md border border-gray-200 bg-slate-50 p-4"><div className="flex items-start justify-between gap-3"><div><Label htmlFor={`sender-limit-${inbox.id}`} className="text-sm font-medium text-gray-800">Limitar envios deste número</Label><p className="mt-1 text-sm text-gray-600">Defina uma capacidade específica para este remetente.</p></div><Switch id={`sender-limit-${inbox.id}`} checked={sender.sendLimitEnabled} onCheckedChange={(checked) => onUpdateSender((current) => ({ ...current, sendLimitEnabled: checked }))} /></div>{sender.sendLimitEnabled ? <div className="mt-4"><Field label="Máximo de envios *" id={`sender-limit-value-${inbox.id}`}><Input id={`sender-limit-value-${inbox.id}`} type="number" min="1" value={sender.maxSends} onChange={(event) => onUpdateSender((current) => ({ ...current, maxSends: event.target.value }))} className="border-gray-300 bg-white" /></Field>{isPositiveInteger(sender.maxSends) ? <p className={cn("mt-2 text-xs", senderCapacity < recipientCount ? "text-amber-800" : "text-gray-600")}>Capacidade deste número: {senderCapacity.toLocaleString("pt-BR")} mensagens.</p> : null}</div> : <p className="mt-3 text-xs text-gray-600">Sem limite configurado para este número.</p>}</div></section>
}

function TemplateOption({ template, selected, onClick }: { template: OfficialTemplate; selected: boolean; onClick: () => void }) { const status = templateStatusLabel(template.status); return <button type="button" onClick={onClick} className={cn("rounded-md border p-4 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500", selected ? "border-blue-500 bg-blue-50 ring-1 ring-blue-500" : "border-gray-200 bg-white hover:border-blue-300")}><div className="flex items-start justify-between gap-2"><span className="text-sm font-semibold text-gray-900">{template.name}</span>{selected ? <Check className="h-4 w-4 text-blue-700" /> : <Badge variant="outline" className="border-slate-200 text-slate-600">{template.category}</Badge>}</div><p className="mt-3 text-sm leading-5 text-gray-600">{template.body}</p><div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500"><Badge variant="outline" className="border-slate-200 text-slate-600">{template.category}</Badge><span>{template.language}</span><span>·</span><QualityBadge label={`Status: ${status.label}`} className={status.className} /></div></button> }
function SenderCapacitySummary({ senders, recipientCount }: { senders: SenderConfiguration[]; recipientCount: number }) { const allLimited = senders.length > 0 && senders.every((sender) => sender.sendLimitEnabled && isPositiveInteger(sender.maxSends)); const hasUnlimitedSender = senders.some((sender) => !sender.sendLimitEnabled); const totalCapacity = senders.reduce((total, sender) => total + (Number(sender.maxSends) || 0), 0); const insufficient = allLimited && totalCapacity < recipientCount; return <div className={cn("rounded-md border px-4 py-3 text-sm", insufficient ? "border-red-200 bg-red-50 text-red-900" : "border-blue-200 bg-blue-50 text-blue-900")}><div className="font-medium">Distribuição automática</div><p className="mt-1">{hasUnlimitedSender ? "Há números sem limite configurado. Eles receberão destinatários após respeitados os limites dos demais remetentes." : !allLimited ? "Informe os limites configurados para calcular a capacidade total." : insufficient ? `Capacidade insuficiente: ${totalCapacity.toLocaleString("pt-BR")} mensagens para ${recipientCount.toLocaleString("pt-BR")} destinatários.` : `Capacidade configurada: ${totalCapacity.toLocaleString("pt-BR")} mensagens para ${recipientCount.toLocaleString("pt-BR")} destinatários.`}</p></div> }
function TemplateVariables({ template, configuration, leadFields, updateParameter }: { template: OfficialTemplate; configuration: DispatchConfiguration; leadFields: Array<{ value: string; label: string }>; updateParameter: (templateId: string, key: string, update: Partial<{ source: ParameterSource; value: string }>) => void }) { return <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}><div className="flex flex-wrap items-center gap-2"><h3 className="text-sm font-semibold text-gray-900">{template.name}</h3><Badge variant="outline" className="border-slate-200 text-slate-600">Variáveis do template</Badge></div><div className="mt-3 rounded-md border border-gray-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-gray-700">{template.body}</div><div className="mt-4 space-y-3">{template.parameters.map((parameter) => { const config = configuration.templateParameters[template.id]?.[parameter.key] ?? { source: "fixed" as const, value: "" }; return <div key={parameter.key} className="grid gap-3 rounded-md border border-gray-200 bg-white p-4 md:grid-cols-[minmax(180px,1fr)_180px_minmax(220px,1fr)] md:items-end"><div><div className="text-sm font-semibold text-gray-800">{parameter.label}</div><div className="mt-1 text-xs text-gray-500">Variável {`{{${parameter.key}}}`} da mensagem</div></div><Field label="Usar" id={`parameter-source-${template.id}-${parameter.key}`}><Select value={config.source} onValueChange={(value) => updateParameter(template.id, parameter.key, { source: value as ParameterSource, value: "" })}><SelectTrigger id={`parameter-source-${template.id}-${parameter.key}`} className="border-gray-300"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="fixed">Texto fixo</SelectItem><SelectItem value="lead_field">Dado do lead</SelectItem></SelectContent></Select></Field><Field label={config.source === "fixed" ? "Texto" : "Campo"} id={`parameter-value-${template.id}-${parameter.key}`}>{config.source === "fixed" ? <Input id={`parameter-value-${template.id}-${parameter.key}`} value={config.value} onChange={(event) => updateParameter(template.id, parameter.key, { value: event.target.value })} placeholder="Ex.: Crédito disponível" className="border-gray-300" /> : <Select value={config.value} onValueChange={(value) => updateParameter(template.id, parameter.key, { value })}><SelectTrigger id={`parameter-value-${template.id}-${parameter.key}`} className="border-gray-300"><SelectValue placeholder="Selecione o campo" /></SelectTrigger><SelectContent>{leadFields.map((field) => <SelectItem key={field.value} value={field.value}>{field.label}</SelectItem>)}</SelectContent></Select>}</Field></div> })}</div></section> }
function QualityBadge({ label, className }: { label: string; className: string }) { return <Badge variant="outline" className={cn("whitespace-nowrap", className)}>{label}</Badge> }
*/
function SectionLabel({ icon, title, description }: { icon: ReactNode; title: string; description: string }) { return <div className="flex gap-3"><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-700">{icon}</span><div><h3 className="text-sm font-semibold text-gray-900">{title}</h3><p className="mt-1 text-sm text-gray-600">{description}</p></div></div> }
function InlineNotice({ tone, className, children }: { tone: "amber" | "red"; className?: string; children: ReactNode }) { return <div role={tone === "red" ? "alert" : "status"} className={cn("rounded-md border px-3 py-2 text-sm", tone === "amber" ? "border-amber-200 bg-amber-50 text-amber-900" : "border-red-200 bg-red-50 text-red-900", className)}>{children}</div> }
function Field({ label, id, className, children }: { label: string; id: string; className?: string; children: ReactNode }) { return <div className={cn("space-y-2", className)}><Label htmlFor={id} className="text-sm font-medium text-gray-700">{label}</Label>{children}</div> }
