package com.echoledger.mobile.recording

import android.media.AudioDeviceInfo
import androidx.compose.ui.graphics.Color
import com.echoledger.mobile.core.Bookmarks
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.MarkerDto
import com.echoledger.mobile.data.PauseDto
import com.echoledger.mobile.overlay.bubbleColour
import com.echoledger.mobile.overlay.bubbleLines
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Capture and control on the phone (plan B1-B5): the pure parts. */
class CaptureControlTest {
    // ---- B3 bookmarks ---------------------------------------------------------------------------------------------

    @Test
    fun `bookmarks are cleaned and come out in time order`() {
        val notes = RecordingNotes()
        notes.mark(9_000, "  Budget ", "max\n\n 5k   per month")
        notes.mark(2_000, "   ", null)

        val (markers, pauses) = notes.finish()

        assertEquals(listOf(MarkerDto(2_000, null, null), MarkerDto(9_000, "Budget", "max 5k per month")), markers)
        assertTrue(pauses.isEmpty())
    }

    @Test
    fun `tags and notes are cut to the server limits in characters`() {
        assertEquals(40, RecordingNotes.clean("t".repeat(45), 40)!!.length)
        assertEquals(200, RecordingNotes.clean("é".repeat(250), 200)!!.codePointCount(0, 200))
        assertEquals(3, RecordingNotes.clean("😀".repeat(5), 3)!!.codePointCount(0, 6))
        assertNull(RecordingNotes.clean("  ", 40))
        assertNull(RecordingNotes.clean(null, 40))
    }

    @Test
    fun `the last bookmark can be tagged cleared and undone`() {
        val notes = RecordingNotes()
        assertFalse("nothing to tag yet", notes.tagLast("Deadline"))
        notes.mark(1_000)
        assertTrue(notes.tagLast("Deadline"))
        assertEquals("Deadline", notes.last()?.tag)
        assertTrue(notes.tagLast(""))
        assertNull("an empty tag clears it", notes.last()?.tag)
        assertEquals(1_000L, notes.undo()?.atMs)
        assertNull(notes.undo())
        assertEquals(0, notes.count())
    }

    @Test
    fun `a recording holds at most five hundred bookmarks`() {
        val notes = RecordingNotes()
        repeat(RecordingNotes.MAX_MARKERS) { assertTrue(notes.mark(it.toLong()) != null) }
        assertNull(notes.mark(9_999, "Budget"))
        assertEquals(RecordingNotes.MAX_MARKERS, notes.count())
    }

    @Test
    fun `tag presets come from the server or the defaults`() {
        assertEquals(listOf("Scope", "Risk"), RecordingNotes.presets(listOf(" Scope ", "Scope", "", "Risk")))
        assertEquals(RecordingNotes.DEFAULT_TAGS, RecordingNotes.presets(emptyList()))
        assertEquals(RecordingNotes.DEFAULT_TAGS, RecordingNotes.presets(null))
        assertEquals(12, RecordingNotes.presets((1..20).map { "Tag $it" }).size)
    }

    // ---- B2 pause gaps --------------------------------------------------------------------------------------------

    @Test
    fun `each resume leaves a gap at the recorded time of the pause`() {
        val notes = RecordingNotes()
        notes.paused(60_000)
        notes.paused(61_000) // pausing twice keeps the first point
        notes.resumed(125_000)
        notes.resumed(5_000) // resuming without a pause changes nothing
        notes.paused(90_000)
        notes.resumed(0) // a zero-length pause is not a gap

        assertEquals(listOf(PauseDto(60_000, 125_000)), notes.finish().second)
    }

    @Test
    fun `a pause still open at the end is not sent`() {
        val notes = RecordingNotes()
        notes.paused(10_000)
        assertTrue(notes.finish().second.isEmpty())
    }

