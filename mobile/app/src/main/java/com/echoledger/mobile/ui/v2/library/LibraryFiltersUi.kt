package com.echoledger.mobile.ui.v2.library

import android.content.res.Resources
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.minimumInteractiveComponentSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.core.FacetOption
import com.echoledger.mobile.core.FilterSection
import com.echoledger.mobile.core.LibraryFacets
import com.echoledger.mobile.core.LibraryFilterSet
import com.echoledger.mobile.core.LibraryKind
import com.echoledger.mobile.core.LibraryPlace
import com.echoledger.mobile.data.ListDto
import com.echoledger.mobile.data.MyView
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoLabelDot
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSegmented
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.LocalPageMargin
import com.echoledger.mobile.ui.v2.components.startAlign
import com.echoledger.mobile.ui.v2.shell.sourceName
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.ProvidePageLook
import com.echoledger.mobile.ui.v2.theme.largeText
import com.echoledger.mobile.ui.v2.theme.rememberPageLook
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * Where Library is (the title menu, docs/PLAN_NAV_3_LEVELS.md §7): one of its views (All recordings first), a label, or
 * one of My views. Kept as its [key], so it survives a rotation.
 */
sealed interface LibraryTarget {
    val key: String

    data class Place(val place: LibraryPlace) : LibraryTarget {
        override val key: String get() = "place:" + place.wire
    }

    data class Label(val label: String) : LibraryTarget {
        override val key: String get() = "label:$label"
    }

    data class View(val id: String) : LibraryTarget {
        override val key: String get() = "view:$id"
    }

    companion object {
        val ALL = Place(LibraryPlace.ALL)

        fun parse(key: String): LibraryTarget = when {
            key.startsWith("label:") -> Label(key.removePrefix("label:"))
            key.startsWith("view:") -> View(key.removePrefix("view:"))
            else -> LibraryPlace.entries.firstOrNull { "place:" + it.wire == key }?.let(::Place) ?: ALL
        }
    }
}

