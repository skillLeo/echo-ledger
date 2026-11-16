package com.echoledger.mobile.calls

import android.content.ComponentName
import android.media.AudioManager
import android.provider.Settings
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.notify.CallerNameListener
import com.echoledger.mobile.recording.CallJournal
import com.echoledger.mobile.recording.CallJournal.Result
import com.echoledger.mobile.recording.CallJournal.Seen
import com.echoledger.mobile.recording.CallJournal.Stage
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.storeRecording
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * The missed-recording detector on the phone (v1.8 0.2, after its review): what it reads (trimmed sightings, the audio
 * mode, the Phone app's imports still going on, notification access), one run at a time, when it runs, and whose items
 * they are.
 */
class MissedRecordingWorkerTest : AppTest() {
    private val min = 60_000L
    private val day = 24 * 60 * min
    private val account = Owner.of("https://echo.example", "omar@example.com")
    private var t = 0L

    @Before
    fun armed() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true, phoneCalls = "always", account = account) } }
        // Notification access, so app calls are compared too.
        Settings.Secure.putString(
            app.contentResolver,
            "enabled_notification_listeners",
            ComponentName(app, CallerNameListener::class.java).flattenToString()
        )
        // After the journal began and the choices above reached it.
        t = System.currentTimeMillis() + 2_000
    }

    private fun detect(atMs: Long) = runBlocking { MissedRecordingWorker.detect(app, atMs) }

    private fun seen(app: String, start: Long, end: Long?, pkg: String) = CallSightings.Seen(app, start, end, pkg)

    @Test
    fun `the sightings it reads are trimmed, so none outlives the journal`() {
        CallSightings.update(app) {
            listOf(
                seen("whatsapp", t - 8 * day, t - 8 * day + 300_000, "com.whatsapp"),
                seen("zoom", t, t + 300_000, "us.zoom.videomeetings")
            )
        }
        detect(t + 10 * min)
        assertEquals(listOf("zoom"), CallSightings.all(app).map { it.app })
    }

    @Test
    fun `a recorded call uploaded long ago is not reported once the journal let it go`() {
        CallJournal.update(app, t) { b ->
            var j = CallJournal.seen(b, "c1", "whatsapp", Seen.HELPER, t)
            j = CallJournal.stage(j, "c1", Stage.STARTED, t + 400)
            j = CallJournal.stage(j, "c1", Stage.BOTH_AUDIO, t + 2_000)
            j = CallJournal.ended(j, "c1", t + 300_000)
            CallJournal.finish(j, "c1", Result.KEPT, t + 301_000, keptMs = 298_000)
        }
        CallSightings.update(app) { listOf(seen("whatsapp", t + 3_000, t + 300_000, "com.whatsapp")) }
        assertTrue("recorded", detect(t + 10 * min).isEmpty())
        assertTrue("a week and an hour on, its file long gone", detect(t + 7 * day + 60 * min).isEmpty())
        assertTrue(MissedRecordingStore.all(app).isEmpty())
    }

    @Test
    fun `nothing open is judged while the phone is in a call`() {
        CallJournal.update(app, t) { b ->
            var j = CallJournal.seen(b, "c1", "whatsapp", Seen.HELPER, t)
            j = CallJournal.stage(j, "c1", Stage.STARTED, t + 400)
            j = CallJournal.answered(j, "c1", t + 1_500, "the other side spoke")
            j = CallJournal.stage(j, "c1", Stage.BOTH_AUDIO, t + 2_000)
            CallJournal.seen(j, "c2", "zoom", Seen.HELPER, t + 40 * min)
        }
        val audio = app.getSystemService(AudioManager::class.java)
        audio.mode = AudioManager.MODE_IN_COMMUNICATION
        assertTrue(detect(t + 45 * min).isEmpty())
        audio.mode = AudioManager.MODE_NORMAL
        assertEquals(listOf("whatsapp"), detect(t + 46 * min).map { it.app })
    }

    @Test
    fun `several looks at once keep each missed call once`() {
        CallSightings.update(app) { listOf(seen("zoom", t, t + 300_000, "us.zoom.videomeetings")) }
        val found = runBlocking {
            (1..4).map { async(Dispatchers.IO) { MissedRecordingWorker.detect(app, t + 10 * min) } }.awaitAll()
        }
        assertEquals(1, found.flatten().size)
        assertEquals(1, MissedRecordingStore.all(app).size)
        // WhatsApp Business at nearly the same time is the same call.
        val again = MissedRecordings.Found("whatsapp_business", t + 30_000, 300, MissedReason.DETECTION, "")
        MissedRecordingStore.add(app, listOf(MissedRecordings.Found("whatsapp", t, 300, MissedReason.DETECTION, "")))
        assertEquals(emptyList<MissedItem>(), MissedRecordingStore.add(app, listOf(again)))
        assertEquals(2, MissedRecordingStore.all(app).size)
    }

    @Test
    fun `opening the app looks at most every 10 minutes, and only where a call can be missed`() {
        CallSightings.update(app) { listOf(seen("zoom", t, t + 300_000, "us.zoom.videomeetings")) }
        assertEquals(1, runBlocking { MissedRecordingWorker.onOpen(app, t + 10 * min) }.size)
        CallSightings.update(app) { it + seen("whatsapp", t + 11 * min, t + 15 * min, "com.whatsapp") }
        assertTrue("it looked 7 minutes ago", runBlocking { MissedRecordingWorker.onOpen(app, t + 17 * min) }.isEmpty())
        assertEquals(1, runBlocking { MissedRecordingWorker.onOpen(app, t + 21 * min) }.size)

        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = false, phoneCalls = "off") } }
        CallSightings.update(app) { it + seen("zoom", t + 40 * min, t + 45 * min, "us.zoom.videomeetings") }
        assertTrue("auto-record and phone calls off", runBlocking { MissedRecordingWorker.onOpen(app, t + 60 * min) }.isEmpty())
    }

    @Test
    fun `the hourly look is scheduled only where a call can be missed`() {
        val work = WorkManager.getInstance(app)
        fun live() = work.getWorkInfosForUniqueWork("missed-recordings").get(10, TimeUnit.SECONDS).filter { !it.state.isFinished }
        MissedRecordingWorker.schedule(app, AppSettings())
        assertTrue("auto-record and phone calls off", live().isEmpty())
        MissedRecordingWorker.schedule(app, AppSettings(autoRecordArmed = true))
        assertEquals(listOf(WorkInfo.State.ENQUEUED), live().map { it.state })
        MissedRecordingWorker.schedule(app, AppSettings(phoneCalls = "ask"))
        assertEquals(1, live().size)
        MissedRecordingWorker.schedule(app, AppSettings())
        assertTrue("called off", live().isEmpty())
    }

    @Test
    fun `a missed call belongs to the account it was found under`() {
        CallSightings.update(app) { listOf(seen("zoom", t, t + 300_000, "us.zoom.videomeetings")) }
        val item = detect(t + 10 * min).single()
        assertEquals(account, item.owner)
        val sara = Owner.of("https://echo.example", "sara@example.com")
        assertTrue("another account does not see it", MissedRecordingStore.forAccount(MissedRecordingStore.all(app), sara).isEmpty())
        assertEquals(1, MissedRecordingStore.forAccount(MissedRecordingStore.all(app), account).size)

        // One found before any account was known stays with the first account when someone else signs in.
        val earlier = MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("whatsapp", t + 30 * min, 60, MissedReason.CRASH, ""))
        ).single()
        MissedRecordingStore.stamp(app, previous = account, next = sara)
        assertEquals(account, MissedRecordingStore.all(app).single { it.ref == earlier.ref }.owner)
    }

    @Test
    fun `a missed call belongs to the account signed in when it started, not the one signed in when it was found`() {
        CallSightings.update(app) { listOf(seen("zoom", t, t + 300_000, "us.zoom.videomeetings")) }
        val sara = Owner.of("https://echo.example", "sara@example.com")
        // Sara signed in on this phone five minutes after Omar's call, before the detector looked.
        MissedRecordingStore.stamp(app, previous = account, next = sara, atMs = t + 5 * min)
        runBlocking { app.graph.settings.update { it.copy(account = sara) } }
        assertEquals(account, detect(t + 10 * min).single().owner)
        assertTrue("Sara never sees Omar's call", MissedRecordingStore.forAccount(MissedRecordingStore.all(app), sara).isEmpty())
        // A call after her sign-in is hers.
        CallSightings.update(app) { it + seen("whatsapp", t + 20 * min, t + 25 * min, "com.whatsapp") }
        assertEquals(sara, detect(t + 30 * min).single().owner)
    }

    /** The journal began a day ago, with the person's choices of now (calls of the last hour are judged). */
    private fun reachBack() {
        val choices = CallJournal.choicesOf(runBlocking { app.graph.settings.current() })
        CallJournal.update(app) { b ->
            b.copy(
                since = b.since - day,
                settings =
                    listOf(CallJournal.SettingsMark(b.since - day, choices)) + b.settings
            )
        }
    }

    /** The notice the hourly look posts, as the phone shows it (null once it is gone). */
    private fun notice(): android.app.Notification? =
        org.robolectric.Shadows.shadowOf(app.getSystemService(android.app.NotificationManager::class.java)).getNotification(7_460)

    @Test
    fun `the missed-recording notice goes once every call it names was recorded after all`() {
        org.robolectric.Shadows.shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        // A Zoom call half an hour ago that nothing recorded (the journal reaches back that far).
        reachBack()
        val at = System.currentTimeMillis() - 30 * min
        CallSightings.update(app) { listOf(seen("zoom", at, at + 5 * min, "us.zoom.videomeetings")) }
        fun look() = runBlocking { androidx.work.testing.TestListenableWorkerBuilder<MissedRecordingWorker>(app).build().doWork() }
        look()
        assertEquals("Missed recording", notice()?.extras?.getString(android.app.Notification.EXTRA_TITLE))
        // Its recording turns up after all (imported late): the item is withdrawn, and the notice goes with it.
        app.storeRecording(
            id = "zoom-late",
            sourceApp = "zoom",
            startedAt = java.time.Instant.ofEpochMilli(at + 2_000).toString(),
            endedAt = java.time.Instant.ofEpochMilli(at + 5 * min).toString(),
            durationMs = 5 * min - 2_000
        )
        look()
        assertTrue(MissedRecordingStore.all(app).single().withdrawn)
        assertEquals(null, notice())
    }

    @Test
    fun `of two calls the notice names, the one recorded after all leaves it and the other stays, quietly`() {
        org.robolectric.Shadows.shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        reachBack()
        val at = System.currentTimeMillis() - 60 * min
        CallSightings.update(app) {
            listOf(seen("zoom", at, at + 5 * min, "us.zoom.videomeetings"), seen("whatsapp", at + 20 * min, at + 22 * min, "com.whatsapp"))
        }
        fun look() = runBlocking { androidx.work.testing.TestListenableWorkerBuilder<MissedRecordingWorker>(app).build().doWork() }
        look()
        assertEquals("2 calls were not recorded", notice()?.extras?.getString(android.app.Notification.EXTRA_TITLE))
        app.storeRecording(
            id = "zoom-late",
            sourceApp = "zoom",
            startedAt = java.time.Instant.ofEpochMilli(at + 2_000).toString(),
            endedAt = java.time.Instant.ofEpochMilli(at + 5 * min).toString(),
            durationMs = 5 * min - 2_000
        )
        look()
        assertEquals("Missed recording", notice()?.extras?.getString(android.app.Notification.EXTRA_TITLE))
        assertTrue(notice()!!.extras.getString(android.app.Notification.EXTRA_TEXT)!!.startsWith("WhatsApp call"))
    }

    @Test
    fun `a report read on the phone after it was sent has its server copy marked read without waiting for the hourly look`() {
        val sent = MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("zoom", t, 300, MissedReason.DETECTION, "")),
            owner = account
        )
            .single()
        MissedRecordingStore.sent(app, sent.ref, "mr_1")
        val vm = com.echoledger.mobile.ui.MainViewModel(app).clearedAfterTest()
        runBlocking { vm.readMissed(sent.ref).join() }
        val upload = WorkManager.getInstance(app).getWorkInfosForUniqueWork("missed-upload").get(10, TimeUnit.SECONDS)
        assertEquals(listOf(WorkInfo.State.ENQUEUED), upload.map { it.state })
        assertTrue(MissedRecordingStore.all(app).single().serverCopyToRead)
    }

    @Test
    fun `a silent SIM call waits while the Phone app's recording may come, and is judged once the import gave up`() {
        CallJournal.update(app, t) { b ->
            var j = CallJournal.seen(b, "c1", "phone_call", Seen.HELPER, t)
            j = CallJournal.stage(j, "c1", Stage.STARTED, t + 400)
            j = CallJournal.ended(j, "c1", t + 120_000)
            CallJournal.fail(j, "c1", "unknown", "No sound was captured, so the recording was not kept.", t + 121_000)
        }
        // The dialer's notice says it was answered.
        CallSightings.update(app) { listOf(seen("phone_call", t + 2_000, t + 120_000, "com.samsung.android.incallui")) }
        PendingImports.add(app, t, t + 120_000, nowMs = t + 121_000)
        assertTrue("the import is still looking", detect(t + 5 * min).isEmpty())
        PendingImports.done(app, t)
        assertEquals(listOf("phone_call"), detect(t + 11 * min).map { it.app })
    }

    @Test
    fun `a call whose recording waits for the person outlasts the time limit and the end of the looks`() {
        PhoneAppRecordings.schedule(app, t, t + 60_000, manufacturer = "samsung")
        PendingImports.waitForPerson(app, t - 5_000, t + 65_000)
        PhoneAppRecordings.schedule(app, t + 10 * min, t + 12 * min, manufacturer = "samsung")
        PendingImports.endLooks(app)
        val farLater = System.currentTimeMillis() + 6 * day
        assertEquals(
            "only the person's wait, in place of the look's",
            listOf((t - 5_000)..(t + 65_000)),
            PendingImports.waiting(app, farLater)
        )
        // Waits written before there were waits for the person still read.
        assertEquals(listOf(PendingImports.Wait(1, 2, 3)), PendingImports.parse("1:2:3"))
        assertEquals(
            PendingImports.parse("1:2:3;4:5:6:1").toSet(),
            PendingImports.parse(PendingImports.format(PendingImports.parse("1:2:3;4:5:6:1"))).toSet()
        )
        PendingImports.done(app, t - 5_000)
        assertTrue(PendingImports.waiting(app, farLater).isEmpty())
    }

    @Test
    fun `scheduling the Phone app's import makes the call wait, and a wait ends by itself`() {
        PhoneAppRecordings.schedule(app, t, t + 60_000, manufacturer = "samsung")
        assertEquals(listOf(t..t + 60_000), PendingImports.waiting(app, t + 70_000))
        assertTrue(
            "never longer than its limit",
            PendingImports.waiting(app, System.currentTimeMillis() + PendingImports.MAX_WAIT_MS + 1).isEmpty()
        )
        PendingImports.done(app, t)
        PhoneAppRecordings.schedule(app, t + 5 * min, t + 6 * min, manufacturer = "Google")
        assertTrue("a phone with no known call folder: nothing can come, nothing waits", PendingImports.waiting(app, t + 7 * min).isEmpty())
    }
}
