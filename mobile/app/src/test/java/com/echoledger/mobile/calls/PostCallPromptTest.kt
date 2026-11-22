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

    @After
    fun closeCard() = DecisionPopup.hide()

    private fun call(id: String = "call-1", state: String = "pending_decision") =
        app.storeRecording(id = id, state = state, sourceApp = "whatsapp", durationMs = 44_000)

    private fun uploadWork(id: String) = WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS)

    private fun waitingUpload(id: String) = uploadWork(id).any { it.state == WorkInfo.State.ENQUEUED }

    private fun noUpload(id: String) = uploadWork(id).none { it.state == WorkInfo.State.ENQUEUED || it.state == WorkInfo.State.RUNNING }

    private fun notifications() = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications

    private fun after(ms: Long) = ShadowLooper.idleMainLooper(ms, TimeUnit.MILLISECONDS)

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
    fun `Delete deletes nothing until Deleted and its Undo are over, and Undo brings the question back`() {
        asked()
        DecisionPopup.delete(app)
        assertEquals(CallCardScreen.Done(Pick.DELETE), DecisionPopup.shown)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        assertTrue("the file waits for Undo", app.graph.recordings.encryptedFile("call-1").exists())

        DecisionPopup.undo(app)
        assertTrue(DecisionPopup.shown is CallCardScreen.Ask)
        pumpUntil("back") { app.peekRecording("call-1")?.state == "pending_decision" }
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())

        DecisionPopup.delete(app)
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        runBlocking { Decisions.finishDiscard(app, "call-1") }
        assertNull("gone after its time", app.peekRecording("call-1"))
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
            DecisionPopup.hide()
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
