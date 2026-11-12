import { useState } from 'react'
import { api } from '../api'
import { RosterActions } from './reports'
import { Button, Empty, ErrorBox, PageHead, Panel, Skeleton, useResource, type Notify, type User } from '../components/ui'

function ClassJoinCode({ notify }: { notify: Notify }) {
  const { data, error, loading, reload } = useResource<{ code: string; url: string }>('/class-code')
  const joinUrl = data ? `${window.location.origin}/app/?join=${data.code}` : ''
  const [busy, setBusy] = useState(false)
  async function copy(value: string) {
    try {
      await navigator.clipboard.writeText(value)
      notify('Đã sao chép mã lớp.')
    } catch {
      notify('Không thể sao chép tự động. Vui lòng chọn và sao chép thủ công.', true)
    }
  }
  async function refresh() {
    if (busy || !window.confirm('Tạo mã lớp mới? Mã cũ sẽ không còn dùng để tự đăng ký được nữa.')) return
    setBusy(true)
    try {
      await api('/class-code/refresh', { method: 'POST' })
      reload()
      notify('Đã tạo mã lớp mới.')
    } catch (err) {
      notify((err as Error).message, true)
    } finally {
      setBusy(false)
    }
  }
  return <Panel title="Mã lớp của tôi" action={data && <Button secondary disabled={busy} onClick={() => void refresh()}>{busy ? 'Đang tạo…' : 'Tạo mã mới'}</Button>}>
    <p>Chia sẻ mã hoặc liên kết này với học sinh. Khi tự tạo tài khoản bằng mã lớp, học sinh được kích hoạt ngay và tự động vào lớp của bạn.</p>
    <ErrorBox message={error}/>
    {loading ? <Skeleton/> : data && <>
      <div className="form-grid">
        <label>Mã lớp<input readOnly value={data.code} onFocus={event => event.currentTarget.select()}/></label>
        <label>Liên kết tham gia<input readOnly value={joinUrl} onFocus={event => event.currentTarget.select()}/></label>
      </div>
      <div className="form-actions">
        <Button secondary onClick={() => void copy(data.code)}>Sao chép mã</Button>
        <Button secondary onClick={() => void copy(joinUrl)}>Sao chép liên kết</Button>
      </div>
    </>}
  </Panel>
}

export function StudentDirectory({ user, notify }: { user: User; notify: Notify }) {
  const { data, error, loading, reload } = useResource<(User & { phone?: string; active: boolean })[]>('/users?role=student')
  const [search, setSearch] = useState('')
  const students = data?.filter(student => `${student.name} ${student.email}`.toLocaleLowerCase('vi').includes(search.trim().toLocaleLowerCase('vi')))
  return <>
    <PageHead title="Học sinh" description="Giao bài, học liệu và lịch học trực tiếp cho từng học sinh."/>
    {user.role === 'admin' && <RosterActions user={user} notify={notify} onChanged={reload}/>}
    {user.role === 'teacher' && <ClassJoinCode notify={notify}/>}
    <label className="toolbar">Tìm học sinh<input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Tên hoặc email"/></label>
    <ErrorBox message={error}/>
    {loading ? <Skeleton/> : <div className="table-wrap"><table><thead><tr><th>Học sinh</th><th>Email</th><th>Điện thoại</th><th>Trạng thái</th></tr></thead><tbody>{students?.map(student => <tr key={student.id}><td><strong>{student.name}</strong></td><td>{student.email}</td><td>{student.phone || '—'}</td><td>{student.active ? 'Đang hoạt động' : 'Đã khóa'}</td></tr>)}</tbody></table>{!students?.length && <Empty>Chưa có học sinh phù hợp.</Empty>}</div>}
  </>
}