    @Test
    fun `past two hundred pauses the last gap keeps the total`() {
        val notes = RecordingNotes()
        repeat(RecordingNotes.MAX_PAUSES + 3) {
            notes.paused(it * 1_000L)
            notes.resumed(10)
        }
        val pauses = notes.finish().second
        assertEquals(RecordingNotes.MAX_PAUSES, pauses.size)
        assertEquals((RecordingNotes.MAX_PAUSES + 3) * 10L, pauses.sumOf { it.pausedMs })
    }

    // ---- B1 auto-record switches ----------------------------------------------------------------------------------

    @Test
    fun `apps without a switch record and a switched-off app does not`() {
        assertTrue(AutoRecordRules.records(SourceApp.WHATSAPP, emptyMap()))
        assertFalse(AutoRecordRules.records(SourceApp.WHATSAPP, mapOf("whatsapp" to false)))
        assertTrue(AutoRecordRules.records(SourceApp.WHATSAPP_BUSINESS, mapOf("whatsapp" to false)))
        assertEquals(
            listOf(
                SourceApp.WHATSAPP, SourceApp.WHATSAPP_BUSINESS, SourceApp.GOOGLE_MEET, SourceApp.ZOOM, SourceApp.MICROSOFT_TEAMS,
                SourceApp.TELEGRAM, SourceApp.SIGNAL, SourceApp.MESSENGER, SourceApp.INSTAGRAM, SourceApp.SKYPE, SourceApp.SLACK,
                SourceApp.DISCORD
            ),
            AutoRecordRules.APPS
        )
    }

    @Test
    fun `the explainer says what happens to each app's calls`() {
        assertTrue(
            AutoRecordRules.explain(SourceApp.WHATSAPP, emptyMap(), armed = true).startsWith("WhatsApp calls are recorded automatically")
        )
        assertTrue(AutoRecordRules.explain(SourceApp.WHATSAPP, mapOf("whatsapp" to false), armed = true).startsWith("Off for WhatsApp"))
        assertTrue(AutoRecordRules.explain(SourceApp.WHATSAPP, emptyMap(), armed = false).startsWith("Auto-record is off"))
    }

    // ---- B5 capture health ----------------------------------------------------------------------------------------

    @Test
    fun `the far-side warning fires once per silent stretch`() {
        val monitor = FarSideMonitor()
        assertNull(monitor.tick(29_999, true))
        assertEquals(FarSideChange(true, 30), monitor.tick(30_000, true))
        assertNull("warned once", monitor.tick(45_000, true))
        assertEquals(FarSideChange(false, 0), monitor.tick(200, true))
        assertNull(monitor.tick(200, true))
    }

    @Test
    fun `the far-side warning clears on pause or end and respects the setting`() {
        val monitor = FarSideMonitor()
        assertNull("switched off", monitor.tick(40_000, false))
        monitor.tick(40_000, true)
        assertEquals(FarSideChange(false, 0), monitor.tick(null, true))
        monitor.tick(40_000, true)
        assertEquals(FarSideChange(false, 0), monitor.tick(41_000, false))
    }

    @Test
    fun `levels peaks and meters`() {
        assertEquals(0f, Levels.rms(ShortArray(0)))
        assertEquals(0.5f, Levels.rms(shortArrayOf(16_384, -16_384)), 0.001f)
        val samples = ShortArray(16_000)
        for (i in 8_000 until 9_600) samples[i] = 8_192 // one loud 100 ms window at 0.25
        assertEquals(0.25f, Levels.peak(samples, 1_600), 0.001f)
        assertEquals(1f, Levels.meter(0.3f))
        assertEquals(0.4f, Levels.meter(0.1f), 0.0001f)
        assertTrue(Levels.rms(ShortArray(160) { 30 }) < Levels.HEARD)
        assertTrue(Levels.rms(ShortArray(160) { 330 }) >= Levels.HEARD)
    }

