package com.echoledger.mobile.calls

import android.Manifest
import android.app.NotificationManager
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.testing.WorkManagerTestInitHelper
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
 * waits; "Ask me" shows the ✓ / Discard chip and keeps the call after 10 s; without the overlay permission a
 * notification does the same job. Since v1.8.1 (bug P-003) the chip's Discard deletes nothing until "Discarded · Undo"
 * is over.
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
    fun `Undo on Saved calls off the upload and discards the call only after its own Undo time`() {
        overlayAllowed(true)
        val rec = call()
        PostCallPrompt.show(app, rec, PostCallPrompt.KEEP, "dark")

        // The chip's Undo on "Saved" (since v1.8.1 a Discard: "Discarded · Undo" first).
        DecisionPopup.discard(app, rec, "dark")
        assertEquals(DecisionPopup.Mode.DISCARDED, DecisionPopup.showingMode)
        pumpUntil("the call to be on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        assertTrue(uploadWork("call-1").all { it.state == WorkInfo.State.CANCELLED })
        assertTrue("nothing deleted yet", app.graph.recordings.encryptedFile("call-1").exists())

        WorkManagerTestInitHelper.getTestDriver(app)!!.setInitialDelayMet(discardWork("call-1").single().id)
        pumpUntil("the call to be deleted") { app.graph.recordings.get("call-1") == null }
        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
    }

    @Test
    fun `Undo on Discarded after Saved keeps the call again and shows Saved`() {
        overlayAllowed(true)
        val rec = call()
        PostCallPrompt.show(app, rec, PostCallPrompt.KEEP, "dark")
        DecisionPopup.discard(app, rec, "dark")
        pumpUntil("the call to be on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }

        DecisionPopup.undoDiscard(app, "call-1", "dark")
        pumpUntil("Saved again") { DecisionPopup.showingMode == DecisionPopup.Mode.SAVED }
        assertEquals("queued", app.peekRecording("call-1")?.state)
        assertTrue(uploadWork("call-1").any { it.state == WorkInfo.State.ENQUEUED })
        assertTrue(discardWork("call-1").all { it.state == WorkInfo.State.CANCELLED })
    }

    @Test
    fun `a longer Undo time keeps the file that much longer`() {
        call(state = "pending_decision")
        val now = System.currentTimeMillis()
        val discarding = runBlocking { Decisions.discardLater(app, "call-1", nowMs = now, afterMs = 20_000) }!!
        assertEquals(java.time.Instant.ofEpochMilli(now + 20_000).toString(), discarding.discardAt)
        assertTrue(discardWork("call-1").single().initialDelayMillis >= 20_000)
        // The default is unchanged.
        call("call-2", state = "pending_decision")
        val usual = runBlocking { Decisions.discardLater(app, "call-2", nowMs = now) }!!
        assertEquals(java.time.Instant.ofEpochMilli(now + Decisions.DISCARD_AFTER_MS).toString(), usual.discardAt)
    }

    @Test
    fun `a call brought back by Undo waits for a decision with its timer running again`() {
        call(state = "pending_decision")
        runBlocking { Decisions.discardLater(app, "call-1") }
        assertTrue(timeoutWork("call-1").all { it.state == WorkInfo.State.CANCELLED })

        // Undo through the core alone (no chip or notification shows it again).
        runBlocking { Decisions.undoDiscard(app, "call-1") }
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
        assertTrue("kept after 10 s again", timeoutWork("call-1").any { it.state == WorkInfo.State.ENQUEUED })
    }

    @Test
    fun `the final delete and an Undo never interleave`() {
        call(state = "pending_decision")
        runBlocking { Decisions.discardLater(app, "call-1") }
        val inUndo = java.util.concurrent.CountDownLatch(1)
        val release = java.util.concurrent.CountDownLatch(1)
        // An Undo that has read the call and is about to save it back...
        val undo = Thread {
            app.graph.recordings.update("call-1") {
                inUndo.countDown()
                release.await()
                it.copy(state = "pending_decision", discardAt = null, discardedFrom = null)
            }
        }.apply { start() }
        inUndo.await()
        // ...while the delete runs: it waits for the Undo and then finds the call back, so it keeps it.
        val finish = Thread { runBlocking { Decisions.finishDiscard(app, "call-1") } }.apply { start() }
        Thread.sleep(200)
        release.countDown()
        undo.join()
        finish.join()
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())
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
        compose.onNodeWithText("Discard").performClick()
        assertEquals(1, titled)
        assertEquals(1, kept)
        assertEquals(1, discarded)
    }

    @Test
    fun `the chip's Discard is a trash with its word, never an x that reads as close`() {
        var discarded = 0
        compose.setContent {
            EchoLedgerTheme("dark") {
                AfterCallChip("Call with Omar", 252_000, DecisionPopup.Mode.ASK, 10_000, onKeep = {}, onDiscard = {
                    discarded++
                }, onUndo = {}, onTitle = {})
            }
        }

        compose.onNodeWithText("Discard").assertIsDisplayed().assertHasClickAction().performClick()
        assertEquals(1, discarded)
    }

    @Test
    fun `Discarded names the call and offers Undo`() {
        var undone = 0
        compose.setContent {
            EchoLedgerTheme("dark") {
                AfterCallChip("Call with Omar", 252_000, DecisionPopup.Mode.DISCARDED, DecisionPopup.DISCARDED_MS, onKeep = {
                }, onDiscard = {}, onUndo = { undone++ }, onTitle = {})
            }
        }

        compose.onNodeWithText("Discarded · Call with Omar · 4:12").assertIsDisplayed()
        compose.onNodeWithText("Undo").performClick()
        assertEquals(1, undone)
        compose.onAllNodesWithText("Discard").assertCountEquals(0)
    }

    private fun discardWork(id: String) =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork(DiscardLaterWorker.workName(id)).get(10, TimeUnit.SECONDS)

    private fun timeoutWork(id: String) =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork(DecisionTimeoutWorker.workName(id)).get(10, TimeUnit.SECONDS)

    @Test
    fun `Discard on the chip deletes nothing until Discarded and its Undo time are over`() {
        overlayAllowed(true)
        val rec = call(state = "pending_decision")
        PostCallPrompt.show(app, rec, PostCallPrompt.ASK, "dark")

        DecisionPopup.discard(app, rec, "dark")
        assertEquals(DecisionPopup.Mode.DISCARDED, DecisionPopup.showingMode)
        pumpUntil("the call to be on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        assertTrue("the tap deletes nothing", app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue("it waits for nobody any more", app.graph.recordings.pendingDecisions().isEmpty())
        assertTrue("and is not kept after ten seconds", timeoutWork("call-1").all { it.state == WorkInfo.State.CANCELLED })

        ShadowLooper.idleMainLooper(DecisionPopup.DISCARDED_MS + 1, TimeUnit.MILLISECONDS)
        assertFalse("Discarded leaves after 5 s", DecisionPopup.isShowing)
        assertTrue("the file outlasts it, so a late Undo still finds it", app.graph.recordings.encryptedFile("call-1").exists())

        // WorkManager keeps the delete when the app is closed or killed meanwhile; it runs only when the time is over.
        val work = discardWork("call-1").single()
        assertEquals(WorkInfo.State.ENQUEUED, work.state)
        assertTrue(work.initialDelayMillis >= DecisionPopup.DISCARDED_MS)
        WorkManagerTestInitHelper.getTestDriver(app)!!.setInitialDelayMet(work.id)
        pumpUntil("the call to be deleted") { app.graph.recordings.get("call-1") == null }
        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
    }

    @Test
    fun `Undo on Discarded puts the call back on its chip, which keeps it after ten seconds as before`() {
        overlayAllowed(true)
        val rec = call(state = "pending_decision")
        PostCallPrompt.show(app, rec, PostCallPrompt.ASK, "dark")
        DecisionPopup.discard(app, rec, "dark")
        pumpUntil("the call to be on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }

        DecisionPopup.undoDiscard(app, "call-1", "dark")
        pumpUntil("the chip to ask again") { DecisionPopup.showingMode == DecisionPopup.Mode.ASK }
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
        assertNull(app.peekRecording("call-1")?.discardAt)
        assertTrue(discardWork("call-1").all { it.state == WorkInfo.State.CANCELLED })
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())

        ShadowLooper.idleMainLooper(DecisionPopup.ASK_KEEP_MS + 1, TimeUnit.MILLISECONDS)
        pumpUntil("kept") { app.peekRecording("call-1")?.state == "queued" }
    }

    @Test
    fun `a call already sent meanwhile is not shown as discarded`() {
        overlayAllowed(true)
        val rec = call(state = "pending_decision")
        PostCallPrompt.show(app, rec, PostCallPrompt.ASK, "dark")
        app.graph.recordings.update("call-1") { it.copy(state = "uploaded") }

        DecisionPopup.discard(app, rec, "dark")
        pumpUntil("the chip to go") { !DecisionPopup.isShowing }
        assertEquals("uploaded", app.peekRecording("call-1")?.state)
        assertTrue(discardWork("call-1").isEmpty())
    }
}