/** The words of the filters in the screen's language: each section, each option, each pill. */
class FilterWords(
    private val res: Resources,
    private val locale: Locale,
    /** Each project's name by id, and each label's name by key (a list's, else the first spelling met). */
    private val projects: Map<String, String>,
    private val labels: Map<String, String>,
    private val tags: Map<String, String>
) {
    fun section(s: FilterSection): String = res.getString(
        when (s) {
            FilterSection.DATE -> R.string.v2_lib_date
            FilterSection.SOURCE -> R.string.v2_lib_source
            FilterSection.DEVICE -> R.string.v2_lf_device
            FilterSection.PROJECT -> R.string.v2_lib_project
            FilterSection.LANGUAGE -> R.string.v2_lib_language
            FilterSection.STATUS -> R.string.v2_lib_status
            FilterSection.LABELS -> R.string.v2_lf_labels
            FilterSection.MARKED -> R.string.v2_lf_marked
            FilterSection.BOOKMARKS -> R.string.v2_lf_bookmarks
        }
    )

    fun option(s: FilterSection, value: String): String = when (s) {
        FilterSection.DATE -> res.getString(DATE_WORDS[value] ?: R.string.v2_lf_custom)

        FilterSection.SOURCE -> if (value == "imported") res.getString(R.string.v2_lf_imported) else sourceName(res, value)

        FilterSection.DEVICE -> res.getString(DEVICE_WORDS[value] ?: R.string.v2_lib_other)

        FilterSection.PROJECT -> projects[value] ?: res.getString(R.string.v2_lib_no_project).takeIf { value == LibraryFacets.NO_PROJECT }
            ?: value

        FilterSection.LANGUAGE -> res.getString(LANGUAGE_WORDS[value] ?: R.string.v2_lf_lang_unknown)

        FilterSection.STATUS -> res.getString(STATUS_WORDS[value] ?: R.string.v2_lf_processing)

        FilterSection.LABELS -> labels[value] ?: value

        FilterSection.MARKED -> res.getString(MARKED_WORDS[value] ?: R.string.v2_lib_pinned)

        FilterSection.BOOKMARKS -> tags[value] ?: res.getString(R.string.v2_lib_any_bookmark).takeIf { value == LibraryFacets.ANY_BOOKMARK }
            ?: value
    }

    /** A day as the pills show it ("10 Nov"), digits always Latin. */
    fun day(iso: String): String = runCatching {
        LibraryModel.latinDigits(LocalDate.parse(iso).format(DateTimeFormatter.ofPattern("d MMM yyyy", locale)))
    }.getOrDefault(iso)

    /** What a section's choice says: "WhatsApp, Zoom", "Last 7 days", "10 Nov – 15 Nov". */
    fun values(s: FilterSection, clause: com.echoledger.mobile.core.FilterClause): String {
        if (s == FilterSection.DATE && clause.values.first() == LibraryFacets.CUSTOM) {
            val from = clause.from?.let(::day)
            val to = clause.to?.let(::day)
            return when {
                from != null && to != null -> res.getString(R.string.v2_lf_date_range, from, to)
                from != null -> res.getString(R.string.v2_lf_date_from, from)
                else -> res.getString(R.string.v2_lf_date_until, to.orEmpty())
            }
        }
        return clause.values.joinToString(", ") { option(s, it) }
    }

    /** A pill: "Source: WhatsApp, Zoom", "Source is not: WhatsApp". */
    fun pill(s: FilterSection, clause: com.echoledger.mobile.core.FilterClause): String =
        res.getString(if (clause.not) R.string.v2_lf_pill_not else R.string.v2_lf_pill, section(s), values(s, clause))

    companion object {
        private val DATE_WORDS = mapOf(
            "today" to R.string.v2_lib_today,
            "last_7_days" to R.string.v2_lf_last_7_days,
            "this_month" to R.string.v2_lf_this_month,
            LibraryFacets.CUSTOM to R.string.v2_lf_custom
        )
        private val DEVICE_WORDS = mapOf(
            "phone" to R.string.v2_lib_phone,
            "desktop" to R.string.v2_lib_desktop,
            "web" to R.string.v2_lib_web
        )
        private val LANGUAGE_WORDS = mapOf(
            "en" to R.string.v2_lt_lang_en, "ur" to R.string.v2_lt_lang_ur, "ar" to R.string.v2_lt_lang_ar,
            "fr" to R.string.v2_lt_lang_fr, "de" to R.string.v2_lt_lang_de, "es" to R.string.v2_lt_lang_es,
            "it" to R.string.v2_lf_lang_it, "pt" to R.string.v2_lt_lang_pt, "nl" to R.string.v2_lf_lang_nl,
            "tr" to R.string.v2_lt_lang_tr, "ru" to R.string.v2_lt_lang_ru, LibraryFacets.UNKNOWN to R.string.v2_lf_lang_unknown
        )
        private val STATUS_WORDS = mapOf(
            "transcribed" to R.string.v2_lf_transcribed,
            "not_transcribed" to R.string.v2_lf_not_transcribed,
            "processing" to R.string.v2_lf_processing,
            "failed" to R.string.v2_lf_failed
        )
        private val MARKED_WORDS = mapOf(
            "pinned" to R.string.v2_lib_pinned,
            "important" to R.string.v2_lists_important,
            "keep_forever" to R.string.v2_lf_keep_forever
        )
    }
}

/** A Library view's words in the title and its menu. */
@Composable
fun placeName(place: LibraryPlace): String = stringResource(
    when (place) {
        LibraryPlace.ALL -> R.string.v2_lf_all_recordings
        LibraryPlace.NEEDS_YOU -> R.string.v2_lib_needs_you
        LibraryPlace.UNREAD -> R.string.v2_lists_unread
        LibraryPlace.IMPORTANT -> R.string.v2_lists_important
        LibraryPlace.NOT_TRANSCRIBED -> R.string.v2_lf_not_transcribed
        LibraryPlace.AUDIO_ONLY -> R.string.v2_lib_audio_only
    }
)

private fun placeIcon(place: LibraryPlace): ImageVector = when (place) {
    LibraryPlace.ALL -> EchoIcons.Library
    LibraryPlace.NEEDS_YOU -> EchoIcons.Error
    LibraryPlace.UNREAD -> EchoIcons.Unread
    LibraryPlace.IMPORTANT -> EchoIcons.Important
    LibraryPlace.NOT_TRANSCRIBED -> EchoIcons.Transcribe
    LibraryPlace.AUDIO_ONLY -> EchoIcons.VoiceNote
}

@Composable
fun kindName(kind: LibraryKind): String = stringResource(
    when (kind) {
        LibraryKind.ALL -> R.string.v2_lists_all
        LibraryKind.CALLS -> R.string.v2_lf_calls
        LibraryKind.MEETINGS -> R.string.v2_lf_meetings
        LibraryKind.VOICE_NOTES -> R.string.v2_lib_voice_notes
        LibraryKind.OTHER -> R.string.v2_lf_other
    }
)

/** The tag the chips row carries in tests. */
const val KIND_ROW_TAG = "library-kinds"

/** The tag the pills row carries in tests. */
const val PILLS_ROW_TAG = "library-pills"

