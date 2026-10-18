import { Empty, ErrorBox, Panel, useResource, displayDate } from '../../components/ui'
import { Icon } from '../../components/Icon'
import type { Profile } from './types'
export function ParentArcadeProgress({ studentId }: { studentId: number }) {
  const { data, error, loading } = useResource<Profile>('/arcade/children/' + studentId)
  return <Panel title="Hành trình học vui của con"><ErrorBox message={error}/>{data && <><p><Icon name="academic"/> Cấp {data.level} · {data.xp} XP · {data.streak} ngày luyện tập · {data.completed_rounds} vòng hoàn thành</p><div className="arcade-parent-topics">{data.mastery.map(m => <div className="list-row" key={m.subject_id + m.topic}><div><strong>{m.subject_name} · {m.topic}</strong><small>{m.recommendation}</small></div><b>{m.accuracy}%</b></div>)}</div>{data.history.slice(0, 3).map(h => <div className="list-row" key={h.id}><div><strong>{h.title}</strong><small>{displayDate(h.completed_at)}</small></div><span>{h.result.stars}/3 sao · {h.result.accuracy}%</span></div>)}{!data.mastery.length && <Empty>Con chưa có hoạt động hoàn thành trong Learning Arcade.</Empty>}</>}{loading && <p role="status">Đang tải tiến bộ học vui…</p>}</Panel>
}
