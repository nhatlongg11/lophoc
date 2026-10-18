import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import { api } from '../../api'
import { useArcadeSession } from './useArcadeSession'
import type { Session } from './types'

vi.mock('../../api', () => ({ api: vi.fn() }))
afterEach(() => { sessionStorage.clear(); vi.clearAllMocks() })

it('queues a pause behind a pending battle sync and sends the new revision', async () => {
  const initial = { id: 91, revision: 3, status: 'active', current_activity: { id: 'defense_1', engine: 'word_defense' } } as Session
  let completeSync!: (session: Session) => void
  vi.mocked(api).mockImplementationOnce(() => new Promise(resolve => { completeSync = resolve as (session: Session) => void })).mockResolvedValueOnce({ ...initial, revision: 5 })
  const { result } = renderHook(() => useArcadeSession(91, 1, initial))
  let sync!: Promise<void>, pause!: Promise<void>
  act(() => { sync = result.current.send('defense_sync'); pause = result.current.send('defense_pause') })
  expect(api).toHaveBeenCalledTimes(1)

  await act(async () => { completeSync({ ...initial, revision: 4 }); await sync })
  await waitFor(() => expect(api).toHaveBeenCalledTimes(2))
  await act(async () => { await pause })

  expect(api).toHaveBeenLastCalledWith('/arcade/sessions/91/actions', expect.objectContaining({ body: expect.objectContaining({ revision: 4, action: { kind: 'defense_pause', activity_id: 'defense_1' } }) }))
  expect(result.current.session?.revision).toBe(5)
})
