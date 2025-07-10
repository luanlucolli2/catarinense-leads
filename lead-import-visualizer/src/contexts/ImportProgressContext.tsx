import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  ReactNode,
} from "react"
import { listActiveImports } from "@/api/importJobs"
import { useImportProgressInternal } from "@/hooks/useImportProgressInternal"

type Status = "pendente" | "em_progresso" | "concluido" | "falhou"

interface JobProgress {
  status: Status
  processed: number
  total: number
}

interface CtxShape {
  jobs: Record<number, JobProgress>
  addJob: (id: number) => void
  updateJob: (id: number, patch: Partial<JobProgress>) => void
  removeJob: (id: number) => void
}

const ImportProgressContext = createContext<CtxShape | null>(null)

/* ---------------- Provider + Manager ---------------- */
export const ImportProgressProvider = ({ children }: { children: ReactNode }) => {
  const [jobs, setJobs] = useState<Record<number, JobProgress>>({})

  /* ---------- CRUD local ---------- */
  const addJob = useCallback((id: number) => {
    setJobs((prev) =>
      prev[id] ? prev : { ...prev, [id]: { status: "pendente", processed: 0, total: 0 } },
    )
  }, [])

  const updateJob = useCallback(
    (id: number, patch: Partial<JobProgress>) => {
      setJobs((prev) =>
        prev[id] ? { ...prev, [id]: { ...prev[id], ...patch } } : prev,
      )
    },
    [],
  )

  const removeJob = useCallback((id: number) => {
    setJobs((prev) => {
      const next = { ...prev }
      delete next[id]
      return next
    })
  }, [])

  /* ---------- Re-hidrata jobs ativos ao montar ---------- */
  useEffect(() => {
    listActiveImports()
      .then((serverJobs) => {
        serverJobs.forEach((j) => {
          addJob(j.id)
          updateJob(j.id, {
            status: j.status,
            processed: j.processed_rows,
            total: j.total_rows,
          })
        })
      })
      .catch(() => {
        /* falha silenciosa */
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []) // apenas na montagem

  return (
    <ImportProgressContext.Provider
      value={{ jobs, addJob, updateJob, removeJob }}
    >
      {children}
      {Object.keys(jobs).map((id) => (
        <ImportProgressTracker key={id} jobId={Number(id)} />
      ))}
    </ImportProgressContext.Provider>
  )
}

/* ------------ utilitário ------------- */
export const useImportProgressCtx = () => {
  const ctx = useContext(ImportProgressContext)
  if (!ctx) throw new Error("ImportProgressCtx not found")
  return ctx
}

/* -------- Tracker isolado por job -------- */
const ImportProgressTracker = ({ jobId }: { jobId: number }) => {
  useImportProgressInternal(jobId)
  return null
}
