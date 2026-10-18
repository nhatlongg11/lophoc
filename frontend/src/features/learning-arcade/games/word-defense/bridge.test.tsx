import { describe, expect, it, vi } from 'vitest'
import { DefenseBridge, predictedPosition, predictionSeconds, type DefenseFrame } from './bridge'
import type { Battle } from './types'

const frame = (paused = false, blocked = false): DefenseFrame => ({ battle: { time: 10, paused, status: 'playing' } as Battle, selected: '', lane: 0, blocked, muted: true })

describe('battlefield synchronization', () => {
  it('moves continuously, including across the end of a freeze, without running beyond the core', () => {
    expect(predictedPosition(.2, .1, 10, 10.5, 1)).toBeCloseTo(.2625)
    expect(predictedPosition(.2, .1, 10, 0, 1)).toBeCloseTo(.3)
    expect(predictedPosition(.99, .1, 10, 0, 1)).toBe(1)
  })
  it('freezes presentation on pause or suspension and bounds network prediction', () => {
    expect(predictionSeconds(frame(true), 100, 900)).toBe(0)
    expect(predictionSeconds(frame(false, true), 100, 900)).toBe(0)
    expect(predictionSeconds(frame(), 100, 900)).toBe(.8)
    expect(predictionSeconds(frame(), 100, 9000)).toBe(1.2)
  })
  it('does not reset the prediction clock for UI selection and unsubscribes when destroyed', () => {
    const current = frame(), bridge = new DefenseBridge(current, vi.fn(), vi.fn(), vi.fn())
    const listener = vi.fn(), timestamp = bridge.receivedAt
    bridge.subscribe(listener)
    bridge.publish({ ...current, selected: 'book' })
    expect(bridge.receivedAt).toBe(timestamp)
    expect(listener).toHaveBeenCalledTimes(1)
    bridge.destroy()
    bridge.publish(frame())
    expect(listener).toHaveBeenCalledTimes(1)
  })
})
