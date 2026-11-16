package com.echoledger.mobile.calls

import android.content.Context
import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.translate.LiveTranslateSession
import com.echoledger.mobile.translate.TranslateStatus
import java.time.Instant
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** "Undecided calls: keep automatically after 24 h / 3 days / never" (since 1.3.2), and the remembered translate refusal. */
class UndecidedKeepWorkerTest : AppTest() {
    private val graph get() = (app as EchoLedgerApp).graph

    private fun runWorker(): ListenableWorker.Result = runBlocking {
        TestListenableWorkerBuilder<UndecidedKeepWorker>(app).build().doWork()
    }

    @Test
    fun `only calls older than the setting are due, and never means none`() {
        val now = Instant.parse("2026-10-31T12:00:00Z")
        val old = app.storeRecording(id = "old", state = "pending_decision", startedAt = "2026-10-30T11:00:00Z")
        val fresh = app.storeRecording(id = "fresh", state = "pending_decision", startedAt = "2026-10-31T09:00:00Z")
        val kept = app.storeRecording(id = "kept", state = "queued", startedAt = "2026-10-20T09:00:00Z")
        val all = listOf(old, fresh, kept)

        assertEquals(listOf("old"), UndecidedKeepWorker.due(all, 24, now))
        assertTrue(UndecidedKeepWorker.due(all, 72, now).isEmpty())
        assertTrue(UndecidedKeepWorker.due(all, 0, now).isEmpty())
    }

    @Test
    fun `the default keeps a call left undecided for more than a day`() {
        assertEquals(24, runBlocking { graph.settings.current() }.undecidedKeepHours)
        app.storeRecording(id = "old", state = "pending_decision", startedAt = "2020-01-01T09:00:00Z")
        app.storeRecording(id = "new", state = "pending_decision", startedAt = Instant.now().toString())

        assertEquals(ListenableWorker.Result.success(), runWorker())
        assertEquals("queued", app.peekRecording("old")?.state)
        assertEquals("pending_decision", app.peekRecording("new")?.state)
    }

    @Test
    fun `never leaves every call waiting`() {
        runBlocking { graph.settings.update { it.copy(undecidedKeepHours = 0) } }
        app.storeRecording(id = "old", state = "pending_decision", startedAt = "2020-01-01T09:00:00Z")

        runWorker()
        assertEquals("pending_decision", app.peekRecording("old")?.state)
    }

    @Test
    fun `a call discarded from the chip goes once its Undo time is over, never sooner, even if its timer was lost`() {
        // As after the app was killed during "Discarded · Undo": the time is on the phone with the call.
        val now = System.currentTimeMillis()
        app.storeRecording(id = "due", state = "pending_decision", startedAt = Instant.now().toString())
        app.storeRecording(id = "later", state = "pending_decision", startedAt = Instant.now().toString())
        runBlocking {
            Decisions.discardLater(app, "due", nowMs = now - Decisions.DISCARD_AFTER_MS - 1_000)
            Decisions.discardLater(app, "later", nowMs = now + 60_000)
        }
        val at = Instant.ofEpochMilli(now)
        assertEquals(listOf("due"), Decisions.discardsDue(app.graph.recordings.all(), at))
        assertTrue(Decisions.discardsDue(app.graph.recordings.all(), at.minusSeconds(60)).isEmpty())

        assertEquals(ListenableWorker.Result.success(), runWorker())
        assertNull(app.graph.recordings.get("due"))
        assertFalse(app.graph.recordings.encryptedFile("due").exists())
        assertEquals(Decisions.DISCARDING, app.peekRecording("later")?.state)
        assertTrue("never sooner", app.graph.recordings.encryptedFile("later").exists())
    }

    @Test
    fun `a refusal is remembered in short by which notice it is, and cleared once translating works`() {
        assertEquals("Needs credit", TranslateStatus.shortReason(LiveTranslateSession.NO_CREDIT))
        assertEquals("Not set up on the server", TranslateStatus.shortReason(TranslateStatus.NOT_SET_UP))
        assertEquals("Budget used up", TranslateStatus.shortReason(TranslateStatus.BUDGET_USED_UP))
        // The words of a server's or an engine's text decide nothing.
        assertNull(TranslateStatus.shortReason("Soniox has no credit left. Add credit at console.soniox.com."))
        assertNull(TranslateStatus.shortReason("The key was not accepted."))
        assertNull(TranslateStatus.shortReason("This month's budget is used up."))
        assertNull(TranslateStatus.shortReason(TranslateStatus.NOT_ANSWERING))
        assertNull(TranslateStatus.shortReason("Sign in on this phone first."))

        TranslateStatus.remember(app, LiveTranslateSession.NO_CREDIT)
        assertEquals("Needs credit", TranslateStatus.blocked(app))
        TranslateStatus.remember(app, null)
        assertNull(TranslateStatus.blocked(app))
    }

    @Test
    fun `a short reason saved by an older version reads in today's words`() {
        val saved = app.getSharedPreferences("live_translate_usage", Context.MODE_PRIVATE)
        saved.edit().putString("blocked", "Needs Soniox credit").commit()
        assertEquals("Needs credit", TranslateStatus.blocked(app))
        saved.edit().putString("blocked", "Needs a Soniox key").commit()
        assertEquals("Not set up on the server", TranslateStatus.blocked(app))
        saved.edit().putString("blocked", "Budget used up").commit()
        assertEquals("Budget used up", TranslateStatus.blocked(app))
        saved.edit().putString("blocked", "Something else").commit()
        assertNull(TranslateStatus.blocked(app))
    }
}
