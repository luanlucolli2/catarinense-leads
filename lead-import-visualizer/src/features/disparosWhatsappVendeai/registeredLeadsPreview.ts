import axiosClient from "@/api/axiosClient"

type BankFilters = Record<string, string>

export type RegisteredLeadsPreviewFilters = {
  selectedBanks: Array<"facta" | "mercantil" | "uy3">
  combinationMode: "any" | "all"
  birthMonth: string[]
  facta: BankFilters
  mercantil: BankFilters
  uy3: BankFilters
}

export type RegisteredLeadsPreviewResponse = { recipient_count: number }

export async function previewRegisteredLeads(filters: RegisteredLeadsPreviewFilters): Promise<RegisteredLeadsPreviewResponse> {
  const { data } = await axiosClient.post<RegisteredLeadsPreviewResponse>("/disparos-whatsapp-vendeai/leads/preview", {
    selected_banks: filters.selectedBanks,
    combination_mode: filters.combinationMode,
    birth_month: filters.birthMonth,
    facta: filters.facta,
    mercantil: filters.mercantil,
    uy3: filters.uy3,
  })

  return data
}