/**
 * Under the title (v2.1 UX-14, 48 dp): [Filters n] (opens the Filters sheet; n the sections on), then All · Calls ·
 * Meetings · Voice notes, and Other while it holds something, with how many each holds, one chosen at a time; and last
 * Sort & display ([onSortDisplay]; it was in ⋮).
 */
@Composable
fun LibraryKindRow(
    kind: LibraryKind,
    counts: Map<LibraryKind, Int>,
    filtersOn: Int,
    onKind: (LibraryKind) -> Unit,
    onSortDisplay: (() -> Unit)? = null,
    onFilters: () -> Unit
) {
    Row(
        Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            // 48 dp (UX-14): the chips' own 48 dp touch areas, nothing above or below them.
            .heightIn(min = EchoTokens.Layout.controlTouchMin)
            .padding(horizontal = LocalPageMargin.current)
            .semanticsTag(KIND_ROW_TAG),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        FiltersButton(filtersOn, onFilters)
        Box(Modifier.width(EchoTokens.Border.hairline).heightIn(min = EchoTokens.Space.s24).background(EchoV2.colors.borderDefault))
        LibraryKind.shown(counts).forEach { k ->
            val n = counts[k] ?: 0
            EchoChip(stringResource(R.string.v2_lf_kind_count, kindName(k), n), k == kind, { onKind(k) })
        }
        if (onSortDisplay != null) {
            Box(Modifier.width(EchoTokens.Border.hairline).heightIn(min = EchoTokens.Space.s24).background(EchoV2.colors.borderDefault))
            RowButton(EchoIcons.Sort, stringResource(R.string.v2_lf_sort_display), onSortDisplay)
        }
    }
}

/** A button the size of a chip, in the chips' row (Sort & display). */
@Composable
private fun RowButton(icon: ImageVector, words: String, onClick: () -> Unit) {
    val c = EchoV2.colors
    androidx.compose.material3.Surface(
        modifier = Modifier
            .minimumInteractiveComponentSize()
            .clickable(role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) {},
        shape = RoundedCornerShape(EchoTokens.Component.chipRadius),
        color = c.bgSurface,
        contentColor = c.textPrimary,
        border = androidx.compose.foundation.BorderStroke(EchoTokens.Border.hairline, c.borderStrong)
    ) {
        Row(
            Modifier
                .defaultMinSize(minHeight = EchoTokens.Component.chipHeight)
                .padding(horizontal = EchoTokens.Space.s12),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
        ) {
            Icon(icon, null, Modifier.size(EchoTokens.Icon.md))
            Text(words, style = EchoV2.type.label, maxLines = 1)
        }
    }
}

/** [Filters n]: tinted while any section is on, its count said to screen readers. */
@Composable
fun FiltersButton(on: Int, onClick: () -> Unit) {
    val c = EchoV2.colors
    val words = if (on > 0) stringResource(R.string.v2_lf_filters_n, on) else stringResource(R.string.v2_lf_filters)
    val spoken = if (on > 0) stringResource(R.string.v2_lf_filters_on, on) else words
    androidx.compose.material3.Surface(
        modifier = Modifier
            .minimumInteractiveComponentSize()
            .clickable(role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) { contentDescription = spoken },
        shape = RoundedCornerShape(EchoTokens.Component.chipRadius),
        color = if (on > 0) c.accentSoft else c.bgSurface,
        contentColor = if (on > 0) c.accentText else c.textPrimary,
        border = androidx.compose.foundation.BorderStroke(EchoTokens.Border.hairline, if (on > 0) c.accent else c.borderStrong)
    ) {
        Row(
            Modifier
                .defaultMinSize(minHeight = EchoTokens.Component.chipHeight)
                .padding(horizontal = EchoTokens.Space.s12),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
        ) {
            Icon(EchoIcons.Filters, null, Modifier.size(EchoTokens.Icon.md))
            Text(words, style = EchoV2.type.label, maxLines = 1)
        }
    }
}

private fun Modifier.semanticsTag(tag: String): Modifier = this.testTag(tag)

/**
 * The pills, only while a filter is on: one per section in its words ("Source: WhatsApp, Zoom", "Date is not: This
 * month"); a tap opens the Filters sheet at that section, its × takes it off; then Clear all, and Save as view (or Save
 * changes while one of My views is open and changed).
 */
