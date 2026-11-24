package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.util.Locale
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.put

/** The sections of the Library's filters, in the panel's order (contract 2.0.0-p, docs/API.md "Library filters"). */
enum class FilterSection(val wire: String) {
    DATE("date"),
    SOURCE("source"),
    DEVICE("device"),
    PROJECT("project"),
    LANGUAGE("language"),
    STATUS("status"),
    LABELS("labels"),
    MARKED("marked"),
    BOOKMARKS("bookmarks");

    companion object {
        fun of(wire: String?): FilterSection? = entries.firstOrNull { it.wire == wire }
    }
}

/**
 * One section's choice, as the contract writes it: a recording that has any of [values] ("is"), or none of them ("is
 * not"). Date takes one value; its `custom` has its days in [from] and [to] (YYYY-MM-DD, both kept, either end open).
 */
data class FilterClause(val values: List<String>, val not: Boolean = false, val from: String? = null, val to: String? = null) {
    /** A value is chosen (Custom with neither day is none). */
    val on: Boolean get() = values.isNotEmpty() && (values.first() != LibraryFacets.CUSTOM || from != null || to != null)
}

/**
 * A filter set: one clause per section in use. Sections combine with AND, the values of one section with OR. Kept in the
 * contract's own shape, so a saved view goes to the server as it is.
 */
data class LibraryFilterSet(val clauses: Map<FilterSection, FilterClause> = emptyMap()) {
    /** The sections that are on, in the panel's order: the pills, and the n of [Filters n]. */
    val sections: List<FilterSection> get() = FilterSection.entries.filter { clauses[it]?.on == true }
    val count: Int get() = sections.size
    val isEmpty: Boolean get() = sections.isEmpty()

    operator fun get(section: FilterSection): FilterClause? = clauses[section]?.takeIf { it.on }

    /** Ticks [value] on or off in [section], keeping its "is not". */
    fun toggle(section: FilterSection, value: String): LibraryFilterSet {
        val now = clauses[section]
        val values = now?.values.orEmpty()
        val next = if (value in values) values - value else values + value
        return with(section, FilterClause(next, not = now?.not ?: false))
    }

    /** Date's one choice (null: Any time), with Custom's days. */
    fun date(preset: String?, from: String? = null, to: String? = null): LibraryFilterSet {
        if (preset == null) return clear(FilterSection.DATE)
        val not = clauses[FilterSection.DATE]?.not ?: false
        return with(
            FilterSection.DATE,
            if (preset == LibraryFacets.CUSTOM) FilterClause(listOf(preset), not, from, to) else FilterClause(listOf(preset), not)
        )
    }

    /** "is" or "is not" for [section]. */
    fun not(section: FilterSection, not: Boolean): LibraryFilterSet =
        with(section, (clauses[section] ?: FilterClause(emptyList())).copy(not = not))

    /** [section] cleared (its Clear, its pill's ×). */
    fun clear(section: FilterSection): LibraryFilterSet = LibraryFilterSet(clauses - section)

    private fun with(section: FilterSection, clause: FilterClause): LibraryFilterSet = LibraryFilterSet(clauses + (section to clause))

    /** Without empty sections, each value once: what a saved view keeps and two filter sets are compared by. */
    fun clean(): LibraryFilterSet = LibraryFilterSet(
        sections.associateWith { s -> clauses.getValue(s).let { it.copy(values = it.values.distinct()) } }
    )

    /** The contract's JSON: `{"source": {"op": "is", "values": ["whatsapp"]}, …}`. */
    fun toJson(): JsonObject = buildJsonObject {
        for (s in sections) {
            val c = clauses.getValue(s)
            put(
                s.wire,
                buildJsonObject {
                    put("op", if (c.not) "is_not" else "is")
                    put("values", JsonArray(c.values.distinct().map { JsonPrimitive(it) }))
                    c.from?.let { put("from", it) }
                    c.to?.let { put("to", it) }
                }
            )
        }
    }

