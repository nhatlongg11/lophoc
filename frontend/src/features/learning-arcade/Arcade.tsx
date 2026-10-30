import { lazy, Suspense, useEffect, useState } from 'react'
import { api } from '../../api'
import { Button, Empty, ErrorBox, Skeleton, type Notify, type User } from '../../components/ui'
import { Icon } from '../../components/Icon'
import type { Session } from './types'
import './arcade.css'
const StudentHub = lazy(() => import('./StudentHub')), TeacherArcade = lazy(() => import('./TeacherArcade')), RoundEditor = lazy(() => import('./RoundEditor')), GameShell = lazy(() => import('./GameShell')), Legacy = lazy(() => import('../games').then(m => ({ default: m.GameHub })))
const route = () => { const hash = window.location.hash; const play = hash.match(/^#arcade\/(?:play|results)\/(\d+)$/); const editor = hash.match(/^#arcade\/builder\/(new|\d+)$/); return { linkedRound: hash.match(/^#arcade\/round\/(\d+)$/)?.[1] || null, session: play ? Number(play[1]) : null, editor: editor ? editor[1] === 'new' ? 0 : Number(editor[1]) : null, tab: ['mistakes', 'progress', 'leaderboard'].find(tab => hash === '#arcade/' + tab) || 'rounds', legacy: hash === '#arcade/legacy' } }
export default function Arcade({ user, notify }: { user: User; notify: Notify }) {
  const [grade, setGrade] = useState('')
  const initialRoute = route()
  const [linkedRound, setLinkedRound] = useState(initialRoute.linkedRound), [sessionId, setSessionId] = useState<number | null>(initialRoute.session), [initialSession, setInitialSession] = useState<Session | undefined>(), [editorId, setEditorId] = useState<number | null>(initialRoute.editor), [tab, setTab] = useState(initialRoute.tab), [legacy, setLegacy] = useState(initialRoute.legacy), [error, setError] = useState(''), [busy, setBusy] = useState(false)
  useEffect(() => { const changed = () => { const next = route(); setLinkedRound(next.linkedRound); setSessionId(next.session); setInitialSession(current => current?.id === next.session ? current : undefined); if (!next.session) setEditorId(next.editor); setTab(next.tab); setLegacy(next.legacy); setError('') }; window.addEventListener('hashchange', changed); return () => window.removeEventListener('hashchange', changed) }, [])
  const play = (session: Session) => { setInitialSession(session); setSessionId(session.id); setError(''); window.location.hash = '#arcade/play/' + session.id }
  const edit = (id: number) => { setEditorId(id); setSessionId(null); window.location.hash = '#arcade/builder/' + (id || 'new') }
  const hub = (next = 'rounds') => { setSessionId(null); setInitialSession(undefined); setEditorId(null); setLegacy(false); setTab(next); window.location.hash = next === 'rounds' ? '#arcade' : '#arcade/' + next }
  async function start(roundId: number, assignmentId?: number, preview = false) {
    if (busy) return
    setBusy(true); setError('')
    try { play(await api<Session>('/arcade/rounds/' + roundId + (preview ? '/preview' : '/start'), { method: 'POST', body: preview ? undefined : { assignment_id: assignmentId || null } })) } catch (err) { setError((err as Error).message); notify((err as Error).message, true) } finally { setBusy(false) }
  }
  useEffect(() => {
    if (!linkedRound || !['teacher', 'admin', 'student'].includes(user.role)) return
    let current = true
    setBusy(true); setError('')
    api<Session>('/arcade/rounds/' + linkedRound + (user.role === 'student' ? '/start' : '/preview'), { method: 'POST' }).then(session => { if (current) { setBusy(false); play(session) } }).catch(err => { if (current) setError((err as Error).message) }).finally(() => { if (current) setBusy(false) })
    return () => { current = false }
  }, [linkedRound, user.role])
  if (!['teacher', 'admin', 'student'].includes(user.role)) return <Empty>Learning Arcade dành cho học sinh, giáo viên và quản trị viên được phân quyền.</Empty>
  return <div className="learning-arcade"><ErrorBox message={error}/>{busy && <p className="arcade-opening" role="status">Đang mở vòng học…</p>}<Suspense fallback={<Skeleton/>}>{sessionId ? <GameShell key={sessionId} id={sessionId} userId={user.id} initial={initialSession} onLeave={next => user.role !== 'student' && editorId !== null ? edit(editorId) : hub(next)} onRestart={s => void start(s.round_id, s.assignment_id || undefined, s.is_preview)}/> : legacy ? <><Button secondary onClick={() => hub()}><Icon name="arrow_left"/> Về Learning Arcade</Button><Legacy user={user} notify={notify} initialGrade={grade}/></> : editorId !== null && user.role !== 'student' ? <RoundEditor initialGrade={grade} roundId={editorId} notify={notify} onClose={() => hub()} onSaved={id => { setEditorId(id); window.history.replaceState(null, '', '#arcade/builder/' + id) }} onPreview={play}/> : <>{user.role === 'student' ? <StudentHub grade={grade} setGrade={setGrade} user={user} tab={tab} setTab={next => hub(next)} onStart={(id, assignment) => void start(id, assignment)} onSession={id => { setInitialSession(undefined); setSessionId(id); window.location.hash = '#arcade/results/' + id }}/> : <TeacherArcade grade={grade} setGrade={setGrade} onEdit={edit} onPreview={play} notify={notify}/>}<div className="arcade-legacy-link"><button className="text-link" onClick={() => { setLegacy(true); window.location.hash = '#arcade/legacy' }}>Trò chơi trước đây <Icon name="arrow_right"/></button><small>Giữ nguyên trò chơi, kết quả và XP đã có của CE - Connecting Education.</small></div></>}</Suspense></div>
}
