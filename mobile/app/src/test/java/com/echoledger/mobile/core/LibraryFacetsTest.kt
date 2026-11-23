package com.echoledger.mobile.core

import com.echoledger.mobile.data.ItemFacetsDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The rules of contract 2.0.0-p the shared fixture doesn't spell out case by case: the Library's tabs (Calls, Meetings,
 * Voice notes by Source, Imported and Other under All only, ANDed with the place, the filters and the search), how a
 * search is read, the Urdu folding letter by letter, a filter set read forgivingly, and Needs you's own part on a phone.
 */
class LibraryFacetsTest {
    private val zone = ZoneId.of("Asia/Karachi")
    private val now = Instant.parse("2026-11-23T10:00:00Z")
    private val today = LocalDate.of(2026, 11, 23)

    private fun item(
        id: String,
        source: String,
        title: String = "Rec $id",
        labels: List<String> = emptyList(),
        opened: Boolean = true,
        status: String = "transcribed",
        startedAt: String = "2026-11-23T05:00:00Z",
        clientRef: String? = null
    ) = LibraryItem(
        id = id,
        clientRef = clientRef,
        title = title,
        startedAt = startedAt,
        status = "completed",
        sourceApp = source,
        sourceDevice = "phone",
        labels = labels,
        opened = opened,
        transcript = TranscriptSummary(finalStatus = "completed"),
        updatedAt = startedAt,
        facets = ItemFacetsDto(source, status, "ur")
    )

    @Test
    fun theTabsSplitTheListBySourceAsTheContractSays() {
        val calls = listOf("whatsapp", "whatsapp_business", "phone_call", "telegram", "signal", "messenger", "instagram", "skype")
        val meetings = listOf("zoom", "google_meet", "microsoft_teams", "slack", "discord")
        for (s in LibraryFacets.SOURCES) {
            assertEquals(s, s in calls, LibraryKind.CALLS.holds(s))
            assertEquals(s, s in meetings, LibraryKind.MEETINGS.holds(s))
            assertEquals(s, s == "voice_note", LibraryKind.VOICE_NOTES.holds(s))
            assertTrue(s, LibraryKind.ALL.holds(s))
        }
        // Imported and Other are under All only.
        listOf("imported", "other").forEach { s -> assertEquals(listOf(LibraryKind.ALL), LibraryKind.entries.filter { it.holds(s) }) }
        assertEquals(listOf("all", "calls", "meetings", "voice_notes"), LibraryKind.entries.map { it.wire })
    }

    @Test
    fun aTabIsAndedWithThePlaceTheFiltersAndTheSearchAndCountedWithThem() {
        val items = listOf(
            item("01", "whatsapp", title = "Acme call", labels = listOf("Clients"), opened = false),
            item("02", "zoom", title = "Acme sync", labels = listOf("Clients"), opened = false),
            item("03", "voice_note", title = "Acme memo", labels = listOf("Clients")),
            item("04", "skype", title = "Acme review", labels = listOf("Clients"), opened = false),
            item("05", "imported", title = "Acme file", labels = listOf("Clients"), opened = false),
            item("06", "whatsapp", title = "Family", labels = listOf("Clients"), opened = false)
        )
        val facts = LibraryFacets.prepare(items, emptyList(), zone, now)
        val searched = facts.filter { it.matches(LibraryFacets.searchWords("acme")) }
        val placed = searched.filter { LibraryFacets.inPlace(it, LibraryPlace.UNREAD) }
        val filters = LibraryFilterSet().toggle(FilterSection.LABELS, "clients")
        val kept = LibraryFacets.filter(placed, filters, today)
        val counts = LibraryKind.entries.associateWith { k -> kept.count { k.holds(it.source) } }
        // Unread, Acme, Clients: 01 (WhatsApp), 02 (Zoom), 04 (Skype), 05 (Imported); 06 isn't Acme, 03 is opened.
        assertEquals(mapOf(LibraryKind.ALL to 4, LibraryKind.CALLS to 2, LibraryKind.MEETINGS to 1, LibraryKind.VOICE_NOTES to 0), counts)
        // A Source filter still applies inside a tab.
        val zoomOnly = LibraryFacets.filter(placed, filters.toggle(FilterSection.SOURCE, "zoom"), today)
        assertEquals(listOf("02"), zoomOnly.filter { LibraryKind.MEETINGS.holds(it.source) }.map { it.item.id })
        assertEquals(emptyList<String>(), zoomOnly.filter { LibraryKind.CALLS.holds(it.source) }.map { it.item.id })
    }

    @Test
    fun isNotKeepsTheRecordingsWithNoneOfTheValuesUnlabelledOnesToo() {
        val items = listOf(
            item("01", "whatsapp", labels = listOf("Clients")),
            item("02", "zoom"),
            item("03", "zoom", labels = listOf("Home"))
        )
        val facts = LibraryFacets.prepare(items, emptyList(), zone, now)
        val not = LibraryFilterSet().toggle(FilterSection.LABELS, "clients").not(FilterSection.LABELS, true)
        assertEquals(listOf("02", "03"), LibraryFacets.filter(facts, not, today).map { it.item.id })
        // Sections AND, values OR.
        val both = LibraryFilterSet().toggle(FilterSection.SOURCE, "zoom")
            .toggle(FilterSection.LABELS, "home")
            .toggle(FilterSection.LABELS, "clients")
        assertEquals(listOf("03"), LibraryFacets.filter(facts, both, today).map { it.item.id })
    }