    companion object {
        /**
         * A filter set read from elsewhere (a saved view, the server's answer, this phone's copy), forgivingly: a
         * section or an option this app doesn't know is left out, and a section left with no value goes.
         */
        fun read(json: JsonElement?): LibraryFilterSet {
            val obj = json as? JsonObject ?: return LibraryFilterSet()
            val out = LinkedHashMap<FilterSection, FilterClause>()
            for (s in FilterSection.entries) {
                val c = obj[s.wire] as? JsonObject ?: continue
                val values = (c["values"] as? JsonArray).orEmpty()
                    .mapNotNull { (it as? JsonPrimitive)?.takeIf { p -> p.isString }?.content }
                    .mapNotNull { LibraryFacets.option(s, it) }
                    .distinct()
                if (values.isEmpty()) continue
                val day = { key: String -> (c[key] as? JsonPrimitive)?.contentOrNull?.takeIf { LibraryFacets.isDay(it) } }
                val clause = if (s == FilterSection.DATE) {
                    FilterClause(values.take(1), c.op(), day("from"), day("to")).takeIf { it.on }
                } else {
                    FilterClause(values.take(LibraryFacets.MAX_VALUES), c.op())
                }
                clause?.let { out[s] = it }
            }
            return LibraryFilterSet(out)
        }

        private fun JsonObject.op(): Boolean = (this["op"] as? JsonPrimitive)?.contentOrNull == "is_not"
    }
}

/** A project as the filters need it: the folder above it ("Project is Acme" keeps Acme's subfolders too). */
data class ProjectNode(val id: String, val name: String, val parentId: String? = null)

/** One option of a section and how many recordings it would keep (the facet rule). */
data class FacetOption(val value: String, val count: Int)

/** The sidebar's Library views (contract 2.0.0-p): the title menu's Views on this phone. */
enum class LibraryPlace(val wire: String) {
    ALL("all"),
    NEEDS_YOU("needs_you"),
    UNREAD("unread"),
    IMPORTANT("important"),
    NOT_TRANSCRIBED("not_transcribed"),
    AUDIO_ONLY("audio_only")
}

/**
 * The Library's tabs, chips under the search on the phone (docs/API.md "The Library's kinds", contract 2.0.0-q): All ·
 * Calls · Meetings · Voice notes · Other. Every recording has exactly one kind ([of]: the server's `facets.kind`, else
 * worked out from its Source with the contract's table, an unknown Source being Other), so the kind tabs always add up
 * to All. A tab narrows the place, the filters and the search, and each one's count is how many those three keep in it.
 * Other shows only while its count is above 0 ([shown]).
 */
enum class LibraryKind(val wire: String, val kind: String?, val sources: Set<String>?) {
    ALL("all", null, null),
    CALLS("calls", "call", setOf("whatsapp", "whatsapp_business", "phone_call", "telegram", "signal", "messenger", "instagram", "skype")),
    MEETINGS("meetings", "meeting", setOf("zoom", "google_meet", "microsoft_teams", "slack", "discord")),
    VOICE_NOTES("voice_notes", "voice_note", setOf("voice_note")),

    /** Imported files, `other`, and any Source the phone does not know (one the server adds later). */
    OTHER("other", "other", null);

    /** Whether a recording of kind [of] is in this tab (All holds every one). */
    fun holds(of: LibraryKind): Boolean = this == ALL || this == of

    companion object {
        /** The kind tabs, each recording in exactly one of them. */
        val KINDS = listOf(CALLS, MEETINGS, VOICE_NOTES, OTHER)

        /** A recording's one kind: the server's [kind] when the phone knows it, else from its [source]. */
        fun of(kind: String?, source: String?): LibraryKind = KINDS.firstOrNull { it.kind == kind } ?: fromSource(source)

        /** The contract's table: a Source in no list (or none) is Other. */
        fun fromSource(source: String?): LibraryKind = KINDS.firstOrNull { k -> k.sources?.contains(source) == true } ?: OTHER

        /** The tabs to show: Other only while it holds something. */
        fun shown(counts: Map<LibraryKind, Int>): List<LibraryKind> = entries.filter { it != OTHER || (counts[OTHER] ?: 0) > 0 }
    }
}

/**
 * What the rules read of one recording, worked out once per list: its day in the zone counted in, its value(s) in each
 * section, and its title, transcript and project path folded for the search.
 */
