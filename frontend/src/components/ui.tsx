import { QuestionImage, type MediaReference } from './QuestionMedia'
import { Icon } from './Icon'
import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { api } from '../api'

export type User = { id: number; name: string; email: string; role: string; is_family_session?: boolean }
export type Notify = (message: string, error?: boolean) => void
export type SchoolClass = { id: number; name: string }
export type Answer = string | string[] | Record<string, string> | boolean | number | null
export type Question = { id: number | string; type: string; kind?: string; prompt: string; options: string[] | { left: string[]; right: string[] }; points?: number; answer?: Answer; media_url?: string; media?: MediaReference | null; choice_media?: Record<string, MediaReference>; explanation?: string | null; difficulty?: string; learning_objective?: string | null; topic?: string }
export const questionTypes: Record<string, string> = { single: 'Chọn một đáp án', multiple: 'Chọn nhiều đáp án', true_false: 'Đúng / sai', fill_blank: 'Điền vào chỗ trống', short: 'Trả lời ngắn', essay: 'Tự luận', matching: 'Ghép cặp', ordering: 'Sắp xếp', image: 'Câu hỏi hình ảnh', audio: 'Nghe và trả lời', file: 'Nộp tệp', word_builder: 'Xây dựng từ' }
export function displayDate(value?: string | null) {
  if (!value) return '—'
  const parsed = new Date(/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value) ? value.replace(' ', 'T') + '+07:00' : value)
  return Number.isNaN(parsed.getTime()) ? '—' : new Intl.DateTimeFormat('vi-VN', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Ho_Chi_Minh' }).format(parsed)
}
export function useResource<T>(path: string | null) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [version, setVersion] = useState(0)
  useEffect(() => {
    let current = true
    setData(null); setError(''); setLoading(!!path)
    if (path) api<T>(path).then(result => { if (current) setData(result) }).catch((err: Error) => { if (current) setError(err.message) }).finally(() => { if (current) setLoading(false) })
    return () => { current = false }
  }, [path, version])
  return { data, error, loading, reload: () => setVersion(v => v + 1) }
}
export function ErrorBox({ message }: { message?: string }) { return message ? <div className="error-box" role="alert">{message}</div> : null }
export function Empty({ children = 'Chưa có dữ liệu.' }: { children?: ReactNode }) { return <div className="empty">{children}</div> }
export function Skeleton() { return <div className="skeleton" role="status" aria-label="Đang tải"><div/><div/><div/></div> }
export function Button({ children, secondary, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { secondary?: boolean }) { return <button className={'btn ' + (secondary ? 'secondary' : 'primary')} {...props}>{children}</button> }
export function Panel({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) { return <section className="panel"><div className="panel-header"><h2>{title}</h2>{action}</div>{children}</section> }
export function Modal({ title, children, onClose, wide = false }: { title: string; children: ReactNode; onClose: () => void; wide?: boolean }) {
  const ref = useRef<HTMLDivElement>(null), titleId = useId()
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    ref.current?.focus()
    return () => { document.body.style.overflow = overflow; previous?.focus() }
  }, [])
  return <div className="modal-overlay" onMouseDown={e => { if (e.target === e.currentTarget) onClose() }}>
    <div className={'modal ' + (wide ? 'wide' : '')} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} ref={ref} onKeyDown={e => {
      if (e.key === 'Escape') onClose()
      if (e.key === 'Tab') {
        const items = Array.from(ref.current?.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex="0"]') || [])
        const first = items[0], last = items.at(-1)
        if (e.shiftKey && (document.activeElement === first || document.activeElement === ref.current)) { e.preventDefault(); last?.focus() }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus() }
      }
    }}>
      <button className="close" type="button" aria-label="Đóng hộp thoại" onClick={onClose}><Icon name="close"/></button>
      <h2 id={titleId}>{title}</h2>{children}
    </div>
  </div>
}
export function PageHead({ title, description, action, eyebrow = 'CE - Connecting Education • CÙNG KHÁM PHÁ' }: { title: string; description?: string; action?: ReactNode; eyebrow?: string }) { return <div className="page-head"><div><span className="eyebrow">{eyebrow}</span><h1>{title}</h1>{description && <p>{description}</p>}</div>{action}</div> }

