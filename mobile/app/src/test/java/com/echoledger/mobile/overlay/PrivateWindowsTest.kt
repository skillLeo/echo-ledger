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

    private fun idle() = shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofMillis(50))

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

    /** Every kind of floating window that is on screen while nothing is hidden (the grey dot shows only while hidden). */
    private val usual = FloatingWindow.entries - FloatingWindow.LIVE_TRANSLATE_DOT

    /** This phone's Live Assist card, on a session that says nothing. */
    private fun assistCard() {
        val quiet = object : com.echoledger.mobile.ui.v2.assist.AssistAdapter {
            override val messages = kotlinx.coroutines.flow.emptyFlow<com.echoledger.mobile.ui.v2.assist.AssistMessage>()
            override val meetingType: com.echoledger.mobile.ui.v2.assist.MeetingType? = null

            override suspend fun snapshot() = emptyList<com.echoledger.mobile.ui.v2.assist.AssistMessage>()

            override fun send(kind: com.echoledger.mobile.ui.v2.assist.SendKind) = Unit

            override fun feedback(suggestionId: String, used: Boolean) = Unit

            override fun reportShown(suggestionId: String, renderMs: Long) = Unit

            override fun end() = Unit
        }
        assertTrue(AssistCardWindow.show(app, quiet))
        closers += { AssistCardWindow.hide() }
    }

    private fun everyWindow(): TranslateOverlay {
        pill()
        captions()
        afterCallCard()
        assistCard()
        return liveTranslate()
    }

    @Test
    fun `in Private every floating window is secure from its first frame`() {
        assertEquals(ShareGuard.Mode.PRIVATE, ShareGuard.mode.value)
        everyWindow()

        val shown = onScreen()
        assertEquals("every kind of floating window is on screen", usual.map { it.title }.toSet(), shown.keys)
        shown.forEach { (title, flags) -> assertTrue("$title has FLAG_SECURE", secure(flags)) }
    }

    @Test
    fun `in Show no floating window is secure`() {
        setting(ShareGuard.Mode.SHOW)
        everyWindow()

        val shown = onScreen()
        assertEquals(usual.map { it.title }.toSet(), shown.keys)
        shown.forEach { (title, flags) -> assertFalse("$title has no FLAG_SECURE", secure(flags)) }
    }

    @Test
    fun `each window keeps its own flags beside the secure one`() {
        everyWindow()
        usual.forEach { window ->
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
        assertEquals(usual.size, onScreen().size)
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

    // ---- Live Translate while the floating windows are hidden (review of v1.8.1) -------------------------------------

    private fun boxView(): android.view.View = Shadow.extract<ShadowWindowManagerImpl>(wm).views.single {
        (it.layoutParams as? WindowManager.LayoutParams)?.title == FloatingWindow.LIVE_TRANSLATE.title
    }

    private fun dotView(): android.view.View? = Shadow.extract<ShadowWindowManagerImpl>(wm).views.firstOrNull {
        (it.layoutParams as? WindowManager.LayoutParams)?.title == FloatingWindow.LIVE_TRANSLATE_DOT.title
    }

    private fun tap(view: android.view.View) {
        val x = view.width / 2f
        val y = view.height / 2f
        val down = android.os.SystemClock.uptimeMillis()
        view.dispatchTouchEvent(android.view.MotionEvent.obtain(down, down, android.view.MotionEvent.ACTION_DOWN, x, y, 0))
        view.dispatchTouchEvent(android.view.MotionEvent.obtain(down, down + 50, android.view.MotionEvent.ACTION_UP, x, y, 0))
        idle()
    }

    @Test
    fun `hidden before Live Translate opens, its box is never drawn, from the first frame`() {
        ShareGuard.setHidden(true)
        liveTranslate(toolbar = false)
        // Checked before the main thread runs anything: no frame with text slips out.
        assertEquals(android.view.View.INVISIBLE, boxView().visibility)
        assertTrue("the grey dot is there at once", dotView() != null)
        assertEquals(android.view.View.GONE, onScreenView(FloatingWindow.LIVE_TRANSLATE_GRIP).visibility)
    }

    private fun onScreenView(window: FloatingWindow): android.view.View = Shadow.extract<ShadowWindowManagerImpl>(wm).views.single {
        (it.layoutParams as? WindowManager.LayoutParams)?.title == window.title
    }

    @Test
    fun `in Game and in Watch mode the grey dot shows the floating windows again`() {
        listOf(false, true).forEach { subtitles ->
            ShareGuard.setHidden(false)
            val box = TranslateOverlay(app, subtitles = subtitles, actions = NoActions)
            assertTrue(box.show(null, 0.8f, showGrip = true))
            closers += { box.hideAll() }
            assertEquals("mode $subtitles: no dot while shown", null, dotView())

            ShareGuard.setHidden(true)
            idle()
            val dot = checkNotNull(dotView()) { "mode $subtitles: the grey dot replaces the grip" }
            assertTrue("the dot is secure like every floating window", secure(flagsOf(FloatingWindow.LIVE_TRANSLATE_DOT)))
            assertEquals(android.view.View.INVISIBLE, boxView().visibility)

            tap(dot)
            assertFalse("mode $subtitles: the tap is Show EchoLedger windows", ShareGuard.hiddenByUser.value)
            idle()
            assertEquals(null, dotView())
            assertEquals(android.view.View.VISIBLE, boxView().visibility)
            box.hideAll()
        }
    }

    @Test
    fun `a hidden box never becomes touchable, and Edit going on locks the moment it is hidden`() {
        val box = liveTranslate(toolbar = false)
        ShareGuard.setHidden(true)
        idle()
        box.state(BoxState.EDIT)
        assertEquals("no Edit while hidden", TranslateOverlay.LOCKED_FLAGS or FLAG_SECURE, flagsOf(FloatingWindow.LIVE_TRANSLATE))

        ShareGuard.setHidden(false)
        idle()
        assertEquals(TranslateOverlay.EDIT_FLAGS or FLAG_SECURE, flagsOf(FloatingWindow.LIVE_TRANSLATE))

        ShareGuard.setHidden(true)
        idle()
        assertEquals(
            "hiding while editing locks at once",
            TranslateOverlay.LOCKED_FLAGS or FLAG_SECURE,
            flagsOf(FloatingWindow.LIVE_TRANSLATE)
        )
    }

    @Test
    fun `while hidden the Live Translate notification offers Show and no Edit, and its Edit leaves the box locked`() {
        val controller = buildService(
            com.echoledger.mobile.translate.LiveTranslateService::class.java,
            android.content.Intent(app, com.echoledger.mobile.translate.LiveTranslateService::class.java)
        ).create()
        controller.get().openForTest()
        idle()
        val nm = app.getSystemService(android.app.NotificationManager::class.java)
        fun actions() = shadowOf(nm).getNotification(com.echoledger.mobile.translate.LiveTranslateService.NOTIFICATION_ID)
            .actions.orEmpty().map { it.title.toString() }
        assertEquals(listOf("Pause", "Edit", "Hide", "Stop"), actions())

        ShareGuard.setHidden(true)
        idle()
        assertEquals("while hidden: Show, and no Edit", listOf("Pause", "Show", "Stop"), actions())
        // The notification's Edit sent anyway (an old notification): the box stays locked.
        controller.withIntent(
            android.content.Intent(app, com.echoledger.mobile.translate.LiveTranslateService::class.java)
                .setAction(com.echoledger.mobile.translate.LiveTranslateService.ACTION_EDIT)
        ).startCommand(0, 2)
        idle()
        assertEquals(TranslateOverlay.LOCKED_FLAGS or FLAG_SECURE, flagsOf(FloatingWindow.LIVE_TRANSLATE))

        controller.withIntent(
            android.content.Intent(app, com.echoledger.mobile.translate.LiveTranslateService::class.java)
                .setAction(com.echoledger.mobile.translate.LiveTranslateService.ACTION_SHOW)
        ).startCommand(0, 3)
        idle()
        assertFalse(ShareGuard.hiddenByUser.value)
        assertEquals(
            "shown again it is still locked: the Edit sent while hidden did nothing",
            TranslateOverlay.LOCKED_FLAGS or FLAG_SECURE,
            flagsOf(FloatingWindow.LIVE_TRANSLATE)
        )
        assertEquals(listOf("Pause", "Edit", "Hide", "Stop"), actions())
        controller.get().onStop()
    }

    @Test
    fun `the notice asking to share the sound goes once Live Translate is on`() {
        val nm = app.getSystemService(android.app.NotificationManager::class.java)
        nm.notify(
            com.echoledger.mobile.translate.LiveTranslateService.NOTIFICATION_PROBLEM_ID,
            androidx.core.app.NotificationCompat.Builder(app, com.echoledger.mobile.translate.LiveTranslateService.CHANNEL)
                .setSmallIcon(com.echoledger.mobile.R.drawable.ic_stat_rec)
                .setContentText("EchoLedger couldn't tell which app is playing, so Android asks to share the sound.")
                .build()
        )
        val controller = buildService(
            com.echoledger.mobile.translate.LiveTranslateService::class.java,
            android.content.Intent(app, com.echoledger.mobile.translate.LiveTranslateService::class.java)
        ).create()
        controller.get().openForTest(com.echoledger.mobile.translate.TranslateMode.WATCH)
        idle()
        assertEquals(null, shadowOf(nm).getNotification(com.echoledger.mobile.translate.LiveTranslateService.NOTIFICATION_PROBLEM_ID))
        controller.get().onStop()
    }

    @Test
    fun `turning the grip on while hidden adds no grip over the grey dot`() {
        ShareGuard.setHidden(true)
        val box = TranslateOverlay(app, subtitles = false, actions = NoActions)
        assertTrue(box.show(null, 0.8f, showGrip = false))
        closers += { box.hideAll() }
        idle()
        box.showGrip(true)
        idle()
        assertEquals("the grey dot stands for it", View.GONE, onScreenView(FloatingWindow.LIVE_TRANSLATE_GRIP).visibility)
        assertTrue(dotView() != null)

        ShareGuard.setHidden(false)
        idle()
        assertEquals(View.VISIBLE, onScreenView(FloatingWindow.LIVE_TRANSLATE_GRIP).visibility)
        assertNull(dotView())
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
