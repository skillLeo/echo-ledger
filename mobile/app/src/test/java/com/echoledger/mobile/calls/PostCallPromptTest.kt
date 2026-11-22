package com.echoledger.mobile.calls

import android.app.NotificationManager
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.calls.CallChoice.Pick
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.live.CallCardScreen
import java.time.Duration
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadows.ShadowLooper
import org.robolectric.shadows.ShadowSettings

/**
 * After each call (the call-choice plan, 2.0.5): in Ask me the after-call card asks; Transcribe, Delete, a swipe or 8 s
 * without a choice (audio only) each become one line with Undo for 5 s, and Undo really undoes all three: the call
 * waits for the question again, its upload called off or its file kept. Back-to-back calls get one grouped line. The
 * other settings, and a choice made on the pill, ask nothing. Without the overlay permission a notification asks with
 * the same three words.
 */
class PostCallPromptTest : AppTest() {
    @Before
    fun allowOverlays() {
        ShadowSettings.setCanDrawOverlays(true)
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        // Remove animations: the card's progress line stands still, so only these tests move the clock (a running
        // animation's frames would move Robolectric's clock past the card's 8 s).
        android.provider.Settings.Global.putFloat(app.contentResolver, android.provider.Settings.Global.ANIMATOR_DURATION_SCALE, 0f)
    }

    private val usualRefresh = Decisions.refreshWidget

    @After
    fun closeCard() {
        DecisionPopup.hide()
        // Every step this test asked for runs now, under its own stand-ins, and what it posts goes with this test.
        DecisionPopup.drainForTest()
        shadowOf(android.os.Looper.getMainLooper()).idle()
        DecisionPopup.hide()
        Decisions.refreshWidget = usualRefresh
        Decisions.afterUndoPosted = {}
        DecisionPopup.stepTimeoutMs = 30_000L
    }

    private fun call(id: String = "call-1", state: String = "pending_decision") =
        app.storeRecording(id = id, state = state, sourceApp = "whatsapp", durationMs = 44_000)

