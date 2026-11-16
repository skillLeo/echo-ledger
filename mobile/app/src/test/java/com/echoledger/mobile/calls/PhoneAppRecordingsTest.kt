package com.echoledger.mobile.calls

import com.echoledger.mobile.calls.PhoneAppRecordings.LogCall
import com.echoledger.mobile.calls.PhoneAppRecordings.MediaFile
import com.echoledger.mobile.calls.PhoneAppRecordings.Own
import com.echoledger.mobile.calls.PhoneAppRecordings.Trust
import com.echoledger.mobile.calls.PhoneAppRecordings.Verdict
import com.echoledger.mobile.recording.CallJournal
import com.echoledger.mobile.recording.CallJournal.Result
import com.echoledger.mobile.recording.CallJournal.Seen
import java.time.Instant
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The Phone app's own call recordings (v1.8 0.3), the pure part: which folders hold them on each brand, which file is
 * which call's recording, what EchoLedger does with it, and what it never takes.
 */
class PhoneAppRecordingsTest {
    private val zone = ZoneId.of("Asia/Karachi")
    private val t0 = LocalDateTime.of(2026, 10, 29, 10, 15, 0).atZone(zone).toInstant().toEpochMilli()
    private val sec = 1_000L
    private val samsungDialer = "com.samsung.android.dialer"

    private fun call(at: Long = t0, talkS: Long = 125, number: String? = "+923001234567", name: String? = null, incoming: Boolean = true) =
        LogCall(at, talkS, number, name, incoming)

    /** The file One UI writes for [call]: recording from the answer ([answerS] after it rang), named with that time. */
    private fun samsung(
        id: Long,
        call: LogCall,
        answerS: Long = 8,
        durationS: Long = call.talkS,
        who: String = "Omar",
        path: String = "Recordings/Call/",
        owner: String? = samsungDialer
    ): MediaFile {
        val start = call.dateMs + answerS * sec
        val stamp = DateTimeFormatter.ofPattern("yyMMdd_HHmmss").withZone(zone).format(Instant.ofEpochMilli(start))
        val written = start + durationS * sec + 1_000
        return MediaFile(id, "Call recording ${who}_$stamp.m4a", path, written, durationS * sec, written, owner, durationS * 16_000)
    }

    private fun match(calls: List<LogCall>, files: List<MediaFile>, handled: Set<Long> = emptySet()) =
        PhoneAppRecordings.match(calls, files, handled, zone)

    // ---- the folders --------------------------------------------------------------------------------------------------

    @Test
    fun `each brand's call-recording folder is known, as the media store gives it`() {
        val rows = mapOf(
            "Recordings/Call/" to "Samsung",
            "recordings/call/" to "Samsung",
            "Recordings/Call/2026/" to "Samsung",
            "Call/" to "Samsung",
            "MIUI/sound_recorder/call_rec/" to "Xiaomi, Redmi, POCO",
            "Music/Recordings/Call Recordings/" to "Oppo, realme, OnePlus",
            "Recordings/Call Recordings/" to "Oppo, realme, OnePlus",
            "Record/PhoneRecord/" to "OnePlus",
            "Record/Call/" to "vivo",
            "Sounds/CallRecord/" to "Huawei, Honor"
        )
        rows.forEach { (path, brand) -> assertEquals(path, brand, PhoneAppRecordings.folderOf(path)?.brand) }
    }

    @Test
    fun `a voice memo folder or any other is never a call folder, nor a path that climbs out of one`() {
        listOf(
            "Recordings/Voice Recorder/",
            "Recordings/",
            "Music/",
            "Download/",
            "WhatsApp/Media/WhatsApp Voice Notes/",
            "Callbacks/",
            "MIUI/sound_recorder/",
            "Android/data/com.google.android.dialer/files/",
            "Recordings/Call/../Voice Recorder/",
            "",
            null
        ).forEach { assertNull("$it", PhoneAppRecordings.folderOf(it)) }
        assertTrue(
            "Google's Phone app keeps its recordings inside the app: no folder for Pixel",
            PhoneAppRecordings.FOLDERS.none { it.brand.contains("Pixel") || it.brand.contains("Google") }
        )
    }

    // ---- which file is which call's -------------------------------------------------------------------------------------

