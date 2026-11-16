package com.echoledger.mobile.overlay

import android.os.Looper
import android.view.View
import android.view.WindowManager
import android.view.WindowManager.LayoutParams.FLAG_SECURE
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.core.ShareGuardMemory
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.translate.BoxBackground
import com.echoledger.mobile.translate.BoxPlacement
import com.echoledger.mobile.translate.BoxState
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowSettings
import org.robolectric.shadows.ShadowWindowManagerImpl

/**
 * Every floating window carries FLAG_SECURE in Private (the default) and not in Show: from its first frame, through
 * every change it makes, and when the setting changes while it is open. Android never reports a screen share for a
 * floating window (the S25, docs/SCREEN_SHARE_MATRIX.md), so this flag is the protection. The flags are read from what
 * the window manager holds (each window's own copy), not from the params object the overlay keeps.
 *
 * A change on the main thread reaches the windows at once, so most tests never idle the looper: idling runs animation
 * frames and moves the clock, which would let the after-call card and the Live Translate toolbar time out.
 */
class PrivateWindowsTest : AppTest() {
    private val wm by lazy { app.getSystemService(WindowManager::class.java) }
    private val closers = mutableListOf<() -> Unit>()

    @Before
    fun allowOverlays() = ShadowSettings.setCanDrawOverlays(true)

    @After
    fun closeWindows() {
        closers.asReversed().forEach { runCatching { it() } }
        closers.clear()
        DecisionPopup.hide()
        idle()
    }

    private fun idle() = shadowOf(Looper.getMainLooper()).idle()

    /** Title → flags as the window manager has them, for each EchoLedger window on screen. */
    private fun onScreen(): Map<String, Int> = Shadow.extract<ShadowWindowManagerImpl>(wm).views.mapNotNull { view ->
        val title = (view.layoutParams as? WindowManager.LayoutParams)?.title?.toString() ?: return@mapNotNull null
        title to view.flagsInWindowManager()
    }.filter { it.first.startsWith("EchoLedger") }.toMap()

    /** The window's flags as last sent to the window manager (ViewRootImpl copies them on add and on every update). */
    private fun View.flagsInWindowManager(): Int {
        val root = checkNotNull(parent) { "not in a window" }
        return root.javaClass.getMethod("getWindowFlags").invoke(root) as Int
    }

    private fun secure(flags: Int?) = flags != null && flags and FLAG_SECURE != 0

    private fun flagsOf(window: FloatingWindow): Int = checkNotNull(onScreen()[window.title]) { "${window.title} is not on screen" }

    private fun setting(mode: ShareGuard.Mode) = runBlocking { app.graph.settings.update { it.copy(screenShareMode = mode.wire) } }

    /** Paused, so its dot holds still: the pulse of a live recording never lets the looper go idle. */
    private fun pill() = ControlPill(app, MutableStateFlow(PillState(elapsedMs = 61_000, paused = true)), {
    }, { _, _ -> }, "right", 0.2f).also {
        it.show()
        closers += { it.hide() }
    }

    private fun captions() = CaptionPanel(app, MutableStateFlow(CaptionFeed()), MutableStateFlow(PillState()), CaptionStyle(), {
    }, {}, {}).also {
        it.show()
        closers += { it.hide() }
    }

    private fun afterCallCard() {
        val rec = app.storeRecording(id = "call-1", durationMs = 252_000)
        assertTrue(DecisionPopup.show(app, rec, "dark", DecisionPopup.Mode.SAVED))
    }

    /** Live Translate's box with its grip dot and, from a long-press on the grip, its toolbar. */
    private fun liveTranslate(toolbar: Boolean = true): TranslateOverlay =
        TranslateOverlay(app, subtitles = false, actions = NoActions).also {
            assertTrue(it.show(null, 0.8f, showGrip = true))
            closers += { it.hideAll() }
            if (toolbar) it.showToolbar()
        }

    private fun everyWindow(): TranslateOverlay {
        pill()
        captions()
        afterCallCard()
        return liveTranslate()
    }

    @Test
    fun `in Private every floating window is secure from its first frame`() {
        assertEquals(ShareGuard.Mode.PRIVATE, ShareGuard.mode.value)
        everyWindow()

        val shown = onScreen()
        assertEquals("every kind of floating window is on screen", FloatingWindow.entries.map { it.title }.toSet(), shown.keys)
        shown.forEach { (title, flags) -> assertTrue("$title has FLAG_SECURE", secure(flags)) }
    }

    @Test
    fun `in Show no floating window is secure`() {
        setting(ShareGuard.Mode.SHOW)
        everyWindow()

        val shown = onScreen()
        assertEquals(FloatingWindow.entries.map { it.title }.toSet(), shown.keys)
        shown.forEach { (title, flags) -> assertFalse("$title has no FLAG_SECURE", secure(flags)) }
    }

    @Test
    fun `each window keeps its own flags beside the secure one`() {
        everyWindow()
        FloatingWindow.entries.forEach { window ->
            val flags = flagsOf(window)
            assertEquals("${window.title} keeps its flags", window.flags, flags and window.flags)
            // Android adds hardware acceleration to a window of a hardware-accelerated app; nothing else is added.
            assertEquals("${window.title} gains only FLAG_SECURE", 0, flags and (window.flags or FLAG_SECURE or HARDWARE).inv())
            assertTrue(secure(flags))
        }
    }

    private companion object {
        const val HARDWARE = WindowManager.LayoutParams.FLAG_HARDWARE_ACCELERATED
    }