class LibraryFacts(
    val item: LibraryItem,
    val day: LocalDate?,
    val values: Map<FilterSection, List<String>>,
    /** Still `recording`: a call waiting for Keep or Discard, in Needs you only. */
    val waiting: Boolean,
    val needs: String?,
    private val title: String,
    private val text: String,
    private val path: List<String>
) {
    val source: String get() = values.getValue(FilterSection.SOURCE).first()
    val status: String get() = values.getValue(FilterSection.STATUS).first()

    /** Its one kind (contract 2.0.0-q): the server's `facets.kind`, else worked out from its Source. */
    val kind: LibraryKind by lazy { LibraryKind.of(item.facets?.kind, source) }

    /** Every word somewhere among the title, the transcript and the project path (each word in any of them). */
    fun matches(words: List<String>): Boolean = words.all { w -> title.contains(w) || text.contains(w) || path.any { it.contains(w) } }
}

/**
 * The Library's filters, their counts and Needs you, as contract 2.0.0-p defines them (docs/API.md "Library filters
 * (since 2.0.0-p)"): the phone works them out from its synced library, so they are instant and work offline. The
 * server, the web console and the desktop get the same numbers; all four check web/tests/Fixtures/library-facets.json
 * (LibraryFacetsFixtureTest). Pure; unit-tested.
 */
object LibraryFacets {
    const val CUSTOM = "custom"
    const val NO_PROJECT = "none"
    const val ANY_BOOKMARK = "any"
    const val UNKNOWN = "unknown"
    const val MAX_VALUES = 50
    const val STUCK_HOURS = 24L

    /** Date's presets (Any time is no filter; Custom names its days). */
    val DATES = listOf("today", "last_7_days", "this_month")
    val SOURCES = listOf(
        "whatsapp", "whatsapp_business", "google_meet", "zoom", "microsoft_teams", "phone_call", "telegram", "signal", "messenger",
        "instagram", "skype", "slack", "discord", "voice_note", "imported", "other"
    )
    val DEVICES = listOf("phone", "desktop", "web")
    val LANGUAGES = listOf("en", "ur", "ar", "fr", "de", "es", "it", "pt", "nl", "tr", "ru", UNKNOWN)
    val STATUSES = listOf("transcribed", "not_transcribed", "processing", "failed")
    val MARKED = listOf("pinned", "important", "keep_forever")

    private val UNDER_WAY = setOf("pending", "submitted", "processing", "awaiting_device")
    private val MOVING = setOf("recording", "uploading", "processing")
    private val ALIASES = mapOf(
        "hi" to "ur", "hin" to "ur", "hindi" to "ur", "urd" to "ur", "urdu" to "ur",
        "eng" to "en", "english" to "en",
        "ara" to "ar", "arb" to "ar", "arabic" to "ar",
        "fra" to "fr", "fre" to "fr", "french" to "fr",
        "deu" to "de", "ger" to "de", "german" to "de",
        "spa" to "es", "spanish" to "es",
        "ita" to "it", "italian" to "it",
        "por" to "pt", "portuguese" to "pt",
        "nld" to "nl", "dut" to "nl", "dutch" to "nl",
        "tur" to "tr", "turkish" to "tr",
        "rus" to "ru", "russian" to "ru"
    )

    fun isDay(s: String): Boolean = Regex("\\d{4}-\\d{2}-\\d{2}").matches(s) && runCatching { LocalDate.parse(s) }.isSuccess

    /** A value as a section keeps it, or null when the section has no such option (labels and tags in lower case). */
    fun option(section: FilterSection, raw: String): String? {
        val v = raw.trim()
        return when (section) {
            FilterSection.DATE -> v.takeIf { it in DATES || it == CUSTOM }
            FilterSection.SOURCE -> v.takeIf { it in SOURCES }
            FilterSection.DEVICE -> v.takeIf { it in DEVICES }
            FilterSection.PROJECT -> v.takeIf { it == NO_PROJECT || Regex("[0-9A-Za-z]{26}").matches(it) }
            FilterSection.LANGUAGE -> v.takeIf { it in LANGUAGES }
            FilterSection.STATUS -> v.takeIf { it in STATUSES }
            FilterSection.LABELS, FilterSection.BOOKMARKS -> labelKey(v)
            FilterSection.MARKED -> v.takeIf { it in MARKED }
        }
    }

