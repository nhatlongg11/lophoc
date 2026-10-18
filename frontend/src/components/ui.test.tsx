import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { Modal, QuestionInput, type Answer, type Question } from './ui'

function AnswerHarness({ question }: { question: Question }) {
  const [value, setValue] = useState<Answer>('')
  return <><QuestionInput question={question} value={value} onChange={setValue}/><output data-testid="answer">{JSON.stringify(value)}</output></>
}
describe('accessible question interactions', () => {
  it('builds a word with repeated letters and resets it', () => {
    render(<AnswerHarness question={{ id: 1, type: 'word_builder', prompt: 'Build', options: ['l', 'a', 'l'] }}/>)
    fireEvent.click(screen.getAllByRole('button', { name: 'l' })[0])
    fireEvent.click(screen.getByRole('button', { name: 'a' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'l' })[1])
    expect(screen.getByTestId('answer')).toHaveTextContent('"lal"')
    fireEvent.click(screen.getByRole('button', { name: 'Xây lại từ' }))
    expect(screen.getAllByRole('button', { name: 'l' })[0]).toBeEnabled()
    expect(screen.getByTestId('answer')).toHaveTextContent('""')
  })
  it('records matching choices by their left-hand concept', () => {
    render(<AnswerHarness question={{ id: 2, type: 'matching', prompt: 'Match', options: { left: ['cat', 'dog'], right: ['chó', 'mèo'] } }}/>)
    fireEvent.change(screen.getByRole('combobox', { name: 'Ghép với cat' }), { target: { value: 'mèo' } })
    fireEvent.change(screen.getByRole('combobox', { name: 'Ghép với dog' }), { target: { value: 'chó' } })
    expect(screen.getByTestId('answer')).toHaveTextContent('{"cat":"mèo","dog":"chó"}')
  })
  it('supports choosing and removing multiple answers', () => {
    render(<AnswerHarness question={{ id: 3, type: 'multiple', prompt: 'Even', options: ['2', '3', '4'] }}/>)
    fireEvent.click(screen.getByRole('checkbox', { name: '2' }))
    fireEvent.click(screen.getByRole('checkbox', { name: '4' }))
    fireEvent.click(screen.getByRole('checkbox', { name: '2' }))
    expect(screen.getByTestId('answer')).toHaveTextContent('["4"]')
  })
  it('keeps dialog focus inside and closes with Escape', () => {
    const close = vi.fn()
    render(<Modal title="Xác nhận" onClose={close}><input aria-label="Nội dung"/><button>Cuối cùng</button></Modal>)
    const dialog = screen.getByRole('dialog', { name: 'Xác nhận' })
    expect(dialog).toHaveFocus()
    const last = screen.getByRole('button', { name: 'Cuối cùng' })
    last.focus()
    fireEvent.keyDown(dialog, { key: 'Tab' })
    expect(screen.getByRole('button', { name: 'Đóng hộp thoại' })).toHaveFocus()
    fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(close).toHaveBeenCalledOnce()
  })
})
