package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.ui.v2.shell.UiFlagLatch
import com.echoledger.mobile.ui.v2.shell.UiFlags
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** The interface flags of `GET /api/config` (contract 2.0.0-a) as the phone reads them. */
class UiFlagsTest {
    @Test
    fun aMissingFlagIsOffAndAnUnknownOneIsIgnored() {
        val flags = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.something_new" to true))
        assertTrue(flags.shell)
        assertFalse(flags.library)
        assertFalse(UiFlags.Off.shell)
        assertFalse(UiFlags.Off.offersClassicSwitch)
    }

    @Test
    fun classicTurnsEveryV2FlagOffButNotLiveAssist() {
        val flags = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "live_assist" to true), classic = true)
        assertFalse(flags.shell)
        assertFalse(flags.library)
        assertTrue(flags.isOn(UiFlags.LIVE_ASSIST))
        // The switch stays in Settings so the person can come back.
        assertTrue(flags.offersClassicSwitch)
    }

    @Test
    fun theClassicSwitchShowsWhileAnyV2FlagIsOn() {
        assertTrue(UiFlags(mapOf("ui_v2.settings" to true)).offersClassicSwitch)
        assertFalse(UiFlags(mapOf("live_assist" to true, "ui_v2.shell" to false)).offersClassicSwitch)
        // Only the contract's names count: an unknown ui_v2 name never shows the switch.
        assertFalse(UiFlags(mapOf("ui_v2.something_new" to true)).offersClassicSwitch)
    }

    @Test
    fun aChangeAppliesOnTheNextScreenNeverDuringARecording() {
        val on = UiFlags(mapOf("ui_v2.shell" to true))
        val latch = UiFlagLatch()
        latch.offer(on)
        assertEquals(UiFlags.Off, latch.current)
        assertEquals(UiFlags.Off, latch.screenOpened(recording = true))
        assertEquals(on, latch.screenOpened(recording = false))
        latch.offer(UiFlags.Off)
        assertEquals(on, latch.current)
        assertEquals(UiFlags.Off, latch.screenOpened(recording = false))
    }
}
