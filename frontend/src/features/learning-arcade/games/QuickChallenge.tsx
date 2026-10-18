import { QuestionImage } from '../../../components/QuestionMedia'
import { Button } from '../../../components/ui'
import { Icon } from '../../../components/Icon'
import type { EngineProps } from '../types'

export default function QuickChallenge({ activity, busy, draft, onDraft, send }: EngineProps) {
  const item = activity.item
  if (!item) return null
  return <section aria-label="Thử thách nhanh"><div className="arcade-combo"><Icon name="quiz"/><span>Chuỗi đúng: {activity.streak} · Combo ×{Math.max(1, activity.combo)} / tối đa ×3</span></div><h2 className="arcade-question">{item.prompt}</h2><QuestionImage media={item.media} url={item.media_url} alt={item.prompt}/>{item.answer_mode === 'choice' ? <div className="arcade-choices">{item.choices?.map((choice, i) => <button type="button" className="arcade-choice" key={choice} disabled={busy} onClick={() => void send('answer', { item_id: item.id, answer: item.choice_ids?.[choice] || choice })}><span>{String.fromCharCode(65 + i)}</span>{choice}<QuestionImage media={item.choice_media?.[choice]}/></button>)}</div> : <form className="arcade-typed-answer" onSubmit={e => { e.preventDefault(); void send('answer', { item_id: item.id, answer: typeof draft === 'string' ? draft : '' }) }}><label>Câu trả lời<input autoComplete="off" maxLength={500} inputMode={item.numeric ? 'decimal' : 'text'} disabled={busy} value={typeof draft === 'string' ? draft : ''} onChange={e => onDraft(e.target.value)}/></label>{item.numeric && <small>Dùng dấu chấm cho phần thập phân.</small>}<Button disabled={busy || typeof draft !== 'string' || !draft.trim()}>Kiểm tra <Icon name="check"/></Button></form>}<p className="arcade-subtle">{activity.timed ? 'Thời gian do giáo viên cấu hình. Điểm chủ yếu dựa trên độ chính xác.' : 'Luyện tập thoải mái, không có đồng hồ đếm ngược.'}</p></section>
}
