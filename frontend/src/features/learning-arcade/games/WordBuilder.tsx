import { QuestionImage } from '../../../components/QuestionMedia'
import { Button, Empty } from '../../../components/ui'
import { Icon } from '../../../components/Icon'
import type { EngineProps } from '../types'

export default function WordBuilder({ activity, busy, draft, onDraft, send }: EngineProps) {
  const item = activity.item
  if (!item) return null
  const selected = Array.isArray(draft) ? draft : [], tokens = item.tokens || []
  return <section aria-label="Xưởng xếp từ"><h2 className="arcade-question">{item.prompt}</h2><QuestionImage media={item.media} url={item.media_url} alt={item.prompt}/><p className="arcade-instruction">Chạm từ để thêm. Chạm từ đã chọn để bỏ ra.</p><div className="arcade-answer-tray" aria-label="Câu đang xây dựng">{selected.length ? selected.map((id, i) => <button type="button" className="arcade-token placed" key={id} aria-label={'Bỏ từ ' + (i + 1) + ': ' + tokens.find(t => t.id === id)?.text} disabled={busy} onClick={() => onDraft(selected.filter(t => t !== id))}>{tokens.find(t => t.id === id)?.text}<Icon name="close"/></button>) : <Empty>Câu của em sẽ xuất hiện ở đây.</Empty>}</div><div className="arcade-token-bank" aria-label="Các từ để lựa chọn">{tokens.map(token => <button type="button" className="arcade-token" key={token.id} disabled={busy || selected.includes(token.id)} aria-label={'Thêm từ: ' + token.text} onClick={() => onDraft([...selected, token.id])}>{token.text}</button>)}</div><div className="arcade-answer-actions"><Button secondary type="button" disabled={busy || !selected.length} onClick={() => onDraft([])}><Icon name="refresh"/> Xếp lại</Button><Button type="button" disabled={busy || selected.length !== tokens.length} onClick={() => void send('answer', { item_id: item.id, answer: selected })}>Kiểm tra câu <Icon name="check"/></Button></div></section>
}
