import { useCallback, useEffect, useRef, useState } from 'react'
import { FgtsOffConsultJobShow, FgtsOffJobStatus, getFgtsOffConsultJob } from '@/api/fgtsOff'

type Options = {
  intervalMs?: number
  stopOn?: FgtsOffJobStatus[]
  enabled?: boolean
}

const DEFAULT_STOP_ON: FgtsOffJobStatus[] = ['concluido', 'falhou', 'cancelado', 'expirado']

export function useFgtsOffJobPolling(jobId: number | null, opts?: Options) {
  const intervalMs = opts?.intervalMs ?? 3000
  const enabled = opts?.enabled ?? Boolean(jobId)

  const stopOnRef = useRef<FgtsOffJobStatus[]>(opts?.stopOn ?? DEFAULT_STOP_ON)
  useEffect(() => {
    stopOnRef.current = opts?.stopOn ?? DEFAULT_STOP_ON
  }, [opts?.stopOn])

  const [job, setJob] = useState<FgtsOffConsultJobShow | null>(null)
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
      const data = await getFgtsOffConsultJob(jobId)
      setJob(data)
      if (stopOnRef.current.includes(data.status)) {
        clearTimer()
      }
    } catch {
      // silencia erros transitórios
    } finally {
      setLoading(false)
    }
  }, [jobId])

  useEffect(() => {
    if (!enabled || !jobId) {
      clearTimer()
      return
    }
    clearTimer()
    void tick()
    timer.current = window.setInterval(tick, intervalMs)
    return clearTimer
  }, [enabled, intervalMs, jobId, tick])

  return { job, loading, refresh: tick }
}
