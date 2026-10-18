import { StudentPicker } from '../components/StudentPicker'
import { QuestionImage } from '../components/QuestionMedia'
import { Icon } from '../components/Icon'
import { useEffect, useRef, useState } from 'react'
import { api, downloadFile } from '../api'
import { Button, Empty, ErrorBox, Modal, PageHead, QuestionInput, Skeleton, displayDate, useResource, type Answer, type Notify, type Question, type User } from '../components/ui'
import { QuestionEditor, draftFromQuestion, newQuestion, serializeQuestion, type DraftQuestion } from './question-authoring'

type Assignment = { id: number; title: string; instructions: string; due_at: string; max_score: number; student_ids: number[]; published: boolean; question_count?: number; student_count: number; submitted_count: number; allowed_attempts: number; grade_policy: string; attachments?: { id: number; name: string }[] }
type Attempt = { id: number; student_id: number; attempt_number: number; answers: Record<string, Answer>; questions: Question[]; submitted_at: string | null; expires_at: string; score: number | null; status: string; feedback?: string }
const dueInput = () => { const d = new Date(Date.now() + 4 * 86400000); return new Date(d.getTime() + 7 * 3600000).toISOString().slice(0, 16) }

function CreateHomework({ onClose, onSaved, notify }: { onClose: () => void; onSaved: () => void; notify: Notify }) {
  const [subjectId, setSubjectId] = useState(''), [title, setTitle] = useState(''), [instructions, setInstructions] = useState(''), [studentIds, setStudentIds] = useState<number[]>([]), [due, setDue] = useState(dueInput())
  const [questions, setQuestions] = useState<DraftQuestion[]>([newQuestion()]), [attempts, setAttempts] = useState(2), [policy, setPolicy] = useState('highest'), [minutes, setMinutes] = useState(0), [busy, setBusy] = useState(false), [error, setError] = useState('')
  const created = useRef<number | null>(null)
  const subjects = useResource<{ id: number; name: string }[]>('/subjects')
  const banks = useResource<{ id: number; name: string }[]>('/question-banks')
  async function save(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setError('')
    const payload = { title, instructions, subject_id: subjectId ? Number(subjectId) : null, student_ids: studentIds, due_at: due.replace('T', ' ') + ':00', submission_type: 'both', max_score: 10, published: false }
    try {
      if (!created.current) created.current = (await api<{ id: number }>('/assignments', { method: 'POST', body: payload })).id
      await api('/assignments/' + created.current + '/questions', { method: 'PUT', body: { allowed_attempts: attempts, grade_policy: policy, time_limit_seconds: minutes ? minutes * 60 : null, questions: questions.map(serializeQuestion) } })
      await api('/assignments/' + created.current, { method: 'PUT', body: { ...payload, published: true } })
      notify('Đã giao bài tập và thông báo cho học sinh.'); onSaved(); onClose()
    } catch (err) { setError((err as Error).message) } finally { setBusy(false) }
  }
  return <Modal title="Tạo bài tập" onClose={onClose} wide><form onSubmit={save}><div className="form-grid"><label>Tên bài tập<input required value={title} onChange={e => setTitle(e.target.value)}/></label><label>Môn học<select value={subjectId} onChange={e => setSubjectId(e.target.value)}><option value="">Chưa chọn môn</option>{subjects.data?.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label><StudentPicker value={studentIds} onChange={setStudentIds}/><label className="full-width">Hướng dẫn<textarea required rows={3} value={instructions} onChange={e => setInstructions(e.target.value)}/></label><label>Hạn nộp (giờ Việt Nam)<input required type="datetime-local" value={due} onChange={e => setDue(e.target.value)}/></label><label>Thời gian làm bài (phút, 0 = không giới hạn)<input type="number" min={0} max={240} value={minutes} onChange={e => setMinutes(Number(e.target.value))}/></label><label>Số lượt (0 = luyện tập không giới hạn)<input type="number" min={0} max={100} required value={attempts} onChange={e => setAttempts(Number(e.target.value))}/></label><label>Cách tính điểm cuối<select value={policy} onChange={e => setPolicy(e.target.value)}><option value="highest">Điểm cao nhất</option><option value="first">Lần đầu</option><option value="latest">Lần gần nhất</option></select></label></div>
    <label>Thêm từ ngân hàng câu hỏi<select value="" onChange={async e => { const id = Number(e.target.value); if (!id) return; try { const bank = await api<{ questions: Question[] }>('/question-banks/' + id); setQuestions([...questions.filter(q => q.prompt.trim()), ...bank.questions.map(draftFromQuestion)]) } catch (err) { setError((err as Error).message) } }}><option value="">Chọn bộ câu hỏi đã lưu…</option>{banks.data?.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
    {questions.map((q, i) => <QuestionEditor key={i} question={q} index={i} onChange={updated => setQuestions(questions.map((v, n) => n === i ? updated : v))} onRemove={() => setQuestions(questions.filter((_, n) => n !== i))}/>)}<Button type="button" secondary onClick={() => setQuestions([...questions, newQuestion()])}><Icon name="plus"/> Thêm câu hỏi</Button><ErrorBox message={error}/><div className="form-actions"><Button disabled={busy || !questions.length || !studentIds.length}>{busy ? 'Đang lưu…' : 'Giao bài cho học sinh'}</Button></div></form></Modal>
}

function TakeHomework({ assignment, onClose, notify, user }: { assignment: Assignment; onClose: () => void; notify: Notify; user: User }) {
  const [attempt, setAttempt] = useState<Attempt | null>(null), [answers, setAnswers] = useState<Record<string, Answer>>({}), [error, setError] = useState(''), [status, setStatus] = useState(''), [busy, setBusy] = useState(false), [confirm, setConfirm] = useState(false), [seconds, setSeconds] = useState(0)
  const latest = useRef(answers), chain = useRef(Promise.resolve()), saved = useRef(true), draftClosed = useRef(false)
  const cacheKey = attempt ? 'eduquest-draft-' + user.id + '-' + attempt.id : ''
  useEffect(() => {
    let current = true
    api<Attempt>('/assignments/' + assignment.id + '/start', { method: 'POST' }).then(a => {
      if (!current) return
      const key = 'eduquest-draft-' + user.id + '-' + a.id
      let restored = a.answers
      try { const cache = sessionStorage.getItem(key); if (cache) restored = JSON.parse(cache) } catch { /* server draft remains available */ }
      draftClosed.current = !!a.submitted_at
      setAttempt(a); setAnswers(restored); latest.current = restored
    }).catch((err: Error) => { if (current) setError(err.message) })
    return () => { current = false }
  }, [assignment.id, user.id])
  useEffect(() => {
    if (!attempt) return
    const deadline = new Date(attempt.expires_at.replace(' ', 'T') + (attempt.expires_at.includes('+') || attempt.expires_at.endsWith('Z') ? '' : '+07:00')).getTime()
    const tick = () => setSeconds(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)))
    tick(); const timer = setInterval(tick, 1000); return () => clearInterval(timer)
  }, [attempt])
  async function save() {
    if (!attempt || attempt.submitted_at || draftClosed.current) return
    const snapshot = latest.current
    setStatus('Đang lưu…')
    const pending = chain.current.then(async () => {
      if (draftClosed.current) return
      try { await api('/attempts/' + attempt.id, { method: 'PUT', body: { answers: snapshot } }); saved.current = snapshot === latest.current; setStatus(saved.current ? 'Đã lưu trên máy chủ' : 'Có thay đổi chưa lưu'); setError('') } catch (err) { saved.current = false; setStatus('Bản nháp đang được giữ trên thiết bị'); setError((err as Error).message) }
    })
    chain.current = pending
    await pending
  }
  useEffect(() => {
    if (!attempt || attempt.submitted_at) return
    const timer = setTimeout(() => { void save() }, 1000)
    return () => clearTimeout(timer)
  }, [answers, attempt?.id, attempt?.submitted_at])
  useEffect(() => {
    const handler = (e: BeforeUnloadEvent) => { if (!saved.current) { e.preventDefault(); e.returnValue = '' } }
    window.addEventListener('beforeunload', handler); return () => window.removeEventListener('beforeunload', handler)
  }, [])
  function change(id: number, answer: Answer) {
    const next = { ...latest.current, [id]: answer }; latest.current = next; saved.current = false; setAnswers(next); setStatus('Đang giữ bản nháp trên thiết bị')
    try { sessionStorage.setItem(cacheKey, JSON.stringify(next)) } catch { setError('Thiết bị không cho lưu bản nháp. Hãy dùng nút Lưu tiến độ.') }
  }
  async function submit() {
    if (!attempt || draftClosed.current) return
    draftClosed.current = true
    setBusy(true)
    try {
      await chain.current
      const result = await api<Attempt>('/attempts/' + attempt.id + '/submit', { method: 'POST', body: { answers: latest.current } })
      setAttempt(result); sessionStorage.removeItem(cacheKey); saved.current = true; setConfirm(false); setError(''); notify('Bài làm đã được nộp và lưu.')
    } catch (err) { draftClosed.current = false; setError((err as Error).message); setConfirm(false) } finally { setBusy(false) }
  }
  return <Modal title={assignment.title} onClose={() => { void save().then(onClose) }} wide><ErrorBox message={error}/>{!attempt ? <Skeleton/> : attempt.submitted_at ? <div className="result-card"><span><Icon name="sparkle"/></span><h3>Đã hoàn thành lượt {attempt.attempt_number}</h3><strong>{attempt.status === 'graded' ? Number(attempt.score) + '/' + Number(assignment.max_score) : 'Chờ chấm'}</strong><p>Thời gian nộp: {displayDate(attempt.submitted_at)}</p><p>{attempt.feedback}</p><Button onClick={onClose}>Xem lịch sử làm bài</Button></div> : <>
    <div className="attempt-toolbar"><span>Lượt {attempt.attempt_number}</span><span className={seconds < 60 ? 'timer urgent' : 'timer'} role="timer">{Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}</span><span role="status">{status}</span></div>
    <p>{assignment.instructions}</p>{attempt.questions.map((q, i) => <section className="question-card" key={q.id}><span className="eyebrow">Câu {i + 1} · {q.points} điểm</span><h3>{q.prompt}</h3><QuestionImage media={q.media}/>{q.type === 'image' && !q.media && q.media_url && <img className="question-image" src={q.media_url} alt={q.prompt}/>} {q.type === 'audio' && q.media_url && <audio controls src={q.media_url} preload="none"/>}<QuestionInput question={q} value={answers[q.id]} onChange={a => change(Number(q.id), a)} disabled={busy || seconds === 0} uploadPath={'/attempts/' + attempt.id + '/files'}/></section>)}
    {seconds === 0 && <p role="alert">Đã hết giờ. Nộp các câu trả lời đã lưu để nhận kết quả.</p>}<div className="form-actions"><Button secondary disabled={busy || seconds === 0} onClick={() => void save()}>Lưu tiến độ</Button><Button disabled={busy} onClick={() => setConfirm(true)}>Nộp bài</Button></div>{confirm && <div className="confirm-box" role="alertdialog" aria-label="Xác nhận nộp bài"><p>Nộp bài làm này? Sau khi nộp, câu trả lời sẽ được lưu vào lịch sử và không thể sửa.</p><div className="form-actions"><Button secondary disabled={busy} onClick={() => setConfirm(false)}>Tiếp tục làm</Button><Button disabled={busy} onClick={() => void submit()}>{busy ? 'Đang nộp…' : 'Xác nhận nộp'}</Button></div></div>}</>}</Modal>
}

