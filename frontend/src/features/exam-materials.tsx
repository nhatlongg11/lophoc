import { StudentPicker } from '../components/StudentPicker'
import { useEffect, useState } from 'react'
import { api, downloadFile } from '../api'
import { Button, Empty, ErrorBox, Modal, PageHead, Skeleton, displayDate, useResource, type Notify, type User } from '../components/ui'

const categories: Record<string, string> = { general: 'Học liệu chung', midterm: 'Ôn giữa kỳ', final: 'Ôn cuối kỳ', weekly_revision: 'Ôn tập tuần', subject_revision: 'Ôn tập môn học', practice_exam: 'Đề thi luyện tập' }
type Material = { id: number; name: string; folder: string; description?: string; subject_id?: number | null; grade?: string; semester?: string; exam_category: string; visibility: string; download_allowed: boolean; practice_round_id?: number | null; mime?: string; size?: number; url?: string; published_at?: string; created_at: string }
type Draft = Omit<Material, 'id' | 'created_at'> & { id?: number; student_ids: number[] }
const fresh = (): Draft => ({ name: '', folder: '', description: '', subject_id: null, grade: '', semester: '', exam_category: 'general', visibility: 'private', download_allowed: true, student_ids: [], practice_round_id: null })

function MaterialPreview({ file, url, canDownload, onClose, notify }: { file: Material; url: string; canDownload: boolean; onClose: () => void; notify: Notify }) {
  const [source, setSource] = useState(''), [error, setError] = useState('')
  const supported = !!file.mime && (file.mime.startsWith('image/') || file.mime.startsWith('audio/') || file.mime === 'application/pdf')
  useEffect(() => {
    if (!supported) return
    const controller = new AbortController()
    let objectUrl = ''
    async function load() {
      try {
        const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal })
        if (!response.ok) {
          const problem = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null
          throw new Error(problem?.message || 'Không thể xem tài liệu. Đóng và mở lại để lấy liên kết mới.')
        }
        const blob = await response.blob()
        if (!blob.size) throw new Error('Tệp tài liệu trống. Vui lòng liên hệ người đăng tải.')
        if (blob.type.split(';')[0] !== file.mime) throw new Error('Máy chủ trả về nội dung không đúng định dạng tài liệu. Vui lòng đóng và mở lại.')
        if (controller.signal.aborted) return
        objectUrl = URL.createObjectURL(blob)
        setSource(objectUrl)
      } catch (failure) {
        if (!controller.signal.aborted) setError((failure as Error).message)
      }
    }
    void load()
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl) }
  }, [url, supported, file.mime])
  const unreadable = () => setError('Không đọc được nội dung tệp. Vui lòng tải xuống hoặc liên hệ người đăng tải.')
  return <Modal title={file.name} onClose={onClose} wide><ErrorBox message={error}/>{supported && !source && !error && <p role="status">Đang tải tài liệu…</p>}{!error && source && (file.mime?.startsWith('image/') ? <img className="question-image" src={source} alt={file.description || file.name} onError={unreadable}/> : file.mime === 'application/pdf' ? <iframe className="document-preview" title={file.name} src={source}/> : <audio controls src={source} onError={unreadable}/>)}{!supported && <p>Tải tài liệu để mở bằng ứng dụng tương ứng.</p>}{canDownload && <Button onClick={() => downloadFile(file).catch((failure: Error) => notify(failure.message, true))}>Tải xuống</Button>}</Modal>
}
export function MaterialLibrary({ user, notify }: { user: User; notify: Notify }) {
  const teacher = ['teacher', 'admin'].includes(user.role)
  const [deleteTarget, setDeleteTarget] = useState<Material | null>(null), [deleting, setDeleting] = useState(false), [deleteError, setDeleteError] = useState('')
  const [subject, setSubject] = useState(''), [category, setCategory] = useState(''), [search, setSearch] = useState(''), [query, setQuery] = useState(''), [draft, setDraft] = useState<Draft | null>(null), [file, setFile] = useState<File | null>(null), [busy, setBusy] = useState(false), [error, setError] = useState(''), [preview, setPreview] = useState<{ file: Material; url: string } | null>(null)
  const materials = useResource<Material[]>('/library?' + new URLSearchParams({ ...(subject ? { subject_id: subject } : {}), ...(category ? { exam_category: category } : {}), ...(query ? { search: query } : {}) }))
  const subjects = useResource<{ id: number; name: string }[]>('/subjects'), rounds = useResource<{ data: { id: number; title: string }[] }>(teacher ? '/arcade/rounds' : null)
  const change = (patch: Partial<Draft>) => setDraft(d => d ? { ...d, ...patch } : null)
  async function removeMaterial() {
    if (!deleteTarget || deleting) return
    setDeleting(true); setDeleteError('')
    try {
      await api('/library/' + deleteTarget.id, { method: 'DELETE' })
      setDeleteTarget(null); materials.reload(); notify('Đã xóa tài liệu khỏi kho và ngừng chia sẻ với học sinh.')
    } catch (failure) { setDeleteError((failure as Error).message) } finally { setDeleting(false) }
  }
  async function save(archive = false) {
    if (!draft) return
    setBusy(true); setError('')
    try {
      if (draft.id) await api('/library/' + draft.id, { method: 'PUT', body: { ...draft, archive } })
      else {
        const form = new FormData(); if (file) form.append('file', file); else if (draft.url) form.append('url', draft.url)
        for (const [key, value] of Object.entries(draft)) { if (key === 'student_ids') draft.student_ids.forEach(id => form.append('student_ids[]', String(id))); else if (key !== 'url' && value !== null && value !== undefined) form.append(key, typeof value === 'boolean' ? value ? '1' : '0' : String(value)) }
        await api('/library', { method: 'POST', body: form })
      }
      setDraft(null); materials.reload(); notify(archive ? 'Đã lưu trữ học liệu.' : 'Đã lưu học liệu và quyền truy cập.')
    } catch (err) { setError((err as Error).message) } finally { setBusy(false) }
  }
  return <><PageHead title={teacher ? 'Thư viện học liệu & ôn thi' : 'Tài liệu được giao'} description="Tài liệu theo môn, khối và kỳ thi, được chia sẻ trực tiếp cho học sinh." action={teacher && <Button onClick={() => { setDraft(fresh()); setFile(null); setError('') }}>Thêm học liệu</Button>}/><div className="form-grid"><label>Môn học<select value={subject} onChange={e => setSubject(e.target.value)}><option value="">Tất cả môn</option>{subjects.data?.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label><label>Loại tài liệu<select value={category} onChange={e => setCategory(e.target.value)}><option value="">Tất cả loại</option>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><form className="message-search" onSubmit={e => { e.preventDefault(); setQuery(search) }}><label>Tìm tài liệu<input value={search} onChange={e => setSearch(e.target.value)}/></label><Button secondary>Tìm</Button></form></div><ErrorBox message={materials.error || subjects.error}/>{materials.loading ? <Skeleton/> : <div className="library-grid">{materials.data?.map(m => <article className="panel material-card" key={m.id}><span className="eyebrow">{categories[m.exam_category]} · {subjects.data?.find(s => s.id === m.subject_id)?.name}</span><h2>{m.name}</h2><p>{m.description}</p><p>Khối {m.grade || '—'} · {m.semester || 'Chưa chọn học kỳ'}</p><small>{displayDate(m.published_at || m.created_at)} · {m.folder}</small><div className="form-actions"><Button secondary onClick={async () => { try { const result = await api<{ url: string }>('/files/' + m.id + '/access'); if (m.url) window.open(result.url, '_blank', 'noopener,noreferrer'); else setPreview({ file: m, url: result.url }) } catch (err) { notify((err as Error).message, true) } }}>Xem tài liệu</Button>{teacher && <Button secondary onClick={async () => { try { const shared = await api<number[]>('/library/' + m.id + '/permissions'); setDraft({ ...m, student_ids: shared }); setError('') } catch (err) { notify((err as Error).message, true) } }}>Sắp xếp & chia sẻ</Button>}{teacher && <Button className="btn secondary danger" onClick={() => { setDeleteTarget(m); setDeleteError('') }}>Xóa tài liệu</Button>}</div>{m.practice_round_id && <a href={'#arcade/round/' + m.practice_round_id}>Luyện tập liên quan</a>}</article>)}{!materials.data?.length && <Empty>Chưa có tài liệu phù hợp.</Empty>}</div>}
    {draft && <Modal title={draft.id ? 'Sắp xếp & chia sẻ học liệu' : 'Thêm học liệu'} onClose={() => setDraft(null)} wide><form onSubmit={e => { e.preventDefault(); void save() }}><label>Tiêu đề tài liệu<input required maxLength={180} value={draft.name} onChange={e => change({ name: e.target.value })}/></label><label>Mô tả<textarea maxLength={4000} value={draft.description || ''} onChange={e => change({ description: e.target.value })}/></label><div className="form-grid"><label>Môn học<select value={draft.subject_id || ''} onChange={e => change({ subject_id: Number(e.target.value) || null })}><option value="">Chọn môn</option>{subjects.data?.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label><label>Khối<input value={draft.grade || ''} onChange={e => change({ grade: e.target.value })}/></label><label>Học kỳ<select value={draft.semester || ''} onChange={e => change({ semester: e.target.value })}><option value="">Chọn học kỳ</option><option>Học kỳ I</option><option>Học kỳ II</option><option>Hè</option></select></label><label>Loại ôn tập<select value={draft.exam_category} onChange={e => change({ exam_category: e.target.value })}>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><label>Thư mục<input value={draft.folder} onChange={e => change({ folder: e.target.value })}/></label><label>Hiển thị<select value={draft.visibility} onChange={e => change({ visibility: e.target.value })}><option value="private">Riêng tư</option><option value="assigned">Chia sẻ với học sinh đã chọn</option></select></label><label>Hoạt động luyện tập<select value={draft.practice_round_id || ''} onChange={e => change({ practice_round_id: Number(e.target.value) || null })}><option value="">Không liên kết</option>{rounds.data?.data.map(r => <option key={r.id} value={r.id}>{r.title}</option>)}</select></label></div><label className="check-row"><input type="checkbox" checked={!!draft.download_allowed} onChange={e => change({ download_allowed: e.target.checked })}/>Cho phép tải xuống</label><StudentPicker label="Chia sẻ cho học sinh" value={draft.student_ids} onChange={student_ids => change({ student_ids })}/>{draft.id ? <label>Thay tệp (giữ nguyên quyền chia sẻ)<input type="file" disabled={busy} accept=".pdf,.doc,.docx,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.mp3,.wav,.ogg,.m4a" onChange={async e => { const next = e.target.files?.[0]; if (!next) return; setBusy(true); try { const body = new FormData(); body.append('file', next); await api('/library/' + draft.id + '/replace', { method: 'POST', body }); materials.reload(); notify('Đã thay tệp và giữ lịch sử.') } catch (err) { setError((err as Error).message) } finally { setBusy(false); e.target.value = '' } }}/></label> : <><label>Tệp PDF, DOCX, ảnh hoặc định dạng được hỗ trợ (tối đa 50 MB)<input type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.mp3,.wav,.ogg,.m4a" onChange={e => { const next = e.target.files?.[0] || null; setFile(next); if (next && !draft.name) change({ name: next.name }) }}/></label><label>Hoặc liên kết tài liệu<input type="url" value={draft.url || ''} onChange={e => change({ url: e.target.value })}/></label></>}<ErrorBox message={error}/><div className="form-actions"><Button disabled={busy || !draft.id && !file && !draft.url}>Lưu tài liệu</Button>{draft.id && <Button secondary type="button" disabled={busy} onClick={() => void save(true)}>Lưu trữ tài liệu</Button>}</div></form></Modal>}
    {deleteTarget && <Modal title="Xóa tài liệu?" onClose={() => { if (!deleting) setDeleteTarget(null) }}><p>Xóa <b>{deleteTarget.name}</b> khỏi kho tài liệu? Học sinh sẽ không còn truy cập được tài liệu này.</p><ErrorBox message={deleteError}/><div className="form-actions"><Button secondary disabled={deleting} onClick={() => setDeleteTarget(null)}>Hủy</Button><Button className="btn danger" disabled={deleting} onClick={() => void removeMaterial()}>{deleting ? 'Đang xóa…' : 'Xác nhận xóa'}</Button></div></Modal>}
    {preview && <MaterialPreview file={preview.file} url={preview.url} canDownload={teacher || !!preview.file.download_allowed} onClose={() => setPreview(null)} notify={notify}/>}
  </>
}
