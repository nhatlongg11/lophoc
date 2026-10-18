import { MediaEditor, type MediaReference } from '../components/QuestionMedia'
import { Button, questionTypes, type Question } from '../components/ui'

export type DraftQuestion = { id?: string | number; media?: MediaReference | null; choice_media?: Record<string, MediaReference>; explanation?: string | null; difficulty?: string; learning_objective?: string | null; type: string; prompt: string; optionsText: string; answerText: string; pairsText: string; points: number; media_url: string; topic: string }
export const newQuestion = (type = 'single'): DraftQuestion => ({ type, prompt: '', optionsText: '', answerText: '', pairsText: '', points: 1, media_url: '', topic: '' })
export function draftFromQuestion(q: Question): DraftQuestion {
  return { id: q.id, media: q.media, choice_media: q.choice_media, explanation: q.explanation, difficulty: q.difficulty, learning_objective: q.learning_objective, type: q.type, prompt: q.prompt, optionsText: Array.isArray(q.options) ? q.options.join('\n') : '', answerText: Array.isArray(q.answer) ? q.answer.join('\n') : typeof q.answer === 'string' ? q.answer : '', pairsText: q.type === 'matching' && q.answer && typeof q.answer === 'object' && !Array.isArray(q.answer) ? Object.entries(q.answer).map(([left, right]) => left + ' | ' + right).join('\n') : '', points: q.points || 1, topic: q.topic || '', media_url: q.media_url || '' }
}
export function serializeQuestion(q: DraftQuestion): Omit<Question, 'id'> & { id?: string | number } {
  const lines = (text: string) => text.split('\n').map(v => v.trim()).filter(Boolean)
  let options: Question['options'] = lines(q.optionsText)
  let answer: Question['answer'] = q.answerText.trim()
  if (q.type === 'true_false') options = ['Đúng', 'Sai']
  if (q.type === 'multiple' || q.type === 'ordering' || q.type === 'fill_blank') answer = lines(q.answerText)
  if (['short', 'essay', 'file'].includes(q.type)) answer = null
  if (q.type === 'word_builder') options = Array.from(q.answerText.trim()).reverse()
  if (q.type === 'matching') {
    const entries = lines(q.pairsText).map(line => line.split('|').map(v => v.trim())).filter(pair => pair.length === 2)
    options = { left: entries.map(p => p[0]), right: entries.map(p => p[1]).reverse() }
    answer = Object.fromEntries(entries)
  }
  return { ...(q.id ? { id: q.id } : {}), media: q.media || null, choice_media: Object.fromEntries(Object.entries(q.choice_media || {}).filter(([key]) => Array.isArray(options) && options.includes(key))), explanation: q.explanation || null, difficulty: q.difficulty || undefined, learning_objective: q.learning_objective || null, type: q.type, kind: q.type, prompt: q.prompt, options, answer, points: q.points, topic: q.topic, media_url: q.media_url || undefined }
}
export function QuestionEditor({ question: q, index, onChange, onRemove, allowedTypes }: { question: DraftQuestion; index: number; onChange: (q: DraftQuestion) => void; onRemove: () => void; allowedTypes?: string[] }) {
  const change = (patch: Partial<DraftQuestion>) => onChange({ ...q, ...patch })
  return <fieldset className="question-editor"><legend>Câu {index + 1}</legend><div className="form-grid"><label>Dạng câu hỏi<select value={q.type} onChange={e => change({ type: e.target.value })}>{(allowedTypes || Object.keys(questionTypes)).map(t => <option value={t} key={t}>{questionTypes[t]}</option>)}</select></label><label>Điểm<input type="number" min={0.1} max={100} step={0.1} required value={q.points} onChange={e => change({ points: Number(e.target.value) })}/></label></div><label>Nội dung câu hỏi<textarea required rows={2} value={q.prompt} onChange={e => change({ prompt: e.target.value })}/></label>
    {q.type === 'matching' ? <label>Các cặp tương ứng (mỗi dòng: từ | nghĩa)<textarea required rows={4} placeholder={'cat | con mèo\ndog | con chó'} value={q.pairsText} onChange={e => change({ pairsText: e.target.value })}/></label> : !['short', 'essay', 'file'].includes(q.type) && <>
      {['single', 'multiple', 'ordering', 'image', 'audio'].includes(q.type) && <label>Các lựa chọn (mỗi dòng một lựa chọn)<textarea required rows={3} value={q.optionsText} onChange={e => change({ optionsText: e.target.value })}/></label>}
      {['single', 'true_false', 'image', 'audio'].includes(q.type) ? <label>Đáp án đúng<select required value={q.answerText} onChange={e => change({ answerText: e.target.value })}><option value="">Chọn đáp án</option>{(q.type === 'true_false' ? ['Đúng', 'Sai'] : q.optionsText.split('\n').map(v => v.trim()).filter(Boolean)).map((v, i) => <option key={i} value={v}>{v}</option>)}</select></label> : <label>{q.type === 'word_builder' ? 'Từ cần xây dựng' : q.type === 'ordering' ? 'Thứ tự đúng (mỗi dòng một lựa chọn)' : 'Đáp án đúng (mỗi dòng một đáp án được chấp nhận)'}<textarea required rows={2} value={q.answerText} onChange={e => change({ answerText: e.target.value })}/></label>}
    </>}
    {q.type === 'audio' && <label>Đường dẫn {q.type === 'audio' ? 'âm thanh' : 'hình ảnh'} được phép sử dụng (HTTPS)<input required type="url" pattern="https://.*" value={q.media_url} onChange={e => change({ media_url: e.target.value })}/></label>}
    <MediaEditor value={q.media} onChange={media => change({ media, ...(media ? { media_url: '' } : {}) })}/>{q.type === 'image' && <label>Hoặc đường dẫn ảnh HTTPS<input type="url" pattern="https://.*" value={q.media_url} onChange={e => change({ media_url: e.target.value, ...(e.target.value ? { media: null } : {}) })}/></label>}
    {['single', 'image', 'multiple', 'true_false'].includes(q.type) && <details><summary>Hình ảnh trong lựa chọn</summary>{(q.type === 'true_false' ? ['Đúng', 'Sai'] : q.optionsText.split('\n').map(v => v.trim()).filter(Boolean)).map(option => <MediaEditor key={option} label={'Ảnh: ' + option} value={q.choice_media?.[option]} onChange={media => { const next = { ...q.choice_media }; if (media) next[option] = media; else delete next[option]; change({ choice_media: next }) }}/>)}</details>}
    <div className="form-grid"><label>Độ khó câu hỏi<select value={q.difficulty || 'medium'} onChange={e => change({ difficulty: e.target.value })}><option value="easy">Cơ bản</option><option value="medium">Vận dụng</option><option value="hard">Nâng cao</option></select></label><label>Mục tiêu câu hỏi<input value={q.learning_objective || ''} onChange={e => change({ learning_objective: e.target.value })}/></label></div><label>Giải thích<textarea value={q.explanation || ''} onChange={e => change({ explanation: e.target.value })}/></label><label>Chủ đề<input value={q.topic} onChange={e => change({ topic: e.target.value })}/></label><Button type="button" secondary onClick={onRemove}>Xóa câu hỏi</Button>
  </fieldset>
}
