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
  channel: "whatsapp_oficial"
  phoneNumber: string
  verifiedName: string
  qualityRating: PhoneQualityRating
  templates: OfficialTemplate[]
}

export type OfficialTemplate = {
  id: string
  name: string
  category: "MARKETING" | "UTILITY"
  language: "pt_BR"
  quality: "ALTA" | "MÉDIA" | "INDISPONÍVEL"
  body: string
  parameters: Array<{ key: string; label: string }>
}

export const OFFICIAL_TEMPLATES: OfficialTemplate[] = [
  {
    id: "template_credito_clt",
    name: "credito_clt_disponivel",
    category: "MARKETING",
    language: "pt_BR",
    quality: "ALTA",
    body: "Olá, {{1}}! Encontramos uma possibilidade para você. Fale com a nossa equipe para saber mais.",
    parameters: [{ key: "1", label: "Nome do cliente" }],
  },
  {
    id: "template_atualizacao_cadastro",
    name: "atualizacao_de_cadastro",
    category: "UTILITY",
    language: "pt_BR",
    quality: "MÉDIA",
    body: "Olá, {{1}}. Precisamos confirmar alguns dados do seu cadastro. Responda esta mensagem para continuar.",
    parameters: [{ key: "1", label: "Nome do cliente" }],
  },
  {
    id: "template_oferta_personalizada",
    name: "oferta_personalizada",
    category: "MARKETING",
    language: "pt_BR",
    quality: "INDISPONÍVEL",
    body: "Olá, {{1}}! Temos uma novidade para o produto {{2}}. Posso explicar as condições?",
    parameters: [
      { key: "1", label: "Nome do cliente" },
      { key: "2", label: "Produto" },
    ],
  },
]

export const OFFICIAL_INBOXES: OfficialInbox[] = [
  {
    id: "inbox_001",
    name: "Catarinense CLT",
    channel: "whatsapp_oficial",
    phoneNumber: "+55 48 99999-0101",
    verifiedName: "Catarinense Benefícios",
    qualityRating: "GREEN",
    templates: [OFFICIAL_TEMPLATES[0], OFFICIAL_TEMPLATES[1]],
  },
  {
    id: "inbox_002",
    name: "Catarinense FGTS",
    channel: "whatsapp_oficial",
    phoneNumber: "+55 48 99999-0102",
    verifiedName: "Catarinense Benefícios",
    qualityRating: "YELLOW",
    templates: [OFFICIAL_TEMPLATES[0], OFFICIAL_TEMPLATES[2]],
  },
  {
    id: "inbox_003",
    name: "Catarinense Novos Leads",
    channel: "whatsapp_oficial",
    phoneNumber: "+55 48 99999-0103",
    verifiedName: "Catarinense Benefícios",
    qualityRating: "NA",
    templates: [],
  },
  {
    id: "inbox_004",
    name: "Catarinense Recuperação",
    channel: "whatsapp_oficial",
    phoneNumber: "+55 48 99999-0104",
    verifiedName: "Catarinense Benefícios",
    qualityRating: "RED",
    templates: [OFFICIAL_TEMPLATES[1]],
  },
]
