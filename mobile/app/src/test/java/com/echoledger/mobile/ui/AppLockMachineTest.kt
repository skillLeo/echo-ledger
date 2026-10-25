package com.echoledger.mobile.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The app lock decisions, driven with a fake monotonic clock. */
class AppLockMachineTest {
    private var now = 1_000_000L
    private val machine = AppLockMachine { now }

    private fun on(lockAfterMs: Long = LockAfter.IMMEDIATELY) = LockInput(enabled = true, available = true, lockAfterMs = lockAfterMs)

    private val off = LockInput(enabled = false, available = true, lockAfterMs = 0)
    private val noScreenLock = LockInput(enabled = true, available = false, lockAfterMs = 0)

    /** Leaves the app for [awayMs] and comes back. */
    private fun trip(input: LockInput, awayMs: Long) {
        machine.onStop(input)
        now += awayMs
        machine.onStart(input)
    }

    private fun unlocked(input: LockInput): AppLockMachine {
        machine.onSettingsLoaded(off)
        machine.onSettingsChanged(input)
        assertEquals(LockState.Unlocked, machine.state)
        return machine
    }

    // ---- cold start -------------------------------------------------------------------------------------------------------

    @Test
    fun `the state is unknown until the settings have loaded`() {
        assertEquals(LockState.Unknown, machine.state)

        machine.onStop(on())
        machine.onStart(on())
        machine.onSettingsChanged(on())

        assertEquals(LockState.Unknown, machine.state)
    }

    @Test
    fun `settings that arrive late with the lock on lock the app`() {
        machine.onSettingsLoaded(on())

        assertEquals(LockState.Locked, machine.state)
    }

    @Test
    fun `settings with the lock off unlock the app`() {
        machine.onSettingsLoaded(off)

        assertEquals(LockState.Unlocked, machine.state)
    }

    @Test
    fun `a cold start always locks, even with a snapshot saved by the previous process`() {
        val first = AppLockMachine { now }
        first.onSettingsLoaded(off)
        val saved = first.save(process = "old process")

        val afterProcessDeath = AppLockMachine { now }
        afterProcessDeath.restore(saved, currentProcess = "new process")
        afterProcessDeath.onSettingsLoaded(on())

        assertEquals(LockState.Locked, afterProcessDeath.state)
    }

    @Test
    fun `a rotation in the same process keeps the app unlocked`() {
        unlocked(on())
        val saved = machine.save()

        val recreated = AppLockMachine { now }
        recreated.restore(saved)
        recreated.onSettingsLoaded(on())

        assertEquals(LockState.Unlocked, recreated.state)
    }

    @Test
    fun `a rotation keeps the time the app went to the background`() {
        unlocked(on(LockAfter.ONE_MINUTE))
        machine.onStop(on(LockAfter.ONE_MINUTE))

        val recreated = AppLockMachine { now }
        recreated.restore(machine.save())
        now += 61_000
        recreated.onStart(on(LockAfter.ONE_MINUTE))

        assertEquals(LockState.Locked, recreated.state)
    }

    // ---- leaving and coming back --------------------------------------------------------------------------------------------

    @Test
    fun `with Immediately the lock engages as soon as the app goes to the background`() {
        unlocked(on())

        machine.onStop(on())

        assertEquals(LockState.Locked, machine.state)
    }

    @Test
    fun `with After 1 minute a short trip keeps the app unlocked and a long one locks it`() {
        val input = on(LockAfter.ONE_MINUTE)
        unlocked(input)

        machine.onStop(input)
        assertEquals("nothing locks at ON_STOP with a grace period", LockState.Unlocked, machine.state)
        now += 59_999
        machine.onStart(input)
        assertEquals(LockState.Unlocked, machine.state)

        trip(input, awayMs = 60_000)
        assertEquals(LockState.Locked, machine.state)
    }