    @Test
    fun `the test recording says whether the microphone was heard`() {
        assertFalse(CaptureHealth.summarize(ShortArray(16_000)).heard)
        assertTrue(CaptureHealth.summarize(ShortArray(16_000) { 3_000 }).heard)
    }

    @Test
    fun `input devices map to the microphones the user can pick`() {
        assertEquals("builtin", CaptureHealth.inputChoice(AudioDeviceInfo.TYPE_BUILTIN_MIC)?.key)
        assertEquals("bluetooth", CaptureHealth.inputChoice(AudioDeviceInfo.TYPE_BLUETOOTH_SCO)?.key)
        assertEquals("usb", CaptureHealth.inputChoice(AudioDeviceInfo.TYPE_USB_HEADSET)?.key)
        assertEquals("wired", CaptureHealth.inputChoice(AudioDeviceInfo.TYPE_WIRED_HEADSET)?.key)
        assertNull("the telephony line is not a microphone to pick", CaptureHealth.inputChoice(AudioDeviceInfo.TYPE_TELEPHONY))
    }

    @Test
    fun `the permissions centre lists each permission with why it is needed`() {
        val items = CaptureHealth.permissionItems(microphone = true, notifications = false, overlay = true, battery = false, helper = false)
        assertEquals(listOf("microphone", "helper", "notifications", "overlay", "battery"), items.map { it.key })
        assertEquals(listOf("helper", "notifications", "battery"), items.filterNot { it.granted }.map { it.key })
        assertTrue(items.all { it.why.isNotBlank() })
    }

    // ---- B4 caption style -----------------------------------------------------------------------------------------

    @Test
    fun `an out-of-range caption style falls back value by value`() {
        val style = CaptionStyle(
            size = "huge",
            opacity = 7,
            lines = 4,
            position = "left",
            autoHideSeconds = 12,
            meColor = "green",
            clientColor = "#F59E0B"
        ).sanitized()
        assertEquals(CaptionStyle(clientColor = "#F59E0B"), style)
        assertEquals(19f, CaptionStyle(size = "large").textSp)
        assertEquals(13f, CaptionStyle(size = "small").textSp)
    }

    @Test
    fun `compact captions drop the speaker labels and keep the speaker colour`() {
        val lines = listOf("Me" to "hello", "Client" to "hi")
        assertEquals(listOf("ME  hello", "CLIENT  hi", "ME  so"), bubbleLines(lines, "Me" to "so", compact = false).map { it.text })
        val compact = bubbleLines(lines, null, compact = true)
        assertEquals(listOf("hello", "hi"), compact.map { it.text })
        assertEquals(listOf(false, true), compact.map { it.client })
        assertEquals(Color(0xFF22C55E), bubbleColour("#22c55e", Color.White))
        assertEquals(Color.White, bubbleColour("green", Color.White))
    }

    // ---- library tags ---------------------------------------------------------------------------------------------

    @Test
    fun `the library filters by bookmark and by tag`() {
        val items = listOf(
            LibraryItem(id = "a", updatedAt = "", markerCount = 3, tags = listOf("Budget", "Deadline")),
            LibraryItem(id = "b", updatedAt = "", markerCount = 1),
            LibraryItem(id = "c", updatedAt = ""),
            LibraryItem(id = "d", updatedAt = "", deleted = true, tags = listOf("Secret"))
        )
        assertEquals(listOf("Budget", "Deadline"), Bookmarks.tags(items))
        assertEquals(listOf("a", "b"), items.filter { Bookmarks.matches(it, Bookmarks.ANY) }.map { it.id })
        assertEquals(listOf("a"), items.filter { Bookmarks.matches(it, "Deadline") }.map { it.id })
        assertEquals(4, items.count { Bookmarks.matches(it, null) })
        assertEquals("3 bookmarks", Bookmarks.countLabel(3))
        assertEquals("1 bookmark", Bookmarks.countLabel(1))
        assertNull(Bookmarks.countLabel(0))
    }
}
