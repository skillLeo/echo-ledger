package com.echoledger.mobile.core

import com.echoledger.mobile.core.ShareGuard.Mode
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class ShareGuardTest {
    @Before fun reset() = ShareGuard.resetForTest()

    @After fun clean() = ShareGuard.resetForTest()

    @Test
    fun `hide mode shows a dot while shared and text otherwise`() {
        assertTrue(ShareGuard.showsText(Mode.HIDE, shared = false, hiddenByUser = false))
        assertFalse(ShareGuard.showsText(Mode.HIDE, shared = true, hiddenByUser = false))
    }

    @Test
    fun `black box and show keep the text while shared`() {
        assertTrue(ShareGuard.showsText(Mode.BLACK_BOX, shared = true, hiddenByUser = false))
        assertTrue(ShareGuard.showsText(Mode.SHOW, shared = true, hiddenByUser = false))
        assertTrue(ShareGuard.secure(Mode.BLACK_BOX))
        assertFalse(ShareGuard.secure(Mode.HIDE))
        assertFalse(ShareGuard.secure(Mode.SHOW))
    }

    @Test
    fun `hidden by the person wins in every mode`() {
        Mode.entries.forEach { mode ->
            assertFalse(mode.name, ShareGuard.showsText(mode, shared = false, hiddenByUser = true))
            assertFalse(mode.name, ShareGuard.showsText(mode, shared = true, hiddenByUser = true))
        }
    }

    @Test
    fun `unknown or missing stored values fall back to hide`() {
        assertEquals(Mode.HIDE, Mode.of(null))
        assertEquals(Mode.HIDE, Mode.of("nonsense"))
        assertEquals(Mode.BLACK_BOX, Mode.of("black_box"))
        assertEquals(Mode.SHOW, Mode.of("show"))
        assertEquals("hide", com.echoledger.mobile.data.AppSettings().screenShareMode)
    }

    @Test
    fun `a share starting and ending collapses and restores the cards`() {
        val seen = mutableListOf<Boolean>()
        val stop = ShareGuard.observe { seen += it }
        ShareGuard.setShared(true)
        assertFalse(ShareGuard.textVisible.value)
        ShareGuard.setShared(false)
        assertTrue(ShareGuard.textVisible.value)
        stop()
        ShareGuard.setShared(true)
        assertEquals("first the current value, then each change, nothing after stop", listOf(true, false, true), seen)
    }

    @Test
    fun `switching to show during a share brings the text back`() {
        ShareGuard.setShared(true)
        assertFalse(ShareGuard.textVisible.value)
        ShareGuard.setMode(Mode.SHOW)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `the tile and the hide button toggle the person's choice`() {
        ShareGuard.toggleHidden()
        assertTrue(ShareGuard.hiddenByUser.value)
        assertFalse(ShareGuard.textVisible.value)
        ShareGuard.toggleHidden()
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `the setting resets with the general group and travels in a settings file`() {
        val changed = com.echoledger.mobile.data.AppSettings(screenShareMode = "show")
        assertEquals("hide", SettingsReset.reset(SettingsGroup.General, changed).screenShareMode)
        assertTrue("screenShareMode" in SettingsTransfer.PORTABLE)
    }
}
