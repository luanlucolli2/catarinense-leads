import { formatCPF, formatPhone } from "@/lib/formatters"
import type {
  LemitPrototypeBankKey,
  LemitPrototypeFilters,
  LemitPrototypeLead,
  LemitPrototypeLot,
  LemitPrototypeLotItem,
  LemitPrototypeOptionCatalog,
  LemitPrototypePhoneCandidate,
  LemitPrototypePhoneClass,
  LemitPrototypeResultStatus,
} from "./types"

const NAMES = [
  "ALANA", "BRUNO", "CARLA", "DANIEL", "ELAINE", "FABIO", "GABRIELA", "HUGO",
  "ISABELA", "JOAO", "KARINA", "LUCAS", "MARIANA", "NATALIA", "OTAVIO", "PATRICIA",
  "RAFAEL", "SABRINA", "THIAGO", "VANESSA",
]

const SURNAMES = [
  "BORTOLOTO", "TRICHES", "BERTOL", "SOUZA", "OLIVEIRA", "MARTINS", "COSTA", "ALMEIDA",
  "FERREIRA", "RODRIGUES", "SANTOS", "MELO", "PEREIRA", "NUNES", "SCHMITT", "RIBEIRO",
]

const ORIGENS = ["Meta Ads", "Google Ads", "Inbound", "Indicação", "CRM", "Landing Page"]
const PHONE_CLASSES: LemitPrototypePhoneClass[] = ["Carteira", "Atendimento IA", "Manual"]
const FGTS_MOTIVOS = ["Saldo FGTS", "Saque Aniversário", "Antecipação", "Recompra"]
const FGTS_ORIGENS_HIG = ["Facta Base Offline", "Planilha Operacional", "Reprocessamento"]
const MERCANTIL_STATUS = ["SUCESSO", "PENDENTE", "ERRO_ANALISE", "SEM_OFERTA"]
const MERCANTIL_ORIGENS = ["Mercantil Top", "Mercantil Base A", "Mercantil Base B"]
const UY3_TYPES = ["LEADS_CLT", "LEADS_CLT_NÃO_QUALIFICADOS", "LEADS_CLT_REPROCESSO"]
const UY3_STATUS = ["ATIVA", "EM_ANALISE", "PENDENTE"]
const DDDS = ["11", "21", "31", "41", "47", "48", "49", "51"]

const BANK_LABELS: Record<LemitPrototypeBankKey, string> = {
  fgts: "FGTS",
  clt: "CLT Facta",
  mercantil: "CLT Mercantil",
  uy3: "CLT UY3",
}

const MONTHS = Array.from({ length: 12 }, (_, index) => String(index + 1))

class SeededRandom {
  private state: number

  constructor(seed: number) {
    this.state = seed >>> 0
  }

  next() {
    this.state = (1664525 * this.state + 1013904223) >>> 0
    return this.state / 0x100000000
  }

  int(min: number, max: number) {
    return Math.floor(this.next() * (max - min + 1)) + min
  }

  pick<T>(items: T[]): T {
    return items[this.int(0, items.length - 1)]
  }

  chance(probability: number) {
    return this.next() < probability
  }
}

function padCpf(value: number) {
  return String(value).padStart(11, "0")
}

function createPhone(random: SeededRandom, mobile: boolean) {
  const ddd = random.pick(DDDS)
  const base = mobile ? `9${random.int(1000, 9999)}${random.int(1000, 9999)}` : `${random.int(2000, 5999)}${random.int(1000, 9999)}`
  return `${ddd}${base}`
}

