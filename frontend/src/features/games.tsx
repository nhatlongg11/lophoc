import { GradePicker, GradeSelect, gradeLabel, gradeParams } from '../components/GradePicker'
import { ContentClassification } from '../components/ContentClassification'
import { SubjectFilter, subjectParams, type Subject } from '../components/SubjectFilter'
import { StudentPicker } from '../components/StudentPicker'
import { Icon, type IconName } from '../components/Icon'
import { useEffect, useRef, useState } from 'react'
import { api } from '../api'
import { Button, Empty, ErrorBox, Modal, PageHead, Panel, QuestionInput, Skeleton, displayDate, useResource, type Answer, type Notify, type Question, type User } from '../components/ui'
import { QuestionEditor, newQuestion, serializeQuestion, type DraftQuestion } from './question-authoring'

const modes: Record<string, { name: string; symbol: IconName; description: string; type: string; color: string }> = {
  quiz: { name: 'Chớp nhanh kiến thức', symbol: 'quiz', description: 'Chọn đáp án, giữ nhịp và chinh phục thời gian.', type: 'single', color: 'mint' },
  matching: { name: 'Đôi bạn tri thức', symbol: 'matching', description: 'Kết nối từ vựng và ý nghĩa qua từng cặp ghép.', type: 'matching', color: 'sky' },
  word_builder: { name: 'Xưởng chữ kỳ diệu', symbol: 'word_builder', description: 'Sắp các chữ cái để mở ra một từ mới.', type: 'word_builder', color: 'peach' },
  fill_blank: { name: 'Mảnh ghép còn thiếu', symbol: 'fill_blank', description: 'Tìm từ đúng để hoàn thiện câu chuyện.', type: 'fill_blank', color: 'lemon' },
  listening: { name: 'Đôi tai khám phá', symbol: 'listening', description: 'Lắng nghe, suy nghĩ và chọn đáp án.', type: 'audio', color: 'sky' },
  challenge: { name: 'Hành trình thử thách', symbol: 'challenge', description: 'Chinh phục nhiều dạng câu hỏi trong một vòng.', type: 'single', color: 'peach' },
}
type Game = { id: number; grade?: string | null; subject_id?: number | null; subject_name?: string | null; title: string; type: string; student_ids: number[]; question_count: number; attempt_count?: number; best_score?: number; required_xp: number; allowed_attempts: number; time_limit_seconds?: number; questions?: Question[] }
type Result = { score: number; points: number; correct_count: number; incorrect_count: number; xp_awarded: number; feedback: { id: number; correct: boolean; answer: Answer }[] }
type Profile = { xp: number; level: number; streak: number; achievements: { id: number; code: string }[]; history: { id: number; title: string; score: number; points: number; created_at: string }[] }
type Session = { session_id: number; expires_at: string; questions: Question[] }

