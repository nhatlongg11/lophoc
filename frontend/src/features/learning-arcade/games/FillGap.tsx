import { QuestionImage } from '../../../components/QuestionMedia'
import { Button } from '../../../components/ui'
import { Icon } from '../../../components/Icon'
import type { EngineProps } from '../types'

export default function FillGap({ activity, busy, draft, onDraft, send }: EngineProps) {
  const item = activity.item
  if (!item) return null
  const value = typeof draft === 'string' ? draft : '', [before, after] = item.prompt.split('___')
  return <section aria-label="Điền mảnh ghép"><p className="arcade-instruction">Tìm mảnh ghép phù hợp để hoàn thiện câu.</p><h2 className="arcade-gap-sentence">{before}<span className={'arcade-gap ' + (value ? 'filled' : '')} aria-label={value ? 'Chỗ trống: ' + value : 'Chỗ trống cần điền'}>{value || '…'}</span>{after}</h2><QuestionImage media={item.media} url={item.media_url} alt={item.prompt}/><form className="arcade-typed-answer" onSubmit={e => { e.preventDefault(); void send('answer', { item_id: item.id, answer: item.answer_mode !== 'typed' ? item.choice_ids?.[value] || value : value }) }}>{item.answer_mode === 'typed' ? <label>Điền vào chỗ trống<input disabled={busy} autoComplete="off" maxLength={500} inputMode={item.numeric ? 'decimal' : 'text'} value={value} onChange={e => onDraft(e.target.value)}/></label> : <div className={item.answer_mode === 'word_bank' ? 'arcade-token-bank' : 'arcade-choices'} aria-label={item.answer_mode === 'word_bank' ? 'Ngân hàng từ' : 'Lựa chọn điền vào chỗ trống'}>{item.choices?.map(choice => <button type="button" key={choice} className={(item.answer_mode === 'word_bank' ? 'arcade-token ' : 'arcade-choice ') + (value === choice ? 'selected' : '')} aria-pressed={value === choice} disabled={busy} onClick={() => onDraft(value === choice ? '' : choice)}>{choice}<QuestionImage media={item.choice_media?.[choice]}/></button>)}</div>}{item.numeric && <small>Dùng dấu chấm cho phần thập phân, ví dụ 1.5.</small>}<div className="arcade-answer-actions"><Button secondary type="button" disabled={busy || !value} onClick={() => onDraft('')}>Bỏ lựa chọn</Button><Button disabled={busy || !value.trim()}>Kiểm tra <Icon name="check"/></Button></div></form></section>
}
