export type CampaignProduct = "clt" | "fgts"

export const CAMPAIGN_PRODUCTS: Array<{ value: CampaignProduct; label: string }> = [
  { value: "clt", label: "Crédito do Trabalhador" },
  { value: "fgts", label: "FGTS" },
]

export type OfficialInbox = {
  id: string
  name: string
  phoneNumber: string
  templates: OfficialTemplate[]
}

export type OfficialHeaderType = "TEXT" | "IMAGE" | "VIDEO" | "DOCUMENT" | null

export type OfficialTemplate = {
  id: string
  name: string
  category: string
  language: string
  status: string
  body: string
  parameters: Array<{ key: string; label: string }>
  headerType: OfficialHeaderType
  headerVariables: string[]
  headerText: string | null
}