    @Test
    fun `the start time in a Phone app's file name is read in the phone's time zone, never a number`() {
        assertEquals(t0, PhoneAppRecordings.stampOf("Call recording Omar_261029_101500.m4a", zone))
        assertEquals(t0, PhoneAppRecordings.stampOf("Omar(03001234567)_20261029101500.mp3", zone))
        assertEquals(t0, PhoneAppRecordings.stampOf("Omar 2026-10-29 10-15-00.m4a", zone))
        assertEquals("a 12-digit number first", t0, PhoneAppRecordings.stampOf("+923001234567_261029101500.m4a", zone))
        assertNull(PhoneAppRecordings.stampOf("Call recording 03001234567.m4a", zone))
    }

    @Test
    fun `incoming and outgoing calls get their recording, from the answer to a moment after the end`() {
        val incoming = call(incoming = true)
        val outgoing = call(at = t0 + 30 * 60 * sec, talkS = 61, incoming = false)
        val files = listOf(samsung(1, incoming, answerS = 20), samsung(2, outgoing, answerS = 6))
        assertEquals(listOf(incoming to files[0], outgoing to files[1]), match(listOf(incoming, outgoing), files))
    }

    @Test
    fun `a 5-second call's recording is found (Skip calls shorter than decides after)`() {
        val short = call(talkS = 5)
        val file = samsung(1, short)
        assertEquals(listOf(short to file), match(listOf(short), listOf(file)))
        assertEquals(Verdict.SHORT, PhoneAppRecordings.verdict(short, null, null, always(), 10, t0))
        assertEquals(Verdict.IMPORT, PhoneAppRecordings.verdict(short, null, null, always(), 0, t0))
        assertEquals("under 3 s is never a call", Verdict.SHORT, PhoneAppRecordings.verdict(call(talkS = 2), null, null, always(), 0, t0))
    }

    @Test
    fun `two calls back to back each get their own recording, even when no name is in the files`() {
        val a = call(talkS = 60)
        val b = call(at = t0 + 65 * sec, talkS = 30)
        val fa = samsung(1, a, answerS = 4, who = "")
        val fb = samsung(2, b, answerS = 4, who = "")
        assertEquals(listOf(a to fa, b to fb), match(listOf(a, b), listOf(fb, fa)))
    }

    @Test
    fun `for an unsaved number the file named with the number wins over one of the same length`() {
        val c = call(number = "+923001234567", name = null)
        val other = samsung(1, c, who = "Ali")
        val named = samsung(2, c, who = "+92 300 1234567")
        assertEquals(named, match(listOf(c), listOf(other, named)).single().second)
        val contact = call(number = "+923331112223", name = "Ali")
        assertEquals("a saved contact: its name", other, match(listOf(contact), listOf(other, named)).single().second)
    }

    @Test
    fun `without a name in the file, the length closest to the talk time is taken`() {
        val c = call(talkS = 123, number = null)
        val far = samsung(1, c, durationS = 110, who = "")
        val close = samsung(2, c, durationS = 122, who = "")
        assertEquals(close, match(listOf(c), listOf(far, close)).single().second)
    }

    @Test
    fun `never a file outside the folder, before the call, long after it, too short, of another length or already handled`() {
        val c = call()
        val good = samsung(9, c)
        val outside = samsung(1, c, path = "Recordings/Voice Recorder/")
        val before = samsung(2, c).let { it.copy(addedMs = c.dateMs - 60_000, modifiedMs = c.dateMs - 60_000) }
        val late = samsung(3, c).let {
            val after = PhoneAppRecordings.windowOf(c).last + 1
            it.copy(addedMs = after, modifiedMs = after)
        }
        val empty = samsung(4, c, durationS = 0).copy(durationMs = 400)
        val otherLength = samsung(5, c, durationS = 30)
        listOf(outside, before, late, empty, otherLength).forEach {
            assertEquals("${it.id}", emptyList<Any>(), match(listOf(c), listOf(it)))
        }
        assertEquals("already settled", emptyList<Any>(), match(listOf(c), listOf(good), handled = setOf(9)))
        assertEquals("the call log's unanswered calls have none", emptyList<Any>(), match(listOf(call(talkS = 0)), listOf(good)))
    }

    @Test
    fun `a file changed after the call is not that call's recording`() {
        val c = call()
        val changed = samsung(1, c).let { it.copy(modifiedMs = PhoneAppRecordings.windowOf(c).last + 1) }
        assertEquals(emptyList<Any>(), match(listOf(c), listOf(changed)))
    }