export function QuestionInput({ question: q, value, onChange, disabled = false, uploadPath }: { question: Question; value?: Answer; onChange: (answer: Answer) => void; disabled?: boolean; uploadPath?: string }) {
  const list = Array.isArray(q.options) ? q.options : []
  const [picked, setPicked] = useState<number[]>([])
  const [uploadError, setUploadError] = useState(''), [uploading, setUploading] = useState(false)
  const text = typeof value === 'string' ? value : ''
  useEffect(() => setPicked([]), [q.id])
  if (['single', 'true_false', 'image', 'audio'].includes(q.type)) return <fieldset className="answer-options" disabled={disabled}><legend className="sr-only">Chọn đáp án</legend>{list.map((option, i) => <label className={'answer-option ' + (value === option ? 'chosen' : '')} key={i}><input name={'q-' + q.id} type="radio" checked={value === option} onChange={() => onChange(option)}/><span>{String.fromCharCode(65 + i)}</span>{option}<QuestionImage media={q.choice_media?.[option]}/></label>)}</fieldset>
  if (q.type === 'multiple') return <fieldset className="answer-options" disabled={disabled}><legend>Chọn tất cả đáp án đúng</legend>{list.map((option, i) => <label className="answer-option" key={i}><input type="checkbox" checked={Array.isArray(value) && value.includes(option)} onChange={e => onChange(e.target.checked ? [...(Array.isArray(value) ? value : []), option] : (Array.isArray(value) ? value : []).filter(v => v !== option))}/>{option}</label>)}</fieldset>
  if (q.type === 'matching' && !Array.isArray(q.options)) { const pairs = q.options; return <div className="pair-grid">{pairs.left.map((left, i) => <label className="pair-row" key={i}><strong>{left}</strong><select aria-label={'Ghép với ' + left} disabled={disabled} value={value && !Array.isArray(value) && typeof value === 'object' ? value[left] || '' : ''} onChange={e => onChange({ ...(value && !Array.isArray(value) && typeof value === 'object' ? value : {}), [left]: e.target.value })}><option value="">Chọn cặp tương ứng</option>{pairs.right.map((right, j) => <option key={j} value={right}>{right}</option>)}</select></label>)}</div> }
  if (q.type === 'ordering') { const order = Array.isArray(value) ? value : []; return <div><p className="muted">Chạm các thẻ theo đúng thứ tự.</p><div className="word-slots">{order.length ? order.map((item, i) => <span key={i}>{i + 1}. {item}</span>) : <span>Thứ tự của em…</span>}</div><div className="letter-bank">{list.map((item, i) => <Button type="button" secondary key={i} disabled={disabled || order.includes(item)} onClick={() => onChange([...order, item])}>{item}</Button>)}</div><Button type="button" secondary disabled={disabled} onClick={() => onChange([])}>Xếp lại</Button></div> }
  if (q.type === 'word_builder') return <div><div className="word-slots" aria-live="polite">{Array.from(text || '…').map((letter, i) => <span key={i}>{letter}</span>)}</div><div className="letter-bank">{list.map((letter, i) => <button className="letter" type="button" disabled={disabled || picked.includes(i)} key={i} onClick={() => { setPicked([...picked, i]); onChange(text + letter) }}>{letter}</button>)}</div><Button type="button" secondary disabled={disabled} onClick={() => { setPicked([]); onChange('') }}>Xây lại từ</Button></div>
  if (q.type === 'file') return <label>Tệp bài làm (tối đa 10 MB)<input type="file" accept=".pdf,.docx,.pptx,.jpg,.jpeg,.png,.webp" disabled={disabled || uploading || !uploadPath} onChange={async e => {
    const file = e.target.files?.[0]; if (!file || !uploadPath) return
    setUploading(true); setUploadError('')
    const form = new FormData(); form.append('file', file)
    try { const saved = await api<{ id: number }>(uploadPath, { method: 'POST', body: form }); onChange(String(saved.id)) } catch (err) { setUploadError((err as Error).message) } finally { setUploading(false) }
  }}/><span role="status">{uploading ? 'Đang tải tệp…' : value ? 'Đã lưu tệp #' + value : ''}</span><ErrorBox message={uploadError}/></label>
  return <label className="answer-text">{['essay', 'short'].includes(q.type) ? 'Bài làm của em' : 'Câu trả lời'}{q.type === 'essay' ? <textarea rows={5} value={text} disabled={disabled} onChange={e => onChange(e.target.value)}/> : <input autoComplete="off" value={text} disabled={disabled} onChange={e => onChange(e.target.value)}/>}</label>
}