@Composable
fun LibraryPillsRow(
    filters: LibraryFilterSet,
    words: FilterWords,
    onEdit: (FilterSection) -> Unit,
    onRemove: (FilterSection) -> Unit,
    onClearAll: () -> Unit,
    saveLabel: String?,
    onSave: () -> Unit
) {
    val res = LocalContext.current.resources
    Row(
        Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = LocalPageMargin.current, vertical = EchoTokens.Space.s4)
            .semanticsTag(PILLS_ROW_TAG),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        filters.sections.forEach { s ->
            val pill = words.pill(s, filters.clauses.getValue(s))
            EchoChip(
                pill,
                true,
                { onEdit(s) },
                dropdown = false,
                onClear = { onRemove(s) },
                clearLabel = res.getString(R.string.v2_lf_pill_remove, pill)
            )
        }
        TextAction(stringResource(R.string.v2_lib_clear_all), onClearAll)
        saveLabel?.let { TextAction(it, onSave) }
    }
}

@Composable
private fun TextAction(words: String, onClick: () -> Unit) = Text(
    words,
    style = EchoV2.type.label,
    color = EchoV2.colors.accentText,
    maxLines = 1,
    modifier = Modifier
        .heightIn(min = EchoTokens.Layout.controlTouchMin)
        .clickable(role = Role.Button, onClick = onClick)
        .padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s12)
)

/** What the title menu lists and counts. */
class LibraryMenuData(
    val target: LibraryTarget,
    val counts: Map<LibraryPlace, Int>,
    val views: List<MyView>,
    val viewsReady: Boolean,
    val lists: List<ListDto>,
    val labelCounts: Map<String, Int>
)

/** What the title menu does. */
class LibraryMenuActions(
    val onChoose: (LibraryTarget) -> Unit,
    val onNewView: () -> Unit,
    val onRenameView: (MyView) -> Unit,
    val onMoveView: (MyView, Int) -> Unit,
    val onDeleteView: (MyView) -> Unit,
    val onNewLabel: () -> Unit,
    val onRenameLabel: (ListDto) -> Unit,
    val onRecolourLabel: (ListDto) -> Unit,
    val onMoveLabel: (ListDto, Int) -> Unit,
    val onDeleteLabel: (ListDto) -> Unit,
    val onSharedWithMe: () -> Unit,
    val onRequests: () -> Unit,
    val onTrash: () -> Unit
)

/**
 * The title's menu (docs/PLAN_NAV_3_LEVELS.md §7): Views (All recordings, Needs you, Unread, Important, Not transcribed,
 * Audio only, Shared ▸ Shared with me and Request files), My views with + New view, Labels (the lists, each with its
 * colour and count) with + New label, and Trash. Counts sit at the end, secondary; only Needs you's and Unread's are
 * bold. Each view and label has ⋮: Rename…, Move up or down, Delete…; a label also Change colour….
 */
@Composable
fun LibraryMenuSheet(data: LibraryMenuData, actions: LibraryMenuActions, onDismiss: () -> Unit) {
    var shared by remember { mutableStateOf(false) }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_library)) {
        Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState())) {
            EchoSectionHeader(stringResource(R.string.v2_lf_views))
            LibraryPlace.entries.forEach { p ->
                val bold = p == LibraryPlace.NEEDS_YOU || p == LibraryPlace.UNREAD
                MenuRow(placeIcon(p), placeName(p), data.counts[p], bold, data.target == LibraryTarget.Place(p)) {
                    actions.onChoose(LibraryTarget.Place(p))
                }
            }
            MenuRow(EchoIcons.SharedWithMe, stringResource(R.string.v2_lf_shared), null, false, false, expandable = shared) {
                shared = !shared
            }
            if (shared) {
                MenuRow(null, stringResource(R.string.v2_fold_shared), null, false, false, indent = true, onClick = actions.onSharedWithMe)
                MenuRow(null, stringResource(R.string.v2_req_title), null, false, false, indent = true, onClick = actions.onRequests)
            }
            EchoSectionHeader(stringResource(R.string.v2_lf_my_views))
            if (data.viewsReady && data.views.isEmpty()) {
                Text(
                    stringResource(R.string.v2_lf_no_views),
                    style = EchoV2.type.meta,
                    color = EchoV2.colors.textSecondary,
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
                )
            }
            data.views.forEachIndexed { i, v ->
                MenuRow(
                    EchoIcons.SavedView,
                    v.name,
                    null,
                    false,
                    data.target == LibraryTarget.View(v.id),
                    content = true,
                    menu = {
                        ItemMenu(
                            stringResource(R.string.v2_lf_item_options, v.name),
                            buildList {
                                add(stringResource(R.string.v2_lf_rename) to { actions.onRenameView(v) })
                                if (i > 0) add(stringResource(R.string.v2_lf_move_up) to { actions.onMoveView(v, -1) })
                                if (i < data.views.lastIndex) add(stringResource(R.string.v2_lf_move_down) to { actions.onMoveView(v, 1) })
                            },
                            danger = stringResource(R.string.v2_lf_delete_view) to { actions.onDeleteView(v) }
                        )
                    }
                ) { actions.onChoose(LibraryTarget.View(v.id)) }
            }
            if (data.viewsReady) {
                MenuRow(EchoIcons.Add, stringResource(R.string.v2_lf_new_view), null, false, false, onClick = actions.onNewView)
            }
            EchoSectionHeader(stringResource(R.string.v2_lf_labels))
            data.lists.forEachIndexed { i, list ->
                MenuRow(
                    null,
                    list.name,
                    data.labelCounts[list.key] ?: 0,
                    false,
                    data.target == LibraryTarget.Label(list.key),
                    dot = list.color,
                    content = true,
                    menu = {
                        ItemMenu(
                            stringResource(R.string.v2_lf_item_options, list.name),
                            buildList {
                                add(stringResource(R.string.v2_lf_rename) to { actions.onRenameLabel(list) })
                                add(stringResource(R.string.v2_lists_recolour) to { actions.onRecolourLabel(list) })
                                if (i > 0) add(stringResource(R.string.v2_lf_move_up) to { actions.onMoveLabel(list, -1) })
                                val down = stringResource(R.string.v2_lf_move_down)
                                if (i < data.lists.lastIndex) add(down to { actions.onMoveLabel(list, 1) })
                            },
                            danger = stringResource(R.string.v2_lf_delete_label) to { actions.onDeleteLabel(list) }
                        )
                    }
                ) { actions.onChoose(LibraryTarget.Label(list.key)) }
            }
            MenuRow(EchoIcons.Add, stringResource(R.string.v2_lf_new_label), null, false, false, onClick = actions.onNewLabel)
            HorizontalDivider(thickness = EchoTokens.Border.hairline, color = EchoV2.colors.borderDefault)
            MenuRow(EchoIcons.Delete, stringResource(R.string.v2_lf_trash), null, false, false, onClick = actions.onTrash)
        }
    }
}

