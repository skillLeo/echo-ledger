package com.echoledger.mobile.ui.v2

import android.app.Application
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.RowStatus
import com.echoledger.mobile.ui.v2.library.SavedView
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import java.time.ZonedDateTime
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Library rows from the server's items: the time, the status, the saved views and the search. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], application = Application::class)
class LibraryModelTest {
    private val english = V2Testing.text(EchoLanguage.English)
    private val urdu = V2Testing.text(EchoLanguage.Urdu)

    private val now = ZonedDateTime.parse("2026-11-17T12:00:00+05:00[Asia/Karachi]")
    private val items = V2Fixtures.library()

    private fun rows(views: Set<SavedView> = emptySet(), query: String = "", locale: Locale = Locale.UK, text: V2Text = english) =
        LibraryModel.rows(items, views, query, now, text, locale)

    @Test
    fun timesReadLikeWhatsAppInTheLocalZone() {
        val byId = rows().associateBy { it.id }
        assertEquals("10:42", byId.getValue("01JA").time)
        assertEquals("Yesterday", byId.getValue("01JD").time)
        assertEquals("Sat", byId.getValue("01JF").time)
        // Before the week, the date as classic and desktop write it.
        assertEquals("Nov 2", byId.getValue("01JG").time)
    }

    @Test
    fun urduTimesKeepLatinDigits() {
        val ur = Locale.forLanguageTag("ur-PK")
        val byId = rows(locale = ur, text = urdu).associateBy { it.id }
        assertEquals("10:42", byId.getValue("01JA").time)
        assertEquals("کل", byId.getValue("01JD").time)
        assertTrue(byId.values.all { r -> (r.time + r.duration).none { it in '٠'..'٩' || it in '۰'..'۹' } })
    }

    @Test
    fun statusDurationAndTitle() {
        val byId = rows().associateBy { it.id }
        assertEquals(RowStatus.Done, byId.getValue("01JA").status)
        assertEquals(RowStatus.Untranscribed, byId.getValue("01JB").status)
        assertEquals(RowStatus.Transcribing, byId.getValue("01JC").status)
        assertEquals(RowStatus.Failed, byId.getValue("01JE").status)
        assertEquals("12:08", byId.getValue("01JA").duration)
        assertEquals("1:06:40", byId.getValue("01JG").duration)
        // No title: the source names it; no preview: the project, else the source.
        assertEquals("Voice note", byId.getValue("01JF").title)
        assertEquals("Qureshi Builders", byId.getValue("01JG").meta)
        assertEquals("Phone call", byId.getValue("01JB").meta)
    }

    @Test
    fun savedViewsCombineAndTheNewestComesFirst() {
        assertEquals(listOf("01JA", "01JB", "01JC"), rows(setOf(SavedView.Today)).map { it.id })
        assertEquals(listOf("01JB", "01JE"), rows(setOf(SavedView.Untranscribed)).map { it.id })
        assertEquals(listOf("01JB"), rows(setOf(SavedView.Today, SavedView.Untranscribed)).map { it.id })
        assertEquals(items.size, rows().size)
    }

    @Test
    fun searchLooksAtTitleMetaAndProject() {
        assertEquals(listOf("01JA"), rows(query = "basmati").map { it.id })
        assertEquals(listOf("01JG"), rows(query = "qureshi").map { it.id })
        assertEquals(listOf("01JD"), rows(query = "PO-2291").map { it.id })
    }

    @Test
    fun deletedItemsNeverShow() {
        val withDeleted = items + items.first().copy(id = "01JZ", deleted = true)
        assertEquals(items.size, LibraryModel.rows(withDeleted, emptySet(), "", now, english, Locale.UK).size)
    }

    @Test
    fun sourcesAreNamedFromTheirKeyNotTheServersEnglish() {
        val item = items.first().copy(sourceAppLabel = "WhatsApp")
        assertEquals("WhatsApp", LibraryModel.row(item, now, urdu, Locale.forLanguageTag("ur-PK")).source)
        assertEquals("WhatsApp", LibraryModel.row(item, now, english, Locale.UK).source)
    }

    @Test
    fun storageSizes() {
        assertEquals("340 MB", LibraryModel.size(340_000_000))
        assertEquals("1.2 GB", LibraryModel.size(1_240_000_000))
    }
}
