package com.echoledger.mobile.calls

import android.app.Application
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.calls.PhoneAppNote.Kind
import com.echoledger.mobile.recording.CallJournal
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** The Phone app import's last-call note is a code each screen says in its own language, never an English sentence. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], application = Application::class)
class PhoneAppNoteTest {
    private val app get() = ApplicationProvider.getApplicationContext<Application>()

    @Test
    fun `every kind is kept and read back with what goes in it`() {
        for (kind in Kind.entries) {
            val note = PhoneAppNote(kind, seconds = 42, app = "WhatsApp", owner = "com.other.app")
            assertEquals(kind.wire, note, PhoneAppNote.fromWire(note.wire()))
            assertFalse(kind.wire, note.english.contains("null") || note.english.isBlank())
        }
        assertEquals(Kind.entries.size, Kind.entries.map { it.wire }.toSet().size)
    }

    @Test
    fun `a free-form reason becomes a code and its words never show`() {
        assertEquals(PhoneAppNote(Kind.PHONE_CALLS_OFF), PhoneAppNote.notWanted(CallJournal.NotWanted.PHONE_CALLS_OFF))
        assertEquals(PhoneAppNote(Kind.RECORD_NOT_CHOSEN), PhoneAppNote.notWanted(CallJournal.NotWanted.RECORD_NOT_CHOSEN))
        assertEquals(
            PhoneAppNote(Kind.APP_AUTO_RECORD_OFF, app = "WhatsApp Business"),
            PhoneAppNote.notWanted(CallJournal.NotWanted.autoRecordOff("WhatsApp Business"))
        )
        // An older build's words, or none: a code with no words of its own.
        val other = PhoneAppNote.notWanted("the user said so on 12 March")
        assertEquals(PhoneAppNote(Kind.NOT_WANTED), other)
        assertFalse(other.english.contains("12 March"))
        assertEquals(PhoneAppNote(Kind.NOT_WANTED), PhoneAppNote.notWanted(null))
    }

    @Test
    fun `the capture log keeps the same sentences as before`() {
        assertEquals("not imported: phone calls are off.", PhoneAppNote(Kind.PHONE_CALLS_OFF).english)
        assertEquals("not imported: auto-record is off for WhatsApp.", PhoneAppNote(Kind.APP_AUTO_RECORD_OFF, app = "WhatsApp").english)
        assertEquals("not imported: Record was not chosen.", PhoneAppNote(Kind.RECORD_NOT_CHOSEN).english)
        assertEquals("imported the Phone app's recording (12 s).", PhoneAppNote(Kind.IMPORTED, seconds = 12).english)
        assertEquals(
            "not imported: another app (com.other.app) made the file in the call folder.",
            PhoneAppNote(Kind.OTHER_APP, owner = "com.other.app").english
        )
        assertEquals(PhoneAppRecordings.UNSUPPORTED, PhoneAppNote(Kind.UNSUPPORTED).english)
    }

    @Test
    fun `lastNote gives the code, and an older build's sentence is not shown`() {
        val prefs = app.getSharedPreferences("phone_app_recordings", 0)
        prefs.edit().putString("note", "Imported the Phone app's recording (12 s).").putLong("note_at", 5).commit()
        assertNull(PhoneAppRecordings.lastNote(app))

        prefs.edit().putString("note", PhoneAppNote(Kind.SHORTER_THAN_SETTING, seconds = 10).wire()).putLong("note_at", 7).commit()
        assertEquals(7L to PhoneAppNote(Kind.SHORTER_THAN_SETTING, seconds = 10), PhoneAppRecordings.lastNote(app))
    }

    @Test
    fun `the not-asked verdict never carries the journal's words`() {
        val file = PhoneAppRecordings.MediaFile(1, "Call.m4a", "Call", 0, 0)
        val call = PhoneAppRecordings.LogCall(1_000, 60, null, null, incoming = true)
        val entry = CallJournal.Call(
            "x",
            "phone_call",
            "helper",
            1_000,
            result = CallJournal.Result.NOT_WANTED.wire,
            note = "phone calls are off"
        )
        val note = PhoneAppRecordings.note(PhoneAppRecordings.Verdict.NOT_ASKED, call, file, entry, null, null, 0)
        assertEquals(PhoneAppNote(Kind.PHONE_CALLS_OFF), note)
    }
}