    @Test
    fun `a recording lasts about as long as its call`() {
        assertTrue(PhoneAppRecordings.lengthFits(125_000, 125))
        assertTrue("started as the call was dialled", PhoneAppRecordings.lengthFits(125_000 + 40_000, 125))
        assertTrue(PhoneAppRecordings.lengthFits(110_000, 125))
        assertFalse(PhoneAppRecordings.lengthFits(30_000, 125))
        assertFalse(PhoneAppRecordings.lengthFits(125_000 + 120_000, 125))
    }

    // ---- what is never taken ----------------------------------------------------------------------------------------------

    @Test
    fun `only the Phone app's own file is taken, an unknown maker waits for the person, an oversize one never`() {
        val c = call()
        assertEquals(Trust.OK, PhoneAppRecordings.trust(samsung(1, c), c, defaultDialer = null))
        assertEquals(Trust.OK, PhoneAppRecordings.trust(samsung(1, c, owner = "com.samsung.android.incallui"), c, null))
        assertEquals(
            "another app",
            Trust.NOT_THE_PHONE_APP,
            PhoneAppRecordings.trust(samsung(1, c, owner = "com.example.recorder"), c, null)
        )
        assertEquals(
            "the phone's own default dialer",
            Trust.OK,
            PhoneAppRecordings.trust(samsung(1, c, owner = "com.example.dialer"), c, "com.example.dialer")
        )
        assertEquals(Trust.OK, PhoneAppRecordings.trust(samsung(1, c, owner = PhoneAppRecordings.GOOGLE_DIALER), c, null))
        assertEquals("the phone does not say", Trust.UNKNOWN_MAKER, PhoneAppRecordings.trust(samsung(1, c, owner = null), c, null))
        val huge = samsung(1, c).copy(sizeBytes = PhoneAppRecordings.maxBytes(c.talkS) + 1)
        assertEquals(Trust.TOO_LARGE, PhoneAppRecordings.trust(huge, c, null))
        assertTrue("never more than the ceiling", PhoneAppRecordings.maxBytes(100_000) == PhoneAppRecordings.MAX_BYTES)
    }

    // ---- what EchoLedger does with it -------------------------------------------------------------------------------------

    private fun always(autoRecord: Boolean = true, viewer: Boolean = false) =
        CallJournal.Choices(autoRecord = autoRecord, phoneCalls = "always", viewer = viewer)

    private fun entry(result: Result?, note: String? = null): CallJournal.Call {
        var b = CallJournal.seen(CallJournal.Book(), "e1", "phone_call", Seen.HELPER, t0 + 8_000)
        if (result == Result.FAILED) b = CallJournal.fail(b, "e1", "unknown", "x", t0 + 9_000)
        if (result != null && result != Result.FAILED) b = CallJournal.finish(b, "e1", result, t0 + 140_000, note)
        return b.call("e1")!!
    }

    @Test
    fun `what becomes of the Phone app's recording`() {
        val c = call()
        val ask = CallJournal.Choices(autoRecord = true, phoneCalls = "ask")
        assertEquals(
            "EchoLedger still saving",
            Verdict.WAIT,
            PhoneAppRecordings.verdict(c, entry(null), null, always(), 10, t0 + 200_000, busy = true)
        )
        assertEquals(
            "its own with sound",
            Verdict.OWN,
            PhoneAppRecordings.verdict(c, entry(Result.FAILED), Own(t0, t0 + 130_000, 2), always(), 10, t0)
        )
        assertEquals(Verdict.OWN, PhoneAppRecordings.verdict(c, entry(Result.KEPT), null, always(), 10, t0))
        assertEquals(Verdict.DISCARDED, PhoneAppRecordings.verdict(c, entry(Result.DISCARDED), null, always(), 10, t0))
        assertEquals(
            "Record not chosen",
            Verdict.NOT_ASKED,
            PhoneAppRecordings.verdict(c, entry(Result.NOT_WANTED, "Record was not chosen"), null, ask, 10, t0)
        )
        assertEquals("the line gave nothing", Verdict.IMPORT, PhoneAppRecordings.verdict(c, entry(Result.FAILED), null, ask, 10, t0))
        assertEquals("Always, EchoLedger never saw it", Verdict.IMPORT, PhoneAppRecordings.verdict(c, null, null, always(), 10, t0))
        assertEquals(Verdict.NOT_ASKED, PhoneAppRecordings.verdict(c, null, null, ask, 10, t0))
        assertEquals(Verdict.NOT_ASKED, PhoneAppRecordings.verdict(c, null, null, always(autoRecord = false), 10, t0))
        assertEquals(Verdict.NOT_ASKED, PhoneAppRecordings.verdict(c, null, null, always(viewer = true), 10, t0))
        assertEquals("no choices known then", Verdict.NOT_ASKED, PhoneAppRecordings.verdict(c, null, null, null, 10, t0))
    }

