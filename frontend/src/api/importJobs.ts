import axiosClient from "@/api/axiosClient";

const IMPORT_UPLOAD_TIMEOUT_MS = Number(import.meta.env.VITE_IMPORT_UPLOAD_TIMEOUT_MS) > 0
  ? Number(import.meta.env.VITE_IMPORT_UPLOAD_TIMEOUT_MS)
  : 120000;

/** Para a listagem de Histórico */
export interface ImportJob {
  id: number;
  type: string;
  fileName: string;
  origin: string;
  status: "pendente"
  | "em_progresso"
  | "concluido"
  | "falhou"
  | "cancelado"
  | "revertido"
  | "cancelamento_solicitado"
  | "revertendo"
  | "rollback_falhou";
  totalRows: number;
  processedRows: number;
  errorsCount: number;
  startedAt: string | null;
  finishedAt: string | null;
  rolledBackAt: string | null;          // 👈 novo

  user: { name: string };
}

/** Erros de importação (modal) */
export interface ImportError {
  id: number;
  row_number: number;
  column_name: string;
  error_message: string;
}

/** POST /import → inicia upload */
export async function startImport(
  formData: FormData
): Promise<{ job_id: number }> {
  const { data } = await axiosClient.post("/import", formData, {
    headers: { "Content-Type": "multipart/form-data" },
    timeout: IMPORT_UPLOAD_TIMEOUT_MS,
  });
  return data;
}

export async function rollbackImportJob(id: number): Promise<void> {
  await axiosClient.post(`/import/${id}/rollback`);
}

export async function cancelImportJob(id: number): Promise<void> {
  await axiosClient.post(`/import/${id}/cancel`);
}

/** GET /imports → lista completa (Histórico) */
export async function listImportJobs(): Promise<ImportJob[]> {
  const { data } = await axiosClient.get("/imports", {
    params: { scope: "all" },
  });
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
    rolledBackAt: raw.rolled_back_at,
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