function createIsoDate(random: SeededRandom) {
  const year = random.int(1968, 2002)
  const month = random.int(1, 12)
  const day = random.int(1, 28)
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`
}

function createIsoDateTime(random: SeededRandom) {
  const month = random.int(1, 12)
  const day = random.int(1, 28)
  const hour = random.int(8, 19)
  const minute = random.int(0, 59)
  return `2026-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")} ${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")}:00`
}

function hasAnyPhone(lead: LemitPrototypeLead) {
  return Boolean(lead.fone1 || lead.fone2 || lead.fone3 || lead.fone4)
}

function getLeadPhoneDigits(lead: LemitPrototypeLead) {
  return [lead.fone1, lead.fone2, lead.fone3, lead.fone4]
    .filter(Boolean)
    .map((phone) => String(phone).replace(/\D/g, ""))
}

function getFirstPhone(lead: LemitPrototypeLead) {
  return lead.fone1 || lead.fone2 || lead.fone3 || lead.fone4 || null
}

function hashString(value: string) {
  let hash = 0
  for (let index = 0; index < value.length; index += 1) {
    hash = ((hash << 5) - hash + value.charCodeAt(index)) | 0
  }
  return Math.abs(hash)
}

function pickPreferredPhone(celulares: LemitPrototypePhoneCandidate[], fixos: LemitPrototypePhoneCandidate[]) {
  const whatsappCandidates = celulares
    .filter((phone) => phone.whatsapp)
    .sort((left, right) => left.ranking - right.ranking)

  if (whatsappCandidates[0]) {
    return { phone: whatsappCandidates[0], type: "celular" as const }
  }

  const sortedCelulares = [...celulares].sort((left, right) => left.ranking - right.ranking)
  if (sortedCelulares[0]) {
    return { phone: sortedCelulares[0], type: "celular" as const }
  }

  const sortedFixos = [...fixos].sort((left, right) => left.ranking - right.ranking)
  if (sortedFixos[0]) {
    return { phone: sortedFixos[0], type: "fixo" as const }
  }

  return { phone: null, type: null }
}

function cloneFilters(filters: LemitPrototypeFilters): LemitPrototypeFilters {
  return JSON.parse(JSON.stringify(filters)) as LemitPrototypeFilters
}

function bankFilterIsFilled(filters: LemitPrototypeFilters, bank: LemitPrototypeBankKey) {
  switch (bank) {
    case "fgts":
      return Boolean(
        filters.bank_filters.fgts.fgts_status ||
        filters.bank_filters.fgts.motivos.length ||
        filters.bank_filters.fgts.origens_hig.length
      )
    case "clt":
      return Boolean(
        filters.bank_filters.clt.clt_consultado ||
        filters.bank_filters.clt.clt_situacao ||
        filters.bank_filters.clt.clt_margem_min.trim() ||
        filters.bank_filters.clt.clt_margem_max.trim()
      )
    case "mercantil":
      return Boolean(
        filters.bank_filters.mercantil.mercantil_situacao ||
        filters.bank_filters.mercantil.mercantil_status.length ||
        filters.bank_filters.mercantil.mercantil_origens.length
      )
    case "uy3":
      return Boolean(
        filters.bank_filters.uy3.uy3_type_webhook.length ||
        filters.bank_filters.uy3.uy3_status.length ||
        filters.bank_filters.uy3.uy3_elegivel_emprestimo
      )
  }
}

function splitMassFilter(raw: string, stripDigits = false) {
  return raw
    .split(/[\n,;]+/)
    .map((value) => (stripDigits ? value.replace(/\D/g, "") : value.trim().toLowerCase()))
    .filter(Boolean)
}

function matchGeneralFilters(lead: LemitPrototypeLead, filters: LemitPrototypeFilters) {
  const { general } = filters
  const search = general.search.trim().toLowerCase()
  const leadPhoneDigits = getLeadPhoneDigits(lead)

  if (search) {
    const normalizedSearch = search.replace(/\D/g, "")
    const matchedSearch =
      lead.nome.toLowerCase().includes(search) ||
      lead.cpf.includes(normalizedSearch || search) ||
      leadPhoneDigits.some((phone) => phone.includes(normalizedSearch || search))

    if (!matchedSearch) {
      return false
    }
  }

  if (general.origens.length && !general.origens.includes(lead.origem_cadastral)) {
    return false
  }

  if (general.cpf.trim()) {
    const cpfs = splitMassFilter(general.cpf, true)
    if (cpfs.length && !cpfs.includes(lead.cpf)) {
      return false
    }
  }

  if (general.names.trim()) {
    const names = splitMassFilter(general.names)
    if (names.length && !names.some((name) => lead.nome.toLowerCase().includes(name))) {
      return false
    }
  }

  if (general.phones.trim()) {
    const phones = splitMassFilter(general.phones, true)
    if (phones.length && !phones.some((phone) => leadPhoneDigits.includes(phone))) {
      return false
    }
  }

  if (general.with_phones && !hasAnyPhone(lead)) {
    return false
  }

  if (general.without_phones && hasAnyPhone(lead)) {
    return false
  }

  if (general.birth_month.length) {
    const month = String(Number(lead.data_nascimento.slice(5, 7)))
    if (!general.birth_month.includes(month)) {
      return false
    }
  }

  return true
}

function matchFgtsFilters(lead: LemitPrototypeLead, filters: LemitPrototypeFilters) {
  const fgtsFilters = filters.bank_filters.fgts
  const snapshot = lead.fgts

  if (fgtsFilters.fgts_status === "nao_consultado") {
    if (snapshot) return false
  } else if (fgtsFilters.fgts_status && snapshot?.status !== fgtsFilters.fgts_status) {
    return false
  }

  if (fgtsFilters.motivos.length) {
    if (!snapshot || !fgtsFilters.motivos.includes(snapshot.motivo)) return false
  }

  if (fgtsFilters.origens_hig.length) {
    if (!snapshot || !fgtsFilters.origens_hig.includes(snapshot.origem_hig)) return false
  }

  return true
}

function matchCltFilters(lead: LemitPrototypeLead, filters: LemitPrototypeFilters) {
  const cltFilters = filters.bank_filters.clt
  const snapshot = lead.clt

  if (cltFilters.clt_consultado === "sim" && !snapshot?.consultado) {
    return false
  }

  if (cltFilters.clt_consultado === "nao" && snapshot?.consultado) {
    return false
  }

  if (cltFilters.clt_situacao && snapshot?.situacao !== cltFilters.clt_situacao) {
    return false
  }

  const margin = snapshot?.margem_disponivel
  if (cltFilters.clt_margem_min.trim()) {
    const min = Number(cltFilters.clt_margem_min.replace(",", "."))
    if (!Number.isFinite(min) || margin === null || margin === undefined || margin < min) {
      return false
    }
  }

  if (cltFilters.clt_margem_max.trim()) {
    const max = Number(cltFilters.clt_margem_max.replace(",", "."))
    if (!Number.isFinite(max) || margin === null || margin === undefined || margin > max) {
      return false
    }
  }

  return true
}

function matchMercantilFilters(lead: LemitPrototypeLead, filters: LemitPrototypeFilters) {
  const mercantilFilters = filters.bank_filters.mercantil
  const snapshot = lead.mercantil

  if (mercantilFilters.mercantil_situacao === "consultado" && !snapshot) {
    return false
  }

  if (mercantilFilters.mercantil_situacao === "sem_consulta" && snapshot) {
    return false
  }

  if (mercantilFilters.mercantil_status.length) {
    if (!snapshot || !mercantilFilters.mercantil_status.includes(snapshot.status)) {
      return false
    }
  }

  if (mercantilFilters.mercantil_origens.length) {
    if (!snapshot || !mercantilFilters.mercantil_origens.includes(snapshot.origem)) {
      return false
    }
  }

  return true
}

function matchUy3Filters(lead: LemitPrototypeLead, filters: LemitPrototypeFilters) {
  const uy3Filters = filters.bank_filters.uy3
  const snapshot = lead.uy3

  if (uy3Filters.uy3_type_webhook.length) {
    if (!snapshot || !uy3Filters.uy3_type_webhook.includes(snapshot.type_webhook)) {
      return false
    }
  }

  if (uy3Filters.uy3_status.length) {
    if (!snapshot || !uy3Filters.uy3_status.includes(snapshot.status)) {
      return false
    }
  }

  if (uy3Filters.uy3_elegivel_emprestimo === "sim" && snapshot?.elegivel_emprestimo !== true) {
    return false
  }

  if (uy3Filters.uy3_elegivel_emprestimo === "nao" && snapshot?.elegivel_emprestimo !== false) {
    return false
  }

  return true
}

function matchSelectedBanks(lead: LemitPrototypeLead, filters: LemitPrototypeFilters) {
  if (!filters.selected_banks.length) {
    return true
  }

  const results = filters.selected_banks.map((bank) => {
    switch (bank) {
      case "fgts":
        return matchFgtsFilters(lead, filters)
      case "clt":
        return matchCltFilters(lead, filters)
      case "mercantil":
        return matchMercantilFilters(lead, filters)
      case "uy3":
        return matchUy3Filters(lead, filters)
    }
  })

  return filters.bank_combination_mode === "all"
    ? results.every(Boolean)
    : results.some(Boolean)
}

export function createDefaultLemitPrototypeFilters(): LemitPrototypeFilters {
  return {
    general: {
      search: "",
      origens: [],
      cpf: "",
      names: "",
      phones: "",
      with_phones: false,
      without_phones: false,
      birth_month: [],
    },
    selected_banks: [],
    bank_combination_mode: "all",
    bank_filters: {
      fgts: {
        fgts_status: "",
        motivos: [],
        origens_hig: [],
      },
      clt: {
        clt_consultado: "",
        clt_situacao: "",
        clt_margem_min: "",
        clt_margem_max: "",
      },
      mercantil: {
        mercantil_situacao: "",
        mercantil_status: [],
        mercantil_origens: [],
      },
      uy3: {
        uy3_type_webhook: [],
        uy3_status: [],
        uy3_elegivel_emprestimo: "",
      },
    },
  }
}

export function createMockLeadsDataset(seed = 20260629, total = 240): LemitPrototypeLead[] {
  const random = new SeededRandom(seed)
  const leads: LemitPrototypeLead[] = []

  for (let index = 1; index <= total; index += 1) {
    const firstName = random.pick(NAMES)
    const lastName = random.pick(SURNAMES)
    const cpf = padCpf(40000000000 + index * 137)
    const phoneCount = random.chance(0.42) ? 0 : random.int(1, 3)
    const phones = Array.from({ length: phoneCount }, (_, phoneIndex) => ({
      number: createPhone(random, true),
      className: PHONE_CLASSES[(phoneIndex + index) % PHONE_CLASSES.length],
    }))
    const fgtsEnabled = random.chance(0.64)
    const cltEnabled = random.chance(0.58)
    const mercantilEnabled = random.chance(0.52)
    const uy3Enabled = random.chance(0.56)
    const cltSituacao = random.pick(["elegivel", "nao_elegivel", "nao_encontrado"] as const)

    leads.push({
      id: index,
      cpf,
      nome: `${firstName} ${lastName}`,
      origem_cadastral: random.pick(ORIGENS),
      data_nascimento: createIsoDate(random),
      created_at: createIsoDateTime(random),
      updated_at: createIsoDateTime(random),
      fone1: phones[0]?.number ?? null,
      classe_fone1: phones[0]?.className ?? null,
      fone2: phones[1]?.number ?? null,
      classe_fone2: phones[1]?.className ?? null,
      fone3: phones[2]?.number ?? null,
      classe_fone3: phones[2]?.className ?? null,
      fone4: null,
      classe_fone4: null,
      fgts: fgtsEnabled
        ? {
            motivo: random.pick(FGTS_MOTIVOS),
            origem_hig: random.pick(FGTS_ORIGENS_HIG),
            status: random.pick(["autorizado", "nao_autorizado"] as const),
          }
        : null,
      clt: cltEnabled
        ? {
            consultado: random.chance(0.82),
            situacao: cltSituacao,
            margem_disponivel: cltSituacao === "nao_encontrado" ? null : Number((random.int(0, 2200) + random.next()).toFixed(2)),
          }
        : null,
      mercantil: mercantilEnabled
        ? {
            status: random.pick(MERCANTIL_STATUS),
            origem: random.pick(MERCANTIL_ORIGENS),
          }
        : null,
      uy3: uy3Enabled
        ? {
            type_webhook: random.pick(UY3_TYPES),
            status: random.pick(UY3_STATUS),
            elegivel_emprestimo: random.pick([true, false, null]),
          }
        : null,
    })
  }

  return leads
}

export function getPrototypeOptionCatalog(leads: LemitPrototypeLead[]): LemitPrototypeOptionCatalog {
  const uniq = (values: string[]) => Array.from(new Set(values)).sort((left, right) => left.localeCompare(right))

  return {
    origens: uniq(leads.map((lead) => lead.origem_cadastral)),
    fgtsMotivos: uniq(leads.map((lead) => lead.fgts?.motivo ?? "").filter(Boolean)),
    fgtsOrigensHig: uniq(leads.map((lead) => lead.fgts?.origem_hig ?? "").filter(Boolean)),
    mercantilStatus: uniq(leads.map((lead) => lead.mercantil?.status ?? "").filter(Boolean)),
    mercantilOrigens: uniq(leads.map((lead) => lead.mercantil?.origem ?? "").filter(Boolean)),
    uy3Types: uniq(leads.map((lead) => lead.uy3?.type_webhook ?? "").filter(Boolean)),
    uy3Statuses: uniq(leads.map((lead) => lead.uy3?.status ?? "").filter(Boolean)),
  }
}

export function validatePrototypeBankSelections(filters: LemitPrototypeFilters) {
  return filters.selected_banks
    .filter((bank) => !bankFilterIsFilled(filters, bank))
    .map((bank) => `Preencha ao menos um filtro no bloco ${BANK_LABELS[bank]}.`)
}

export function filterPrototypeLeads(leads: LemitPrototypeLead[], filters: LemitPrototypeFilters) {
  return leads.filter((lead) => matchGeneralFilters(lead, filters) && matchSelectedBanks(lead, filters))
}

export function samplePrototypeLeads(leads: LemitPrototypeLead[], quantity: number) {
  const shuffled = [...leads]
  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const target = Math.floor(Math.random() * (index + 1))
    const aux = shuffled[index]
    shuffled[index] = shuffled[target]
    shuffled[target] = aux
  }
  return shuffled.slice(0, quantity)
}

function createMockLemitPhones(seedKey: string) {
  const random = new SeededRandom(hashString(seedKey))
  const phoneStatusRoll = random.next()

  if (phoneStatusRoll < 0.12) {
    return { celulares: [], fixos: [], resultado: "erro_simulado" as LemitPrototypeResultStatus }
  }

  if (phoneStatusRoll < 0.34) {
    return { celulares: [], fixos: [], resultado: "sem_telefone" as LemitPrototypeResultStatus }
  }

  const celularCount = random.int(1, 3)
  const fixoCount = random.chance(0.45) ? random.int(0, 2) : 0
  const celulares = Array.from({ length: celularCount }, (_, index) => ({
    ddd: random.pick(DDDS),
    numero: createPhone(random, true).slice(2),
    plus: true,
    ranking: index + 1,
    whatsapp: index === 0 ? random.chance(0.8) : random.chance(0.45),
  }))
  const fixos = Array.from({ length: fixoCount }, (_, index) => ({
    ddd: random.pick(DDDS),
    numero: createPhone(random, false).slice(2),
    plus: false,
    ranking: index + 1,
    whatsapp: false,
  }))

  return { celulares, fixos, resultado: "telefone_encontrado" as LemitPrototypeResultStatus }
}

function formatCandidatePhone(candidate: LemitPrototypePhoneCandidate | null) {
  if (!candidate) return null
  return formatPhone(`${candidate.ddd}${candidate.numero}`)
}

function fillFirstEmptyPhoneSlot(lead: LemitPrototypeLead, phone: LemitPrototypePhoneCandidate) {
  const nextLead = { ...lead }
  const rawPhone = `${phone.ddd}${phone.numero}`
  const fields = [
    ["fone1", "classe_fone1"],
    ["fone2", "classe_fone2"],
    ["fone3", "classe_fone3"],
    ["fone4", "classe_fone4"],
  ] as const

  for (const [phoneField, classField] of fields) {
    if (!nextLead[phoneField]) {
      nextLead[phoneField] = rawPhone
      nextLead[classField] = "Lemit"
      nextLead.updated_at = new Date().toISOString().slice(0, 19).replace("T", " ")
      break
    }
  }

  return nextLead
}

export function createPrototypeLotExecution(
  allLeads: LemitPrototypeLead[],
  filteredLeads: LemitPrototypeLead[],
  filters: LemitPrototypeFilters,
  quantity: number,
  lotId: number,
  lotTitle: string,
) {
  const sampledLeads = samplePrototypeLeads(filteredLeads, quantity)
  const leadMap = new Map(allLeads.map((lead) => [lead.cpf, lead]))
  const items: LemitPrototypeLotItem[] = []

  for (const lead of sampledLeads) {
    const existingPhone = getFirstPhone(lead)
    const mockPhones = createMockLemitPhones(`${lotId}:${lead.cpf}`)
    const preferred = pickPreferredPhone(mockPhones.celulares, mockPhones.fixos)
    const telefonePreferido = formatCandidatePhone(preferred.phone)
    const atualizariaLead =
      !hasAnyPhone(lead) &&
      mockPhones.resultado === "telefone_encontrado" &&
      Boolean(preferred.phone)

    items.push({
      cpf: lead.cpf,
      nome: lead.nome,
      telefone_atual_antes: existingPhone ? formatPhone(existingPhone) : "",
      celulares: mockPhones.celulares,
      fixos: mockPhones.fixos,
      telefone_preferido: telefonePreferido,
      telefone_lemit: telefonePreferido,
      tipo_telefone: preferred.type,
      whatsapp: preferred.phone?.whatsapp ?? null,
      ranking: preferred.phone?.ranking ?? null,
      resultado: mockPhones.resultado,
      atualizaria_lead: atualizariaLead,
    })

    if (atualizariaLead && preferred.phone) {
      leadMap.set(lead.cpf, fillFirstEmptyPhoneSlot(leadMap.get(lead.cpf) ?? lead, preferred.phone))
    }
  }

  const updatedLeads = allLeads.map((lead) => leadMap.get(lead.cpf) ?? lead)
  const now = new Date().toISOString()
  const normalizedLotTitle = lotTitle.trim() || `Lote #${String(lotId).padStart(4, "0")}`
  const lot: LemitPrototypeLot = {
    id: lotId,
    title: normalizedLotTitle,
    created_at: now,
    banks: [...filters.selected_banks],
    bank_combination_mode: filters.bank_combination_mode,
    pool_size: filteredLeads.length,
    requested_quantity: quantity,
    sampled_quantity: sampledLeads.length,
    status: "em_andamento",
    phones_found_count: items.filter((item) => item.resultado === "telefone_encontrado" && item.telefone_lemit).length,
    leads_updated_count: items.filter((item) => item.atualizaria_lead).length,
    filters_snapshot: cloneFilters(filters),
    items,
  }

  return { lot, updatedLeads }
}

export function finalizePrototypeLots(lots: LemitPrototypeLot[]) {
  return lots.map((lot) => (lot.status === "em_andamento" ? { ...lot, status: "concluido" as const } : lot))
}

export function countPoolWithoutPhones(leads: LemitPrototypeLead[]) {
  return leads.filter((lead) => !hasAnyPhone(lead)).length
}

export function countPoolWithPhones(leads: LemitPrototypeLead[]) {
  return leads.filter((lead) => hasAnyPhone(lead)).length
}

export function getPrototypeBankLabel(bank: LemitPrototypeBankKey) {
  return BANK_LABELS[bank]
}

export function getPrototypeMonthOptions() {
  return MONTHS
}

export function formatPrototypeLeadPhoneStatus(lead: LemitPrototypeLead) {
  return hasAnyPhone(lead) ? "Com telefone" : "Sem telefone"
}

export function formatPrototypeLeadPrimaryPhone(lead: LemitPrototypeLead) {
  const phone = getFirstPhone(lead)
  return phone ? formatPhone(phone) : "--"
}

export function formatPrototypeLeadCpf(lead: LemitPrototypeLead) {
  return formatCPF(lead.cpf)
}