/**
 * One row of the title menu, a button: its icon (or a label's colour dot), its words, its count at the end when above 0
 * (bold for Needs you and Unread) and its ⋮; the current one tinted, and said to be the one selected.
 */
@Composable
private fun MenuRow(
    icon: ImageVector?,
    words: String,
    count: Int?,
    bold: Boolean,
    current: Boolean,
    dot: String? = null,
    indent: Boolean = false,
    content: Boolean = false,
    expandable: Boolean? = null,
    menu: (@Composable () -> Unit)? = null,
    onClick: () -> Unit
) {
    val c = EchoV2.colors
    val selected = EchoV2.strings.selected
    // A count of 0 is not shown (as on the desktop and the web).
    val shown = count?.takeIf { it > 0 }
    val spoken = shown?.let { stringResource(R.string.v2_lf_count, words, it) } ?: words
    Row(
        Modifier
            .fillMaxWidth()
            .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
            .then(if (current) Modifier.background(c.accentSoft) else Modifier)
            .padding(start = if (indent) EchoTokens.Space.s40 else EchoTokens.Space.s0),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Row(
            Modifier
                .weight(1f)
                .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
                .clickable(role = Role.Button, onClick = onClick)
                .semantics(mergeDescendants = true) {
                    contentDescription = spoken
                    if (current) stateDescription = selected
                }
                .padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s8),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            when {
                dot != null || (icon == null && !indent) -> Box(Modifier.size(EchoTokens.Icon.lg), contentAlignment = Alignment.Center) {
                    EchoLabelDot(dot)
                }

                icon != null -> Icon(icon, null, Modifier.size(EchoTokens.Icon.lg), tint = if (current) c.accentText else c.textSecondary)
            }
            Text(
                words,
                style = if (content) EchoV2.type.content(words, EchoV2.type.body) else EchoV2.type.body,
                color = if (current) c.accentText else c.textPrimary,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                // A view's or a label's own name (Latin in Urdu too) still starts at the screen's start.
                textAlign = startAlign(),
                modifier = Modifier.weight(1f)
            )
            shown?.let {
                Text(
                    "$it",
                    style = EchoV2.type.meta.copy(fontWeight = if (bold) FontWeight.SemiBold else EchoV2.type.meta.fontWeight),
                    color = if (bold) c.textPrimary else c.textSecondary,
                    maxLines = 1
                )
            }
            expandable?.let { open ->
                Icon(if (open) EchoIcons.Collapse else EchoIcons.Expand, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
            }
        }
        menu?.invoke()
    }
}

