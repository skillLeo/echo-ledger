package com.echoledger.mobile.calls

import android.Manifest
import android.app.NotificationManager
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.overlay.AfterCallChip
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.EchoLedgerTheme
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadows.ShadowLooper
import org.robolectric.shadows.ShadowSettings

/**
 * After a call (since v1.5): "Keep automatically" keeps the call and shows "Saved · … · Undo" for 6 s while the upload
 * waits; "Ask me" shows the ✓ / ✕ chip and keeps the call after 10 s; without the overlay permission a notification
 * does the same job.
 */
class PostCallPromptTest : AppTest() {
    @get:Rule
    val compose = createComposeRule()

    @After
    fun closeChip() {
        DecisionPopup.hide()
    }

    private fun overlayAllowed(allowed: Boolean) = ShadowSettings.setCanDrawOverlays(allowed)

    private fun call(id: String = "call-1", app: String = "whatsapp", state: String = "queued") =
        this.app.storeRecording(id = id, state = state, sourceApp = app, durationMs = 272_000)

    private fun uploadWork(id: String) = WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS)

    private fun notifications() = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications

    @Test
    fun `keep automatically shows Saved for six seconds and the upload waits for it`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, call(), PostCallPrompt.KEEP, "dark")

        assertEquals("call-1", DecisionPopup.showingFor)
        assertEquals(DecisionPopup.Mode.SAVED, DecisionPopup.showingMode)
        assertEquals(listOf(WorkInfo.State.ENQUEUED), uploadWork("call-1").map { it.state })
        ShadowLooper.idleMainLooper(DecisionPopup.SAVED_MS - 1, TimeUnit.MILLISECONDS)
        assertTrue(DecisionPopup.isShowing)
        ShadowLooper.idleMainLooper(2, TimeUnit.MILLISECONDS)
        assertFalse(DecisionPopup.isShowing)
        assertEquals("queued", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `Undo deletes the call and calls off its upload`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, call(), PostCallPrompt.KEEP, "dark")

        runBlocking { Decisions.undoKeep(app, "call-1") }
        ShadowLooper.idleMainLooper()

        assertNull(app.graph.recordings.get("call-1"))
        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue(uploadWork("call-1").all { it.state == WorkInfo.State.CANCELLED })
        assertFalse(DecisionPopup.isShowing)
    }

    @Test
    fun `ask me shows the chip and keeps the call after ten seconds without a tap`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, call(state = "pending_decision"), PostCallPrompt.ASK, "dark")
        assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)

        ShadowLooper.idleMainLooper(DecisionPopup.ASK_KEEP_MS + 1, TimeUnit.MILLISECONDS)
        pumpUntil("kept") { app.peekRecording("call-1")?.state == "queued" }
        assertFalse(DecisionPopup.isShowing)
    }

    @Test
    fun `a decision anywhere closes the chip, and only for its own call`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, call("a", state = "pending_decision"), PostCallPrompt.ASK, "dark")
        PostCallPrompt.show(app, call("b", state = "pending_decision"), PostCallPrompt.ASK, "dark")
        assertEquals("b", DecisionPopup.showingFor)

        runBlocking { Decisions.apply(app, "a", keep = false) }
        ShadowLooper.idleMainLooper()
        assertEquals("b", DecisionPopup.showingFor)

        runBlocking { Decisions.apply(app, "b", keep = false) }
        ShadowLooper.idleMainLooper()
        assertNull(DecisionPopup.showingFor)
        assertNull(app.graph.recordings.get("b"))
    }

    @Test
    fun `without the overlay permission a quiet notification with Undo does the job`() {
        overlayAllowed(false)
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS)

        PostCallPrompt.show(app, call(), PostCallPrompt.KEEP, "dark")

        assertFalse(DecisionPopup.isShowing)
        val posted = notifications().single()
        assertEquals("Saved · WhatsApp call · 4:32", shadowOf(posted).contentTitle.toString())
        assertEquals(listOf("Undo"), posted.actions.map { it.title.toString() })
        assertEquals(DecisionPopup.SAVED_MS, posted.timeoutAfter)
    }

    @Test
    fun `ask me without the overlay permission asks in a notification that keeps after ten seconds`() {
        overlayAllowed(false)
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS)

        PostCallPrompt.show(app, call(state = "pending_decision"), PostCallPrompt.ASK, "dark")

        val posted = notifications().single()
        assertEquals(listOf("Keep", "Discard"), posted.actions.map { it.title.toString() })
        assertEquals(DecisionTimeoutWorker.WINDOW_SECONDS * 1000, posted.timeoutAfter)
    }

    @Test
    fun `a voice note gets no chip`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, call("note-1", "voice_note"), PostCallPrompt.KEEP, "dark")
        assertFalse(DecisionPopup.isShowing)
    }

    @Test
    fun `the chip says who the call was with and its buttons call back`() {
        val rec = call().copy(callerName = "Omar")
        assertEquals("Call with Omar", DecisionPopup.title(rec))
        assertEquals("Phone call", DecisionPopup.title(call(app = "phone_call")))
        var kept = 0
        var discarded = 0
        var titled = 0
        compose.setContent {
            EchoLedgerTheme("light") {
                AfterCallChip(
                    "Call with Omar",
                    252_000,
                    DecisionPopup.Mode.ASK,
                    10_000,
                    onKeep = { kept++ },
                    onDiscard = { discarded++ },
                    onUndo = {},
                    onTitle = { titled++ }
                )
            }
        }

        compose.onNodeWithText("Call with Omar · 4:12").performClick()
        compose.onNodeWithContentDescription("Keep").performClick()
        compose.onNodeWithContentDescription("Discard").performClick()
        assertEquals(1, titled)
        assertEquals(1, kept)
        assertEquals(1, discarded)
    }
}
