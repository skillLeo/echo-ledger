package com.echoledger.mobile.core

import android.view.WindowManager.LayoutParams.FLAG_SECURE
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import kotlin.concurrent.thread
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Settings › Privacy & data › Hide EchoLedger's live words (the owner's decision, 2.0.5) and the state of the floating
 * windows (pure; see PrivateWindowsTest for the windows themselves).
 */
class ShareGuardTest {
    @Before fun reset() = ShareGuard.resetForTest()

    @After fun clean() = ShareGuard.resetForTest()

    @Test
    fun `with live words hidden a share shows a dot and text otherwise`() {
        assertTrue(ShareGuard.showsText(hideLive = true, shared = false, hiddenByUser = false))
        assertFalse(ShareGuard.showsText(hideLive = true, shared = true, hiddenByUser = false))
    }

    @Test
    fun `with live words shown a share keeps the text`() {
        assertTrue(ShareGuard.showsText(hideLive = false, shared = true, hiddenByUser = false))
    }

    @Test
    fun `only the live-words windows are ever secure, and only while the switch is on`() {
        assertTrue(ShareGuard.secureWindow(liveWords = true, hideLive = true))
        assertFalse(ShareGuard.secureWindow(liveWords = true, hideLive = false))
        // The pill and the after-call card always show.
        assertFalse(ShareGuard.secureWindow(liveWords = false, hideLive = true))
        assertFalse(ShareGuard.secureWindow(liveWords = false, hideLive = false))
    }

    @Test
    fun `the secure flag leaves every other flag alone`() {
        val others = listOf(0, 0x0008, 0x0010 or 0x0100, 0x0008 or 0x0020 or 0x0100 or 0x40000, 0x7FFFFFFF and FLAG_SECURE.inv())
        others.forEach { flags ->
            assertEquals(flags or FLAG_SECURE, ShareGuard.windowFlags(flags, secure = true))
            assertEquals(flags, ShareGuard.windowFlags(flags, secure = false))
            assertEquals("set twice is set once", flags or FLAG_SECURE, ShareGuard.windowFlags(flags or FLAG_SECURE, secure = true))
            assertEquals("off clears a flag set before", flags, ShareGuard.windowFlags(flags or FLAG_SECURE, secure = false))
        }
    }

    @Test
    fun `hidden by the person wins either way`() {
        listOf(true, false).forEach { hide ->
            assertFalse(ShareGuard.showsText(hide, shared = false, hiddenByUser = true))
            assertFalse(ShareGuard.showsText(hide, shared = true, hiddenByUser = true))
        }
    }

    @Test
    fun `live words start hidden until the settings are read, as the setting's default`() {
        assertTrue("a window that opens first is never readable in a screen share", ShareGuard.hideLive.value)
        assertTrue(com.echoledger.mobile.data.AppSettings().hideLiveWords)
    }