    /** A label or a bookmark tag as the filters key it: control characters out, single spaces, in lower case. */
    fun labelKey(label: String?): String? {
        val clean = label?.replace(Regex("\\p{C}+"), "")?.replace(Regex("\\s+"), " ")?.trim().orEmpty()
        return clean.takeIf { it.isNotEmpty() && it.length <= 64 }?.let(::lower)
    }

    /** The recording's Source: the server's (`facets.source`), else worked out the same way (WhatsApp Web is WhatsApp). */
    fun source(item: LibraryItem): String {
        item.facets?.source?.takeIf { it in SOURCES }?.let { return it }
        val app = if (item.sourceApp == "whatsapp_web") "whatsapp" else item.sourceApp
        return app?.takeIf { it in SOURCES } ?: "other"
    }

    /** The recording's Status, the first that holds: failed, transcribed, processing, else not transcribed. */
    fun status(item: LibraryItem): String {
        item.facets?.status?.takeIf { it in STATUSES }?.let { return it }
        val final = item.transcript?.finalStatus
        return when {
            item.status == "failed" || final == "failed" -> "failed"
            final == "completed" -> "transcribed"
            item.status in MOVING || final in UNDER_WAY -> "processing"
            else -> "not_transcribed"
        }
    }

    /** The recording's Language: the final transcript's, else the one chosen, else Unknown ("en+ur" and Hindi are Urdu). */
    fun language(item: LibraryItem): String {
        item.facets?.language?.takeIf { it in LANGUAGES }?.let { return it }
        return languageCode(item.transcript?.finalLanguage) ?: languageCode(item.language) ?: UNKNOWN
    }

    /** One stored or engine spelling as an offered code, or null (blank, `auto`, a language not offered). */
    fun languageCode(raw: String?): String? {
        val value = raw?.trim()?.lowercase(Locale.ROOT).orEmpty()
        if (value.isEmpty() || value == "auto") return null
        val codes = value.split('+').mapNotNull { part ->
            val p = part.trim()
            val base = p.replace(Regex("[-_].*$"), "")
            (ALIASES[p] ?: ALIASES[base] ?: base).takeIf { it.isNotEmpty() && it in LANGUAGES && it != UNKNOWN }
        }
        if (codes.isEmpty()) return null
        return codes.firstOrNull { it != "en" } ?: "en"
    }

    /** Bookmarks' values: `any` when it has one, then each tag in lower case. */
    fun bookmarks(item: LibraryItem): List<String> {
        val tags = item.tags.mapNotNull { labelKey(it) }
        return if (item.markerCount > 0 || tags.isNotEmpty()) (listOf(ANY_BOOKMARK) + tags).distinct() else emptyList()
    }

    fun marked(item: LibraryItem): List<String> = listOfNotNull(
        "pinned".takeIf { item.pinned },
        "important".takeIf { item.important },
        "keep_forever".takeIf { item.keep }
    )