export function Homework({ user, notify, legacy }: { user: User; notify: Notify; legacy?: React.ReactNode }) {
  const { data, error, loading, reload } = useResource<Assignment[]>('/assignments')
  const [creating, setCreating] = useState(false), [selected, setSelected] = useState<Assignment | null>(null), [taking, setTaking] = useState(false), [history, setHistory] = useState<Attempt[]>([]), [grade, setGrade] = useState<Record<number, { score: string; feedback: string; reason: string }>>({}), [tab, setTab] = useState('questions')
  async function open(a: Assignment) {
    try { const detail = await api<Assignment>('/assignments/' + a.id); setSelected(detail); setHistory(await api<Attempt[]>('/assignments/' + a.id + '/attempts')) } catch (err) { notify((err as Error).message, true) }
  }
  return <><PageHead title="Bài tập" description="Giao kiến thức. Theo dõi tiến bộ. Ghi nhận từng nỗ lực." action={user.role !== 'student' ? <Button onClick={() => setCreating(true)}><Icon name="plus"/> Tạo bài tập có câu hỏi</Button> : undefined}/><div className="segment admin-tabs"><button className={tab === 'questions' ? 'selected' : ''} onClick={() => setTab('questions')}>Bài tập & lượt làm</button><button className={tab === 'files' ? 'selected' : ''} onClick={() => setTab('files')}>Nộp văn bản / tệp</button></div>{tab === 'files' ? legacy : <><ErrorBox message={error}/>{loading ? <Skeleton/> : <div className="assignment-grid">{data?.map(a => <article className="panel assignment-card" key={a.id}><div className="panel-header"><span className="pill">{a.published ? 'Đã giao' : 'Bản nháp'}</span><span className="muted">{a.student_count} học sinh</span></div><h2>{a.title}</h2><p>{a.instructions}</p><small>Hạn nộp {displayDate(a.due_at)}</small><div className="assignment-footer"><span>{a.allowed_attempts === 0 ? 'Luyện tập' : a.allowed_attempts + ' lượt'} · {a.max_score} điểm</span><Button secondary onClick={() => void open(a)}>Xem bài tập <Icon name="arrow_right"/></Button></div></article>)}{!data?.length && <Empty>Chưa có bài tập. Tạo bài đầu tiên để bắt đầu.</Empty>}</div>}</>}
    {creating && <CreateHomework onClose={() => setCreating(false)} onSaved={reload} notify={notify}/>}
    {selected && !taking && <Modal title={selected.title} onClose={() => setSelected(null)} wide><p>{selected.instructions}</p><p className="muted">Hạn nộp {displayDate(selected.due_at)} · Điểm tối đa {selected.max_score}</p>{selected.attachments?.map(f => <button className="file-row" key={f.id} onClick={() => downloadFile(f).catch((err: Error) => notify(err.message, true))}><Icon name="download"/> {f.name}</button>)}{user.role === 'student' && !!selected.question_count && <Button onClick={() => setTaking(true)}>Bắt đầu / tiếp tục làm bài</Button>}{!selected.question_count && <p className="soft-box">Bài tập này nhận văn bản hoặc tệp. Mở mục “Nộp văn bản / tệp” để làm bài.</p>}<h3>Lịch sử lượt làm</h3>{history.map(a => <article className="submission" key={a.id}><strong>Lượt {a.attempt_number}{user.role !== 'student' && ' · Học sinh #' + a.student_id}</strong><p>{a.submitted_at ? displayDate(a.submitted_at) : 'Đang làm'} · {a.status === 'graded' ? a.score + '/' + selected.max_score : 'Chờ hoàn thành / chấm'}</p>{a.feedback && <p>{a.feedback}</p>}<details><summary>Xem bài làm</summary>{a.questions.map(q => <div className="review-answer" key={q.id}><strong>{q.prompt}</strong><p>{typeof a.answers[q.id] === 'object' ? JSON.stringify(a.answers[q.id]) : String(a.answers[q.id] ?? 'Chưa trả lời')}</p>{q.type === 'file' && a.answers[q.id] && <Button secondary onClick={() => downloadFile({ id: Number(a.answers[q.id]), name: 'bai-lam' }).catch((err: Error) => notify(err.message, true))}>Tải tệp bài làm</Button>}{q.answer !== undefined && <p>Đáp án: {typeof q.answer === 'object' ? JSON.stringify(q.answer) : String(q.answer)}</p>}</div>)}</details>{user.role !== 'student' && a.submitted_at && <form className="grade-form" onSubmit={async e => { e.preventDefault(); try { await api('/attempts/' + a.id + '/grade', { method: 'POST', body: grade[a.id] }); notify('Đã lưu điểm.'); void open(selected) } catch (err) { notify((err as Error).message, true) } }}><label>Điểm<input type="number" required min={0} max={selected.max_score} step={0.1} value={grade[a.id]?.score ?? ''} onChange={e => setGrade({ ...grade, [a.id]: { score: e.target.value, feedback: grade[a.id]?.feedback || '', reason: grade[a.id]?.reason || '' } })}/></label><label>Nhận xét<textarea required value={grade[a.id]?.feedback || ''} onChange={e => setGrade({ ...grade, [a.id]: { score: grade[a.id]?.score || '', feedback: e.target.value, reason: grade[a.id]?.reason || '' } })}/></label>{a.status === 'graded' && <label>Lý do điều chỉnh<input required value={grade[a.id]?.reason || ''} onChange={e => setGrade({ ...grade, [a.id]: { score: grade[a.id]?.score || '', feedback: grade[a.id]?.feedback || '', reason: e.target.value } })}/></label>}<Button>Chấm bài</Button></form>}</article>)}{!history.length && <Empty>Chưa có lượt làm bài.</Empty>}</Modal>}
    {selected && taking && <TakeHomework assignment={selected} user={user} notify={notify} onClose={() => { setTaking(false); void open(selected); reload() }}/>}
  </>
}
