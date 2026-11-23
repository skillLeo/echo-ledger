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
import org.robolectric.annotation.Config
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowSettings
import org.robolectric.shadows.ShadowWindowManagerImpl

/**
 * The owner's decision (2.0.5): the recording controls and the after-call card never carry FLAG_SECURE, whatever the
 * settings, so they show in screenshots and screen shares. The live-words windows (captions, Live Assist, Live
 * Translate's box, grip, dot and toolbar) carry it while Settings › Privacy & data › Hide EchoLedger's live words is on
 * (the default), on every Android version: from their first frame, through every change they make, and when the setting
 * changes while they are open; off, they are plain. Android's screen-recording callback never reports an overlay window
 * (AOSP ScreenRecordingCallbackController counts activities only), so the flag cannot wait for it. The flags are read
 * from what the window manager holds (each window's own copy), not from the params object the overlay keeps.
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
        ShareWatch.registrar = null
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

    private fun live(hidden: Boolean) = runBlocking { app.graph.settings.update { it.copy(hideLiveWords = hidden) } }

    private fun block(on: Boolean) = runBlocking { app.graph.settings.update { it.copy(blockScreens = on) } }

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
        assertTrue(DecisionPopup.show(app, rec, "dark", DecisionPopup.Mode.ASK))
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
        assertTrue(StartBanner.show(app, "dark") {})
        closers += { StartBanner.hide() }
        assistCard()
        return liveTranslate()
    }

    /** The windows that show live words, and the ones that always show. */
    private val liveWords = usual.filter { it.liveWords }.map { it.title }.toSet()
    private val alwaysShown = usual.filterNot { it.liveWords }.map { it.title }.toSet()

    private fun assertSecureAsDecided(why: String, hidden: Boolean) {
        val shown = onScreen()
        assertEquals("every kind of floating window is on screen", usual.map { it.title }.toSet(), shown.keys)
        shown.forEach { (title, flags) ->
            when (title) {
                in alwaysShown -> assertFalse("$why: $title always shows", secure(flags))
                else -> assertEquals("$why: $title", hidden, secure(flags))
            }
        }
    }

    @Test
    fun `by default the live-words windows are secure from their first frame and the pill and the card are not`() {
        assertTrue(AppSettingsDefaults.hideLiveWords)
        assertEquals(
            setOf(FloatingWindow.RECORDING_CONTROLS.title, FloatingWindow.AFTER_CALL_CARD.title, FloatingWindow.START_BANNER.title),
            alwaysShown
        )
        everyWindow()
        assertSecureAsDecided("by default", hidden = true)
    }

    @Test
    fun `with the switch off every floating window is plain, and Block screenshots never changes the floating windows`() {
        live(false)
        everyWindow()
        assertSecureAsDecided("switch off", hidden = false)
        block(true)
        assertSecureAsDecided("switch off with Block screenshots", hidden = false)
        live(true)
        assertSecureAsDecided("switch on with Block screenshots", hidden = true)
    }

    @Test
    fun `each window keeps its own flags beside the secure one`() {
        everyWindow()
        usual.forEach { window ->
            val flags = flagsOf(window)
            assertEquals("${window.title} keeps its flags", window.flags, flags and window.flags)
            // Android adds hardware acceleration to a window of a hardware-accelerated app; nothing else is added.
            assertEquals("${window.title} gains only FLAG_SECURE", 0, flags and (window.flags or FLAG_SECURE or HARDWARE).inv())
            assertEquals(window.title, window.liveWords, secure(flags))
        }
    }

    private companion object {
        const val HARDWARE = WindowManager.LayoutParams.FLAG_HARDWARE_ACCELERATED
        val AppSettingsDefaults = com.echoledger.mobile.data.AppSettings()

        /** WindowManager.SCREEN_RECORDING_STATE_VISIBLE and _NOT_VISIBLE (Android 15). */
        const val SHARED = 1
        const val NOT_SHARED = 0
    }

    @Test
    fun `changing the setting reaches the windows already open, both ways`() {
        everyWindow()
        assertSecureAsDecided("open", hidden = true)
        live(false)
        assertSecureAsDecided("turned off", hidden = false)
        live(true)
        assertSecureAsDecided("turned on again", hidden = true)
    }

    @Test
    fun `the switch turned off over an open Live Translate box clears its flag, and the card never had one`() {
        afterCallCard()
        liveTranslate(toolbar = false)
        assertFalse(secure(flagsOf(FloatingWindow.AFTER_CALL_CARD)))
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))

        live(false)

        assertFalse(secure(flagsOf(FloatingWindow.AFTER_CALL_CARD)))
        assertFalse(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertFalse(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE_GRIP)))
    }

    @Test
    fun `a setting changed on another thread reaches the windows through the main thread`() {
        live(false)
        captions()
        liveTranslate(toolbar = false)
        assertFalse(secure(flagsOf(FloatingWindow.CAPTIONS)))
        // Saved from a background thread, the way a settings import or reset does it.
        val change = Thread { runBlocking { app.graph.settings.update { it.copy(hideLiveWords = true) } } }
        change.start()
        change.join(10_000)
        assertFalse("the change finished", change.isAlive)
        assertTrue(ShareGuard.hideLive.value)
        assertFalse("not from the other thread itself", secure(flagsOf(FloatingWindow.CAPTIONS)))
        idle()
        assertTrue(secure(flagsOf(FloatingWindow.CAPTIONS)))
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE_GRIP)))
    }

    @Test
    fun `a window opened before the store has loaded hides live words at once, then follows the store`() {
        live(false)
        // A new process whose small memory is empty (the first start after the update): hidden until the store answers.
        ShareGuard.resetForTest()
        liveTranslate(toolbar = false)
        afterCallCard()

        assertTrue("secure at once, before the settings store answered", secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertFalse("the card shows at once", secure(flagsOf(FloatingWindow.AFTER_CALL_CARD)))
        DecisionPopup.hide() // its progress line would run out while the looper turns
        pumpUntil("the stored choice to arrive") { !secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)) }
        assertFalse(ShareGuard.hideLive.value)
    }

    @Test
    fun `the choice kept from the last process gives the first frame its flag`() {
        live(false)
        assertEquals("every saved change reaches the memory", false, ShareGuardMemory(app).read()?.hideLive)
        ShareGuard.resetForTest()
        ShareGuard.restore(ShareGuardMemory(app)) // a new process
        captions()
        assertFalse("off is honoured from the first frame", secure(flagsOf(FloatingWindow.CAPTIONS)))
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

        live(false)
        box.state(BoxState.LOCKED)
        assertEquals(TranslateOverlay.LOCKED_FLAGS, flagsOf(FloatingWindow.LIVE_TRANSLATE))
    }

    @Test
    fun `the recording controls stay plain when they move, whatever the settings`() {
        block(true)
        val controls = pill()
        controls.avoid(400)
        controls.avoid(null)
        assertFalse(secure(flagsOf(FloatingWindow.RECORDING_CONTROLS)))
        live(false)
        live(true)
        assertFalse(secure(flagsOf(FloatingWindow.RECORDING_CONTROLS)))
    }

    @Test
    fun `closed windows are forgotten and a later change leaves them alone`() {
        val before = PrivateWindows.count
        val controls = pill()
        afterCallCard()
        captions()
        assertEquals(before + 3, PrivateWindows.count)

        controls.hide()
        DecisionPopup.hide()
        closers.forEach { runCatching { it() } }
        closers.clear()
        assertEquals(before, PrivateWindows.count)
        live(false)
        assertTrue(onScreen().isEmpty())
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

    // ---- the screen-recording callback (Android 15+): the windows hide during a share, without FLAG_SECURE -----------

    /** Android's screen-recording callback as the windows meet it: [state] is what Android says now. */
    private class FakeRecordings(var state: Int = NOT_SHARED) : ShareWatch.Registrar {
        val callbacks = mutableListOf<java.util.function.Consumer<Int>>()
        var removed = 0

        override fun add(context: android.content.Context, callback: java.util.function.Consumer<Int>): Int {
            callbacks += callback
            return state
        }

        override fun remove(context: android.content.Context, callback: java.util.function.Consumer<Int>) {
            assertTrue("only a registered callback is removed", callbacks.remove(callback))
            removed++
        }

        fun say(now: Int) {
            state = now
            callbacks.toList().forEach { it.accept(now) }
        }
    }

    private fun recordings(state: Int = NOT_SHARED): FakeRecordings {
        shadowOf(app).grantPermissions(ShareWatch.PERMISSION)
        return FakeRecordings(state).also { ShareWatch.registrar = it }
    }

    @Test
    @Config(sdk = [35])
    fun `on Android 15 a screen share hides the live words to the grey dot and its end shows them again`() {
        val android = recordings()
        liveTranslate(toolbar = false)
        pill()
        assertEquals("one callback for all the windows", 1, android.callbacks.size)
        assertTrue(ShareGuard.textVisible.value)
        assertEquals(null, dotView())

        android.say(SHARED)
        idle()
        assertTrue(ShareGuard.shared.value)
        assertFalse("the live words hide in the share", ShareGuard.textVisible.value)
        assertEquals(View.INVISIBLE, boxView().visibility)
        assertTrue("the grey dot is there instead", dotView() != null)
        assertTrue("the box stays a black box too", secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertFalse("the pill always shows", secure(flagsOf(FloatingWindow.RECORDING_CONTROLS)))
        assertTrue("the pill keeps its words: only the live words hide", ShareGuard.hiddenByUser.value.not())

        android.say(NOT_SHARED)
        idle()
        assertFalse(ShareGuard.shared.value)
        assertTrue(ShareGuard.textVisible.value)
        assertEquals(View.VISIBLE, boxView().visibility)
        assertEquals(null, dotView())
    }

    @Test
    @Config(sdk = [35])
    fun `on Android 15 a share already running when the first window opens hides it at once`() {
        recordings(state = SHARED)
        liveTranslate(toolbar = false)
        assertFalse(ShareGuard.textVisible.value)
        assertEquals(View.INVISIBLE, boxView().visibility)
    }

    @Test
    @Config(sdk = [35])
    fun `on Android 15 with the switch off the windows stay readable in a share`() {
        val android = recordings()
        live(false)
        pill()
        pumpUntil("the switch to reach the windows") { !ShareGuard.hideLive.value }
        liveTranslate(toolbar = false)

        android.say(SHARED)
        idle()
        assertTrue(ShareGuard.shared.value)
        assertTrue(ShareGuard.textVisible.value)
        assertEquals(View.VISIBLE, boxView().visibility)
        assertEquals(null, dotView())
    }

    @Test
    @Config(sdk = [35])
    fun `the last window closing stops listening and forgets the share`() {
        val android = recordings()
        val box = liveTranslate(toolbar = false)
        val controls = pill()
        android.say(SHARED)
        assertTrue(ShareGuard.shared.value)

        controls.hide()
        assertEquals("still listening while a window is open", 0, android.removed)
        box.hideAll()
        assertEquals(1, android.removed)
        assertTrue(android.callbacks.isEmpty())
        assertFalse(ShareGuard.shared.value)
        assertTrue(ShareGuard.textVisible.value)
    }

    @Test
    @Config(sdk = [35])
    fun `without the screen-recording permission nothing listens`() {
        val android = FakeRecordings().also { ShareWatch.registrar = it }
        shadowOf(app).denyPermissions(ShareWatch.PERMISSION)
        liveTranslate(toolbar = false)
        assertTrue(android.callbacks.isEmpty())
    }

    @Test
    fun `before Android 15 there is no callback, and the flag keeps the live words out of a share all the same`() {
        val android = recordings()
        liveTranslate(toolbar = false)
        assertTrue(android.callbacks.isEmpty())
        assertTrue(ShareGuard.textVisible.value)
        assertTrue("FLAG_SECURE without any signal", secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        live(false)
        assertFalse(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
    }

    @Test
    @Config(sdk = [29])
    fun `on Android 10 the live words are secure while the switch is on and the pill is not`() {
        liveTranslate(toolbar = false)
        pill()
        assertTrue(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
        assertFalse(secure(flagsOf(FloatingWindow.RECORDING_CONTROLS)))
        live(false)
        assertFalse(secure(flagsOf(FloatingWindow.LIVE_TRANSLATE)))
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
            assertTrue("the dot is secure like every live-words window", secure(flagsOf(FloatingWindow.LIVE_TRANSLATE_DOT)))
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
