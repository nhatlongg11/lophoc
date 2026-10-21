import { useState } from 'react'
import { api } from '../api'
import { Button, Empty, ErrorBox, Modal, Skeleton, displayDate, useResource, type Notify } from '../components/ui'

type Request = { id: number; name: string; email: string; role: string; created_at: string }
const roles: Record<string, string> = { student: 'Học sinh', teacher: 'Giáo viên', family: 'Phụ huynh' }

export function RegistrationReview({ notify, onReviewed }: { notify: Notify; onReviewed: () => void }) {
  const requests = useResource<Request[]>('/registrations')
  const [action, setAction] = useState<{ request: Request; decision: 'approved' | 'rejected' } | null>(null)
  const [busy, setBusy] = useState(false), [error, setError] = useState('')
  async function review() {
    if (!action || busy) return
    setBusy(true); setError('')
    try {
      const result = await api<{ message: string }>('/registrations/' + action.request.id + '/review', { method: 'POST', body: { decision: action.decision } })
      setAction(null); requests.reload(); onReviewed(); notify(result.message)
    } catch (failure) { setError((failure as Error).message) } finally { setBusy(false) }
  }
  return <><p>Chỉ tài khoản được chấp nhận mới có thể đăng nhập. Học sinh và giáo viên được duyệt theo giới hạn tài khoản của tổ chức.</p><ErrorBox message={requests.error}/>{requests.loading ? <Skeleton/> : requests.data?.length ? <div className="table-wrap"><table><thead><tr><th>Người đăng ký</th><th>Email</th><th>Vai trò</th><th>Ngày đăng ký</th><th>Thao tác</th></tr></thead><tbody>{requests.data.map(request => <tr key={request.id}><td><strong>{request.name}</strong></td><td>{request.email}</td><td>{roles[request.role]}</td><td>{displayDate(request.created_at)}</td><td><div className="form-actions"><Button onClick={() => { setAction({ request, decision: 'approved' }); setError('') }}>Chấp nhận</Button><Button secondary onClick={() => { setAction({ request, decision: 'rejected' }); setError('') }}>Từ chối</Button></div></td></tr>)}</tbody></table></div> : !requests.error && <Empty>Không có yêu cầu đăng ký đang chờ duyệt.</Empty>}{action && <Modal title={action.decision === 'approved' ? 'Chấp nhận đăng ký?' : 'Từ chối đăng ký?'} onClose={() => { if (!busy) setAction(null) }}><p><b>{action.request.name}</b> · {action.request.email}</p><p>{action.decision === 'approved' ? 'Người dùng sẽ được đăng nhập với vai trò ' + roles[action.request.role] + '.' : 'Tài khoản sẽ không được phép đăng nhập.'}</p><ErrorBox message={error}/><div className="form-actions"><Button secondary disabled={busy} onClick={() => setAction(null)}>Hủy</Button><Button disabled={busy} onClick={() => void review()}>{busy ? 'Đang xử lý…' : 'Xác nhận'}</Button></div></Modal>}</>
}