    private fun instant(s: String?): Instant? = s?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }

    /**
     * Why the recording needs the person, or null, the first that holds: `waiting` (a call still waiting for Keep or
     * Discard, session `recording`), `failed` (Status failed), `stuck` (Status processing for more than 24 hours since
     * `processing_since`, the start of the current attempt; without it since it ended, else since it started).
     */
    fun needsReason(item: LibraryItem, now: Instant): String? {
        if (item.deleted) return null
        if (item.status == "recording") return "waiting"
        val status = status(item)
        if (status == "failed") return "failed"
        if (status != "processing") return null
        val since = instant(item.processingSince) ?: instant(item.endedAt) ?: instant(item.startedAt) ?: return null
        return if (since < now.minusSeconds(STUCK_HOURS * 3600)) "stuck" else null
    }

    /**
     * Every recording's facts (deleted ones never; a call still recording only for Needs you), with each project's path
     * from [projects] and [title] as the row shows it.
     */
    fun prepare(
        items: List<LibraryItem>,
        projects: List<ProjectNode>,
        zone: ZoneId,
        now: Instant,
        title: (LibraryItem) -> String = { it.title.orEmpty() }
    ): List<LibraryFacts> {
        val parents = projects.associate { it.id to it.parentId }
        val names = projects.associate { it.id to it.name }
        return items.filter { !it.deleted }.map { item ->
            val chain = item.client?.id?.let { chain(it, parents) }
            LibraryFacts(
                item = item,
                day = instant(item.startedAt)?.atZone(zone)?.toLocalDate(),
                values = mapOf(
                    FilterSection.SOURCE to listOf(source(item)),
                    FilterSection.DEVICE to listOfNotNull(item.sourceDevice),
                    FilterSection.PROJECT to (chain ?: listOf(NO_PROJECT)),
                    FilterSection.LANGUAGE to listOf(language(item)),
                    FilterSection.STATUS to listOf(status(item)),
                    FilterSection.LABELS to item.labels.mapNotNull { labelKey(it) }.distinct(),
                    FilterSection.MARKED to marked(item),
                    FilterSection.BOOKMARKS to bookmarks(item)
                ),
                waiting = item.status == "recording",
                needs = needsReason(item, now),
                title = UrduFold.fold(title(item)),
                text = UrduFold.fold(item.transcript?.preview.orEmpty()),
                path = chain.orEmpty().map { id -> UrduFold.fold(names[id] ?: item.client?.takeIf { it.id == id }?.name.orEmpty()) }
            )
        }
    }

    /** A project and every folder above it. */
    private fun chain(id: String, parents: Map<String, String?>): List<String> {
        val out = mutableListOf(id)
        var up = parents[id]
        while (up != null && up !in out && out.size < 16) {
            out += up
            up = parents[up]
        }
        return out
    }

    /** Whether [f] is in [place]: a call still waiting only in Needs you, every other one by its own values. */
    fun inPlace(f: LibraryFacts, place: LibraryPlace): Boolean = when {
        place == LibraryPlace.NEEDS_YOU -> f.needs != null

        f.waiting -> false

        else -> when (place) {
            LibraryPlace.ALL, LibraryPlace.NEEDS_YOU -> true
            LibraryPlace.UNREAD -> f.item.opened == false
            LibraryPlace.IMPORTANT -> f.item.important
            LibraryPlace.NOT_TRANSCRIBED -> f.status == "not_transcribed"
            LibraryPlace.AUDIO_ONLY -> f.item.status == "audio_only"
        }
    }

    /** Each Library view's count: All, Needs you (waiting calls too), Unread, Important, Not transcribed, Audio only. */
    fun placeCounts(facts: List<LibraryFacts>): Map<LibraryPlace, Int> =
        LibraryPlace.entries.associateWith { p -> facts.count { inPlace(it, p) } }

    /** The days a Date choice keeps, both ends included; null for an open end. Nothing after today is in Today or Last 7 days. */
    fun range(preset: String, today: LocalDate, from: String? = null, to: String? = null): Pair<LocalDate?, LocalDate?> = when (preset) {
        "today" -> today to today
        "last_7_days" -> today.minusDays(6) to today
        "this_month" -> today.withDayOfMonth(1) to today.withDayOfMonth(today.lengthOfMonth())
        else -> from?.let { runCatching { LocalDate.parse(it) }.getOrNull() } to to?.let { runCatching { LocalDate.parse(it) }.getOrNull() }
    }

    private fun within(day: LocalDate?, range: Pair<LocalDate?, LocalDate?>): Boolean =
        day != null && (range.first == null || !day.isBefore(range.first)) && (range.second == null || !day.isAfter(range.second))

    /** A section that is on, made ready once: whether a recording passes it. */
    private class Check(val section: FilterSection, val passes: (LibraryFacts) -> Boolean)

    private fun checks(filters: LibraryFilterSet, today: LocalDate): List<Check> = filters.sections.map { s ->
        val c = filters.clauses.getValue(s)
        if (s == FilterSection.DATE) {
            val r = range(c.values.first(), today, c.from, c.to)
            Check(s) { f -> within(f.day, r) != c.not }
        } else {
            val wanted = c.values.toSet()
            Check(s) { f -> f.values.getValue(s).any { it in wanted } != c.not }
        }
    }

    /** The recordings that pass every section that is on. */
    fun filter(facts: List<LibraryFacts>, filters: LibraryFilterSet, today: LocalDate): List<LibraryFacts> {
        val all = checks(filters, today)
        return if (all.isEmpty()) facts else facts.filter { f -> all.all { it.passes(f) } }
    }

    /**
     * The panel's options and their counts (the facet rule): each section's counts apply every *other* section's filter,
     * never its own, so every option stays selectable; a recording counts once in each option it has. [facts] are the
     * recordings counted (the place's, searched); [library] the whole library, whose values are offered (a search never
     * takes an option away). Options in the panel's order: Date's three, Status's four, Marked's three and Bookmarks'
     * Any always; Source, Device and Language the values the recordings have, in the contract's order; Bookmarks then
     * the tags by name; Project No project then every project as a tree (each folder before its subfolders, by name);
     * Labels every list in its order (empty ones too), then labels without a list by key; and in every section any value
     * the filters name, at 0 when nothing has it.
     */
    fun facets(
        facts: List<LibraryFacts>,
        filters: LibraryFilterSet,
        library: List<LibraryFacts>,
        today: LocalDate,
        projects: List<ProjectNode> = emptyList(),
        lists: List<String> = emptyList()
    ): Map<FilterSection, List<FacetOption>> {
        val all = checks(filters, today)
        val counts = FilterSection.entries.associateWith { HashMap<String, Int>() }
        val presets = DATES.associateWith { range(it, today) }
        for (f in facts) {
            val failed = all.filterNot { it.passes(f) }.map { it.section }
            if (failed.size > 1) continue
            for (s in FilterSection.entries) {
                if (failed.isNotEmpty() && failed.first() != s) continue
                val values = if (s == FilterSection.DATE) presets.filterValues { within(f.day, it) }.keys.toList() else f.values.getValue(s)
                values.forEach { v -> counts.getValue(s).merge(v, 1, Int::plus) }
            }
        }
        val present = FilterSection.entries.associateWith { LinkedHashSet<String>() }
        library.filter { !it.waiting }.forEach { f ->
            listOf(FilterSection.SOURCE, FilterSection.DEVICE, FilterSection.LANGUAGE, FilterSection.LABELS, FilterSection.BOOKMARKS)
                .forEach { s -> present.getValue(s).addAll(f.values.getValue(s)) }
        }
        filters.sections.forEach { s -> present.getValue(s).addAll(filters.clauses.getValue(s).values) }
        fun plain(s: FilterSection, values: List<String>) = values.map { FacetOption(it, counts.getValue(s)[it] ?: 0) }
        fun ordered(s: FilterSection, order: List<String>) = plain(s, order.filter { it in present.getValue(s) })
        val tags = present.getValue(FilterSection.BOOKMARKS).filter { it != ANY_BOOKMARK }.sorted()
        val listed = lists.mapNotNull { labelKey(it) }.distinct()
        val labels = listed + present.getValue(FilterSection.LABELS).filter { it !in listed }.sorted()
        return mapOf(
            FilterSection.DATE to plain(FilterSection.DATE, DATES),
            FilterSection.SOURCE to ordered(FilterSection.SOURCE, SOURCES),
            FilterSection.DEVICE to ordered(FilterSection.DEVICE, DEVICES),
            FilterSection.PROJECT to plain(FilterSection.PROJECT, projectOrder(projects, present.getValue(FilterSection.PROJECT))),
            FilterSection.LANGUAGE to ordered(FilterSection.LANGUAGE, LANGUAGES),
            FilterSection.STATUS to plain(FilterSection.STATUS, STATUSES),
            FilterSection.LABELS to plain(FilterSection.LABELS, labels),
            FilterSection.MARKED to plain(FilterSection.MARKED, MARKED),
            FilterSection.BOOKMARKS to plain(FilterSection.BOOKMARKS, listOf(ANY_BOOKMARK) + tags)
        )
    }

    /** No project, then the projects as a tree (each folder before its subfolders, by name), then a named id that is gone. */
    fun projectOrder(projects: List<ProjectNode>, asked: Collection<String> = emptyList()): List<String> {
        val ids = projects.map { it.id }.toSet()
        val children = projects.sortedWith(compareBy(String.CASE_INSENSITIVE_ORDER) { it.name })
            .groupBy { p -> p.parentId?.takeIf { it in ids } }
        val out = mutableListOf(NO_PROJECT)
        fun walk(parent: String?) {
            children[parent].orEmpty().forEach { p ->
                if (p.id !in out) {
                    out += p.id
                    walk(p.id)
                }
            }
        }
        walk(null)
        return out + asked.filter { it != NO_PROJECT && it !in out }
    }

    /** How deep each project sits in the tree (0 at the top), for the panel's indent. */
    fun depth(projects: List<ProjectNode>): Map<String, Int> {
        val parents = projects.associate { it.id to it.parentId }
        return projects.associate { p -> p.id to (chain(p.id, parents).size - 1) }
    }

    /**
     * The search's words as the server reads a query: none under 2 characters or without a letter or digit; a "quoted
     * phrase" stays one word, the rest split on spaces; quotes and + - * ( ) < > ~ @ round a word dropped; each word
     * once, at most 8 of up to 64 characters; folded ([UrduFold]).
     */
    fun searchWords(query: String?): List<String> {
        val q = query?.trim().orEmpty()
        if (q.codePointCount(0, q.length) < 2 || q.none { it.isLetterOrDigit() }) return emptyList()
        val words = mutableListOf<String>()
        val seen = mutableSetOf<String>()
        for (m in Regex("\"([^\"]+)\"|(\\S+)").findAll(q.take(200))) {
            val raw = m.groupValues[1].ifEmpty { m.groupValues[2] }.trim().trim(*TRIM)
            if (raw.isEmpty() || !seen.add(lower(raw))) continue
            words += raw.take(64)
        }
        return words.take(8).map { UrduFold.fold(it) }.filter { it.isNotEmpty() }
    }

    private val TRIM = charArrayOf(' ', '\t', '\n', '\r', '\u0000', '\u000B', '"', '\'', '+', '-', '*', '(', ')', '<', '>', '~', '@')

    /** Lower case one character at a time, as the server's mb_strtolower does (a word-final Σ is σ). */
    fun lower(s: String): String = buildString {
        s.codePoints().forEach { cp -> append(String(Character.toChars(cp)).lowercase(Locale.ROOT)) }
    }

    /**
     * Needs you's count on this phone: the synced recordings that need the person (the server's calls still waiting
     * too), plus its own ones the server can't know ([local]: calls waiting on the card or Choose again, uploads that
     * failed or are stuck here), each once: [local] gives each one's references (its id, its `client_ref`, its server
     * session); one the server already has, by any of them, is counted there.
     */
    fun needsYouCount(facts: List<LibraryFacts>, local: Collection<Set<String>>): Int {
        val known = facts.mapNotNull { it.item.clientRef }.toSet() + facts.map { it.item.id }.toSet()
        return facts.count { it.needs != null } + local.count { refs -> refs.none { it in known } }
    }
}

