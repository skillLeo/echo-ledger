package com.echoledger.mobile.calls

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.translate.TranslateStatus
import java.time.Instant
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
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
    fun `a Soniox refusal is remembered in short and cleared once translating works`() {
        assertEquals("Needs Soniox credit", TranslateStatus.shortReason("Soniox has no credit left. Add credit at console.soniox.com."))
        assertEquals("Budget used up", TranslateStatus.shortReason("This month's Live Translate budget is used up."))
        assertNull(TranslateStatus.shortReason("Pair this phone first."))

        TranslateStatus.remember(app, "Soniox has no credit left.")
        assertEquals("Needs Soniox credit", TranslateStatus.blocked(app))
        TranslateStatus.remember(app, null)
        assertNull(TranslateStatus.blocked(app))
    }
}