    private fun uploadWork(id: String) = WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS)

    private fun waitingUpload(id: String) = uploadWork(id).any { it.state == WorkInfo.State.ENQUEUED }

    private fun noUpload(id: String) = uploadWork(id).none { it.state == WorkInfo.State.ENQUEUED || it.state == WorkInfo.State.RUNNING }

    private fun notifications() = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications

    private fun after(ms: Long) = ShadowLooper.idleMainLooper(ms, TimeUnit.MILLISECONDS)

    private fun discardWork(id: String) =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork(DiscardLaterWorker.workName(id)).get(10, TimeUnit.SECONDS)

    private fun timeoutWork(id: String) =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork(DecisionTimeoutWorker.workName(id)).get(10, TimeUnit.SECONDS)

    private fun runDiscardWork(id: String) =
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.setInitialDelayMet(discardWork(id).single().id)

    private fun asked(id: String = "call-1") {
        PostCallPrompt.show(app, call(id), "dark")
        assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
    }

    @Test
    fun `Ask me shows the card and no notification on top of it`() {
        asked()
        assertTrue(DecisionPopup.shown is CallCardScreen.Ask)
        assertEquals("one question at a time", 0, notifications().size)
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `nothing chosen for 8 seconds keeps the audio only, then Saved as audio goes after 5 seconds`() {
        asked()
        after(DecisionPopup.ASK_MS - 100)
        assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
        after(200)
        assertEquals(CallCardScreen.Done(Pick.AUDIO), DecisionPopup.shown)
        pumpUntil("kept as audio") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == false } == true }
        pumpUntil("its upload waits for the line") { waitingUpload("call-1") }
        after(DecisionPopup.DONE_MS + 100)
        assertFalse(DecisionPopup.isShowing)
    }

    @Test
    fun `a swipe keeps the audio only, as the 8 seconds do`() {
        asked()
        DecisionPopup.audio(app, chosen = true)
        assertEquals(CallCardScreen.Done(Pick.AUDIO), DecisionPopup.shown)
        pumpUntil("kept as audio") { app.peekRecording("call-1")?.transcribe == false }
    }

    @Test
    fun `Transcribe keeps the call to be transcribed, and its Undo asks again with nothing decided`() {
        asked()
        DecisionPopup.transcribe(app)
        assertEquals(CallCardScreen.Done(Pick.TRANSCRIBE), DecisionPopup.shown)
        pumpUntil("kept to be transcribed") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == true } == true }
        pumpUntil("its upload waits") { waitingUpload("call-1") }

        DecisionPopup.undo(app)
        assertTrue(DecisionPopup.shown is CallCardScreen.Ask)
        pumpUntil("waiting again") { app.peekRecording("call-1")?.state == "pending_decision" }
        assertNull(app.peekRecording("call-1")?.transcribe)
        pumpUntil("nothing is sent") { noUpload("call-1") }
        // The question has its whole 8 s again.
        after(DecisionPopup.ASK_MS - 100)
        assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
    }

    @Test
    fun `Undo on Saved as audio asks again, and its Transcribe turns it into a transcript`() {
        asked()
        DecisionPopup.audio(app, chosen = false)
        pumpUntil("kept as audio") { app.peekRecording("call-1")?.state == "queued" }
        DecisionPopup.undo(app)
        pumpUntil("waiting again") { app.peekRecording("call-1")?.state == "pending_decision" }
        pumpUntil("nothing is sent") { noUpload("call-1") }

        DecisionPopup.audio(app, chosen = false)
        pumpUntil("kept as audio") { app.peekRecording("call-1")?.transcribe == false }
        DecisionPopup.transcribe(app)
        assertEquals(CallCardScreen.Done(Pick.TRANSCRIBE), DecisionPopup.shown)
        pumpUntil("to be transcribed after all") { app.peekRecording("call-1")?.transcribe == true }
    }

    @Test
    fun `Delete deletes nothing until Deleted and its Undo are over, and the file outlasts the line`() {
        asked()
        DecisionPopup.delete(app)
        assertEquals(CallCardScreen.Done(Pick.DELETE), DecisionPopup.shown)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        assertTrue("the tap deletes nothing", app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue("it waits for nobody any more", app.graph.recordings.pendingDecisions().isEmpty())
        assertTrue("and is not kept after ten seconds", timeoutWork("call-1").all { it.state == WorkInfo.State.CANCELLED })

        after(DecisionPopup.DONE_MS + 1)
        assertFalse("Deleted leaves after 5 s", DecisionPopup.isShowing)
        assertTrue("the file outlasts it, so a late Undo still finds it", app.graph.recordings.encryptedFile("call-1").exists())

        // WorkManager keeps the delete when the app is closed or killed meanwhile; it runs only when the time is over.
        val work = discardWork("call-1").single()
        assertEquals(WorkInfo.State.ENQUEUED, work.state)
        assertTrue("its delay is at least the line's time", work.initialDelayMillis >= DecisionPopup.DONE_MS)
        runDiscardWork("call-1")
        pumpUntil("the call to be deleted") { app.graph.recordings.get("call-1") == null }
        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
    }

    @Test
    fun `Undo on Deleted brings the question back, with its keep timer running again`() {
        asked()
        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }

        DecisionPopup.undo(app)
        assertTrue(DecisionPopup.shown is CallCardScreen.Ask)
        pumpUntil("back") { app.peekRecording("call-1")?.state == "pending_decision" }
        assertNull(app.peekRecording("call-1")?.discardAt)
        pumpUntil("the delete called off") { discardWork("call-1").all { it.state == WorkInfo.State.CANCELLED } }
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue("kept after 10 s again", timeoutWork("call-1").any { it.state == WorkInfo.State.ENQUEUED })
    }

    @Test
    fun `a quick Undo right after Delete is applied after it, never before`() {
        asked()
        // Both taps before the store has done anything: its steps run in the order they were asked for.
        DecisionPopup.delete(app)
        DecisionPopup.undo(app)
        DecisionPopup.transcribe(app)
        pumpUntil("kept to be transcribed") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == true } == true }
        assertNull(app.peekRecording("call-1")?.discardAt)
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue(discardWork("call-1").all { it.state == WorkInfo.State.CANCELLED })
    }

    @Test
    fun `a call that ends while Deleted shows is asked about only after that line, and the Undo stays reachable`() {
        asked("call-1")
        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }

        PostCallPrompt.show(app, call("call-2"), "dark")
        assertEquals("the Deleted line stays", DecisionPopup.Mode.DELETED, DecisionPopup.showingMode)
        assertEquals("call-1", DecisionPopup.showingFor)
        DecisionPopup.undo(app)
        pumpUntil("call-1 back") { app.peekRecording("call-1")?.state == "pending_decision" }
        assertEquals("call-1", DecisionPopup.showingFor)

        // call-1 asked again, then kept as audio after its 8 s; its line ends, and call-2 is asked about.
        after(DecisionPopup.ASK_MS + 1)
        after(DecisionPopup.DONE_MS + 1)
        pumpUntil("call-2 asked") { DecisionPopup.showingFor == "call-2" && DecisionPopup.showingMode == DecisionPopup.Mode.ASK }
        assertEquals("pending_decision", app.peekRecording("call-2")?.state)
    }

    @Test
    fun `a call that ends while Deleted shows waits, and call-1 is deleted only when its line is over`() {
        asked("call-1")
        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        PostCallPrompt.show(app, call("call-2"), "dark")
        after(DecisionPopup.DONE_MS + 1)
        pumpUntil("call-2 asked") { DecisionPopup.showingFor == "call-2" }
        assertTrue("call-1's file is still there for a late Undo", app.graph.recordings.encryptedFile("call-1").exists())
        runDiscardWork("call-1")
        pumpUntil("call-1 deleted") { app.graph.recordings.get("call-1") == null }
        assertEquals("pending_decision", app.peekRecording("call-2")?.state)
    }

    @Test
    fun `a Delete due after its process is gone brings the call back to choose again`() {
        call()
        runBlocking { Decisions.discardLater(app, "call-1", undoHere = true) }
        // As after EchoLedger closed within those seconds: the call names a process that is no longer there.
        app.graph.recordings.update("call-1") { it.copy(undoIn = "a process that has gone") }
        runDiscardWork("call-1")
        pumpUntil("back") { app.peekRecording("call-1")?.state == "pending_decision" }
        val back = app.peekRecording("call-1")!!
        assertTrue("its row says so", back.restoredDelete)
        assertNull(back.discardAt)
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue("it waits for the person, not for a timer", timeoutWork("call-1").all { it.state == WorkInfo.State.CANCELLED })
        assertTrue("nothing is sent", noUpload("call-1"))
        // Never kept by itself later either: not by the hourly 24 h keep (it started long ago), not by a late backstop.
        assertTrue(UndecidedKeepWorker.due(listOf(back), 24, java.time.Instant.now()).isEmpty())
        runBlocking { androidx.work.testing.TestListenableWorkerBuilder<UndecidedKeepWorker>(app).build().doWork() }
        runBlocking {
            androidx.work.testing.TestListenableWorkerBuilder<DecisionTimeoutWorker>(app)
                .setInputData(androidx.work.workDataOf(DecisionTimeoutWorker.KEY_ID to "call-1"))
                .build()
                .doWork()
        }
        assertEquals("still waiting for the person", "pending_decision", app.peekRecording("call-1")?.state)
        assertTrue("still nothing sent", noUpload("call-1"))

        // A notification's Delete keeps its Undo in the shade, which outlives the process: it is applied.
        call("call-2")
        runBlocking { Decisions.discardLater(app, "call-2") }
        runDiscardWork("call-2")
        pumpUntil("call-2 deleted") { app.graph.recordings.get("call-2") == null }
        // And the hourly check follows the same rule.
        call("call-3")
        runBlocking { Decisions.discardLater(app, "call-3", afterMs = 60_000, undoHere = true) }
        app.graph.recordings.update("call-3") { it.copy(undoIn = "a process that has gone") }
        runBlocking { Decisions.finishDiscard(app, "call-3") }
        assertEquals("pending_decision", app.peekRecording("call-3")?.state)
    }

    @Test
    fun `a held call's backstop waits for its question, and is never kept before it was asked`() {
        asked("call-1")
        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        PostCallPrompt.show(app, call("call-2"), "dark")
        assertTrue(DecisionPopup.answersFor("call-2"))

        // Its backstop waits for the line it is held behind, then the whole question.
        val first = timeoutWork("call-2").single { it.state == WorkInfo.State.ENQUEUED }
        assertTrue("${first.initialDelayMillis} ms", first.initialDelayMillis >= DecisionPopup.ASK_MS + DecisionPopup.BACKSTOP_GRACE_MS)
        // Even run early (the clock moved, a long Undo), it keeps nothing while the card still answers for the call.
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.setInitialDelayMet(first.id)
        pumpUntil("the backstop ran and came again") {
            timeoutWork("call-2").any { it.state == WorkInfo.State.ENQUEUED && it.id != first.id }
        }
        assertEquals("not kept before it was asked", "pending_decision", app.peekRecording("call-2")?.state)

        after(DecisionPopup.DONE_MS + 1)
        pumpUntil("call-2 asked") { DecisionPopup.showingFor == "call-2" && DecisionPopup.showingMode == DecisionPopup.Mode.ASK }
        val asked = timeoutWork("call-2").single { it.state == WorkInfo.State.ENQUEUED }
        assertTrue(asked.initialDelayMillis >= DecisionPopup.ASK_MS)
    }

    @Test
    fun `several calls held behind a Deleted line are asked in order, the later ones on the grouped line`() {
        asked("call-1")
        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        PostCallPrompt.show(app, call("call-2"), "dark")
        PostCallPrompt.show(app, call("call-3"), "dark")
        assertTrue(DecisionPopup.answersFor("call-2") && DecisionPopup.answersFor("call-3"))
        assertEquals("call-1", DecisionPopup.showingFor)

        after(DecisionPopup.DONE_MS + 1)
        pumpUntil("both asked, on one line") { DecisionPopup.showingMode == DecisionPopup.Mode.GROUPED }
        assertEquals(CallCardScreen.Grouped(2), DecisionPopup.shown)
        listOf("call-2", "call-3").forEach { id ->
            pumpUntil("$id kept as audio") { app.peekRecording(id)?.let { it.state == "queued" && it.transcribe == false } == true }
        }
    }

    @Test
    fun `a Delete that ends a call never replaces another call's line, and its Undo goes in a notification then`() {
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        asked("call-1")
        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }

        assertFalse(DecisionPopup.deleted(app, call("call-2"), "dark", 3_000))
        assertEquals("call-1's line stays", "call-1", DecisionPopup.showingFor)
        assertEquals(DecisionPopup.Mode.DELETED, DecisionPopup.showingMode)
        pumpUntil("call-2 on its way out") { app.peekRecording("call-2")?.state == Decisions.DISCARDING }
        assertNull("its Undo is in the shade, which outlives the process", app.peekRecording("call-2")?.undoIn)
        pumpUntil("Deleted · Undo in a notification") {
            notifications().any { n -> n.actions.orEmpty().any { it.title.toString() == "Undo" } }
        }

        // With the card free, the line is on the card and its Undo is this process's.
        DecisionPopup.hide()
        assertTrue(DecisionPopup.deleted(app, call("call-3"), "dark", 3_000))
        assertEquals(DecisionPopup.Mode.DELETED, DecisionPopup.showingMode)
        pumpUntil("call-3 on its way out") { app.peekRecording("call-3")?.state == Decisions.DISCARDING }
        assertEquals(Decisions.PROCESS, app.peekRecording("call-3")?.undoIn)
        assertTrue(discardWork("call-3").single().initialDelayMillis >= 3_000)
    }

    /** The shade's "Deleted · …" lines that offer Undo. */
    private fun undoLines(): List<String> = notifications()
        .filter { n -> n.actions.orEmpty().any { it.title.toString() == "Undo" } }
        .map { shadowOf(it).contentTitle.toString() }

    @Test
    fun `a step cut short while the widget refreshes has already posted the Undo, before the delete is due`() {
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        // A widget refresh that never ends: each step's time runs out there. When call-2's step reaches it (call-2 is on
        // its way out by then), what the shade holds is kept.
        val atCall2Refresh = java.util.concurrent.atomic.AtomicReference<List<String>>()
        Decisions.refreshWidget = {
            if (app.peekRecording("call-2")?.state == Decisions.DISCARDING) atCall2Refresh.compareAndSet(null, undoLines())
            kotlinx.coroutines.awaitCancellation()
        }
        DecisionPopup.stepTimeoutMs = 300
        asked("call-1")
        DecisionPopup.delete(app)
        // call-2 ends while call-1's Deleted line shows: its "Deleted · Undo" goes in a notification.
        assertFalse(DecisionPopup.deleted(app, call("call-2"), "dark", 3_000))

        pumpUntil("call-2's step to reach the widget") { atCall2Refresh.get() != null }
        assertEquals("its Undo was in the shade before the widget began", listOf("Deleted · WhatsApp call · 0:44"), atCall2Refresh.get())
        val due = java.time.Instant.parse(app.peekRecording("call-2")!!.discardAt).toEpochMilli()
        assertTrue("and before the delete is due", System.currentTimeMillis() < due)
        assertNull("the shade outlives the process: nothing to bring back", app.peekRecording("call-2")?.undoIn)

        // The cut-short steps held up nothing: the next one (Undo on call-1) still runs.
        DecisionPopup.undo(app)
        pumpUntil("call-1 back") { app.peekRecording("call-1")?.state == "pending_decision" }
    }

    @Test
    fun `a death between the posted Undo and the store leaves only an Undo that finds nothing`() {
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        call()
        Decisions.afterUndoPosted = { throw IllegalStateException("the process died here") }
        assertTrue(runCatching { runBlocking { Decisions.discardWithNotification(app, "call-1") } }.isFailure)

        assertEquals("the Undo was posted first", listOf("Deleted · WhatsApp call · 0:44"), undoLines())
        assertEquals("nothing was deleted or scheduled", "pending_decision", app.peekRecording("call-1")?.state)
        assertTrue(discardWork("call-1").isEmpty())

        // Its Undo finds nothing deleted: the call still waits, with its file, and is simply asked about again.
        Decisions.afterUndoPosted = {}
        app.sendBroadcast(
            android.content.Intent(app, SaveDecisionReceiver::class.java)
                .setAction(SaveDecisionReceiver.ACTION_UNDO_DISCARD)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, "call-1")
        )
        pumpUntil("asked again") { DecisionPopup.showingFor == "call-1" && DecisionPopup.showingMode == DecisionPopup.Mode.ASK }
        assertTrue(undoLines().isEmpty())
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())
    }

    @Test
    fun `with no notification permission the card's fallback Delete keeps its Undo with this process`() {
        shadowOf(app).denyPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        asked("call-1")
        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }

        assertFalse(DecisionPopup.deleted(app, call("call-2"), "dark", 3_000))
        pumpUntil("call-2 on its way out") { app.peekRecording("call-2")?.state == Decisions.DISCARDING }
        assertTrue("no notification could be posted", undoLines().isEmpty())
        assertEquals("so a lost process brings it back to choose again", Decisions.PROCESS, app.peekRecording("call-2")?.undoIn)

        // As after EchoLedger closed: the delete comes due, and the call waits again instead of going unseen.
        app.graph.recordings.update("call-2") { it.copy(undoIn = "a process that has gone") }
        runDiscardWork("call-2")
        pumpUntil("back") { app.peekRecording("call-2")?.state == "pending_decision" }
        assertTrue(app.peekRecording("call-2")!!.restoredDelete)
    }

    @Test
    fun `the card's follow-up runs before the widget, so a slow widget never delays or skips it`() {
        // A widget refresh that takes the whole step time.
        val refreshing = java.util.concurrent.CountDownLatch(1)
        val ended = java.util.concurrent.CountDownLatch(1)
        Decisions.refreshWidget = {
            try {
                refreshing.countDown()
                kotlinx.coroutines.awaitCancellation()
            } finally {
                ended.countDown()
            }
        }
        DecisionPopup.stepTimeoutMs = 3_000
        asked()
        // Sent meanwhile: the Delete finds nothing to delete, and the follow-up takes the Deleted line away.
        app.graph.recordings.update("call-1") { it.copy(state = "uploaded") }
        DecisionPopup.delete(app)
        pumpUntil("the follow-up, well before the step time", timeoutMs = 1_500) { !DecisionPopup.isShowing }
        assertTrue("the widget came after it", refreshing.await(5, TimeUnit.SECONDS))
        assertEquals("uploaded", app.peekRecording("call-1")?.state)
        // The slow widget is given up after the step time, and the queue moves on.
        assertTrue(ended.await(10, TimeUnit.SECONDS))
    }

    @Test
    fun `a longer Undo time keeps the file that much longer`() {
        call()
        val now = System.currentTimeMillis()
        val discarding = runBlocking { Decisions.discardLater(app, "call-1", nowMs = now, afterMs = 20_000) }!!
        assertEquals(java.time.Instant.ofEpochMilli(now + 20_000).toString(), discarding.discardAt)
        assertTrue(discardWork("call-1").single().initialDelayMillis >= 20_000)
        // The default is unchanged.
        call("call-2")
        val usual = runBlocking { Decisions.discardLater(app, "call-2", nowMs = now) }!!
        assertEquals(java.time.Instant.ofEpochMilli(now + Decisions.DISCARD_AFTER_MS).toString(), usual.discardAt)
    }

    @Test
    fun `a decision anywhere closes the card, and only for its own call`() {
        asked("a")
        DecisionPopup.transcribe(app)
        PostCallPrompt.show(app, call("b"), "dark")
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
    fun `back-to-back calls get one grouped line, never a stack of cards`() {
        asked("call-1")
        PostCallPrompt.show(app, call("call-2"), "dark")
        assertEquals(DecisionPopup.Mode.GROUPED, DecisionPopup.showingMode)
        assertEquals(CallCardScreen.Grouped(2), DecisionPopup.shown)
        PostCallPrompt.show(app, call("call-3"), "dark")
        assertEquals(CallCardScreen.Grouped(3), DecisionPopup.shown)
        listOf("call-1", "call-2", "call-3").forEach { id ->
            pumpUntil("$id kept as audio") { app.peekRecording(id)?.let { it.state == "queued" && it.transcribe == false } == true }
        }
        assertEquals("one window", 1, com.echoledger.mobile.overlay.PrivateWindows.count)
    }

    @Test
    fun `a confirmation still showing gives way to the next call's question`() {
        asked("call-1")
        DecisionPopup.transcribe(app)
        PostCallPrompt.show(app, call("call-2"), "dark")
        assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
        assertEquals("call-2", DecisionPopup.showingFor)
    }

    @Test
    fun `the first card shows the hint once`() {
        asked("call-1")
        assertEquals(true, (DecisionPopup.shown as CallCardScreen.Ask).hint)
        pumpUntil("remembered") { app.graph.liveSettings.value?.callChoiceHintShown == true }
        DecisionPopup.hide()
        asked("call-2")
        assertEquals(false, (DecisionPopup.shown as CallCardScreen.Ask).hint)
    }

    @Test
    fun `after three identical choices the next card offers Always do this once, and Yes sets the setting`() {
        runBlocking { app.graph.settings.update { it.copy(callChoiceHintShown = true) } }
        listOf("call-1", "call-2", "call-3").forEach { id ->
            asked(id)
            assertNull((DecisionPopup.shown as CallCardScreen.Ask).always)
            DecisionPopup.transcribe(app)
            // A choice counts once its line closes without Undo.
            after(DecisionPopup.DONE_MS + 1)
        }
        pumpUntil("three in a row") { app.graph.liveSettings.value?.callChoiceStreakCount == 3 }
        asked("call-4")
        assertEquals(Pick.TRANSCRIBE, (DecisionPopup.shown as CallCardScreen.Ask).always)
        DecisionPopup.always(app)
        pumpUntil("Transcribe every call") { app.graph.liveSettings.value?.afterEachCall == CallChoice.TRANSCRIBE }
        pumpUntil("and this call too") { app.peekRecording("call-4")?.transcribe == true }
        assertTrue(app.graph.liveSettings.value?.callChoiceAlwaysOffered == true)
    }

    @Test
    fun `a choice taken back with Undo does not count toward Always do this`() {
        runBlocking { app.graph.settings.update { it.copy(callChoiceHintShown = true) } }
        asked("call-1")
        DecisionPopup.transcribe(app)
        DecisionPopup.undo(app)
        DecisionPopup.delete(app)
        DecisionPopup.undo(app)
        DecisionPopup.hide()
        asked("call-2")
        DecisionPopup.transcribe(app)
        after(DecisionPopup.DONE_MS + 1)
        pumpUntil("one counted") { app.graph.liveSettings.value?.callChoiceStreakCount == 1 }
        assertEquals(Pick.TRANSCRIBE.wire, app.graph.liveSettings.value?.callChoiceStreak)
    }

    @Test
    fun `Transcribe every call and Save audio only ask nothing and send the call`() {
        listOf(CallChoice.Outcome.TRANSCRIBE to true, CallChoice.Outcome.AUDIO to false).forEachIndexed { i, (outcome, transcribe) ->
            val rec = PostCallPrompt.stored(call("call-$i", state = "recording"), outcome)
            assertEquals("queued", rec.state)
            assertEquals(transcribe, rec.transcribe)
            app.graph.recordings.update(rec.id) { rec }
            PostCallPrompt.show(app, rec, "dark")
            assertFalse(DecisionPopup.isShowing)
            pumpUntil("sent") { uploadWork(rec.id).isNotEmpty() }
        }
        assertEquals(0, notifications().size)
    }

    @Test
    fun `a choice made on the pill suppresses the card`() {
        val settings = app.graph.settings.let { runBlocking { it.current() } }
        listOf(Pick.TRANSCRIBE, Pick.AUDIO).forEach { pick ->
            val outcome = PostCallPrompt.outcome(app, settings, duringCall = pick)
            val rec = PostCallPrompt.stored(call("pill-${pick.wire}", state = "recording"), outcome)
            PostCallPrompt.show(app, rec, "dark")
            assertFalse(DecisionPopup.isShowing)
        }
    }

    @Test
    fun `Do Not Disturb saves the audio only with no card`() {
        val nm = app.getSystemService(NotificationManager::class.java)
        shadowOf(nm).setNotificationPolicyAccessGranted(true)
        nm.setInterruptionFilter(NotificationManager.INTERRUPTION_FILTER_PRIORITY)
        val settings = runBlocking { app.graph.settings.current() }
        assertTrue(CallChoice.quietNow(app, settings))
        assertEquals(CallChoice.Outcome.AUDIO_QUIET, PostCallPrompt.outcome(app, settings))
        nm.setInterruptionFilter(NotificationManager.INTERRUPTION_FILTER_ALL)
        assertFalse("quiet hours are off by default", settings.quietHours)
        assertEquals(CallChoice.Outcome.CARD, PostCallPrompt.outcome(app, settings))
    }

    @Test
    fun `calls saved during quiet hours are remembered for the Library by their local and server ids`() {
        QuietSaved.clear(app)
        assertFalse(QuietSaved.has(app, "local-1", null))
        QuietSaved.mark(app, "local-1")
        assertTrue(QuietSaved.has(app, "server-9", "local-1"))
        assertFalse(QuietSaved.has(app, "server-9", null))
    }

    @Test
    fun `without the overlay permission a notification asks with Transcribe, Save audio only and Delete`() {
        ShadowSettings.setCanDrawOverlays(false)
        PostCallPrompt.show(app, call(), "dark")
        assertFalse(DecisionPopup.isShowing)
        val asked = notifications().single()
        assertEquals(listOf("Transcribe", "Save audio only", "Delete"), asked.actions.map { it.title.toString() })
        listOf("Keep", "Discard", "Skip").forEach { word -> assertTrue(asked.actions.none { it.title.toString() == word }) }
    }

    @Test
    fun `the final delete and an Undo never interleave`() {
        call()
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
    fun `a call sent meanwhile is not taken back by Undo`() {
        asked()
        DecisionPopup.transcribe(app)
        pumpUntil("kept") { app.peekRecording("call-1")?.state == "queued" }
        app.graph.recordings.update("call-1") { it.copy(state = "uploaded") }
        DecisionPopup.undo(app)
        pumpUntil("the card to go") { !DecisionPopup.isShowing }
        assertEquals("uploaded", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `a voice note gets no card`() {
        val note = app.storeRecording(id = "note-1", state = "pending_decision", sourceApp = "voice_note", durationMs = 5_000)
        PostCallPrompt.show(app, note, "dark")
        assertFalse(DecisionPopup.isShowing)
        after(Duration.ofSeconds(1).toMillis())
    }
}
