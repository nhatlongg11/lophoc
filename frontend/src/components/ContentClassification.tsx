import { useState } from 'react'
import { api } from '../api'
import { GradeSelect } from './GradePicker'
import { Button, ErrorBox, Modal, useResource, type Notify } from './ui'
import type { Subject } from './SubjectFilter'

export type ClassifiedContent = { id: number; title: string; grade?: string | null; subject_id?: number | null }

export function ContentClassification({ content, resource, onClose, onSaved, notify }: { content: ClassifiedContent; resource: 'assignments' | 'games'; onClose: () => void; onSaved: () => void; notify: Notify }) {
  const [grade, setGrade] = useState(content.grade || ''), [subject, setSubject] = useState(String(content.subject_id || '')), [busy, setBusy] = useState(false), [error, setError] = useState('')
  const subjects = useResource<Subject[]>('/subjects')
  return <Modal title="Phân lớp & môn học" onClose={() => { if (!busy) onClose() }}><p><b>{content.title}</b></p><form onSubmit={async event => { event.preventDefault(); setBusy(true); setError(''); try { await api('/' + resource + '/' + content.id + '/classification', { method: 'PATCH', body: { grade, subject_id: subject ? Number(subject) : null } }); onSaved(); onClose(); notify('Đã cập nhật lớp và môn học. Bài làm và kết quả được giữ nguyên.') } catch (failure) { setError((failure as Error).message) } finally { setBusy(false) } }}><GradeSelect value={grade} onChange={setGrade}/><label>Môn học<select value={subject} onChange={event => setSubject(event.target.value)}><option value="">Chưa phân môn</option>{subjects.data?.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><ErrorBox message={error || subjects.error}/><div className="form-actions"><Button type="button" secondary disabled={busy} onClick={onClose}>Hủy</Button><Button disabled={busy || !grade}>{busy ? 'Đang lưu…' : 'Lưu phân loại'}</Button></div></form></Modal>
}
