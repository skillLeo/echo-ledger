package com.echoledger.mobile.calls

import android.app.NotificationManager
import androidx.work.testing.TestListenableWorkerBuilder
import com.echoledger.mobile.calls.PhoneAppRecordings.LogCall
import com.echoledger.mobile.calls.PhoneAppRecordings.MediaFile
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.recording.CallJournal
import com.echoledger.mobile.recording.CaptureLog
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.storeRecording
import java.io.ByteArrayInputStream
import java.io.File
import java.io.InputStream
import java.time.Instant
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.jsonObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadows.ShadowSettings

/**
 * The Phone app's own call recordings brought in (v1.8 0.3), on the phone with a stand-in media store and call log: the
 * recording, its name and the after-call card; EchoLedger's own recording kept instead; what is never taken, never
 * deleted, and what waits for the person.
 */
class PhoneAppRecordingsRunTest : AppTest() {
    private val sec = 1_000L
    private var t = 0L

    /** The media store and the call log, as the phone would answer; it can only be read. */
    private class Store(
        val files: MutableList<MediaFile>,
        var calls: List<LogCall>,
        var dialer: String? = null,
        var system: Set<String> = setOf("com.samsung.android.dialer", "com.samsung.android.incallui")
    ) : PhoneAppRecordings.Sources {
        val opened = mutableListOf<Long>()
        val bytes = mutableMapOf<Long, ByteArray>()
        val changed = mutableMapOf<Long, MediaFile>()

        override fun files(sinceMs: Long) = files.filter { it.addedMs >= sinceMs - 10_000 }

        override fun calls(sinceMs: Long) = calls.filter { it.dateMs >= sinceMs }

        override fun reload(file: MediaFile): MediaFile? = changed[file.id] ?: files.firstOrNull { it.id == file.id }

        override fun open(file: MediaFile): InputStream? {
            opened += file.id
            return ByteArrayInputStream(bytes[file.id] ?: ByteArray(file.sizeBytes.toInt()) { (it % 251).toByte() })
        }

        override fun thisPhone() = PhoneAppRecordings.ThisPhone("samsung", dialer, isSystemApp = { it in system })
    }