/** A row's ⋮ and its menu: [items], then after a hairline [danger] in red. */
@Composable
private fun ItemMenu(label: String, items: List<Pair<String, () -> Unit>>, danger: Pair<String, () -> Unit>) {
    var open by remember { mutableStateOf(false) }
    val c = EchoV2.colors
    Box {
        EchoIconButton(EchoIcons.More, label, { open = true }, tint = c.textSecondary)
        val look = rememberPageLook()
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            ProvidePageLook(look) {
                Column {
                    items.forEach { (words, run) ->
                        DropdownMenuItem(text = { Text(words, style = EchoV2.type.body) }, onClick = {
                            open = false
                            run()
                        })
                    }
                    HorizontalDivider(thickness = EchoTokens.Border.hairline, color = c.borderDefault)
                    DropdownMenuItem(text = { Text(danger.first, style = EchoV2.type.body, color = c.danger) }, onClick = {
                        open = false
                        danger.second()
                    })
                }
            }
        }
    }
}

/**
 * The Filters sheet (PLAN_LIBRARY_FILTERS §2): every section with its options and live counts (the facet rule, worked
 * out on this phone from [counts]), "is" or "is not" once a section is on, each section's Clear, then Save as view
 * and "Show N results" ([total] of the choice so far). Nothing changes until Show; a swipe down leaves it as it was.
 * [focus] is the section a pill opened it at.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun LibraryFiltersSheet(
    start: LibraryFilterSet,
    words: FilterWords,
    counts: (LibraryFilterSet) -> Map<FilterSection, List<FacetOption>>,
    total: (LibraryFilterSet) -> Int,
    depth: Map<String, Int>,
    labelColour: (String) -> String?,
    focus: FilterSection?,
    canSave: Boolean,
    onSave: (LibraryFilterSet) -> Unit,
    onDismiss: () -> Unit,
    onApply: (LibraryFilterSet) -> Unit
) {
    var draft by remember { mutableStateOf(start) }
    val options = remember(draft) { counts(draft) }
    val n = remember(draft) { total(draft) }
    val anchors = remember { FilterSection.entries.associateWith { BringIntoViewRequester() } }
    LaunchedEffect(focus) { focus?.let { runCatching { anchors.getValue(it).bringIntoView() } } }
    var picking by remember { mutableStateOf<String?>(null) }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_lf_filters)) {
        Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState())) {
            for (s in FilterSection.entries) {
                val opts = options[s].orEmpty()
                if (s != FilterSection.DATE && opts.isEmpty()) continue
                Column(Modifier.bringIntoViewRequester(anchors.getValue(s))) {
                    SectionHead(s, draft, words, onNot = { draft = draft.not(s, it) }, onClear = { draft = draft.clear(s) })
                    if (s == FilterSection.DATE) {
                        DateOptions(draft, opts, words, { draft = it }, { picking = it })
                    } else {
                        opts.forEach { o ->
                            val indent = if (s == FilterSection.PROJECT) depth[o.value] ?: 0 else 0
                            OptionRow(
                                words.option(s, o.value),
                                o.count,
                                draft.clauses[s]?.values.orEmpty().contains(o.value),
                                indent,
                                dot = if (s == FilterSection.LABELS) labelColour(o.value) ?: "" else null
                            ) { draft = draft.toggle(s, o.value) }
                        }
                    }
                }
            }
        }
        val save: @Composable (Modifier) -> Unit = { m ->
            EchoButton(stringResource(R.string.v2_lf_save_view), {
                onSave(draft)
            }, m, kind = EchoButtonKind.Secondary, enabled = !draft.isEmpty)
        }
        val show: @Composable (Modifier) -> Unit = { m ->
            EchoButton(pluralStringResource(R.plurals.v2_lf_show_results, n, n), { onApply(draft) }, m)
        }
        val padded = Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
        // From 150 % text the two buttons don't fit side by side (Urdu above all): one above the other, full width.
        if (largeText()) {
            Column(padded, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                if (canSave) save(Modifier.fillMaxWidth())
                show(Modifier.fillMaxWidth())
            }
        } else {
            Row(padded, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8), verticalAlignment = Alignment.CenterVertically) {
                if (canSave) save(Modifier.weight(1f))
                show(Modifier.weight(1f))
            }
        }
    }
    picking?.let { end ->
        DayPicker(
            initial = (if (end == "from") draft.clauses[FilterSection.DATE]?.from else draft.clauses[FilterSection.DATE]?.to),
            onDismiss = { picking = null }
        ) { day ->
            picking = null
            val now = draft.clauses[FilterSection.DATE]
            val from = if (end == "from") day else now?.from
            val to = if (end == "to") day else now?.to
            // Both days kept in order, whichever was picked last.
            val (a, b) = if (from != null && to != null && from > to) to to from else from to to
            draft = draft.date(LibraryFacets.CUSTOM, a, b)
        }
    }
}

/** A section's head: its name (with how many are on), "is" / "is not" and Clear once it is on. */
@Composable
private fun SectionHead(s: FilterSection, draft: LibraryFilterSet, words: FilterWords, onNot: (Boolean) -> Unit, onClear: () -> Unit) {
    val on = draft[s]
    Column(Modifier.padding(top = EchoTokens.Space.s8)) {
        Row(
            Modifier.fillMaxWidth().padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s4),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                words.section(s),
                style = EchoV2.type.bodyStrong,
                color = EchoV2.colors.textPrimary,
                modifier = Modifier.weight(1f).semantics { heading() }
            )
            if (on != null) TextAction(stringResource(R.string.v2_lib_clear), onClear)
        }
        if (on != null) {
            EchoSegmented(
                listOf(stringResource(R.string.v2_lf_is), stringResource(R.string.v2_lf_is_not)),
                if (on.not) 1 else 0,
                { onNot(it == 1) },
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
            )
        }
    }
}