    @Test
    fun `with After 5 minutes the lock engages only after five minutes away`() {
        val input = on(LockAfter.FIVE_MINUTES)
        unlocked(input)

        trip(input, awayMs = 299_000)
        assertEquals(LockState.Unlocked, machine.state)

        trip(input, awayMs = 300_000)
        assertEquals(LockState.Locked, machine.state)
    }

    @Test
    fun `an unknown grace period counts as Immediately`() {
        unlocked(on(lockAfterMs = 12_345))

        machine.onStop(on(lockAfterMs = 12_345))

        assertEquals(LockState.Locked, machine.state)
        assertEquals(LockAfter.IMMEDIATELY, LockAfter.normalize(-1))
        assertEquals(LockAfter.FIVE_MINUTES, LockAfter.normalize(300_000))
    }

    @Test
    fun `coming back without a recorded trip changes nothing`() {
        unlocked(on())

        machine.onStart(on())

        assertEquals(LockState.Unlocked, machine.state)
        assertNull(machine.backgroundedAt)
    }

    @Test
    fun `trips with the lock off never lock`() {
        machine.onSettingsLoaded(off)

        trip(off, awayMs = 3_600_000)

        assertEquals(LockState.Unlocked, machine.state)
    }

    // ---- settings changes -----------------------------------------------------------------------------------------------------

    @Test
    fun `turning the lock off unlocks`() {
        machine.onSettingsLoaded(on())

        machine.onSettingsChanged(off)

        assertEquals(LockState.Unlocked, machine.state)
    }

    @Test
    fun `turning the lock on never locks by itself`() {
        machine.onSettingsLoaded(off)

        machine.onSettingsChanged(on())

        assertEquals(LockState.Unlocked, machine.state)
    }

    @Test
    fun `a second settings load behaves like a change`() {
        machine.onSettingsLoaded(off)

        machine.onSettingsLoaded(on())

        assertEquals(LockState.Unlocked, machine.state)
    }

    // ---- unlocking ------------------------------------------------------------------------------------------------------------

    @Test
    fun `a successful unlock opens the app`() {
        machine.onSettingsLoaded(on())

        machine.onUnlockSucceeded()

        assertEquals(LockState.Unlocked, machine.state)
    }

    @Test
    fun `a failed or cancelled unlock keeps the app locked`() {
        machine.onSettingsLoaded(on())

        machine.onUnlockFailed()

        assertEquals(LockState.Locked, machine.state)
    }

    @Test
    fun `an unlock result before the settings load does not open the app`() {
        machine.onUnlockSucceeded()

        assertEquals(LockState.Unknown, machine.state)
    }

    // ---- no screen lock: never trap the user -----------------------------------------------------------------------------------

    @Test
    fun `with no screen lock the app opens and the user is told once`() {
        machine.onSettingsLoaded(noScreenLock)

        assertEquals(LockState.Unlocked, machine.state)
        assertTrue(machine.takeNoScreenLockNotice())
        assertFalse("the notice is given once", machine.takeNoScreenLockNotice())
    }

    @Test
    fun `a screen lock removed while the app was away unlocks it instead of trapping the user`() {
        unlocked(on())
        machine.onStop(on())
        assertEquals(LockState.Locked, machine.state)

        now += 1_000
        machine.onStart(noScreenLock)

        assertEquals(LockState.Unlocked, machine.state)
        assertTrue(machine.takeNoScreenLockNotice())

        trip(noScreenLock, awayMs = 1_000)
        assertFalse("not repeated on every return", machine.takeNoScreenLockNotice())
    }

    @Test
    fun `losing the screen lock while locked unlocks`() {
        machine.onSettingsLoaded(on())

        machine.onSettingsChanged(noScreenLock)

        assertEquals(LockState.Unlocked, machine.state)
    }

    @Test
    fun `with the lock off there is nothing to tell`() {
        machine.onSettingsLoaded(LockInput(enabled = false, available = false, lockAfterMs = 0))

        assertFalse(machine.takeNoScreenLockNotice())
    }
}
