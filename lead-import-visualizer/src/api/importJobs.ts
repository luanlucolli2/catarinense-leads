import axiosClient from "@/api/axiosClient"

export interface ImportJobDto {
  id: number
  status: "pendente" | "em_progresso" | "concluido" | "falhou"
  processed_rows: number
  total_rows: number
  errors: number
}

/* POST upload */
export async function startImport(formData: FormData): Promise<ImportJobDto> {
  const { data } = await axiosClient.post("/import", formData, {
    headers: { "Content-Type": "multipart/form-data" },
  })
  return getImportJob(data.job_id)
}

/* GET /import/{id} */
export async function getImportJob(id: number): Promise<ImportJobDto> {
  const { data } = await axiosClient.get(`/import/${id}`)
  return { id, ...data }
}

/* 🆕 GET /imports?status=em_progresso,pendente */
export async function listActiveImports() {
  const { data } = await axiosClient.get("/imports", {
    params: { status: "em_progresso,pendente" },
  })
  return data as Array<{
    id: number
    status: "pendente" | "em_progresso"
    processed_rows: number
    total_rows: number
  }>
}
