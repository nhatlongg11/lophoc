export const grades = ['3', '4', '5'] as const
export const gradeLabel = (value: string) => value === 'unclassified' ? 'Chưa phân lớp' : 'Lớp ' + value
export const gradeParams = (value: string): Record<string, string> => value ? { grade: value } : {}

export function GradePicker({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return <section className="grade-picker"><h2>Chọn lớp</h2><nav className="grade-choices" aria-label="Chọn lớp">{grades.map(grade => <button type="button" key={grade} className={value === grade ? 'selected' : ''} aria-pressed={value === grade} onClick={() => onChange(grade)}>Lớp {grade}</button>)}</nav>{!value && <p>Chọn lớp trước, sau đó chọn môn để xem nội dung phù hợp.</p>}<button className="text-link" type="button" aria-pressed={value === 'unclassified'} onClick={() => onChange('unclassified')}>Bài chưa phân lớp</button></section>
}

export function GradeSelect({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return <label>Lớp<select required value={grades.includes(value as typeof grades[number]) ? value : ''} onChange={event => onChange(event.target.value)}><option value="">Chọn lớp</option>{grades.map(grade => <option key={grade} value={grade}>Lớp {grade}</option>)}</select></label>
}