    @Test
    fun `a screen share starting and ending hides and restores the live words`() {
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
    fun `turning the switch off during a screen share brings the text back`() {
        ShareGuard.setShared(true)
        assertFalse(ShareGuard.textVisible.value)
        ShareGuard.setHideLive(false)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `secure-flag listeners hear the current setting, then each change of it once`() {
        val seen = mutableListOf<Boolean>()
        val stop = ShareGuard.observeHideLive { seen += it }
        ShareGuard.setHideLive(false)
        ShareGuard.setHideLive(false)
        ShareGuard.setHidden(true)
        ShareGuard.setShared(true)
        ShareGuard.setHideLive(true)
        stop()
        ShareGuard.setHideLive(false)
        assertEquals(listOf(true, false, true), seen)
    }

    @Test
    fun `the tile and the hide button toggle the person's choice`() {
        assertTrue(ShareGuard.toggleHidden())
        assertTrue(ShareGuard.hiddenByUser.value)
        assertFalse(ShareGuard.textVisible.value)
        assertFalse(ShareGuard.toggleHidden())
        assertTrue(ShareGuard.textVisible.value)
    }

    /**
     * The setting arrives from one thread while taps toggle on another. Before v1.8.1's follow-up a toggle read and wrote
     * in two steps, so taps could get lost and the windows could stay a dot after the last one.
     */
    @Test
    fun `taps and setting changes from several threads never lose a tap or leave a stale dot`() {
        val start = CountDownLatch(1)
        val hides = AtomicInteger()
        val shows = AtomicInteger()
        val togglers = (1..4).map {
            thread {
                start.await()
                repeat(TOGGLES) { if (ShareGuard.toggleHidden()) hides.incrementAndGet() else shows.incrementAndGet() }
            }
        }
        val setting = thread {
            start.await()
            repeat(TOGGLES) { i ->
                ShareGuard.setHideLive(i % 2 != 0)
                ShareGuard.setShared(i % 3 == 0)
            }
            ShareGuard.setHideLive(true)
            ShareGuard.setShared(false)
        }
        start.countDown()
        (togglers + setting).forEach { it.join(TimeUnit.SECONDS.toMillis(30)) }

        assertEquals("every tap counted once: as many hides as shows", hides.get(), shows.get())
        assertFalse("an even number of taps leaves the windows shown", ShareGuard.hiddenByUser.value)
        assertEquals(
            "what the windows show matches the state",
            ShareGuard.showsText(ShareGuard.hideLive.value, ShareGuard.shared.value, ShareGuard.hiddenByUser.value),
            ShareGuard.textVisible.value
        )
        assertTrue(ShareGuard.textVisible.value)
    }

    private class FakeMemory(var saved: ShareGuard.Saved? = null) : ShareGuard.Memory {
        var writes = 0

        override fun read(): ShareGuard.Saved? = saved

        override fun write(saved: ShareGuard.Saved) {
            this.saved = saved
            writes++
        }
    }

    /**
     * Review of v1.8.1: a Hide kept across process death left Live Translate without a way back. So only the settings
     * outlive the process; Hide lasts the session.
     */
    @Test
    fun `the setting survives a new process but Hide does not`() {
        val memory = FakeMemory()
        ShareGuard.restore(memory)
        ShareGuard.setHideLive(false)
        assertTrue(ShareGuard.toggleHidden())
        assertEquals(ShareGuard.Saved(hideLive = false), memory.saved)

        ShareGuard.resetForTest() // the process ends (a cached kill, a crash, an update)
        ShareGuard.setHidden(true)
        ShareGuard.setShared(true)
        ShareGuard.restore(memory) // a new one starts

        assertFalse("the switch comes back with the process", ShareGuard.hideLive.value)
        assertFalse("Hide is for the session only", ShareGuard.hiddenByUser.value)
        assertFalse("nothing is in a screen share when a process starts", ShareGuard.shared.value)
        assertTrue(ShareGuard.textVisible.value)
        assertTrue("the next tap hides them again", ShareGuard.toggleHidden())
    }

    @Test
    fun `a Hide stored by the first v1_8_1 builds is not brought back`() {
        ShareGuard.setHidden(true)
        ShareGuard.restore(FakeMemory(ShareGuard.Saved(hideLive = true)))
        assertFalse(ShareGuard.hiddenByUser.value)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `with nothing saved a process starts with live words hidden and the windows shown`() {
        ShareGuard.setHideLive(false)
        ShareGuard.setHidden(true)
        ShareGuard.restore(FakeMemory())
        assertTrue(ShareGuard.hideLive.value)
        assertFalse(ShareGuard.hiddenByUser.value)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `only a change of the setting is written, never Hide`() {
        val memory = FakeMemory()
        ShareGuard.restore(memory)
        ShareGuard.setShared(true)
        ShareGuard.setShared(false)
        ShareGuard.setHideLive(true)
        ShareGuard.setHidden(true)
        ShareGuard.setHidden(false)
        assertEquals(0, memory.writes)
        ShareGuard.setHideLive(false)
        assertEquals(1, memory.writes)
    }

    @Test
    fun `a memory that fails never stops hide or the setting`() {
        val broken = object : ShareGuard.Memory {
            override fun read(): ShareGuard.Saved = error("unreadable")

            override fun write(saved: ShareGuard.Saved) = error("disk full")
        }
        ShareGuard.restore(broken)
        assertTrue(ShareGuard.hideLive.value)
        assertTrue(ShareGuard.toggleHidden())
        ShareGuard.setHideLive(false)
        assertFalse(ShareGuard.hideLive.value)
        assertFalse(ShareGuard.textVisible.value)
    }

    @Test
    fun `the setting resets with the general group and travels in a settings file`() {
        val changed = com.echoledger.mobile.data.AppSettings(hideLiveWords = false)
        assertTrue(SettingsReset.reset(SettingsGroup.General, changed).hideLiveWords)
        assertTrue("hideLiveWords" in SettingsTransfer.PORTABLE)
    }

    private companion object {
        /** Even, so the four togglers end where they began. */
        const val TOGGLES = 20_000
    }
}