/** One option: its box, its words (indented under its folder for a project), its colour dot for a label, its count. */
@Composable
private fun OptionRow(words: String, count: Int, checked: Boolean, indent: Int, dot: String?, onToggle: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(start = EchoTokens.Space.s12 + EchoTokens.Space.s16 * indent, end = EchoTokens.Layout.pagePaddingMobile),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(Modifier.weight(1f)) {
            EchoCheckbox(checked, { onToggle() }, words)
        }
        if (dot != null) EchoLabelDot(dot.ifEmpty { null })
        Text(
            "$count",
            style = EchoV2.type.meta,
            color = EchoV2.colors.textSecondary,
            modifier = Modifier.padding(start = EchoTokens.Space.s8)
        )
    }
}

/** Date's one choice: Any time, Today, Last 7 days, This month (with counts), or Custom with its two days. */
@Composable
private fun DateOptions(
    draft: LibraryFilterSet,
    opts: List<FacetOption>,
    words: FilterWords,
    onChange: (LibraryFilterSet) -> Unit,
    onPick: (String) -> Unit
) {
    val chosen = draft[FilterSection.DATE]?.values?.first() ?: draft.clauses[FilterSection.DATE]?.values?.firstOrNull()
    Column(Modifier.padding(horizontal = EchoTokens.Space.s12)) {
        EchoRadio(chosen == null, { onChange(draft.date(null)) }, stringResource(R.string.v2_lf_any_time))
        opts.forEach { o ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) {
                    EchoRadio(chosen == o.value, { onChange(draft.date(o.value)) }, words.option(FilterSection.DATE, o.value))
                }
                Text(
                    "${o.count}",
                    style = EchoV2.type.meta,
                    color = EchoV2.colors.textSecondary,
                    modifier = Modifier.padding(end = EchoTokens.Space.s4)
                )
            }
        }
        EchoRadio(chosen == LibraryFacets.CUSTOM, {
            val now = draft.clauses[FilterSection.DATE]
            onChange(draft.date(LibraryFacets.CUSTOM, now?.from, now?.to))
            if (now?.from == null && now?.to == null) onPick("from")
        }, words.option(FilterSection.DATE, LibraryFacets.CUSTOM))
        if (chosen == LibraryFacets.CUSTOM) {
            val clause = draft.clauses[FilterSection.DATE]
            DayRow(stringResource(R.string.v2_lf_from), clause?.from?.let(words::day)) { onPick("from") }
            DayRow(stringResource(R.string.v2_lf_to), clause?.to?.let(words::day)) { onPick("to") }
        }
    }
}

