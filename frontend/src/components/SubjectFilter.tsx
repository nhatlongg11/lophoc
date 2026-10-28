export type Subject = { id: number; name: string }

export function subjectParams(value: string): Record<string, string> {
  return value === 'unassigned' ? { unassigned: '1' } : value ? { subject_id: value } : {}
}

export function SubjectFilter({ subjects, value, onChange, includeUnassigned = false }: { subjects: Subject[]; value: string; onChange: (value: string) => void; includeUnassigned?: boolean }) {
  const choices = [{ id: '', name: 'Tất cả môn học' }, ...subjects.map(subject => ({ id: String(subject.id), name: subject.name })), ...(includeUnassigned ? [{ id: 'unassigned', name: 'Chưa phân môn' }] : [])]
  return <nav className="subject-filter" aria-label="Lọc theo môn học">{choices.map(choice => <button type="button" key={choice.id} aria-pressed={value === choice.id} className={value === choice.id ? 'selected' : ''} onClick={() => onChange(choice.id)}>{choice.name}</button>)}</nav>
}
