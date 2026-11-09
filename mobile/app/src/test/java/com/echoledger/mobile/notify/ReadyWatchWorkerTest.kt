package com.echoledger.mobile.notify

import android.Manifest
import android.app.NotificationManager
import androidx.work.WorkManager
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** v1.6 P5.4: "Notify me when ready" gives one notice when the transcript is ready, whatever the switches say. */
class ReadyWatchWorkerTest : AppTest() {
    private val id = ServerFixtures.SESSION_ID

    @Before
    fun pair() {
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS)
        runBlocking { app.pairLocally(server) }
    }

    private fun serveLibrary(status: String, finalStatus: String?) = server.on(
        "GET",
        "/api/library",
        FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem(id, status = status, finalStatus = finalStatus))))
    )

    private fun run(round: Int = 0) {
        val future = TestListenableWorkerBuilder<ReadyWatchWorker>(app)
            .setInputData(workDataOf("session" to id, "round" to round))
            .build()
            .startWork()
        pumpUntil("the worker") { future.isDone }
    }

    private fun posted() = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications

    @Test
    fun `a ready transcript is announced once and the notice opens the recording`() {
        serveLibrary("completed", "completed")

        run()

        val note = posted().single { it.extras.getString("android.title") == "Transcript ready" }
        assertEquals("Call with Acme", note.extras.getCharSequence("android.text").toString())
        val opens = shadowOf(note.contentIntent).savedIntent
        assertEquals(MainActivity.OPEN_SESSION_PREFIX + id, opens.getStringExtra(MainActivity.EXTRA_OPEN))
        assertEquals(listOf(id), ReadyWatchWorker.notified(app))
    }

    @Test
    fun `a transcript still being made is asked about again later, with no notice yet`() {
        serveLibrary("processing", "processing")

        run()

        assertTrue(posted().none { it.extras.getString("android.title") == "Transcript ready" })
        val next = WorkManager.getInstance(app).getWorkInfosForUniqueWork("ready-watch:$id").get()
        assertEquals(1, next.size)
    }

    @Test
    fun `it comes through even when the Transcription switch is off`() {
        runBlocking { app.graph.settings.update { it.copy(notifyOff = it.notifyOff + NotifyCategory.TRANSCRIPTION.key) } }
        serveLibrary("completed", "completed")

        run()

        assertEquals(1, posted().count { it.extras.getString("android.title") == "Transcript ready" })
    }

    @Test
    fun `the activity poll does not repeat a notice given here`() {
        serveLibrary("completed", "completed")
        run()
        fun item(n: String, session: String, body: String) =
            """{"id":"$n","kind":"transcript_ready","title":"Transcript ready","body":"$body","session_id":"$session","read":false,"at":"2026-11-09T08:00:00+00:00"}"""
        fun poll(vararg items: String) {
            server.on(
                "GET",
                "/api/activity",
                FakeServer.json(200, """{"data":[${items.joinToString(",")}],"unread_count":${items.size}}""")
            )
            val future = TestListenableWorkerBuilder<ActivityWorker>(app).build().startWork()
            pumpUntil("the activity poll") { future.isDone }
        }
        poll() // the first run only learns what is there

        poll(item("A1", id, "Call with Acme"), item("A2", "01JOTHER00000000000000000A", "Weekly review"))

        val ready = posted().filter { it.extras.getString("android.title") == "Transcript ready" }
        assertEquals(listOf("Call with Acme", "Weekly review"), ready.map { it.extras.getCharSequence("android.text").toString() })
    }
}
