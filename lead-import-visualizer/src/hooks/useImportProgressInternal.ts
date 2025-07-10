import { useEffect, useRef, useState } from "react"
import { toast } from "sonner"
import { useQuery, useQueryClient } from "@tanstack/react-query"
import { useImportProgressCtx } from "@/contexts/ImportProgressContext"
import { getImportJob } from "@/api/importJobs"

export const useImportProgressInternal = (jobId: number) => {
  const { updateJob, removeJob } = useImportProgressCtx()
  const queryClient = useQueryClient()

  const toastId = useRef<string | number | null>(null)
  const [finished, setFinished] = useState(false)

  /* Toast inicial */
  useEffect(() => {
    toastId.current = toast.info("Importação iniciada", {
      description: "Aguardando processamento...",
      duration: Infinity,
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []) // uma única vez

  /* Polling */
  const { data } = useQuery({
    queryKey: ["importJob", jobId],
    queryFn: () => getImportJob(jobId),
    enabled: !finished,
    refetchInterval: 2000,
    refetchOnWindowFocus: false,
  })

  /* Actualiza toast + contexto */
  useEffect(() => {
    if (!data || !toastId.current) return

    const { status, processed_rows, total_rows, errors } = data
    const percent = total_rows
      ? Math.floor((processed_rows / total_rows) * 100)
      : 0

    updateJob(jobId, {
      status,
      processed: processed_rows,
      total: total_rows,
    })

    if (status === "em_progresso") {
      toast.message("Importação em andamento", {
        id: toastId.current,
    description: `${percent}%  •  ${processed_rows}/${total_rows} linhas  •  ${errors} erro(s)`,
      })
    }

    if (status === "concluido") {
      toast.success("Importação concluída", {
        id: toastId.current,
    description: `${total_rows} linhas importadas  •  ${errors} erro(s).`,
        duration: 8000,
        onDismiss() {
          removeJob(jobId)
        },
      })
       queryClient.invalidateQueries({ queryKey: ["leads"] })  // ← força reload

      queryClient.invalidateQueries({ queryKey: ["imports"] })
      setFinished(true)
    }

    if (status === "falhou") {
      toast.error("Importação falhou", {
        id: toastId.current,
        description:
          errors > 0
            ? `Falhou com ${errors} erro(s).`
            : "Ocorreu um erro durante a importação.",
        duration: 8000,
        onDismiss() {
          removeJob(jobId)
        },
      })
      setFinished(true)
    }
  }, [data, jobId, updateJob, removeJob, queryClient])
}
