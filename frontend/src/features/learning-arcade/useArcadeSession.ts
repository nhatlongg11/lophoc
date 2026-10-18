import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../../api'
import type { ActionFields, ActionKind, Draft, Session } from './types'

type Pending = { event_key: string; revision: number; action: ActionFields & { kind: ActionKind; activity_id: string } }
const read = <T,>(key: string): T | null => { try { return JSON.parse(sessionStorage.getItem(key) || 'null') as T | null } catch { return null } }
const write = (key: string, value: unknown) => { try { sessionStorage.setItem(key, JSON.stringify(value)) } catch { /* Server saving remains available when local storage is disabled. */ } }
const remove = (key: string) => { try { sessionStorage.removeItem(key) } catch { /* Optional local cache. */ } }

export function useArcadeSession(id: number, userId: number, initial?: Session) {
  const [session, setSession] = useState<Session | null>(initial || null), [busy, setBusy] = useState(false), [error, setError] = useState(''), [pending, setPending] = useState<Pending | null>(null), [draft, setDraft] = useState<Draft>(''), [saveStatus, setSaveStatus] = useState(''), [online, setOnline] = useState(navigator.onLine)
  const sessionRef = useRef(session), busyRef = useRef(false), draftRef = useRef<Draft>(''), touched = useRef(false), mounted = useRef(true)
  const pendingKey = 'eduquest-arcade:' + userId + ':' + id + ':pending'
  const draftKey = (activity: string, item: string) => 'eduquest-arcade:' + userId + ':' + id + ':draft:' + activity + ':' + item
  const apply = useCallback((next: Session) => { sessionRef.current = next; if (mounted.current) setSession(next) }, [])
  const load = useCallback(async () => {
    try { const next = await api<Session>('/arcade/sessions/' + id); apply(next); if (mounted.current) setError('') }
    catch (err) { if (mounted.current) setError((err as Error).message) }
  }, [id, apply])
  const run = useCallback(async (body: Pending) => {
    if (busyRef.current) return
    busyRef.current = true; setBusy(true); setPending(body); setError(''); write(pendingKey, body)
    try {
      const next = await api<Session>('/arcade/sessions/' + id + '/actions', { method: 'POST', body })
      remove(pendingKey); apply(next); if (mounted.current) setPending(null)
    } catch (err) {
      const failure = err as Error & { status?: number }
      if (mounted.current) setError(failure.message)
      if (failure.status && failure.status < 500 && ![419, 429].includes(failure.status)) {
        remove(pendingKey); if (mounted.current) setPending(null)
        if (failure.status === 409) await load()
      }
    } finally { busyRef.current = false; if (mounted.current) setBusy(false) }
  }, [id, pendingKey, apply, load])
  const send = useCallback(async (kind: ActionKind, fields: ActionFields = {}) => {
    // Tactical commands, especially exit/pause, must survive an in-flight sync.
    if (kind.startsWith('defense_') && kind !== 'defense_sync') {
      while (busyRef.current && mounted.current) await new Promise(resolve => setTimeout(resolve, 25))
      if (!mounted.current) return
    }
    const current = sessionRef.current
    const activityId = fields.activity_id || current?.current_activity?.id
    if (!current || !activityId || busyRef.current || read<Pending>(pendingKey)) return
    await run({ event_key: crypto.randomUUID(), revision: current.revision, action: { ...fields, kind, activity_id: activityId } })
  }, [pendingKey, run])
  const retry = useCallback(async () => { const stored = read<Pending>(pendingKey); if (stored) await run(stored); else await load() }, [pendingKey, run, load])
  useEffect(() => {
    mounted.current = true
    if (!initial) void load()
    const stored = read<Pending>(pendingKey)
    if (stored) { setPending(stored); void run(stored) }
    const wentOnline = () => { setOnline(true); void retry() }, wentOffline = () => setOnline(false)
    window.addEventListener('online', wentOnline); window.addEventListener('offline', wentOffline)
    return () => { mounted.current = false; window.removeEventListener('online', wentOnline); window.removeEventListener('offline', wentOffline) }
  }, [id, load, run, retry, pendingKey, initial])
  const activityId = session?.current_activity?.id, itemId = session?.current_activity?.item?.id
  useEffect(() => {
    if (!activityId || !itemId) { touched.current = false; return }
    const current = sessionRef.current?.current_activity
    const cached = read<{ value: Draft; dirty: boolean }>(draftKey(activityId, itemId))
    const value = cached?.dirty ? cached.value : current?.item?.draft ?? (current?.engine === 'word_builder' ? [] : '')
    touched.current = !!cached?.dirty; draftRef.current = value; setDraft(value); setSaveStatus(cached?.dirty ? 'Đã giữ bản nháp trên thiết bị' : current?.item?.draft != null ? 'Đã khôi phục bản nháp từ máy chủ' : '')
  }, [activityId, itemId])
  const onDraft = (value: Draft) => {
    draftRef.current = value; touched.current = true; setDraft(value); setSaveStatus('Đã giữ bản nháp trên thiết bị')
    if (activityId && itemId) write(draftKey(activityId, itemId), { value, dirty: true })
  }
  const flushDraft = useCallback(async () => {
    const a = sessionRef.current?.current_activity
    if (!a?.item || !touched.current || sessionRef.current?.status !== 'active') return
    const value = draftRef.current
    try {
      if (mounted.current) setSaveStatus('Đang lưu bản nháp…')
      await api('/arcade/sessions/' + id + '/draft', { method: 'PUT', body: { activity_id: a.id, item_id: a.item.id, answer: value } })
      write(draftKey(a.id, a.item.id), { value, dirty: false })
      if (mounted.current) setSaveStatus('Đã lưu bản nháp trên máy chủ')
    } catch { if (mounted.current) setSaveStatus('Bản nháp được giữ trên thiết bị. Sẽ thử lưu khi có kết nối.') }
  }, [id, userId])
  useEffect(() => {
    if (!touched.current || !activityId || !itemId || busy) return
    const timer = setTimeout(() => void flushDraft(), 600)
    return () => clearTimeout(timer)
  }, [draft, activityId, itemId, busy, flushDraft])
  const dismissFeedback = () => { if (sessionRef.current) apply({ ...sessionRef.current, feedback: null }) }
  return { session, busy: busy || !!pending, isSending: busy, error, pending, draft, onDraft, saveStatus, online, send, retry, dismissFeedback, flushDraft, load }
}
