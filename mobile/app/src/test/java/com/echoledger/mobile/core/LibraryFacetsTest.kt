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
 * The rules of contract 2.0.0-p the shared fixture doesn't spell out case by case: the Library's tabs (since 2.0.0-q one
 * kind per recording, Calls, Meetings, Voice notes and Other, the server's or by Source, so they add up to All; ANDed
 * with the place, the filters and the search), how a
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

    /** The tabs a recording of [source] (with the server's [kind], when given) is in. */
    private fun tabsOf(source: String?, kind: String? = null): List<LibraryKind> =
        LibraryKind.entries.filter { it.holds(LibraryKind.of(kind, source)) }

    @Test
    fun everyRecordingHasExactlyOneKindByItsSourceAsTheContractSays() {
        val calls = listOf("whatsapp", "whatsapp_business", "phone_call", "telegram", "signal", "messenger", "instagram", "skype")
        val meetings = listOf("zoom", "google_meet", "microsoft_teams", "slack", "discord")
        for (s in LibraryFacets.SOURCES) {
            val want = when (s) {
                in calls -> LibraryKind.CALLS
                in meetings -> LibraryKind.MEETINGS
                "voice_note" -> LibraryKind.VOICE_NOTES
                else -> LibraryKind.OTHER
            }
            assertEquals(s, listOf(LibraryKind.ALL, want), tabsOf(s))
        }
        // Since 2.0.0-q imported files, `other` and a Source the phone does not know are Other (no longer All only).
        listOf("imported", "other", "a_new_app", null).forEach { s ->
            assertEquals("$s", listOf(LibraryKind.ALL, LibraryKind.OTHER), tabsOf(s))
        }
        assertEquals(listOf("all", "calls", "meetings", "voice_notes", "other"), LibraryKind.entries.map { it.wire })
    }

    @Test
    fun theServersKindWinsAndAnUnknownOneIsWorkedOutFromTheSource() {
        // An imported WhatsApp file: the server says other, whatever app it names.
        assertEquals(listOf(LibraryKind.ALL, LibraryKind.OTHER), tabsOf("whatsapp", kind = "other"))
        assertEquals(listOf(LibraryKind.ALL, LibraryKind.MEETINGS), tabsOf("other", kind = "meeting"))
        // A kind this phone does not know, or none (cached before 2.0.0-q): from the Source.
        assertEquals(listOf(LibraryKind.ALL, LibraryKind.CALLS), tabsOf("telegram", kind = "webinar"))
        assertEquals(listOf(LibraryKind.ALL, LibraryKind.VOICE_NOTES), tabsOf("voice_note", kind = null))
    }

    /**
     * Review of I-07: a chosen Other goes back to All only once it has nothing and either had something on the page or
     * the library has synced; a first frame with the library not read yet (back from the background) keeps it.
     */
    @Test
    fun aChosenOtherSurvivesAFirstFrameBeforeTheLibraryIsRead() {
        // The first frame after the page is made again: nothing read yet, nothing had, not synced.
        assertTrue(!LibraryKind.backToAll(LibraryKind.OTHER, otherCount = 0, otherHad = false, synced = false))
        // Other emptied by a filter or a search while it was on screen, or a synced library with none: All.
        assertTrue(LibraryKind.backToAll(LibraryKind.OTHER, otherCount = 0, otherHad = true, synced = false))
        assertTrue(LibraryKind.backToAll(LibraryKind.OTHER, otherCount = 0, otherHad = false, synced = true))
        // Something in it, or another tab chosen: nothing changes.
        assertTrue(!LibraryKind.backToAll(LibraryKind.OTHER, otherCount = 2, otherHad = true, synced = true))
        assertTrue(!LibraryKind.backToAll(LibraryKind.CALLS, otherCount = 0, otherHad = true, synced = true))
    }

    @Test
    fun otherIsATabOnlyWhileItHoldsSomething() {
        assertEquals(
            listOf(LibraryKind.ALL, LibraryKind.CALLS, LibraryKind.MEETINGS, LibraryKind.VOICE_NOTES),
            LibraryKind.shown(mapOf(LibraryKind.ALL to 3, LibraryKind.CALLS to 3, LibraryKind.OTHER to 0))
        )
        assertEquals(LibraryKind.entries, LibraryKind.shown(mapOf(LibraryKind.OTHER to 1)))
    }

    @Test
    fun theKindTabsAlwaysAddUpToAllWithEveryFilterAndSearch() {
        val sources = LibraryFacets.SOURCES + listOf("imported", "other", "a_new_app")
        val items = sources.flatMapIndexed { i, s ->
            listOf(
                item(
                    "%02d".format(i * 2),
                    s,
                    title = "Acme $s",
                    labels = if (i % 2 ==
                        0
                    ) {
                        listOf("Clients")
                    } else {
                        emptyList()
                    },
                    opened = i % 3 == 0
                ),
                item("%02d".format(i * 2 + 1), s, title = "Family $s", opened = i % 2 == 0)
            )
        }
        val facts = LibraryFacets.prepare(items, emptyList(), zone, now)
        val filterSets = listOf(
            LibraryFilterSet(),
            LibraryFilterSet().toggle(FilterSection.LABELS, "clients"),
            LibraryFilterSet().toggle(FilterSection.SOURCE, "zoom").toggle(FilterSection.SOURCE, "imported"),
            LibraryFilterSet().toggle(FilterSection.SOURCE, "whatsapp").not(FilterSection.SOURCE, true)
        )
        for (place in LibraryPlace.entries) {
            for (search in listOf(null, "acme", "family zoom")) {
                for (filters in filterSets) {
                    val placed = facts.filter { it.matches(LibraryFacets.searchWords(search)) && LibraryFacets.inPlace(it, place) }
                    val kept = LibraryFacets.filter(placed, filters, today)
                    val counts = LibraryKind.KINDS.sumOf { k -> kept.count { k.holds(it.kind) } }
                    assertEquals("$place, $search, $filters", kept.size, counts)
                    kept.forEach { f -> assertEquals(f.item.id, 1, LibraryKind.KINDS.count { it.holds(f.kind) }) }
                }
            }
        }
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
        val counts = LibraryKind.entries.associateWith { k -> kept.count { k.holds(it.kind) } }
        // Unread, Acme, Clients: 01 (WhatsApp), 02 (Zoom), 04 (Skype), 05 (Imported); 06 isn't Acme, 03 is opened.
        assertEquals(
            mapOf(
                LibraryKind.ALL to 4,
                LibraryKind.CALLS to 2,
                LibraryKind.MEETINGS to 1,
                LibraryKind.VOICE_NOTES to 0,
                LibraryKind.OTHER to 1
            ),
            counts
        )
        // A Source filter still applies inside a tab.
        val zoomOnly = LibraryFacets.filter(placed, filters.toggle(FilterSection.SOURCE, "zoom"), today)
        assertEquals(listOf("02"), zoomOnly.filter { LibraryKind.MEETINGS.holds(it.kind) }.map { it.item.id })
        assertEquals(emptyList<String>(), zoomOnly.filter { LibraryKind.CALLS.holds(it.kind) }.map { it.item.id })
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
        // Its own: a call waiting here (ref-9), an upload that failed here whose recording the server has (ref-1), and a
        // call waiting here whose open session the server lists (its server id 02).
        assertEquals(2, LibraryFacets.needsYouCount(facts, listOf(setOf("r-9", "ref-9"), setOf("r-1", "ref-1"), setOf("r-2", "02"))))
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

    /** Contract 2.0.0-z: a call is waiting only once it is over (ended_at) or started more than 4 hours ago. */
    @Test
    fun aCallIsWaitingOnlyOnceItIsOverNeverWhileItIsBeingRecorded() {
        fun ago(seconds: Long) = now.minusSeconds(seconds).toString()
        fun call(startedAt: String, endedAt: String? = null, state: String? = null) = LibraryItem(
            id = "live",
            title = "Call",
            startedAt = startedAt,
            status = "recording",
            sourceApp = "zoom",
            updatedAt = startedAt,
            endedAt = endedAt,
            facets = ItemFacetsDto("zoom", "processing", "unknown", "meeting", state)
        )
        // A call still being recorded (no end, started 3 minutes ago) needs nothing yet, and Needs you doesn't count it.
        val live = call(ago(3 * 60))
        assertEquals(null, LibraryFacets.needsReason(live, now))
        val facts = LibraryFacets.prepare(listOf(live), emptyList(), zone, now)
        assertEquals(false, facts.single().waiting)
        assertEquals(0, LibraryFacets.needsYouCount(facts, emptyList()))
        // Once its end is sent, it is.
        assertEquals("waiting", LibraryFacets.needsReason(call(ago(3 * 60), endedAt = ago(60)), now))
        // Without an end: exactly 4 hours is not yet, 4 hours and a second is.
        assertEquals(null, LibraryFacets.needsReason(call(ago(4 * 3600)), now))
        assertEquals("waiting", LibraryFacets.needsReason(call(ago(4 * 3600 + 1)), now))
        // The server's "waiting" counts at once; a state it sent earlier never stops the clock (the sync keeps a live
        // call's "waiting_to_upload" past 4 hours).
        assertEquals("waiting", LibraryFacets.needsReason(call(ago(60), state = "waiting"), now))
        assertEquals(null, LibraryFacets.needsReason(call(ago(60), state = "waiting_to_upload"), now))
        assertEquals("waiting", LibraryFacets.needsReason(call(ago(4 * 3600 + 1), state = "waiting_to_upload"), now))
        // Classic's Waiting view agrees.
        val at = now.atZone(zone)
        assertEquals(false, Organise.inView(live, SmartView.WAITING, at))
        assertEquals(true, Organise.inView(call(ago(3 * 60), endedAt = ago(60)), SmartView.WAITING, at))
    }
}
