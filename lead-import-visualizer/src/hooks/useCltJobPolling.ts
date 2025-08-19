import { useCallback, useEffect, useRef, useState } from 'react'
import { CltConsultJobShow, CltJobStatus, getCltConsultJob } from '../api/clt'

type Options = {
  intervalMs?: number
  /** estados que param o polling; default fixo para não mudar identidade a cada render */
  stopOn?: CltJobStatus[]
  enabled?: boolean
}

const DEFAULT_STOP_ON: CltJobStatus[] = ['concluido', 'falhou']

export function useCltJobPolling(jobId: number | null, opts?: Options) {
  const intervalMs = opts?.intervalMs ?? 3000
  const enabled = opts?.enabled ?? Boolean(jobId)

  // stopOn nunca muda de identidade sem necessidade
  const stopOnRef = useRef<CltJobStatus[]>(opts?.stopOn ?? DEFAULT_STOP_ON)
  useEffect(() => {
    stopOnRef.current = opts?.stopOn ?? DEFAULT_STOP_ON
  }, [opts?.stopOn])

  const [job, setJob] = useState<CltConsultJobShow | null>(null)
  const [loading, setLoading] = useState(false)
  const timer = useRef<number | null>(null)

  const clearTimer = () => {
    if (timer.current) {
      window.clearInterval(timer.current)
      timer.current = null
    }
  }

  const tick = useCallback(async () => {
    if (!jobId) return
    try {
      setLoading(true)
      const data = await getCltConsultJob(jobId)
      setJob(data)
      if (stopOnRef.current.includes(data.status)) {
        clearTimer()
      }
    } catch {
      // silencia erros transitórios; se quiser, logue
    } finally {
      setLoading(false)
    }
  }, [jobId])

  useEffect(() => {
    if (!enabled || !jobId) {
      clearTimer()
      return
    }
    // limpa qualquer intervalo pré-existente (StrictMode em dev monta/desmonta)
    clearTimer()

    // primeira batida imediata
    void tick()

    // segue com o polling
    timer.current = window.setInterval(tick, intervalMs)

    return clearTimer
  }, [enabled, intervalMs, jobId, tick])

  return { job, loading, refresh: tick }
}