function CreateGame({ onClose, onSaved, notify, initialSubject, initialGrade }: { onClose: () => void; onSaved: () => void; notify: Notify; initialSubject: string; initialGrade: string }) {
  const [grade, setGrade] = useState(initialGrade === 'unclassified' ? '' : initialGrade)
  const [subject, setSubject] = useState(initialSubject === 'unassigned' ? '' : initialSubject)
  const subjects = useResource<Subject[]>('/subjects')
  const [title, setTitle] = useState(''), [mode, setMode] = useState('quiz'), [studentIds, setStudentIds] = useState<number[]>([]), [minutes, setMinutes] = useState(5), [attempts, setAttempts] = useState(10), [xp, setXp] = useState(0)
  const [questions, setQuestions] = useState<DraftQuestion[]>([newQuestion()]), [error, setError] = useState(''), [busy, setBusy] = useState(false)
  async function save(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setError('')
    try { await api('/games', { method: 'POST', body: { title, grade, subject_id: subject ? Number(subject) : null, type: mode, student_ids: studentIds, time_limit_seconds: minutes * 60, allowed_attempts: attempts, required_xp: xp, questions: questions.map(serializeQuestion) } }); notify('Đã tạo vòng chơi và thông báo cho học sinh.'); onSaved(); onClose() } catch (err) { setError((err as Error).message) } finally { setBusy(false) }
  }
  return <Modal title="Tạo vòng chơi" onClose={onClose} wide><form onSubmit={save}><GradeSelect value={grade} onChange={setGrade}/><label>Môn học<select value={subject} onChange={e => setSubject(e.target.value)}><option value="">Chưa phân môn</option>{subjects.data?.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label><div className="form-grid"><label>Tên vòng chơi<input required value={title} onChange={e => setTitle(e.target.value)}/></label><StudentPicker value={studentIds} onChange={setStudentIds}/><label>Chế độ<select value={mode} onChange={e => { setMode(e.target.value); setQuestions([newQuestion(modes[e.target.value].type)]) }}>{Object.entries(modes).map(([key, value]) => <option key={key} value={key}>{value.name}</option>)}</select></label><label>Thời gian (phút)<input type="number" required min={1} max={60} value={minutes} onChange={e => setMinutes(Number(e.target.value))}/></label><label>Số lượt chơi<input type="number" required min={1} max={100} value={attempts} onChange={e => setAttempts(Number(e.target.value))}/></label><label>XP cần có để mở vòng<input type="number" required min={0} max={100000} value={xp} onChange={e => setXp(Number(e.target.value))}/></label></div>{questions.map((q, i) => <QuestionEditor key={i} question={q} index={i} allowedTypes={mode === 'challenge' ? ['single', 'multiple', 'fill_blank', 'matching', 'ordering', 'word_builder'] : [modes[mode].type]} onChange={next => setQuestions(questions.map((v, j) => i === j ? next : v))} onRemove={() => setQuestions(questions.filter((_, j) => j !== i))}/>)}<Button secondary type="button" onClick={() => setQuestions([...questions, newQuestion(modes[mode].type)])}><Icon name="plus"/> Thêm câu hỏi</Button>{mode === 'listening' && <p className="soft-box">Chỉ dùng âm thanh do thầy cô tạo hoặc có quyền sử dụng. Cung cấp nội dung câu hỏi rõ ràng cho người học.</p>}<ErrorBox message={error}/><div className="form-actions"><Button disabled={busy || !questions.length || !studentIds.length}>{busy ? 'Đang lưu…' : 'Giao vòng chơi'}</Button></div></form></Modal>
}

function GameSession({ game, user, onClose, onComplete, notify }: { game: Game; user: User; onClose: () => void; onComplete: () => void; notify: Notify }) {
  const [session, setSession] = useState<Session | null>(null), [index, setIndex] = useState(0), [answers, setAnswers] = useState<Record<string, Answer>>({}), [seconds, setSeconds] = useState<number | null>(null), [error, setError] = useState(''), [busy, setBusy] = useState(false), [result, setResult] = useState<Result | null>(null), [muted, setMuted] = useState(true), [confirm, setConfirm] = useState(false)
  const latest = useRef(answers), claimed = useRef(false)
  const mode = modes[game.type] || modes.quiz
  useEffect(() => {
    let current = true
    api<Session>('/games/' + game.id + '/start', { method: 'POST' }).then(s => {
      if (!current) return
      setSession(s)
      try { const previous = sessionStorage.getItem('eduquest-game-' + user.id + '-' + s.session_id); if (previous) { const restored = JSON.parse(previous); setAnswers(restored); latest.current = restored } } catch { /* an empty answer map is safe */ }
    }).catch((err: Error) => { if (current) setError(err.message) })
    return () => { current = false }
  }, [game.id, user.id])
  useEffect(() => {
    if (!session || result) return
    const tick = () => setSeconds(Math.max(0, Math.ceil((new Date(session.expires_at).getTime() - Date.now()) / 1000)))
    tick(); const timer = setInterval(tick, 250); return () => clearInterval(timer)
  }, [session, result])
  useEffect(() => { if (seconds === 0 && session && !result && !claimed.current) void finish() }, [seconds])
  async function finish() {
    if (!session || claimed.current) return
    claimed.current = true; setBusy(true); setError('')
    try {
      const r = await api<Result>('/games/' + game.id + '/attempt', { method: 'POST', body: { session_id: session.session_id, answers: latest.current } })
      setResult(r); onComplete(); sessionStorage.removeItem('eduquest-game-' + user.id + '-' + session.session_id); notify('Đã lưu kết quả vòng chơi.')
      if (!muted) {
        try { const ctx = new AudioContext(); const oscillator = ctx.createOscillator(); const gain = ctx.createGain(); oscillator.connect(gain); gain.connect(ctx.destination); gain.gain.setValueAtTime(0.08, ctx.currentTime); oscillator.frequency.value = 660; oscillator.start(); oscillator.stop(ctx.currentTime + 0.18); oscillator.onended = () => void ctx.close() } catch { /* sound is optional */ }
      }
    } catch (err) { setError((err as Error).message); claimed.current = false } finally { setBusy(false) }
  }
  const question = session?.questions[index]
  return <Modal title={game.title} onClose={() => setConfirm(true)} wide><ErrorBox message={error}/>{!session ? error ? <Button secondary onClick={onClose}>Về thư viện trò chơi</Button> : <Skeleton/> : result ? <div className="result-card"><span><Icon name="sparkle"/></span><span className="eyebrow">MỘT BƯỚC TIẾN MỚI</span><h3>Hoàn thành thử thách!</h3><strong>{result.points} điểm</strong><p>{result.correct_count}/{result.correct_count + result.incorrect_count} câu đúng · {result.score}/10 · +{result.xp_awarded} XP</p>{result.xp_awarded === 0 && <p className="muted">Mỗi vòng chỉ nhận thưởng XP một lần khi có câu đúng.</p>}<div className="review-list">{result.feedback.map((f, i) => <div className="list-row" key={f.id}><strong>Câu {i + 1}</strong><span className={'pill ' + (f.correct ? 'ok' : 'warning')}>{f.correct ? 'Chính xác' : 'Cần ôn lại'}</span><span>{typeof f.answer === 'object' ? JSON.stringify(f.answer) : String(f.answer)}</span></div>)}</div><Button onClick={onClose}>Về thư viện · Chơi lại</Button></div> : question && <>
    <div className="game-status"><span className="game-symbol"><Icon name={mode.symbol}/></span><div><span className="eyebrow">{mode.name}</span><b>Câu {index + 1}/{session.questions.length}</b></div><span className={'timer ' + ((seconds || 0) < 30 ? 'urgent' : '')} role="timer">{Math.floor((seconds || 0) / 60)}:{String((seconds || 0) % 60).padStart(2, '0')}</span><button className="sound-control" aria-label={muted ? 'Bật âm thanh kết quả' : 'Tắt âm thanh kết quả'} onClick={() => setMuted(!muted)}>{muted ? 'Âm thanh: tắt' : 'Âm thanh: bật'}</button></div>
    <progress className="game-progress" max={session.questions.length} value={Object.keys(answers).length} aria-label="Tiến độ câu trả lời"/><div className="active-question"><h3>{question.prompt}</h3>{question.type === 'audio' && question.media_url && <audio controls preload="auto" src={question.media_url}/>}<QuestionInput question={question} value={answers[question.id]} disabled={busy || seconds === 0} onChange={answer => { const next = { ...latest.current, [question.id]: answer }; setAnswers(next); latest.current = next; try { sessionStorage.setItem('eduquest-game-' + user.id + '-' + session.session_id, JSON.stringify(next)) } catch { setError('Thiết bị không hỗ trợ lưu tiến độ vòng chơi.') } }}/></div><div className="form-actions"><Button secondary disabled={index === 0 || busy} onClick={() => setIndex(index - 1)}><Icon name="arrow_left"/> Câu trước</Button>{index < session.questions.length - 1 ? <Button disabled={busy} onClick={() => setIndex(index + 1)}>Câu tiếp theo <Icon name="arrow_right"/></Button> : <Button disabled={busy} onClick={() => void finish()}>{busy ? 'Đang tính điểm…' : 'Hoàn thành vòng chơi'}</Button>}</div><p className="muted">Kết quả chính thức được tính khi nộp vòng chơi. Thời gian vẫn tiếp tục khi rời màn hình.</p>
  </>}{confirm && <div className="confirm-box" role="alertdialog" aria-label="Rời vòng chơi"><p>Rời vòng chơi? Đồng hồ vẫn tiếp tục. Em có thể trở lại phiên đang chơi khi còn thời gian.</p><Button secondary onClick={() => setConfirm(false)}>Tiếp tục chơi</Button><Button onClick={onClose}>Rời vòng chơi</Button></div>}</Modal>
}

export function GameHub({ user, notify, initialGrade = '' }: { user: User; notify: Notify; initialGrade?: string }) {
  const [grade, setGrade] = useState(initialGrade), [classification, setClassification] = useState<Game | null>(null)
  const [subject, setSubject] = useState('')
  const subjects = useResource<Subject[]>('/subjects')
  const { data, error, loading, reload } = useResource<Game[]>(grade ? '/games?' + new URLSearchParams({ ...subjectParams(subject), ...gradeParams(grade) }) : null), profile = useResource<Profile>(user.role === 'student' ? '/games-profile' : null)
  const [creating, setCreating] = useState(false), [selected, setSelected] = useState<Game | null>(null), [mode, setMode] = useState('all'), [period, setPeriod] = useState('week')
  const rankings = useResource<{ enabled: boolean; rows: { alias: string; points: number; is_me: boolean }[] }>('/leaderboard?period=' + period)
  const games = data?.filter(g => mode === 'all' || g.type === mode)
  return <><PageHead title="Khám phá tri thức" description="Mỗi thử thách là một cơ hội để tiến bộ." action={user.role !== 'student' && grade ? <Button onClick={() => setCreating(true)}><Icon name="plus"/> Tạo vòng chơi</Button> : undefined}/>
    {profile.data && <div className="quest-banner"><div><span className="eyebrow">HÀNH TRÌNH CỦA EM</span><h2>Nhà khám phá · Cấp {profile.data.level}</h2><p>{profile.data.streak} ngày luyện tập liên tiếp · {profile.data.achievements.length} huy hiệu</p><progress max={200} value={profile.data.xp % 200} aria-label="XP đến cấp tiếp theo"/></div><div className="xp-orb"><strong>{profile.data.xp}</strong><span>XP TÍCH LŨY</span></div></div>}
    <GradePicker value={grade} onChange={value => { setGrade(value); setSubject('') }}/>{grade && <><SubjectFilter subjects={subjects.data || []} value={subject} onChange={setSubject} includeUnassigned/><ErrorBox message={subjects.error}/><div className="mode-filter" aria-label="Lọc chế độ"><button className={mode === 'all' ? 'selected' : ''} onClick={() => setMode('all')}>Tất cả</button>{Object.entries(modes).map(([key, value]) => <button key={key} className={mode === key ? 'selected' : ''} onClick={() => setMode(key)}><Icon name={value.symbol}/> {value.name}</button>)}</div><ErrorBox message={error || profile.error}/>{loading ? <Skeleton/> : <div className="game-grid">{games?.map(g => { const m = modes[g.type] || modes.quiz, locked = user.role === 'student' && (profile.data?.xp || 0) < g.required_xp; return <article className={'game-card ' + m.color} key={g.id}><span className="game-symbol"><Icon name={m.symbol}/></span><span className="eyebrow">{m.name}</span><small>{g.grade ? gradeLabel(g.grade) : 'Chưa phân lớp'} · {g.subject_name || 'Chưa phân môn'}</small><h2>{g.title}</h2><p>{m.description}</p><small>{g.question_count} câu hỏi · {Math.round((g.time_limit_seconds || 600) / 60)} phút</small><p className="muted">{g.required_xp > 0 ? 'Mở từ ' + g.required_xp + ' XP' : 'Sẵn sàng khám phá'}{g.attempt_count !== undefined && ' · ' + g.attempt_count + ' lượt đã chơi'}</p><Button secondary disabled={locked} onClick={async () => { if (user.role === 'student') setSelected(g); else { try { setSelected(await api<Game>('/games/' + g.id)) } catch (err) { notify((err as Error).message, true) } } }}>{locked ? 'Chưa đủ XP' : user.role === 'student' ? <>Bắt đầu thử thách <Icon name="arrow_right"/></> : 'Xem câu hỏi'}</Button>{user.role !== 'student' && <Button secondary onClick={() => setClassification(g)}>Phân lớp & môn</Button>}</article> })}{!games?.length && <Empty>Chưa có vòng chơi trong mục này.</Empty>}</div>}
    <div className="two-col game-extras"><Panel title="Bảng thành tích"><div className="form-grid"><label>Khoảng thời gian<select value={period} onChange={e => setPeriod(e.target.value)}><option value="week">Tuần này</option><option value="month">Tháng này</option><option value="all">Tất cả</option></select></label></div><ErrorBox message={rankings.error}/>{rankings.data && !rankings.data.enabled ? <Empty>Bảng thành tích chưa được tổ chức bật.</Empty> : rankings.data?.rows.length ? rankings.data.rows.map((r, i) => <div className="list-row" key={r.alias}><b>{i + 1}</b><div><strong>{r.alias}{r.is_me ? ' · Em' : ''}</strong></div><b>{r.points} điểm</b></div>) : <Empty>Chưa có kết quả để xếp hạng.</Empty>}</Panel>{profile.data && <Panel title="Dấu chân khám phá">{profile.data.achievements.map(a => <p className="achievement" key={a.id}><Icon name="sparkle"/> {a.code === 'perfect_round' ? 'Vòng chơi hoàn hảo' : a.code}</p>)}{profile.data.history.slice(0, 6).map(h => <div className="list-row" key={h.id}><div><strong>{h.title}</strong><small>{displayDate(h.created_at)}</small></div><b>{h.points} điểm</b></div>)}{!profile.data.history.length && <Empty>Hoàn thành vòng đầu tiên để ghi dấu hành trình.</Empty>}</Panel>}</div>
    </>}
    {classification && <ContentClassification content={classification} resource="games" notify={notify} onClose={() => setClassification(null)} onSaved={reload}/>}
    {creating && <CreateGame initialGrade={grade} initialSubject={subject} onClose={() => setCreating(false)} onSaved={reload} notify={notify}/>}
    {selected && user.role === 'student' && <GameSession key={selected.id} game={selected} user={user} notify={notify} onComplete={() => { reload(); profile.reload(); rankings.reload() }} onClose={() => setSelected(null)}/>}
    {selected && user.role !== 'student' && <Modal title={selected.title} onClose={() => setSelected(null)} wide>{selected.questions?.map((q, i) => <div className="question-card" key={q.id}><h3>Câu {i + 1}: {q.prompt}</h3><p>Đáp án: {typeof q.answer === 'object' ? JSON.stringify(q.answer) : String(q.answer)}</p></div>)}</Modal>}
  </>
}