    @Before
    fun alwaysRecordPhoneCalls() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true, phoneCalls = "always") } }
        ShadowSettings.setCanDrawOverlays(true)
        t = System.currentTimeMillis() + 2_000 // after the journal began and the choices reached it
    }

    @After
    fun closeCard() = DecisionPopup.hide()

    private fun call(at: Long = t, talkS: Long = 125, number: String? = "+923001234567", name: String? = "Omar Khan") =
        LogCall(at, talkS, number, name, incoming = true)

    /** One UI's file for [call]: from the answer 8 s after it rang, written when it ended, by the Phone app. */
    private fun recording(id: Long, call: LogCall, owner: String? = "com.samsung.android.dialer", durationS: Long = call.talkS): MediaFile {
        val end = call.dateMs + 8 * sec + durationS * sec + 1_000
        return MediaFile(id, "Call recording $id.m4a", "Recordings/Call/", end, durationS * sec, end, owner, 4_000)
    }

    private fun run(store: Store, atMs: Long = t + 10 * 60 * sec) = runBlocking { PhoneAppRecordings.run(app, store, atMs) }

    private fun note() = PhoneAppRecordings.lastNote(app)?.second

    @Test
    fun `the Phone app's recording of a call EchoLedger missed comes in, named after the contact, with the after-call card`() {
        val c = call()
        val store = Store(mutableListOf(recording(1, c)), listOf(c))
        val imported = run(store).imported.single()

        val rec = app.graph.recordings.get(imported)!!
        assertEquals("phone_call", rec.sourceApp)
        assertEquals("Omar Khan", rec.callerName)
        assertEquals("+923001234567", rec.callerNumber)
        assertEquals("its length is the file's", 125_000L, rec.durationMs)
        assertEquals(Instant.ofEpochMilli(c.dateMs + 8 * sec + 1_000).toString(), rec.startedAt)
        assertTrue(rec.captureWarning!!.startsWith(PhoneAppRecordings.IMPORTED))
        assertEquals("the after-call card", imported, DecisionPopup.showingFor)
        assertEquals("Call with Omar Khan", DecisionPopup.title(rec))
        assertEquals(CallJournal.Seen.PHONE_APP, CallJournal.book(app).call(imported)?.seenBy)
        assertEquals("Imported the Phone app's recording (125 s).", note())
        assertTrue(CaptureLog.lines(app).any { it.contains("phone app recording") && it.contains("imported") })
        assertEquals("brought in once", 0, run(store).imported.size)
    }

    @Test
    fun `an unsaved number names the call by its number`() {
        val c = call(name = null)
        val rec = app.graph.recordings.get(run(Store(mutableListOf(recording(1, c)), listOf(c))).imported.single())!!
        assertNull(rec.callerName)
        assertEquals("+923001234567", rec.callerNumber)
        assertEquals("Call with +923001234567", DecisionPopup.title(rec))
    }

    /** EchoLedger's own two-track recording of [c], kept; its journal says whether it heard [bothSides]. */
    private fun ownRecording(c: LogCall, bothSides: Boolean) {
        app.storeRecording(
            id = "mine",
            sourceApp = "phone_call",
            startedAt = Instant.ofEpochMilli(c.dateMs + 6 * sec).toString(),
            endedAt = Instant.ofEpochMilli(c.dateMs + 134 * sec).toString(),
            durationMs = 128_000,
            channels = 2
        )
        CallJournal.update(app) { b ->
            var j = CallJournal.seen(b, "mine", "phone_call", CallJournal.Seen.HELPER, c.dateMs + 3 * sec)
            j = CallJournal.stage(j, "mine", CallJournal.Stage.ARMED, c.dateMs + 3 * sec, "helper ready")
            j = CallJournal.stage(j, "mine", CallJournal.Stage.STARTED, c.dateMs + 6 * sec)
            if (bothSides) j = CallJournal.stage(j, "mine", CallJournal.Stage.BOTH_AUDIO, c.dateMs + 9 * sec)
            j = CallJournal.ended(j, "mine", c.dateMs + 134 * sec)
            CallJournal.finish(j, "mine", CallJournal.Result.KEPT, c.dateMs + 135 * sec, keptMs = 128_000)
        }
    }

    @Test
    fun `EchoLedger's own two-sided recording is kept and the Phone app's copy is never even read`() {
        val c = call()
        ownRecording(c, bothSides = true)
        val store = Store(mutableListOf(recording(1, c)), listOf(c))
        assertEquals(emptyList<String>(), run(store).imported)
        assertEquals(emptyList<Long>(), store.opened)
        assertEquals("EchoLedger's own recording kept (both sides apart); the Phone app's copy stays in its folder.", note())
        assertEquals(listOf("mine"), app.graph.recordings.all().map { it.id })
    }

    @Test
    fun `EchoLedger's own recording with your voice only does not keep the Phone app's two-sided copy out`() {
        val c = call()
        ownRecording(c, bothSides = false)
        val store = Store(mutableListOf(recording(1, c)), listOf(c))
        val imported = run(store).imported.single()
        assertEquals(listOf(1L), store.opened)
        assertEquals("Imported the Phone app's recording (125 s); EchoLedger's own has one side only.", note())
        assertEquals(setOf("mine", imported), app.graph.recordings.all().map { it.id }.toSet())
    }

    @Test
    fun `a call EchoLedger could not hear is brought in`() {
        val c = call()
        CallJournal.update(app) { b ->
            val seen = CallJournal.seen(b, "e1", "phone_call", CallJournal.Seen.HELPER, c.dateMs + 8 * sec)
            CallJournal.fail(
                CallJournal.ended(seen, "e1", c.dateMs + 140 * sec),
                "e1",
                "unknown",
                MissedReason.PHONE_LINE,
                c.dateMs + 9 * sec
            )
        }
        assertEquals(1, run(Store(mutableListOf(recording(1, c)), listOf(c))).imported.size)
    }

    @Test
    fun `nothing in the Phone app's folder is deleted unless the person turned it on, and then Android asks`() {
        val c = call()
        run(Store(mutableListOf(recording(1, c)), listOf(c)))
        assertEquals(emptyList<Long>(), PhoneAppRecordings.pendingDeletes(app))
        assertEquals(emptyList<Any>(), PhoneAppRecordings.takeDeletes(app, runBlocking { app.graph.settings.current() }))

        runBlocking { app.graph.settings.update { it.copy(deletePhoneAppCopy = true) } }
        val d = call(at = t + 30 * 60 * sec)
        run(Store(mutableListOf(recording(2, d)), listOf(d)), atMs = t + 40 * 60 * sec)
        assertEquals("waits for Android's own question", listOf(2L), PhoneAppRecordings.pendingDeletes(app))
        val off = runBlocking { app.graph.settings.current() }.copy(deletePhoneAppCopy = false)
        assertEquals("turned off before it was asked: forgotten", emptyList<Any>(), PhoneAppRecordings.takeDeletes(app, off))
        assertEquals(emptyList<Long>(), PhoneAppRecordings.pendingDeletes(app))

        // The import itself has no way to delete: it reads the media store and nothing else.
        val source = File("src/main/java/com/echoledger/mobile/calls/PhoneAppRecordings.kt").readText()
        listOf(
            "contentResolver.delete",
            "createDeleteRequest",
            "createTrashRequest",
            "deleteDocument",
            "MediaStore.Audio.Media.DATA"
        ).forEach {
            assertFalse(it, source.contains(it))
        }
    }

    @Test
    fun `a call shorter than Skip calls shorter than is not brought in`() {
        val c = call(talkS = 5)
        val store = Store(mutableListOf(recording(1, c)), listOf(c))
        assertEquals(emptyList<String>(), run(store).imported)
        assertEquals("Not imported, shorter than 10 s (Skip calls shorter than).", note())
    }

    @Test
    fun `on Ask each time only a call Record was chosen for comes in`() {
        runBlocking { app.graph.settings.update { it.copy(phoneCalls = "ask") } }
        val declined = call()
        val chosen = call(at = t + 20 * 60 * sec)
        CallJournal.update(app) { b ->
            var j = CallJournal.finish(
                CallJournal.seen(b, "e1", "phone_call", CallJournal.Seen.HELPER, declined.dateMs + 3 * sec),
                "e1",
                CallJournal.Result.NOT_WANTED,
                declined.dateMs + 140 * sec,
                "Record was not chosen"
            )
            j = CallJournal.seen(j, "e2", "phone_call", CallJournal.Seen.HELPER, chosen.dateMs + 3 * sec)
            CallJournal.fail(
                CallJournal.ended(j, "e2", chosen.dateMs + 140 * sec),
                "e2",
                "unknown",
                "No sound was captured.",
                chosen.dateMs + 141 * sec
            )
        }
        val store = Store(mutableListOf(recording(1, declined), recording(2, chosen)), listOf(declined, chosen))
        val imported = run(store, atMs = t + 30 * 60 * sec).imported
        assertEquals(1, imported.size)
        assertEquals(Instant.ofEpochMilli(chosen.dateMs + 9 * sec).toString(), app.graph.recordings.get(imported.single())!!.startedAt)
        assertTrue(CaptureLog.lines(app).any { it.contains("not imported: Record was not chosen") })
    }

    @Test
    fun `a file another app put in the call folder is never brought in, nor read, and never takes the real file's place`() {
        val c = call()
        val store = Store(mutableListOf(recording(1, c, owner = "com.example.recorder")), listOf(c))
        assertEquals(emptyList<String>(), run(store).imported)
        assertEquals(emptyList<Long>(), store.opened)
        assertTrue(
            CaptureLog.lines(app).any {
                it.contains("not imported: another app (com.example.recorder) made the file in the call folder")
            }
        )

        // Both in one window: the other app's file names the person and has the closest length, the Phone app's comes in.
        val d = call(at = t + 30 * 60 * sec, name = "Sara")
        val fake = recording(3, d, owner = "com.example.recorder").copy(name = "Call recording Sara.m4a")
        val real = recording(4, d, durationS = d.talkS - 6)
        val both = Store(mutableListOf(fake, real), listOf(d))
        val imported = run(both, atMs = t + 40 * 60 * sec).imported.single()
        assertEquals(listOf(4L), both.opened)
        assertEquals((d.talkS - 6) * sec, app.graph.recordings.get(imported)!!.durationMs)
    }

    @Test
    fun `an app installed later under the Phone app's name is not trusted`() {
        val c = call()
        val store = Store(mutableListOf(recording(1, c, owner = "com.google.android.dialer")), listOf(c))
        assertEquals(emptyList<String>(), run(store).imported)
        val spoof = Store(mutableListOf(recording(2, c)), listOf(c), system = emptySet())
        assertEquals("named like Samsung's Phone app, not installed with the phone", emptyList<String>(), run(spoof).imported)
        assertEquals(emptyList<Long>(), store.opened + spoof.opened)
    }

    @Test
    fun `a look stopped right after a file was stored never brings it in a second time`() {
        val c = call()
        val store = Store(mutableListOf(recording(1, c)), listOf(c))
        PhoneAppRecordings.afterStoreForTest = { throw kotlinx.coroutines.CancellationException("the look was stopped") }
        try {
            runCatching { run(store) }
        } finally {
            PhoneAppRecordings.afterStoreForTest = null
        }
        assertEquals("stored once", 1, app.graph.recordings.all().size)
        assertEquals("the next look skips it", emptyList<String>(), run(store, atMs = t + 11 * 60 * sec).imported)
        assertEquals(1, app.graph.recordings.all().size)
        assertTrue("the server is told to skip a file it has", app.graph.recordings.all().single().skipDuplicate)
    }

    @Test
    fun `a file changed after the call is not brought in`() {
        val c = call()
        val file = recording(1, c)
        val store = Store(mutableListOf(file), listOf(c))
        store.changed[1] = file.copy(modifiedMs = file.modifiedMs + 30 * 60 * sec, sizeBytes = 9_000)
        assertEquals(emptyList<String>(), run(store).imported)
        assertEquals("Not imported: the file changed after the call.", note())
        assertTrue(app.graph.recordings.all().isEmpty())
    }

    @Test
    fun `a file larger than a recording of the call can be is not brought in, however small it says it is`() {
        val c = call(talkS = 20)
        val store = Store(mutableListOf(recording(1, c).copy(sizeBytes = 0)), listOf(c))
        store.bytes[1] = ByteArray((PhoneAppRecordings.maxBytes(20) + 1).toInt())
        assertEquals(emptyList<String>(), run(store).imported)
        assertEquals("Not imported: the file is larger than a recording of this call can be.", note())
        assertTrue(app.graph.recordings.all().isEmpty())
        assertTrue("no copy left behind", app.cacheDir.listFiles().orEmpty().none { it.name.startsWith("import-") })
    }

    @Test
    fun `a file the phone does not say the maker of waits for the person, and comes in when they choose it`() {
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        val c = call()
        val store = Store(mutableListOf(recording(1, c, owner = null)), listOf(c))
        assertEquals(emptyList<String>(), run(store).imported)
        assertEquals(listOf(PhoneAppRecordings.Candidate(1, c.dateMs)), PhoneAppRecordings.candidates(app))
        assertEquals(emptyList<Long>(), store.opened)
        val notices = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications
        assertTrue(notices.any { it.extras.getString("android.title") == "Import the Phone app's recording?" })

        val note = runBlocking { PhoneAppRecordings.importCandidate(app, 1, store) }
        assertEquals("imported the Phone app's recording (125 s), as you chose.", note)
        assertEquals(1, app.graph.recordings.all().size)
        assertTrue(PhoneAppRecordings.candidates(app).isEmpty())
        assertEquals(app.graph.recordings.all().single().id, DecisionPopup.showingFor)
    }

    @Test
    fun `a missed recording found before the Phone app's recording came in is withdrawn`() {
        val c = call()
        MissedRecordingStore.add(app, listOf(MissedRecordings.Found("phone_call", c.dateMs, 125, MissedReason.UNKNOWN, "")))
        run(Store(mutableListOf(recording(1, c)), listOf(c)))
        val item = MissedRecordingStore.all(app).single()
        assertTrue(item.withdrawn && !item.waiting)
    }

    @Test
    fun `a call whose recording waits for the person is never reported missed while it waits, however long`() {
        val c = call()
        // EchoLedger could not open the phone line; the dialer's notice says the call was answered.
        CallJournal.update(app) { b ->
            val seen = CallJournal.seen(b, "e1", "phone_call", CallJournal.Seen.HELPER, c.dateMs + 8 * sec)
            CallJournal.fail(
                CallJournal.ended(seen, "e1", c.dateMs + 135 * sec),
                "e1",
                "unknown",
                MissedReason.PHONE_LINE,
                c.dateMs + 9 * sec
            )
        }
        CallSightings.update(app) {
            listOf(CallSightings.Seen("phone_call", c.dateMs + 8 * sec, c.dateMs + 134 * sec, "com.samsung.android.incallui"))
        }
        PhoneAppRecordings.schedule(app, c.dateMs + 8 * sec, c.dateMs + 135 * sec)
        val store = Store(mutableListOf(recording(1, c, owner = null)), listOf(c))
        run(store)
        assertEquals(listOf(1L), PhoneAppRecordings.candidates(app).map { it.fileId })

        // The looks after the call are over, and an hour passes: the call still waits for the person's answer.
        runBlocking { TestListenableWorkerBuilder<PhoneAppRecordings.Worker>(app).build().doWork() }
        val hourLater = t + 60 * 60 * sec
        assertTrue(runBlocking { MissedRecordingWorker.detect(app, hourLater) }.isEmpty())

        // "Not this call": now it is a missed recording.
        PhoneAppRecordings.dropCandidate(app, 1)
        assertEquals(
            listOf(c.dateMs + 8 * sec),
            runBlocking {
                MissedRecordingWorker.detect(app, hourLater + 60 * sec)
            }.map { it.startedAtMs }
        )
    }

    @Test
    fun `a report sent before the person imported the call's recording is withdrawn, and read on the server too`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/activity",
            FakeServer.json(
                200,
                """{"data":[{"id":"act-1","kind":"missed_recording","title":"Missed recording","body":"Phone call",""" +
                    """"read":false,"missed_recording_id":"01JMISSED0000000000000000F"}],"unread_count":1}"""
            )
        )
        server.on("POST", "/api/activity/read", FakeServer.json(200, """{"unread_count":0}"""))
        val c = call()
        val store = Store(mutableListOf(recording(1, c, owner = null)), listOf(c))
        run(store)
        // It was found and sent before (an import that looked late), and the person imports the recording now.
        val item = MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("phone_call", c.dateMs, 125, MissedReason.UNKNOWN, ""))
        ).single()
        MissedRecordingStore.sent(app, item.ref, "01JMISSED0000000000000000F")
        runBlocking { PhoneAppRecordings.importCandidate(app, 1, store) }

        assertTrue(MissedRecordingStore.all(app).single().withdrawn)
        runBlocking { TestListenableWorkerBuilder<MissedUploadWorker>(app).build().doWork() }
        val read = server.requestsTo("POST", "/api/activity/read").map { ApiJson.parseToJsonElement(it.bodyText()).jsonObject.toString() }
        assertEquals(listOf("""{"ids":["act-1"]}"""), read)
    }

    @Test
    fun `settling or importing one call never touches the next call's wait or report`() {
        val a = call(talkS = 30)
        // B rang 90 s after A: its report and its wait are its own.
        val next = MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("phone_call", a.dateMs + 90 * sec, 60, MissedReason.HELPER_NOT_ARMED, ""))
        ).single()
        PhoneAppRecordings.schedule(app, a.dateMs + 8 * sec, a.dateMs + 40 * sec)
        PhoneAppRecordings.schedule(app, a.dateMs + 90 * sec, a.dateMs + 150 * sec)
        assertEquals(1, run(Store(mutableListOf(recording(1, a)), listOf(a))).imported.size)
        assertEquals(listOf((a.dateMs + 90 * sec)..(a.dateMs + 150 * sec)), PendingImports.waiting(app))
        assertFalse(MissedRecordingStore.all(app).single { it.ref == next.ref }.withdrawn)
    }

    @Test
    fun `while the import looks after a call the missed-recording detector waits, and stops waiting once it is settled`() {
        val c = call()
        PhoneAppRecordings.schedule(app, c.dateMs + 8 * sec, c.dateMs + 135 * sec)
        assertEquals(1, PendingImports.waiting(app).size)
        run(Store(mutableListOf(recording(1, c)), listOf(c)))
        assertEquals(emptyList<LongRange>(), PendingImports.waiting(app))
    }

    @Test
    fun `the import is off with its switch, with phone calls off, and for a viewer`() {
        val c = call()
        val store = Store(mutableListOf(recording(1, c)), listOf(c))
        runBlocking { app.graph.settings.update { it.copy(phoneAppImport = false) } }
        assertEquals(emptyList<String>(), run(store).imported)
        runBlocking { app.graph.settings.update { it.copy(phoneAppImport = true, phoneCalls = "off") } }
        assertEquals(emptyList<String>(), run(store).imported)
        assertEquals(emptyList<Long>(), store.opened)
    }
}