@Composable
private fun DayRow(label: String, day: String?, onClick: () -> Unit) {
    val c = EchoV2.colors
    val value = day ?: stringResource(R.string.v2_lf_no_day)
    Row(
        Modifier
            .fillMaxWidth()
            .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
            .clickable(role = Role.Button, onClickLabel = stringResource(R.string.v2_lf_pick_day), onClick = onClick)
            .semantics(mergeDescendants = true) {}
            .padding(start = EchoTokens.Space.s48, end = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(label, style = EchoV2.type.body, color = c.textSecondary, modifier = Modifier.weight(1f))
        Text(value, style = EchoV2.type.bodyStrong, color = if (day != null) c.textPrimary else c.textSecondary)
    }
}

/** The system's calendar for one day (YYYY-MM-DD), in the screen's colours. */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun DayPicker(initial: String?, onDismiss: () -> Unit, onPick: (String) -> Unit) {
    val start = initial?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: LocalDate.now()
    val state = androidx.compose.material3.rememberDatePickerState(
        initialSelectedDateMillis = start.atStartOfDay(java.time.ZoneOffset.UTC).toInstant().toEpochMilli()
    )
    val look = rememberPageLook()
    androidx.compose.material3.DatePickerDialog(
        onDismissRequest = onDismiss,
        confirmButton = {
            EchoButton(stringResource(R.string.v2_lf_save), {
                state.selectedDateMillis?.let {
                    onPick(java.time.Instant.ofEpochMilli(it).atZone(java.time.ZoneOffset.UTC).toLocalDate().toString())
                } ?: onDismiss()
            }, kind = EchoButtonKind.Quiet)
        },
        dismissButton = { EchoButton(com.echoledger.mobile.ui.v2.shell.v2Text.cancel, onDismiss, kind = EchoButtonKind.Quiet) }
    ) {
        ProvidePageLook(look) { androidx.compose.material3.DatePicker(state = state) }
    }
}

/**
 * A view's name (Save as view, Rename…): focused, up to 60 characters; [problem] (a name in use, the 30 views…) under
 * it. [onSave] gets the trimmed name and answers the problem, if any.
 */
@Composable
fun ViewNameSheet(title: String, current: String, onDismiss: () -> Unit, onSave: (String) -> String?) {
    var name by remember { mutableStateOf(current) }
    var problem by remember { mutableStateOf<String?>(null) }
    val focus = remember { FocusRequester() }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    val save = { problem = onSave(name.trim()) }
    EchoBottomSheet(onDismiss = onDismiss, title = title) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            EchoTextField(
                value = name,
                onValueChange = {
                    name = it.take(com.echoledger.mobile.data.SavedViewsRepository.NAME_MAX)
                    problem = null
                },
                label = stringResource(R.string.v2_lf_view_name),
                modifier = Modifier.focusRequester(focus),
                error = problem,
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { save() })
            )
            EchoButton(stringResource(R.string.v2_lf_save), save, Modifier.fillMaxWidth(), enabled = name.isNotBlank())
        }
    }
}

/** A refusal from the server, or a name problem here, in the screen's words. */
fun viewWords(res: Resources, problem: com.echoledger.mobile.data.ViewProblem): String = when (problem.kind) {
    com.echoledger.mobile.data.ViewProblem.Kind.NOT_SAVED_FULL -> res.getString(R.string.v2_lf_refused_full, problem.name)
    com.echoledger.mobile.data.ViewProblem.Kind.NOT_SAVED -> res.getString(R.string.v2_lf_refused_save, problem.name)
    com.echoledger.mobile.data.ViewProblem.Kind.NOT_CHANGED -> res.getString(R.string.v2_lf_refused_change, problem.name)
    com.echoledger.mobile.data.ViewProblem.Kind.NOT_DELETED -> res.getString(R.string.v2_lf_refused_delete, problem.name)
    com.echoledger.mobile.data.ViewProblem.Kind.GONE -> res.getString(R.string.v2_lf_refused_gone, problem.name)
    com.echoledger.mobile.data.ViewProblem.Kind.ORDER_NOT_SAVED -> res.getString(R.string.v2_lf_refused_order)
}

fun viewWords(res: Resources, error: com.echoledger.mobile.data.ViewError): String = when (error.reason) {
    com.echoledger.mobile.data.ViewError.Reason.NOT_READY -> res.getString(R.string.v2_lf_not_ready)
    com.echoledger.mobile.data.ViewError.Reason.FULL -> res.getString(R.string.v2_lf_full)
    com.echoledger.mobile.data.ViewError.Reason.EMPTY -> res.getString(R.string.v2_lf_name_empty)
    com.echoledger.mobile.data.ViewError.Reason.TOO_LONG -> res.getString(R.string.v2_lf_name_long)
    com.echoledger.mobile.data.ViewError.Reason.TAKEN -> res.getString(R.string.v2_lf_name_taken, error.name)
    com.echoledger.mobile.data.ViewError.Reason.GONE -> res.getString(R.string.v2_lf_gone)
}

/** A view's sort and group in this app's words ("name" is Name A–Z, "source" is App / Type); one it lacks is the default. */
object ViewWords {
    fun sortIn(wire: String): String = if (wire == "name") "title" else wire

    fun sortOut(key: String): String = if (key == "title") "name" else key

    fun groupIn(wire: String): String = if (wire == "source") "app" else wire

    fun groupOut(key: String): String = if (key == "app") "source" else key
}
