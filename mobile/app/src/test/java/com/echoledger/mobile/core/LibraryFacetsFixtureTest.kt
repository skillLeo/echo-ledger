package com.echoledger.mobile.core

import com.echoledger.mobile.data.ItemFacetsDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import java.io.File
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The shared cases of the Library's filters (contract 2.0.0-p): web/tests/Fixtures/library-facets.json, which the server,
 * the desktop and the phone all read and must agree on. Every recording is turned into the library item this phone
 * syncs (with the facets the server sends), and the Library views' counts, Needs you (each recording for the first
 * reason that holds), and for every filter set the list (in its order) and every option's count in the panel's order
 * must be the file's.
 */
class LibraryFacetsFixtureTest {
    private val fixture: JsonObject = Json.parseToJsonElement(fixtureFile().readText()).jsonObject
    private val now: Instant = OffsetDateTime.parse(fixture.str("now")).toInstant()
    private val zone: ZoneId = ZoneId.of(fixture.str("tz"))
    private val today = now.atZone(zone).toLocalDate()
    private val projects = fixture.getValue("projects").jsonArray.map {
        val p = it.jsonObject
        ProjectNode(p.str("id"), p.str("name"), p.strOrNull("parent_id"))
    }
    private val lists = fixture.getValue("lists").jsonArray.map { it.jsonObject }
        .sortedBy { it.getValue("position").jsonPrimitive.intOrNull ?: 0 }.map { it.str("name") }
    private val recordings = fixture.getValue("recordings").jsonArray.map { it.jsonObject }
    private val items = recordings.map { item(it, withFacets = true) }
    private val facts = LibraryFacets.prepare(items, projects, zone, now)

    private fun fixtureFile(): File {
        var dir: File? = File(System.getProperty("user.dir")).absoluteFile
        while (dir != null) {
            val f = File(dir, "web/tests/Fixtures/library-facets.json")
            if (f.isFile) return f
            dir = dir.parentFile
        }
        error("web/tests/Fixtures/library-facets.json not found above ${System.getProperty("user.dir")}")
    }

    /** A recording as this phone holds it: a library item from the sync, with the server's facets ([withFacets]). */
    private fun item(r: JsonObject, withFacets: Boolean): LibraryItem {
        val clientId = r.strOrNull("client_id")
        val bookmarks = r.getValue("bookmarks").jsonArray.map { (it as? JsonPrimitive)?.contentOrNull }
        val facets = r.getValue("facets").jsonObject
        return LibraryItem(
            id = r.str("id"),
            deleted = r.bool("deleted"),
            title = r.strOrNull("title"),
            startedAt = r.str("started_at"),
            status = r.str("status"),
            sourceApp = r.strOrNull("source_app"),
            sourceDevice = r.strOrNull("source_device"),
            client = clientId?.let { id -> NamedRef(id, projects.first { it.id == id }.name) },
            language = r.strOrNull("language"),
            labels = r.getValue("labels").jsonArray.map { it.jsonPrimitive.content },
            pinned = r.bool("pinned"),
            important = r.bool("important"),
            keep = r.bool("keep"),
            opened = r.bool("opened"),
            markerCount = bookmarks.size,
            tags = bookmarks.filterNotNull().distinct(),
            transcript = TranscriptSummary(
                finalStatus = r.strOrNull("final_status"),
                preview = r.strOrNull("text"),
                finalLanguage = r.strOrNull("final_language")
            ),
            updatedAt = r.str("started_at"),
            endedAt = r.strOrNull("ended_at"),
            facets = if (withFacets) ItemFacetsDto(facets.str("source"), facets.str("status"), facets.str("language")) else null,
            processingSince = r.strOrNull("processing_since")
        )
    }

    @Test
    fun theFileHasTheContractsCases() {
        assertTrue(recordings.size > 40)
        assertTrue(fixture.getValue("filter_sets").jsonArray.size >= 28)
    }

    @Test
    fun theLibraryViewsCountAsTheServerDoes() {
        val counts = LibraryFacets.placeCounts(facts)
        val expected = fixture.getValue("library_counts").jsonObject
        assertEquals(
            LibraryPlace.entries.associate { it.wire to expected.getValue(it.wire).jsonPrimitive.intOrNull },
            counts.mapKeys { it.key.wire }
        )
        // The phone's own Needs you adds nothing the server has (none of its own here).
        assertEquals(expected.getValue("needs_you").jsonPrimitive.intOrNull, LibraryFacets.needsYouCount(facts, emptyList()))
    }