    @Test
    fun aSearchIsReadAsTheServerReadsIt() {
        // Under 2 characters, or no letter or digit: no search.
        assertEquals(emptyList<String>(), LibraryFacets.searchWords("a"))
        assertEquals(emptyList<String>(), LibraryFacets.searchWords("  "))
        assertEquals(emptyList<String>(), LibraryFacets.searchWords("--"))
        // Quoted phrases stay one word; the marks round a word go; each word once, capitals ignored.
        assertEquals(listOf("march invoice", "acme"), LibraryFacets.searchWords("\"March invoice\" +acme ACME"))
        // At most 8 words of up to 64 characters.
        assertEquals(8, LibraryFacets.searchWords((1..12).joinToString(" ") { "w$it" }).size)
        assertEquals(64, LibraryFacets.searchWords("x".repeat(100)).single().length)
        // Folded: Arabic Kaf and Yeh are the Urdu letters.
        assertEquals(listOf("کراچی"), LibraryFacets.searchWords("كراچي"))
    }

    @Test
    fun urduSpellingsFoldLetterByLetter() {
        val pairs = mapOf(
            "ي" to "ی", "ى" to "ی", "ئ" to "ی", "ك" to "ک",
            "ه" to "ہ", "ة" to "ہ", "ۀ" to "ہ", "ۃ" to "ہ",
            "أ" to "ا", "إ" to "ا", "آ" to "ا", "ٱ" to "ا",
            "ؤ" to "و", "ۓ" to "ے", "٣" to "3", "۷" to "7", "Σ" to "σ"
        )
        pairs.forEach { (from, to) -> assertEquals(from, to, UrduFold.fold(from)) }
        // Harakat, superscript Alef, tatweel, zero-width joiners and direction marks go.
        assertEquals("کتاب", UrduFold.fold("کِتـاب‌ٰ‏"))
        // Latin and Devanagari stay (in small letters).
        assertEquals("invoice नमस्ते", UrduFold.fold("INVOICE नमस्ते"))
    }

    @Test
    fun aFilterSetIsReadForgivinglyAndWrittenInTheContractsShape() {
        val read = LibraryFilterSet.read(
            Json.parseToJsonElement(
                """{"source":{"op":"is","values":["zoom","fax"]},"colour":{"values":["red"]},"labels":{"values":[]},
                   "date":{"op":"is_not","values":["custom"]},"marked":{"op":"is_not","values":["pinned"]}}"""
            )
        )
        // An unknown option and section are left out; an empty one goes; Custom with no day is no filter.
        assertEquals(listOf(FilterSection.SOURCE, FilterSection.MARKED), read.sections)
        assertEquals(listOf("zoom"), read[FilterSection.SOURCE]?.values)
        assertTrue(read[FilterSection.MARKED]!!.not)
        assertEquals(
            """{"source":{"op":"is","values":["zoom"]},"marked":{"op":"is_not","values":["pinned"]}}""",
            read.toJson().toString()
        )
        // Labels and tags are kept as keys, in lower case.
        val labels = LibraryFilterSet.read(Json.parseToJsonElement("""{"labels":{"values":["Clients"]}}"""))
        assertEquals(listOf("clients"), labels[FilterSection.LABELS]?.values)
        // Custom keeps its days.
        val custom = LibraryFilterSet().date(LibraryFacets.CUSTOM, "2026-11-10", null)
        assertEquals("""{"date":{"op":"is","values":["custom"],"from":"2026-11-10"}}""", custom.toJson().toString())
        assertTrue(LibraryFilterSet().date(LibraryFacets.CUSTOM).isEmpty)
    }

    @Test
    fun needsYouAddsThePhonesOwnOnceEach() {
        val facts = LibraryFacets.prepare(
            listOf(item("01", "whatsapp", status = "failed", clientRef = "ref-1"), item("02", "zoom", clientRef = "ref-2")),
            emptyList(),
            zone,
            now
        )
        // Its own: a call waiting here (ref-9), and an upload that failed here whose recording the server has (ref-1).
        assertEquals(2, LibraryFacets.needsYouCount(facts, listOf("ref-9", "ref-1")))
    }

    @Test
    fun projectsAreOfferedAsATreeAndAProjectKeepsItsSubfolders() {
        val projects = listOf(
            ProjectNode("b", "Zed"),
            ProjectNode("a", "acme"),
            ProjectNode("c", "Invoices", "a"),
            ProjectNode("d", "Old", "c")
        )
        assertEquals(listOf(LibraryFacets.NO_PROJECT, "a", "c", "d", "b"), LibraryFacets.projectOrder(projects))
        assertEquals(mapOf("a" to 0, "b" to 0, "c" to 1, "d" to 2), LibraryFacets.depth(projects))
        val inSub = item("01", "whatsapp").copy(client = NamedRef("d", "Old"))
        val facts = LibraryFacets.prepare(listOf(inSub), projects, zone, now)
        assertEquals(listOf("d", "c", "a"), facts.single().values.getValue(FilterSection.PROJECT))
        assertTrue(facts.single().matches(LibraryFacets.searchWords("acme old")))
    }
}
