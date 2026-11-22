package com.echoledger.mobile.core

import android.view.WindowManager.LayoutParams.FLAG_SECURE
import com.echoledger.mobile.core.ShareGuard.Mode
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
 * Settings → During screen sharing and Block screenshots of EchoLedger, and the state of the floating windows (pure; see
 * PrivateWindowsTest for the windows themselves).
 */
class ShareGuardTest {
    @Before fun reset() = ShareGuard.resetForTest()

    @After fun clean() = ShareGuard.resetForTest()

    @Test
    fun `private shows a dot while shared and text otherwise`() {
        assertTrue(ShareGuard.showsText(Mode.PRIVATE, shared = false, hiddenByUser = false))
        assertFalse(ShareGuard.showsText(Mode.PRIVATE, shared = true, hiddenByUser = false))
    }

    @Test
    fun `show keeps the text while shared`() {
        assertTrue(ShareGuard.showsText(Mode.SHOW, shared = true, hiddenByUser = false))
    }

    @Test
    fun `the two sharing modes are Private and Show`() {
        assertEquals(listOf(Mode.PRIVATE, Mode.SHOW), Mode.entries.toList())
    }

    @Test
    fun `the floating windows are secure in Private and while Block screenshots is on, readable only in Show`() {
        // Android's screen-recording callback never reports an overlay window, so Private cannot wait for a signal.
        assertTrue(ShareGuard.secureWindows(Mode.PRIVATE, block = false))
        assertTrue(ShareGuard.secureWindows(Mode.PRIVATE, block = true))
        assertTrue(ShareGuard.secureWindows(Mode.SHOW, block = true))
        assertFalse(ShareGuard.secureWindows(Mode.SHOW, block = false))
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
    fun `hidden by the person wins in every mode`() {
        Mode.entries.forEach { mode ->
            assertFalse(mode.name, ShareGuard.showsText(mode, shared = false, hiddenByUser = true))
            assertFalse(mode.name, ShareGuard.showsText(mode, shared = true, hiddenByUser = true))
        }
    }

    @Test
    fun `hide, the old black box, unknown and missing values are all private, and private is stored as hide`() {
        assertEquals(Mode.PRIVATE, Mode.of(null))
        assertEquals(Mode.PRIVATE, Mode.of("nonsense"))
        assertEquals(Mode.PRIVATE, Mode.of("hide"))
        assertEquals("v1.8.1 builds stored Black box this way", Mode.PRIVATE, Mode.of("black_box"))
        assertEquals(Mode.SHOW, Mode.of("show"))
        assertEquals("older builds read hide as protected", "hide", Mode.PRIVATE.wire)
        assertEquals("hide", com.echoledger.mobile.data.AppSettings().screenShareMode)
    }

    @Test
    fun `the windows start private and secure, with the app's screenshots allowed, until the settings are read`() {
        assertEquals(Mode.PRIVATE, ShareGuard.mode.value)
        assertTrue("a window that opens first is never readable in a screen share", ShareGuard.windowsSecure)
        assertFalse("screenshots of the app are allowed by default, also while recording", ShareGuard.block.value)
    }

    @Test
    fun `a screen share starting and ending shrinks and restores the windows`() {
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
    fun `switching to show during a screen share brings the text back`() {
        ShareGuard.setShared(true)
        assertFalse(ShareGuard.textVisible.value)
        ShareGuard.setMode(Mode.SHOW)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `secure-flag listeners hear the current flag, then each change of it once`() {
        val seen = mutableListOf<Boolean>()
        val stop = ShareGuard.observeSecure { seen += it }
        ShareGuard.setMode(Mode.SHOW) // Private → Show: plain
        ShareGuard.setMode(Mode.SHOW)
        ShareGuard.setHidden(true)
        ShareGuard.setBlock(true) // Show with Block: secure
        ShareGuard.setMode(Mode.PRIVATE) // still secure: no news
        ShareGuard.setBlock(false) // Private alone: still secure
        ShareGuard.setMode(Mode.SHOW) // plain
        stop()
        ShareGuard.setMode(Mode.PRIVATE)
        assertEquals(listOf(true, false, true, false), seen)
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
                ShareGuard.setMode(if (i % 2 == 0) Mode.SHOW else Mode.PRIVATE)
                ShareGuard.setShared(i % 3 == 0)
            }
            ShareGuard.setMode(Mode.PRIVATE)
            ShareGuard.setShared(false)
        }
        start.countDown()
        (togglers + setting).forEach { it.join(TimeUnit.SECONDS.toMillis(30)) }

        assertEquals("every tap counted once: as many hides as shows", hides.get(), shows.get())
        assertFalse("an even number of taps leaves the windows shown", ShareGuard.hiddenByUser.value)
        assertEquals(
            "what the windows show matches the state",
            ShareGuard.showsText(ShareGuard.mode.value, ShareGuard.shared.value, ShareGuard.hiddenByUser.value),
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
    fun `the settings survive a new process but Hide does not`() {
        val memory = FakeMemory()
        ShareGuard.restore(memory)
        ShareGuard.setMode(Mode.SHOW)
        ShareGuard.setBlock(true)
        assertTrue(ShareGuard.toggleHidden())
        assertEquals(ShareGuard.Saved(Mode.SHOW, block = true), memory.saved)

        ShareGuard.resetForTest() // the process ends (a cached kill, a crash, an update)
        ShareGuard.setHidden(true)
        ShareGuard.setShared(true)
        ShareGuard.restore(memory) // a new one starts

        assertEquals(Mode.SHOW, ShareGuard.mode.value)
        assertTrue("Block screenshots comes back with the process", ShareGuard.block.value)
        assertFalse("Hide is for the session only", ShareGuard.hiddenByUser.value)
        assertFalse("nothing is in a screen share when a process starts", ShareGuard.shared.value)
        assertTrue(ShareGuard.textVisible.value)
        assertTrue("the next tap hides them again", ShareGuard.toggleHidden())
    }

    @Test
    fun `a Hide stored by the first v1_8_1 builds is not brought back`() {
        ShareGuard.setHidden(true)
        ShareGuard.restore(FakeMemory(ShareGuard.Saved(Mode.PRIVATE)))
        assertFalse(ShareGuard.hiddenByUser.value)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `with nothing saved a process starts private with screenshots allowed and the windows shown`() {
        ShareGuard.setMode(Mode.SHOW)
        ShareGuard.setBlock(true)
        ShareGuard.setHidden(true)
        ShareGuard.restore(FakeMemory())
        assertEquals(Mode.PRIVATE, ShareGuard.mode.value)
        assertFalse(ShareGuard.block.value)
        assertFalse(ShareGuard.hiddenByUser.value)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    fun `only a change of the setting is written, never Hide`() {
        val memory = FakeMemory()
        ShareGuard.restore(memory)
        ShareGuard.setShared(true)
        ShareGuard.setShared(false)
        ShareGuard.setMode(Mode.PRIVATE)
        ShareGuard.setHidden(true)
        ShareGuard.setHidden(false)
        assertEquals(0, memory.writes)
        ShareGuard.setMode(Mode.SHOW)
        assertEquals(1, memory.writes)
        ShareGuard.setBlock(true)
        assertEquals(2, memory.writes)
    }

    @Test
    fun `a memory that fails never stops hide or the setting`() {
        val broken = object : ShareGuard.Memory {
            override fun read(): ShareGuard.Saved = error("unreadable")

            override fun write(saved: ShareGuard.Saved) = error("disk full")
        }
        ShareGuard.restore(broken)
        assertEquals(Mode.PRIVATE, ShareGuard.mode.value)
        assertTrue(ShareGuard.toggleHidden())
        ShareGuard.setMode(Mode.SHOW)
        assertEquals(Mode.SHOW, ShareGuard.mode.value)
        assertFalse(ShareGuard.textVisible.value)
    }

    @Test
    fun `the setting resets with the general group and travels in a settings file`() {
        val changed = com.echoledger.mobile.data.AppSettings(screenShareMode = "show")
        assertEquals("hide", SettingsReset.reset(SettingsGroup.General, changed).screenShareMode)
        assertTrue("screenShareMode" in SettingsTransfer.PORTABLE)
    }

    private companion object {
        /** Even, so the four togglers end where they began. */
        const val TOGGLES = 20_000
    }
}
