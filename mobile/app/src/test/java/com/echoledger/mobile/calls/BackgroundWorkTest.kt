package com.echoledger.mobile.calls

import android.app.NotificationManager
import android.content.Intent
import androidx.work.NetworkType
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.widget.WidgetState
import com.echoledger.mobile.widget.WidgetStateStore
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** Scheduling, notifications, the post-call decision receiver, the widget cache and the API client cache. */
class BackgroundWorkTest : AppTest() {
    private fun infos(id: String) = WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS)

    // ---- upload scheduling ---------------------------------------------------------------------------------------------------

    @Test
    fun `enqueue schedules one upload that waits for a network connection`() {
        UploadWorker.enqueue(app, "rec-1")

        val work = infos("rec-1").single()
        assertEquals(WorkInfo.State.ENQUEUED, work.state)
        assertEquals(NetworkType.CONNECTED, work.constraints.requiredNetworkType)
    }

    @Test
    fun `enqueue twice keeps the first request so a running upload is never duplicated`() {
        UploadWorker.enqueue(app, "rec-1")
        val first = infos("rec-1").single().id

        UploadWorker.enqueue(app, "rec-1")

        assertEquals(listOf(first), infos("rec-1").map { it.id })
    }

    @Test
    fun `retryNow replaces a request that is waiting out its back-off`() {
        UploadWorker.enqueue(app, "rec-1")
        val first = infos("rec-1").single().id

        UploadWorker.retryNow(app, "rec-1")
        pumpUntil("the replacement request") { infos("rec-1").any { it.id != first && it.state == WorkInfo.State.ENQUEUED } }

        val all = infos("rec-1")
        val current = all.single { it.state == WorkInfo.State.ENQUEUED }
        assertTrue(current.id != first)
        assertTrue("the replaced request is cancelled or removed", all.none { it.id == first && it.state != WorkInfo.State.CANCELLED })
    }

    @Test
    fun `different recordings are scheduled independently`() {
        UploadWorker.enqueue(app, "a")
        UploadWorker.enqueue(app, "b")
        val firstA = infos("a").single().id
        UploadWorker.retryNow(app, "a")
        pumpUntil("the replacement request") { infos("a").any { it.id != firstA && it.state == WorkInfo.State.ENQUEUED } }

        assertEquals(1, infos("b").size)
        assertEquals(WorkInfo.State.ENQUEUED, infos("b").single().state)
    }

    // ---- notifications ------------------------------------------------------------------------------------------------------

    @Test
    fun `the three notification channels are created with calm importance`() {
        val manager = app.getSystemService(NotificationManager::class.java)

        assertEquals(NotificationManager.IMPORTANCE_LOW, manager.getNotificationChannel(Notifications.CHANNEL_RECORDING).importance)
        assertEquals(NotificationManager.IMPORTANCE_DEFAULT, manager.getNotificationChannel(Notifications.CHANNEL_DECISIONS).importance)
        assertEquals(NotificationManager.IMPORTANCE_MIN, manager.getNotificationChannel(Notifications.CHANNEL_STATUS).importance)
        assertNull("no sound for the post-call question", manager.getNotificationChannel(Notifications.CHANNEL_DECISIONS).sound)
        assertFalse(manager.getNotificationChannel(Notifications.CHANNEL_DECISIONS).shouldVibrate())
        assertFalse(manager.getNotificationChannel(Notifications.CHANNEL_RECORDING).canShowBadge())
    }

    @Test
    fun `the recording notification is ongoing and can be stopped from the shade`() {
        val notification = Notifications.recording(app, "Recording", "Microphone")

        assertTrue(notification.flags and android.app.Notification.FLAG_ONGOING_EVENT != 0)
        assertEquals(listOf("Stop"), notification.actions.map { it.title.toString() })
        assertEquals("Recording", shadowOf(notification).contentTitle.toString())
    }

    @Test
    fun `the armed notification offers recording now and turning off`() {
        val notification = Notifications.armed(app)

        assertEquals(listOf("Record now", "Turn off"), notification.actions.map { it.title.toString() })
        assertEquals("Ready to record WhatsApp calls", shadowOf(notification).contentTitle.toString())
    }

    @Test
    fun `the post-call question offers Keep and Discard and can be withdrawn`() {
        Notifications.showSaveDecision(app, "rec-1", "WhatsApp", "2:05")
        val manager = shadowOf(app.getSystemService(NotificationManager::class.java))

        val posted = manager.allNotifications.single()
        assertEquals("Save this recording?", shadowOf(posted).contentTitle.toString())
        assertEquals("WhatsApp call · 2:05", shadowOf(posted).contentText.toString())
        assertEquals(listOf("Keep", "Discard"), posted.actions.map { it.title.toString() })

        Notifications.cancelDecision(app, "rec-1")
        assertTrue(manager.allNotifications.isEmpty())
    }

    // ---- post-call decisions through the notification ----------------------------------------------------------------------------

    private fun decision(action: String, id: String?) = Intent(app, SaveDecisionReceiver::class.java).setAction(action).apply {
        if (id !=
            null
        ) {
            putExtra(SaveDecisionReceiver.EXTRA_ID, id)
        }
    }

    @Test
    fun `Keep from the notification queues the recording and schedules the upload`() {
        app.storeRecording(id = "call-1", state = "pending_decision")
        Notifications.showSaveDecision(app, "call-1", "WhatsApp", "1:00")

        app.sendBroadcast(decision(SaveDecisionReceiver.ACTION_KEEP, "call-1"))
        pumpUntil("the decision") { app.peekRecording("call-1")?.state == "queued" }

        assertEquals(listOf(WorkInfo.State.ENQUEUED), infos("call-1").map { it.state })
        assertTrue("the question is withdrawn", shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications.isEmpty())
    }

    @Test
    fun `Discard from the notification deletes the recording and its encrypted file`() {
        app.storeRecording(id = "call-1", state = "pending_decision")

        app.sendBroadcast(decision(SaveDecisionReceiver.ACTION_DISCARD, "call-1"))
        pumpUntil("the decision") { app.peekRecording("call-1") == null }

        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue(infos("call-1").isEmpty())
    }

    @Test
    fun `applying a discard decision directly deletes the recording`() {
        app.storeRecording(id = "call-1", state = "pending_decision")

        runBlocking { Decisions.apply(app, "call-1", keep = false) }

        assertNull(app.graph.recordings.get("call-1"))
        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
    }

    @Test
    fun `a decision without a recording id is ignored`() {
        app.storeRecording(id = "call-1", state = "pending_decision")

        app.sendBroadcast(decision(SaveDecisionReceiver.ACTION_DISCARD, null))
        pumpUntil("the broadcast to be handled") { true }
        Thread.sleep(200)

        assertEquals("pending_decision", app.graph.recordings.get("call-1")!!.state)
    }

    // ---- widget cache -----------------------------------------------------------------------------------------------------------

    @Test
    fun `the widget cache starts unpaired and survives a round trip`() {
        assertEquals(WidgetState(), WidgetStateStore.load(app))

        val state =
            WidgetState(recording = true, armed = true, paired = true, lastTitle = "Café 你好", lastPreview = "Preview", pendingDecisions = 3)
        WidgetStateStore.save(app, state)

        assertEquals(state, WidgetStateStore.load(app))
    }

    @Test
    fun `a corrupt widget cache falls back to the default state`() {
        app.getSharedPreferences("echoledger_widget", 0).edit().putString("state", "{broken").commit()

        assertEquals(WidgetState(), WidgetStateStore.load(app))
    }

    @Test
    fun `refreshing the widget reflects pairing the newest recording and pending decisions`() {
        runBlocking {
            WidgetStateStore.refresh(app)
            assertFalse(WidgetStateStore.load(app).paired)
        }
        app.storeRecording(id = "p1", state = "pending_decision")
        app.storeRecording(id = "p2", state = "pending_decision")
        app.storeRecording(id = "q1", state = "queued")
        runBlocking {
            app.pairLocally(server)
            app.graph.settings.update { it.copy(autoRecordArmed = true) }
            WidgetStateStore.refresh(app)
        }

        val state = WidgetStateStore.load(app)
        assertTrue(state.paired)
        assertTrue(state.armed)
        assertFalse(state.recording)
        assertEquals(2, state.pendingDecisions)
        assertEquals("2 recording(s) to review", state.statusLine)
    }

    // ---- API client cache ---------------------------------------------------------------------------------------------------------

    @Test
    fun `there is no API client for a phone that is not paired or has lost its token`() {
        assertNull(runBlocking { app.graph.api() })

        runBlocking { app.graph.settings.update { it.copy(serverUrl = server.baseUrl, deviceId = "d") } }
        assertNull("no token yet", runBlocking { app.graph.api() })
    }

    @Test
    fun `the API client is reused until the server or token changes`() {
        runBlocking { app.pairLocally(server, token = "7|eld_one") }
        val first = runBlocking { app.graph.api() }
        assertNotNull(first)
        assertSame(first, runBlocking { app.graph.api() })

        runBlocking { app.graph.settings.saveToken("7|eld_two") }
        val rotated = runBlocking { app.graph.api() }
        assertTrue("a new token needs a new client", rotated !== first)

        app.graph.invalidateApi()
        assertTrue(runBlocking { app.graph.api() } !== rotated)
    }
}
