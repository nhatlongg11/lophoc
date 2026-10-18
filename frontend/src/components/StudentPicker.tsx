import { useId, useState } from 'react'
import { Empty, ErrorBox, useResource } from './ui'

export function StudentPicker({ value, onChange, label = 'Học sinh được giao' }: { value: number[]; onChange: (ids: number[]) => void; label?: string }) {
  const { data, error, loading } = useResource<{ id: number; name: string; email: string; active: boolean }[]>('/users?role=student')
  const [search, setSearch] = useState('')
  const searchId = useId()
  const available = (data || []).filter(student => student.active)
  const visible = available.filter(student => `${student.name} ${student.email}`.toLocaleLowerCase('vi').includes(search.trim().toLocaleLowerCase('vi')))
  return <fieldset className="student-picker full-width">
    <legend>{label} · Đã chọn {value.length}</legend>
    <label htmlFor={searchId}>Tìm học sinh</label>
    <input id={searchId} type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Tên hoặc email"/>
    <div className="form-actions">
      <button className="text-link" type="button" onClick={() => onChange(Array.from(new Set([...value, ...visible.map(student => student.id)])))}>Chọn tất cả kết quả</button>
      <button className="text-link" type="button" onClick={() => onChange([])}>Bỏ chọn</button>
    </div>
    <ErrorBox message={error}/>
    {loading ? <p role="status">Đang tải học sinh…</p> : <div className="student-picker-list">{visible.map(student => <label className="check-row" key={student.id}>
      <input type="checkbox" checked={value.includes(student.id)} onChange={event => onChange(event.target.checked ? [...value, student.id] : value.filter(id => id !== student.id))}/>
      <span>{student.name}<small>{student.email}</small></span>
    </label>)}{!visible.length && <Empty>Không có học sinh phù hợp.</Empty>}</div>}
  </fieldset>
}
