import axiosClient from "@/api/axiosClient";

/** Para polling (toast de progresso) */
export interface ActiveImportJobDto {
  id: number;
  status: "pendente" | "em_progresso" | "concluido" | "falhou";
  processed_rows: number;
  total_rows: number;
}

/** Para a listagem de Histórico */
export interface ImportJob {
  id: number;
  type: string;
  fileName: string;
  origin: string;
  status: "pendente" | "em_progresso" | "concluido" | "falhou";
  totalRows: number;
  processedRows: number;
  errorsCount: number;
  startedAt: string | null;
  finishedAt: string | null;
  user: { name: string };
}

/** Erros de importação (modal) */
export interface ImportError {
  id: number;
  row_number: number;
  column_name: string;
  error_message: string;
}

/** POST /import → inicia upload e retorna o DTO de polling */
export async function startImport(
  formData: FormData
): Promise<ActiveImportJobDto> {
  const { data } = await axiosClient.post("/import", formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return getImportJob(data.job_id);
}

/** GET /import/{id} → status para polling */
export async function getImportJob(
  id: number
): Promise<ActiveImportJobDto> {
  const { data } = await axiosClient.get(`/import/${id}`);
  return { id, ...data };
}

/** GET /imports?status=pendente,em_progresso → polling de jobs ativos */
export async function listActiveImports(): Promise<
  ActiveImportJobDto[]
> {
  const { data } = await axiosClient.get("/imports", {
    params: { status: "pendente,em_progresso" },
  });
  return data as ActiveImportJobDto[];
}

/** GET /imports → lista completa (Histórico) */
export async function listImportJobs(): Promise<ImportJob[]> {
  const { data } = await axiosClient.get("/imports");
  return (data as any[]).map((raw) => ({
    id: raw.id,
    type: raw.type,
    fileName: raw.file_name,
    origin: raw.origin,
    status: raw.status,
    totalRows: raw.total_rows,
    processedRows: raw.processed_rows,
    errorsCount: raw.errors_count,
    startedAt: raw.started_at,
    finishedAt: raw.finished_at,
    user: raw.user,
  }));
}

/** GET /import/{id}/errors → lista de erros para o modal */
export async function fetchImportErrors(
  jobId: number
): Promise<ImportError[]> {
  const { data } = await axiosClient.get(
    `/import/${jobId}/errors`
  );
  return data as ImportError[];
}

/** GET /import/{id}/errors/export → exporta CSV de erros */
export async function exportImportErrorsCsv(
  jobId: number
): Promise<void> {
  const response = await axiosClient.get(
    `/import/${jobId}/errors/export`,
    { responseType: "blob" }
  );
  const blob = new Blob(
    [response.data],
    { type: response.headers["content-type"] }
  );
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  // tenta extrair filename do header
  const cd = response.headers["content-disposition"];
  let filename = `import_${jobId}_errors.csv`;
  if (cd) {
    const match = cd.match(/filename="?(.+)"?/);
    if (match?.[1]) filename = match[1];
  }
  link.href = url;
  link.setAttribute("download", filename);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}
