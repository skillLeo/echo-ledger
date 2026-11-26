package com.echoledger.mobile.ui.v2

import android.app.NotificationManager
import android.view.WindowManager
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.compose.ui.test.swipeRight
import com.echoledger.mobile.calls.CallLines
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.calls.PostCallPrompt
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.EchoToastState
import com.echoledger.mobile.ui.v2.shell.CallLinesHost
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowSettings
import org.robolectric.shadows.ShadowWindowManagerImpl

/**
 * v2.1 FIX-03 inside the app: a choice on the after-call card closes the card at once, and its line is the app's own
 * Material 3 snackbar (one at a time, Undo for 10 s, a swipe left or right closes it), never a floating window. After
 * an Undo flow no window over other apps is left.
 */
class CallLinesTest : ScreenTest() {
    private val wm by lazy { app.getSystemService(WindowManager::class.java) }

    @Before
    fun allowOverlays() {
        ShadowSettings.setCanDrawOverlays(true)
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        // The card's progress line stands still, so only these tests move the clock.
        android.provider.Settings.Global.putFloat(app.contentResolver, android.provider.Settings.Global.ANIMATOR_DURATION_SCALE, 0f)
    }

    @After
    fun closeCard() {
        DecisionPopup.resetForTest()
        DecisionPopup.drainForTest()
        shadowOf(android.os.Looper.getMainLooper()).idle()
        DecisionPopup.resetForTest()
    }

    /**
     * The app on screen: its snackbar host, at the bottom, as the shell has it. Its clock is the test's (the shell gives
     * its own the same way): a line's end time and the snackbar's wait are then counted on one clock, so what the test
     * says about 10 s never depends on how long the phone took between the choice and the first frame.
     */
    private fun inApp() {
        val start = System.currentTimeMillis()
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = remember { EchoToastState { start + compose.mainClock.currentTime } }
                CallLinesHost(toasts)
                Box(Modifier.fillMaxSize()) { EchoToastHost(toasts, Modifier.align(Alignment.BottomCenter)) }
            }
        }
        pumpUntil("the app's host") { CallLines.host != null }
    }

    private fun overlayWindows(): Int = Shadow.extract<ShadowWindowManagerImpl>(wm).views.count {
        (it.layoutParams as? WindowManager.LayoutParams)?.type == WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
    }

    private fun notifications() = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications

    /** What is posted, for a failure's words: each notification's channel and title. */
    private fun posted() = notifications().map { "${it.channelId}: ${it.extras.getCharSequence(android.app.Notification.EXTRA_TITLE)}" }

    private fun asked(id: String) {
        PostCallPrompt.show(
            app,
            app.storeRecording(id = id, state = "pending_decision", sourceApp = "whatsapp", durationMs = 44_000),
            "light"
        )
        assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
        assertEquals("the question is the one window", 1, overlayWindows())
    }

    private fun shown(text: String) = compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()

    /** From the middle of [text] across the screen's width. */
    private fun swipeAway(text: String, left: Boolean) {
        val wide = compose.onRoot().fetchSemanticsNode().size.width.toFloat()
        compose.onNodeWithText(text).performTouchInput {
            if (left) swipeLeft(startX = centerX, endX = centerX - wide) else swipeRight(startX = centerX, endX = centerX + wide)
        }
        compose.waitForIdle()
    }

    @Test
    fun `a choice is the app's snackbar, its Undo asks again, and after the Undo flow no overlay window is left`() {
        inApp()
        asked("call-1")
        DecisionPopup.transcribe(app)
        assertEquals("the choice closes the card at once", 0, overlayWindows())
        compose.onNodeWithText("Transcribing…").assertIsDisplayed()
        assertTrue("no notification on top of it: ${posted()}", notifications().isEmpty())
        pumpUntil("kept to be transcribed") { app.peekRecording("call-1")?.transcribe == true }

        compose.onNodeWithText("Undo").performClick()
        pumpUntil("asked again") { DecisionPopup.showingFor == "call-1" && DecisionPopup.showingMode == DecisionPopup.Mode.ASK }
        pumpUntil("waiting again, with nothing decided") { app.peekRecording("call-1")?.state == "pending_decision" }
        assertNull(app.peekRecording("call-1")?.transcribe)
        compose.onNodeWithText("Transcribing…").assertDoesNotExist()

        DecisionPopup.delete(app)
        assertEquals(0, overlayWindows())
        compose.onNodeWithText("Deleted").assertIsDisplayed()
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        compose.onNodeWithText("Undo").performClick()
        pumpUntil("asked again") { DecisionPopup.showingFor == "call-1" && DecisionPopup.showingMode == DecisionPopup.Mode.ASK }
        pumpUntil("back, its file kept") { app.peekRecording("call-1")?.state == "pending_decision" }
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())

        DecisionPopup.audio(app, chosen = true)
        compose.onNodeWithText("Saved as audio").assertIsDisplayed()
        swipeAway("Saved as audio", left = true)
        assertEquals("no overlay window after the Undo flow", 0, overlayWindows())
        assertTrue("no notification after the Undo flow: ${posted()}", notifications().isEmpty())
    }

    @Test
    fun `a swipe left or right closes a call's snackbar, and its choice stands`() {
        inApp()
        asked("call-1")
        DecisionPopup.transcribe(app)
        compose.onNodeWithText("Transcribing…").assertIsDisplayed()
        swipeAway("Transcribing…", left = true)
        compose.onNodeWithText("Transcribing…").assertDoesNotExist()

        asked("call-2")
        DecisionPopup.delete(app)
        compose.onNodeWithText("Deleted").assertIsDisplayed()
        swipeAway("Deleted", left = false)
        compose.onNodeWithText("Deleted").assertDoesNotExist()

        pumpUntil("call-1 still to be transcribed") {
            app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == true } ==
                true
        }
        pumpUntil("call-2 still on its way out") { app.peekRecording("call-2")?.state == Decisions.DISCARDING }
        assertTrue("its file waits for the delete's time, not for the swipe", app.graph.recordings.encryptedFile("call-2").exists())
        assertEquals(0, overlayWindows())
    }

    @Test
    fun `two quick choices never show two messages at once, and Undo stays 10 seconds`() {
        inApp()
        compose.mainClock.autoAdvance = false
        asked("call-1")
        DecisionPopup.transcribe(app)
        asked("call-2")
        // The delete's time is the choice's plus its wait: measured from the choice, never from a slower machine's now.
        val chosenAt = System.currentTimeMillis()
        DecisionPopup.delete(app)
        compose.mainClock.advanceTimeByFrame()
        assertTrue("the first choice's line", shown("Transcribing…"))
        assertTrue("never two at once", !shown("Deleted"))

        compose.mainClock.advanceTimeBy(CallLines.LONG_MS - 200)
        assertTrue("its Undo stays 10 s", shown("Transcribing…"))
        compose.mainClock.advanceTimeBy(400)
        assertTrue("then it goes", !shown("Transcribing…"))
        assertTrue("and the next one shows, alone", shown("Deleted"))

        // The second line waited, so its file waits for its whole time too.
        pumpUntil("call-2 on its way out") { app.peekRecording("call-2")?.state == Decisions.DISCARDING }
        val due = java.time.Instant.parse(app.peekRecording("call-2")!!.discardAt).toEpochMilli()
        assertTrue("its delete waits for both lines", due - chosenAt >= 2 * CallLines.LONG_MS)
    }
}
