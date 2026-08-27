import axiosClient from "@/api/axiosClient"
import type { OfficialHeaderType, OfficialInbox, OfficialTemplate } from "./configurationFixtures"

type ApiTemplate = {
  id: string
  name: string
  status: string
  category: string
  language: string
  body: string
  variables: string[]
  header_type: OfficialHeaderType
  header_variables: string[]
  header_text: string | null
}

type ApiInbox = {
  id: string
  name: string
  phone_number: string
  templates: ApiTemplate[]
}

type ApiResponse = { inboxes: ApiInbox[] }

const toTemplate = (template: ApiTemplate): OfficialTemplate => ({
  id: template.id,
  name: template.name,
  status: template.status,
  category: template.category,
  language: template.language,
  body: template.body,
  parameters: template.variables.map((key) => ({ key, label: `Variável ${key}` })),
  headerType: template.header_type,
  headerVariables: template.header_variables,
  headerText: template.header_text,
})

export async function listMailingInboxes(refresh = false): Promise<OfficialInbox[]> {
  const { data } = await axiosClient.get<ApiResponse>("/disparos-whatsapp-vendeai/inboxes", {
    params: refresh ? { refresh: 1 } : undefined,
  })

  return data.inboxes.map((inbox) => ({
    id: inbox.id,
    name: inbox.name,
    phoneNumber: inbox.phone_number,
    templates: inbox.templates.map(toTemplate),
  }))
}
