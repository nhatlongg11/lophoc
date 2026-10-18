import { useState } from 'react'
import { RosterActions } from './reports'
import { Empty, ErrorBox, PageHead, Skeleton, useResource, type Notify, type User } from '../components/ui'

export function StudentDirectory({ user, notify }: { user: User; notify: Notify }) {
  const { data, error, loading, reload } = useResource<(User & { phone?: string; active: boolean })[]>('/users?role=student')
  const [search, setSearch] = useState('')
  const students = data?.filter(student => `${student.name} ${student.email}`.toLocaleLowerCase('vi').includes(search.trim().toLocaleLowerCase('vi')))
  return <>
    <PageHead title="Học sinh" description="Giao bài, học liệu và lịch học trực tiếp cho từng học sinh."/>
    {user.role === 'admin' && <RosterActions user={user} notify={notify} onChanged={reload}/>}
    <label className="toolbar">Tìm học sinh<input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Tên hoặc email"/></label>
    <ErrorBox message={error}/>
    {loading ? <Skeleton/> : <div className="table-wrap"><table><thead><tr><th>Học sinh</th><th>Email</th><th>Điện thoại</th><th>Trạng thái</th></tr></thead><tbody>{students?.map(student => <tr key={student.id}><td><strong>{student.name}</strong></td><td>{student.email}</td><td>{student.phone || '—'}</td><td>{student.active ? 'Đang hoạt động' : 'Đã khóa'}</td></tr>)}</tbody></table>{!students?.length && <Empty>Chưa có học sinh phù hợp.</Empty>}</div>}
  </>
}