    @Test
    fun `EchoLedger's own entry for each of two calls back to back, never an import's`() {
        val a = call(talkS = 60)
        val b = call(at = t0 + 65 * sec, talkS = 30)
        var j = CallJournal.seen(CallJournal.Book(), "e1", "phone_call", Seen.HELPER, t0 + 3_000)
        j = CallJournal.seen(j, "e2", "phone_call", Seen.HELPER, t0 + 66_000)
        j = CallJournal.seen(j, "p1", "phone_call", Seen.PHONE_APP, t0 + 4_000)
        j = CallJournal.seen(j, "w1", "whatsapp", Seen.HELPER, t0 + 2_000)
        assertEquals(mapOf(a to "e1", b to "e2"), PhoneAppRecordings.entriesFor(listOf(a, b), j.calls).mapValues { it.value.id })
    }

    @Test
    fun `EchoLedger's own recording counts when it covers the call, not when the last seconds of the one before run over`() {
        val span = (t0 + 8_000)..(t0 + 133_000)
        val two = Own(t0, t0 + 134_000, 2)
        assertEquals(two, PhoneAppRecordings.ownFor(span, listOf(two)))
        val before = Own(t0 - 60_000, t0 + 9_500, 2)
        assertNull("1.5 s over: the call before", PhoneAppRecordings.ownFor(span, listOf(before)))
    }

    @Test
    fun `the notes name no one and no number`() {
        val c = call(name = "Omar Khan")
        val file = samsung(1, c)
        val notes =
            Verdict.entries.map {
                PhoneAppRecordings.note(it, c, file, entry(Result.NOT_WANTED, "Record was not chosen"), Own(0, 1, 2), always(), 10)
            } +
                Trust.entries.map { PhoneAppRecordings.note(it) }
        notes.forEach { note ->
            assertFalse(note, note.contains("Omar") || Regex("\\d{5,}").containsMatchIn(note))
        }
        assertEquals(
            "EchoLedger's own recording kept (both sides apart); the Phone app's copy stays in its folder.",
            PhoneAppRecordings.note(Verdict.OWN, c, file, null, Own(0, 1, 2), always(), 10)
        )
        assertEquals(
            "not imported, shorter than 10 s (Skip calls shorter than).",
            PhoneAppRecordings.note(Verdict.SHORT, call(talkS = 5), file, null, null, always(), 10)
        )
    }

    @Test
    fun `a call is named after the contact, else its number`() {
        assertEquals("Call with Omar Khan", PhoneAppRecordings.title("Omar Khan", "+923001234567"))
        assertEquals("Call with +92 300 1234567", PhoneAppRecordings.title(null, "+92 300 1234567"))
        assertEquals("Call with +92 300 1234567", PhoneAppRecordings.title(" ", "+92 300 1234567"))
        assertNull(PhoneAppRecordings.title(null, "Private"))
    }

    @Test
    fun `the guide names the Phone app's own path on a Galaxy and on Xiaomi phones`() {
        assertEquals("Phone app → ⋮ → Settings → Record calls → Auto record calls → All calls.", PhoneAppRecordings.guide("samsung"))
        assertEquals(PhoneAppRecordings.guide("samsung"), PhoneAppRecordings.guide("SAMSUNG"))
        assertEquals(
            "Phone app → Settings → Call recording → Record calls automatically → All numbers.",
            PhoneAppRecordings.guide("Xiaomi")
        )
        assertEquals(PhoneAppRecordings.guide("Xiaomi"), PhoneAppRecordings.guide("POCO"))
        assertTrue(PhoneAppRecordings.guide("Google").startsWith("In your Phone app's settings"))
    }
}