    @Test
    fun `changing the setting reaches the windows already open, both ways`() {
        setting(ShareGuard.Mode.SHOW)
        everyWindow()
        assertTrue(onScreen().values.none(::secure))

        setting(ShareGuard.Mode.PRIVATE)
        assertEquals(FloatingWindow.entries.size, onScreen().size)
        onScreen().forEach { (title, flags) -> assertTrue("$title became secure", secure(flags)) }

        setting(ShareGuard.Mode.SHOW)
        onScreen().forEach { (title, flags) -> assertFalse("$title is plain again", secure(flags)) }
    }

    @Test
    fun `Show to Private on an open after-call card and an open Live Translate box sets the flag`() {
        setting(ShareGuard.Mode.SHOW)
        afterCallCard()
        liveTranslate(toolbar = false)
        assertFalse(secure(flagsOf(FloatingWindow.AFTER_CALL_CARD)))
        assertFalse(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))

        setting(ShareGuard.Mode.PRIVATE)

        assertTrue(secure(flagsOf(FloatingWindow.AFTER_CALL_CARD)))
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE_GRIP)))
    }

    @Test
    fun `a setting changed on another thread reaches the windows through the main thread`() {
        setting(ShareGuard.Mode.SHOW)
        captions()
        liveTranslate(toolbar = false)
        // Saved from a background thread, the way a settings import or reset does it.
        val change = Thread { runBlocking { app.graph.settings.update { it.copy(screenShareMode = ShareGuard.Mode.PRIVATE.wire) } } }
        change.start()
        change.join()
        assertEquals(ShareGuard.Mode.PRIVATE, ShareGuard.mode.value)
        assertFalse("not from the other thread itself", secure(flagsOf(FloatingWindow.CAPTIONS)))
        idle()
        assertTrue(secure(flagsOf(FloatingWindow.CAPTIONS)))
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE_GRIP)))
    }

    @Test
    fun `a window opened before the setting has loaded is secure, then follows the setting`() {
        setting(ShareGuard.Mode.SHOW)
        // A new process whose small memory is empty (the first start after an update): the setting is not read yet.
        ShareGuard.resetForTest()
        liveTranslate(toolbar = false)
        afterCallCard()

        assertTrue("secure at once, before the settings store answered", secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertTrue(secure(flagsOf(FloatingWindow.AFTER_CALL_CARD)))
        DecisionPopup.hide() // its progress line would run out while the looper turns
        pumpUntil("the stored Show to arrive") { !secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)) }
        assertEquals(ShareGuard.Mode.SHOW, ShareGuard.mode.value)
    }

    @Test
    fun `the setting kept from the last process gives the first frame its flag`() {
        setting(ShareGuard.Mode.SHOW)
        setting(ShareGuard.Mode.PRIVATE)
        assertEquals("every saved change reaches the memory", ShareGuard.Mode.PRIVATE, ShareGuardMemory(app).read()?.mode)

        setting(ShareGuard.Mode.SHOW)
        ShareGuard.resetForTest()
        ShareGuard.restore(ShareGuardMemory(app)) // a new process
        afterCallCard()
        assertFalse("Show is honoured from the first frame", secure(flagsOf(FloatingWindow.AFTER_CALL_CARD)))
    }

    @Test
    fun `Live Translate keeps the flag while it is moved, locked, restyled and hidden`() {
        val box = liveTranslate(toolbar = false)
        listOf(BoxState.EDIT, BoxState.LOCKED, BoxState.PAUSED, BoxState.HIDDEN, BoxState.LOCKED).forEach { state ->
            box.state(state)
            assertTrue("$state keeps FLAG_SECURE", secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        }
        box.state(BoxState.EDIT)
        assertEquals(TranslateOverlay.EDIT_FLAGS or FLAG_SECURE, flagsOf(FloatingWindow.LIVE_TRANSLATE))
        box.style(22, 3, BoxBackground.DARK, showOriginal = true, language = "EN", opacity = 0.7f)
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))

        setting(ShareGuard.Mode.SHOW)
        box.state(BoxState.LOCKED)
        assertEquals(TranslateOverlay.LOCKED_FLAGS, flagsOf(FloatingWindow.LIVE_TRANSLATE))
    }

    @Test
    fun `the recording controls keep the flag when they move`() {
        val controls = pill()
        controls.avoid(400)
        controls.avoid(null)
        assertTrue(secure(flagsOf(FloatingWindow.RECORDING_CONTROLS)))
    }

    @Test
    fun `closed windows are forgotten and a later change leaves them alone`() {
        val before = PrivateWindows.count
        val controls = pill()
        afterCallCard()
        assertEquals(before + 2, PrivateWindows.count)

        controls.hide()
        DecisionPopup.hide()
        assertEquals(before, PrivateWindows.count)
        setting(ShareGuard.Mode.SHOW)
        assertNull(onScreen()[FloatingWindow.RECORDING_CONTROLS.title])
        assertNull(onScreen()[FloatingWindow.AFTER_CALL_CARD.title])
    }

    @Test
    fun `without the overlay permission nothing opens and nothing is counted`() {
        ShadowSettings.setCanDrawOverlays(false)
        val before = PrivateWindows.count
        pill()
        assertFalse(TranslateOverlay(app, subtitles = false, actions = NoActions).show(BoxPlacement(0.1f, 0.1f, 0.5f, 0.2f), 0.8f, true))
        assertEquals(before, PrivateWindows.count)
        assertTrue(onScreen().isEmpty())
    }

    private object NoActions : TranslateBoxActions {
        override fun onGripTap() = Unit

        override fun onDone() = Unit

        override fun onTouched() = Unit

        override fun onPause() = Unit

        override fun onResume() = Unit

        override fun onHide() = Unit

        override fun onStop() = Unit

        override fun onTextSize(step: Int) = Unit

        override fun onOpacity(step: Float) = Unit

        override fun onToggleOriginal() = Unit

        override fun onLanguage() = Unit

        override fun onPlaced(placement: BoxPlacement) = Unit
    }
}
