import { useState } from 'react'
import { api } from '../api'
import { Button, ErrorBox } from './ui'

export type MediaReference = { id: number; alt: string; caption?: string | null }
export function QuestionImage({ media, url, alt = '' }: { media?: MediaReference | null; url?: string | null; alt?: string }) {
  return media || url ? <figure className="question-media"><img className="question-image" src={media ? '/api/question-media/' + media.id : url!} alt={media?.alt || alt} loading="lazy"/>{media?.caption && <figcaption>{media.caption}</figcaption>}</figure> : null
}
export function MediaEditor({ value, onChange, label = 'Hình ảnh' }: { value?: MediaReference | null; onChange: (value: MediaReference | null) => void; label?: string }) {
  const [busy, setBusy] = useState(false), [error, setError] = useState('')
  return <div className="media-editor"><label>{label} · PNG, JPEG, WebP, tối đa 5 MB<input type="file" accept="image/png,image/jpeg,image/webp" disabled={busy} onChange={async e => {
    const file = e.target.files?.[0]; if (!file) return
    setError(''); setBusy(true)
    try { const form = new FormData(); form.append('file', file); const saved = await api<{ id: number }>('/question-media', { method: 'POST', body: form }); onChange({ id: saved.id, alt: value?.alt || file.name.replace(/\.[^.]+$/, ''), caption: value?.caption || '' }) } catch (err) { setError((err as Error).message) } finally { setBusy(false); e.target.value = '' }
  }}/></label>{busy && <p role="status">Đang lưu ảnh…</p>}<ErrorBox message={error}/>{value && <><QuestionImage media={value}/><label>Mô tả ảnh cho trình đọc màn hình<input required maxLength={500} value={value.alt} onChange={e => onChange({ ...value, alt: e.target.value })}/></label><label>Chú thích ảnh<input maxLength={1000} value={value.caption || ''} onChange={e => onChange({ ...value, caption: e.target.value })}/></label><Button secondary type="button" onClick={() => onChange(null)}>Gỡ ảnh</Button></>}</div>
}
