import { getPrototypeBankLabel } from "./mock"
import type {
  LemitPrototypeBankKey,
  LemitPrototypeCombinationMode,
  LemitPrototypeLot,
  LemitPrototypeLotStatus,
  LemitPrototypeResultStatus,
} from "./types"

function escapeCsv(value: unknown) {
  const stringValue = value === null || value === undefined ? "" : String(value)
  return `"${stringValue.replace(/"/g, '""')}"`
}

export function getPrototypeCombinationLabel(
  mode: LemitPrototypeCombinationMode,
  short = false,
) {
  if (mode === "all") {
    return short ? "Todos" : "Todos os bancos selecionados"
  }

  return short ? "Qualquer" : "Qualquer banco selecionado"
}

export function getPrototypeBanksLabel(banks: LemitPrototypeBankKey[]) {
  if (!banks.length) {
    return "Somente filtros gerais"
  }

  return banks.map((bank) => getPrototypeBankLabel(bank)).join(", ")
}

export function getPrototypeLotStatusLabel(status: LemitPrototypeLotStatus) {
  return status === "em_andamento" ? "Em andamento" : "Concluído"
}

export function getPrototypeLotStatusClassName(status: LemitPrototypeLotStatus) {
  return status === "em_andamento"
    ? "border-blue-200 bg-blue-50 text-blue-700"
    : "border-emerald-200 bg-emerald-50 text-emerald-700"
}

export function getPrototypeResultLabel(status: LemitPrototypeResultStatus) {
  switch (status) {
    case "telefone_encontrado":
      return "Telefone encontrado"
    case "sem_telefone":
      return "Sem telefone"
    case "erro_simulado":
    default:
      return "Erro simulado"
  }
}

export function getPrototypeResultClassName(status: LemitPrototypeResultStatus) {
  switch (status) {
    case "telefone_encontrado":
      return "border-emerald-200 bg-emerald-50 text-emerald-700"
    case "sem_telefone":
      return "border-amber-200 bg-amber-50 text-amber-700"
    case "erro_simulado":
    default:
      return "border-red-200 bg-red-50 text-red-700"
  }
}

export function downloadPrototypeLotCsv(lot: LemitPrototypeLot) {
  const headers = [
    "lote_id",
    "cpf",
    "nome",
    "telefone_atual_antes",
    "telefone_lemit",
    "tipo_telefone",
    "whatsapp",
    "ranking",
    "resultado",
    "atualizaria_lead",
    "bancos_usados",
    "modo_combinacao",
  ]

  const banksUsed = getPrototypeBanksLabel(lot.banks)
  const combination = getPrototypeCombinationLabel(lot.bank_combination_mode, true)

  const rows = lot.items.map((item) => [
    lot.title,
    item.cpf,
    item.nome,
    item.telefone_atual_antes,
    item.telefone_lemit ?? "",
    item.tipo_telefone ?? "",
    item.whatsapp === null ? "" : item.whatsapp ? "sim" : "nao",
    item.ranking ?? "",
    item.resultado,
    item.atualizaria_lead ? "sim" : "nao",
    banksUsed,
    combination,
  ])

  const csv = [headers, ...rows]
    .map((row) => row.map((value) => escapeCsv(value)).join(","))
    .join("\n")

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement("a")

  link.href = url
  link.download = `${lot.title.toLowerCase().replace(/\s+/g, "-")}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(url)
}
