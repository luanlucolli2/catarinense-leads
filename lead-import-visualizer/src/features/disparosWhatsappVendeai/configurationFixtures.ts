export type PhoneQualityRating = "GREEN" | "YELLOW" | "RED" | "NA"

export type CampaignProduct = "clt" | "fgts"

export const CAMPAIGN_PRODUCTS: Array<{ value: CampaignProduct; label: string }> = [
  { value: "clt", label: "Crédito do Trabalhador" },
  { value: "fgts", label: "FGTS" },
]

export const REGISTERED_LEADS_PREVIEW_FIXTURE = {
  recipientCount: 1248,
}

export type OfficialInbox = {
  id: string
  name: string
  channel: "whatsapp"
  phoneNumber: string
  verifiedName?: string
  qualityRating: PhoneQualityRating
  templates: OfficialTemplate[]
}

export type OfficialHeaderType = "TEXT" | "IMAGE" | "VIDEO" | "DOCUMENT" | null

export type OfficialTemplate = {
  id: string
  name: string
  category: "MARKETING" | "UTILITY"
  language: string
  status: string
  body: string
  parameters: Array<{ key: string; label: string }>
  headerType: OfficialHeaderType
}

export const OFFICIAL_TEMPLATES: OfficialTemplate[] = [
  {
    id: "template_credito_clt",
    name: "credito_clt_disponivel",
    category: "MARKETING",
    language: "en",
    status: "APPROVED",
    body: "Olá, {{1}}! Encontramos uma possibilidade para você. Fale com a nossa equipe para saber mais.",
    parameters: [{ key: "1", label: "Variável 1" }],
    headerType: null,
  },
  {
    id: "template_atualizacao_cadastro",
    name: "atualizacao_de_cadastro",
    category: "UTILITY",
    language: "en",
    status: "APPROVED",
    body: "Olá, {{1}}. Precisamos confirmar alguns dados do seu cadastro. Responda esta mensagem para continuar.",
    parameters: [{ key: "1", label: "Variável 1" }],
    headerType: null,
  },
  {
    id: "template_oferta_personalizada",
    name: "oferta_personalizada",
    category: "MARKETING",
    language: "en",
    status: "APPROVED",
    body: "Olá, {{1}}! Temos uma novidade para o produto {{2}}. Posso explicar as condições?",
    parameters: [
      { key: "1", label: "Variável 1" },
      { key: "2", label: "Variável 2" },
    ],
    headerType: null,
  },
]

export const OFFICIAL_INBOXES: OfficialInbox[] = [
  {
    id: "inbox_001",
    name: "Catarinense CLT",
    channel: "whatsapp",
    phoneNumber: "+55 48 99999-0101",
    qualityRating: "NA",
    templates: [OFFICIAL_TEMPLATES[0], OFFICIAL_TEMPLATES[1]],
  },
  {
    id: "inbox_002",
    name: "Catarinense FGTS",
    channel: "whatsapp",
    phoneNumber: "+55 48 99999-0102",
    qualityRating: "NA",
    templates: [OFFICIAL_TEMPLATES[0], OFFICIAL_TEMPLATES[2]],
  },
  {
    id: "inbox_003",
    name: "Catarinense Novos Leads",
    channel: "whatsapp",
    phoneNumber: "+55 48 99999-0103",
    qualityRating: "NA",
    templates: [],
  },
  {
    id: "inbox_004",
    name: "Catarinense Recuperação",
    channel: "whatsapp",
    phoneNumber: "+55 48 99999-0104",
    qualityRating: "NA",
    templates: [OFFICIAL_TEMPLATES[1]],
  },
]