    @Test
    fun needsYouHoldsEachRecordingOnceForTheFirstReason() {
        val expected = fixture.getValue("needs_you").jsonObject
        val reasons = facts.mapNotNull { f -> f.needs?.let { f.item.id to it } }.toMap()
        assertEquals(expected.getValue("ids").jsonObject.mapValues { it.value.jsonPrimitive.content }, reasons)
        for (reason in listOf("failed", "waiting", "stuck")) {
            assertEquals(reason, expected.getValue(reason).jsonPrimitive.intOrNull, reasons.values.count { it == reason })
        }
        assertEquals(expected.getValue("count").jsonPrimitive.intOrNull, reasons.size)
    }

    @Test
    fun everyFilterSetKeepsTheFilesListAndCountsEveryOptionInThePanelsOrder() {
        for (set in fixture.getValue("filter_sets").jsonArray.map { it.jsonObject }) {
            val name = set.str("name")
            val filters = LibraryFilterSet.read(set.getValue("filters"))
            val words = LibraryFacets.searchWords(set.strOrNull("search"))
            if (set.strOrNull("search") != null) assertTrue("$name: a search", words.isNotEmpty())
            val counted = facts.filter { it.matches(words) && LibraryFacets.inPlace(it, LibraryPlace.ALL) }
            val shown = LibraryFacets.filter(counted, filters, today)
                .sortedWith(compareByDescending<LibraryFacts> { it.item.startedAt }.thenByDescending { it.item.id })
                .map { it.item.id }
            val expect = set.getValue("expect").jsonObject
            assertEquals("$name: total", expect.getValue("total").jsonPrimitive.intOrNull, shown.size)
            assertEquals("$name: ids", expect.getValue("ids").jsonArray.map { it.jsonPrimitive.content }, shown)
            // The kind tabs (contract 2.0.0-q): each recording in exactly one, so they add up to the list; the file's own
            // numbers once it has them (expect.kinds, beside expect.facets).
            val kept = LibraryFacets.filter(counted, filters, today)
            val kinds = LibraryKind.KINDS.associate { k -> k.kind to kept.count { k.holds(it.kind) } }
            assertEquals("$name: the kinds add up to All", shown.size, kinds.values.sum())
            expect["kinds"]?.jsonObject?.let { want ->
                assertEquals("$name: kinds", want.mapValues { it.value.jsonPrimitive.intOrNull }, kinds)
            }
            val counts = LibraryFacets.facets(counted, filters, facts, today, projects, lists)
            val sections = expect.getValue("facets").jsonObject
            for (section in FilterSection.entries) {
                val want = sections.getValue(section.wire).jsonObject.map { (k, v) -> k to v.jsonPrimitive.intOrNull }
                val got = counts.getValue(section).map { it.value to it.count }
                assertEquals("$name: ${section.wire}", want, got)
            }
        }
    }

    @Test
    fun aFilterSetReadsBackAsTheContractWritesIt() {
        for (set in fixture.getValue("filter_sets").jsonArray.map { it.jsonObject }) {
            val read = LibraryFilterSet.read(set.getValue("filters"))
            assertEquals(set.str("name"), read, LibraryFilterSet.read(read.toJson()))
            assertEquals(set.str("name"), set.getValue("filters").jsonObject.keys, read.toJson().keys)
        }
    }

    /**
     * An item cached before 2.0.0-p has no facets: its Source, Status and Language are worked out the same way (all but
     * what only the server knows: a file that came in as a file, an upload that failed).
     */
    @Test
    fun withoutTheServersFacetsAnItemIsWorkedOutTheSameWay() {
        for (r in recordings) {
            if (r.strOrNull("origin") != null || r.bool("upload_failed")) continue
            val bare = item(r, withFacets = false)
            val facets = r.getValue("facets").jsonObject
            assertEquals(r.str("id"), facets.str("source"), LibraryFacets.source(bare))
            assertEquals(r.str("id"), facets.str("status"), LibraryFacets.status(bare))
            assertEquals(r.str("id"), facets.str("language"), LibraryFacets.language(bare))
        }
    }

    private fun JsonObject.str(key: String): String = getValue(key).jsonPrimitive.content
    private fun JsonObject.strOrNull(key: String): String? = this[key]?.takeIf { it !is JsonNull }?.jsonPrimitive?.contentOrNull
    private fun JsonObject.bool(key: String): Boolean = (this[key] as? JsonPrimitive)?.booleanOrNull ?: false
}