/**
 * Urdu spelling variants made one for the search, as the server folds them (App\Support\UrduText): Arabic Yeh, Alef
 * Maksura and Yeh with Hamza → ی; Arabic Kaf → ک; Arabic Heh, Teh Marbuta, Heh with Yeh above and Teh Marbuta Goal → ہ;
 * Alef with Hamza, Madda or Wasla → ا; Waw with Hamza → و; Yeh Barree with Hamza → ے; Arabic-Indic and Extended
 * Arabic-Indic digits → 0-9; harakat, superscript Alef, tatweel, zero-width joiners and direction marks dropped; then
 * lower case one character at a time. Latin and Devanagari stay as they are.
 */
object UrduFold {
    private val MAP = mapOf(
        0x064A to 0x06CC, 0x0649 to 0x06CC, 0x0626 to 0x06CC,
        0x0643 to 0x06A9,
        0x0647 to 0x06C1, 0x0629 to 0x06C1, 0x06C0 to 0x06C1, 0x06C3 to 0x06C1,
        0x0623 to 0x0627, 0x0625 to 0x0627, 0x0622 to 0x0627, 0x0671 to 0x0627,
        0x0624 to 0x0648,
        0x06D3 to 0x06D2
    )

    private fun dropped(cp: Int): Boolean = cp in 0x064B..0x065F || cp == 0x0670 || cp == 0x0640 || cp in 0x200C..0x200F

    fun fold(text: String): String = buildString(text.length) {
        text.codePoints().forEach { cp ->
            when {
                dropped(cp) -> Unit
                cp in 0x0660..0x0669 -> append('0' + (cp - 0x0660))
                cp in 0x06F0..0x06F9 -> append('0' + (cp - 0x06F0))
                else -> append(String(Character.toChars(MAP[cp] ?: cp)).lowercase(Locale.ROOT))
            }
        }
    }
}
