package com.echoledger.mobile.ui.v2.library

import android.content.res.Resources
import com.echoledger.mobile.core.FilterSection
import com.echoledger.mobile.core.LibraryFacets
import com.echoledger.mobile.core.LibraryFacts
import com.echoledger.mobile.core.LibraryFilterSet
import com.echoledger.mobile.core.LibraryPlace
import com.echoledger.mobile.core.LibraryViewChoice
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ListDto
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.MyView
import com.echoledger.mobile.data.ViewSpec
import com.echoledger.mobile.ui.v2.shell.V2Text
import java.time.Instant
import java.time.OffsetDateTime
import java.util.Locale
import kotlinx.serialization.json.Json

/** The Library screen's rules that need no Compose: where it starts, what a place keeps, what Needs you adds here. */
object LibraryScreenState {
    /** Where Library opens: Not transcribed from Activity's "Not transcribed", else All recordings. */
    fun initialTarget(views: Set<SavedView>): LibraryTarget =
        if (SavedView.Untranscribed in views) LibraryTarget.Place(LibraryPlace.NOT_TRANSCRIBED) else LibraryTarget.ALL

    /** The filters it opens with: Date › Today for Home's Today, else none. */
    fun initialFilters(views: Set<SavedView>): LibraryFilterSet =
        if (SavedView.Today in views) LibraryFilterSet().date("today") else LibraryFilterSet()

    fun json(filters: LibraryFilterSet): String = filters.clean().toJson().toString()

    fun filters(json: String): LibraryFilterSet = LibraryFilterSet.read(runCatching { Json.parseToJsonElement(json) }.getOrNull())

    /**
     * The target still to show: a label whose list is gone, or one of My views deleted here or elsewhere, is All
     * recordings again; a view made here keeps showing once the server gives it its id.
     */
    fun resolve(target: LibraryTarget, lists: List<ListDto>, views: List<MyView>, viewsReady: Boolean): LibraryTarget = when (target) {
        is LibraryTarget.Label -> if (lists.any { it.key == target.label }) target else LibraryTarget.ALL

        is LibraryTarget.View -> views.firstOrNull { it.id == target.id || (it.clientRef != null && "local-" + it.clientRef == target.id) }
            ?.let { LibraryTarget.View(it.id) }
            ?: if (viewsReady) LibraryTarget.ALL else target

        is LibraryTarget.Place -> target
    }

    /**
     * Where Sort & display is remembered: the whole Library as before ("all"), each other place by its own key, and one
     * of My views by the reference it was made with (it keeps it once the server gives it its id).
     */
    fun choiceKey(target: LibraryTarget, views: List<MyView> = emptyList()): String = when {
        target == LibraryTarget.ALL -> "all"
        target is LibraryTarget.View -> views.firstOrNull { it.id == target.id }?.clientRef?.let { "view:local-$it" } ?: target.key
        else -> target.key
    }

    /** A row's title as it shows (the source's name when untitled): what the search reads. */
    fun shownTitle(item: LibraryItem, text: V2Text): String = item.title?.takeIf { it.isNotBlank() } ?: text.sourceName(item.sourceApp)

    /** What a target keeps: a Library view its recordings, a label the ones carrying it, one of My views every one. */
    fun inTarget(facts: List<LibraryFacts>, target: LibraryTarget): List<LibraryFacts> = when (target) {
        is LibraryTarget.Place -> facts.filter { LibraryFacets.inPlace(it, target.place) }
        is LibraryTarget.Label -> facts.filter { !it.waiting && target.label in it.values.getValue(FilterSection.LABELS) }
        is LibraryTarget.View -> facts.filter { LibraryFacets.inPlace(it, LibraryPlace.ALL) }
    }

    /** The person's projects (`GET /api/clients`), then any a recording is in that the list doesn't have (offline). */
    fun projects(clients: List<ClientDto>, library: List<LibraryItem>): List<com.echoledger.mobile.core.ProjectNode> {
        val known = clients.map { com.echoledger.mobile.core.ProjectNode(it.id, it.name, it.parentId) }
        val ids = known.map { it.id }.toSet()
        val more = library.mapNotNull { it.client }.filter { it.id !in ids }.distinctBy { it.id }
            .map { com.echoledger.mobile.core.ProjectNode(it.id, it.name) }
        return known + more
    }

    /** How many recordings carry each label (by its key), as the title menu counts them. */
    fun labelCounts(facts: List<LibraryFacts>): Map<String, Int> =
        facts.filter { !it.waiting }.flatMap { it.values.getValue(FilterSection.LABELS) }.groupingBy { it }.eachCount()

    /**
     * Needs you's own part on this phone, which the server can't know: a call waiting on the card or Choose again, an
     * upload that failed, and one stuck here (waiting or sending) for more than 24 hours since the call ended.
     */
    fun localNeeds(rec: LocalRecording, now: Instant): Boolean = when (rec.state) {
        "pending_decision", "failed" -> true

        "queued", "uploading" -> runCatching { OffsetDateTime.parse(rec.endedAt).toInstant() }.getOrNull()
            ?.let { it < now.minusSeconds(LibraryFacets.STUCK_HOURS * 3600) } == true

        else -> false
    }

    /** What the list shows now, as one of My views keeps it (in the contract's words). */
    fun spec(filters: LibraryFilterSet, query: String, choice: LibraryViewChoice): ViewSpec = ViewSpec(
        filters = filters.clean().toJson(),
        search = query.trim().ifEmpty { null },
        sort = ViewWords.sortOut(choice.sort),
        group = ViewWords.groupOut(choice.group),
        density = choice.density
    ).clean()

    /** One of My views' Sort & display in this app's words (one it lacks shows as Newest or Date). */
    fun choiceOf(spec: ViewSpec): LibraryViewChoice = LibraryViewChoice(
        group = ViewWords.groupIn(spec.group),
        sort = ViewWords.sortIn(spec.sort),
        density = spec.density
    )

    /** The filters' words: each project's name, each label's (its list's, else the first spelling met) and each tag's. */
    fun words(res: Resources, locale: Locale, clients: List<ClientDto>, lists: List<ListDto>, library: List<LibraryItem>): FilterWords {
        val projects = LinkedHashMap<String, String>()
        library.forEach { item -> item.client?.let { projects.putIfAbsent(it.id, it.name) } }
        clients.forEach { projects[it.id] = it.name }
        val labels = LinkedHashMap<String, String>()
        lists.forEach { labels[it.key] = it.name }
        library.forEach { item -> item.labels.forEach { l -> LibraryFacets.labelKey(l)?.let { labels.putIfAbsent(it, l) } } }
        val tags = LinkedHashMap<String, String>()
        library.forEach { item -> item.tags.forEach { t -> LibraryFacets.labelKey(t)?.let { tags.putIfAbsent(it, t) } } }
        return FilterWords(res, locale, projects, labels, tags)
    }
}
