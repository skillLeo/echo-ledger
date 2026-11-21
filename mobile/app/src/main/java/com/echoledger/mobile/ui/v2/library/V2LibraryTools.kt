package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Bookmarks
import com.echoledger.mobile.core.Density
import com.echoledger.mobile.core.GroupBy
import com.echoledger.mobile.core.LibraryFilters
import com.echoledger.mobile.core.LibraryFilters.Companion.toggle
import com.echoledger.mobile.core.LibrarySort
import com.echoledger.mobile.core.LibraryViewChoice
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.SmartView
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.ProvidePageLook
import com.echoledger.mobile.ui.v2.theme.rememberPageLook
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** The actions of Library's selection mode (docs/PLAN_V2_PARITY.md section 3), the same as classic's selection bar. */
sealed interface SelectionAction {
    data class Move(val clientId: String?) : SelectionAction
    data class NewProject(val name: String) : SelectionAction
    data class Label(val label: String, val add: Boolean) : SelectionAction
    data class Pin(val on: Boolean) : SelectionAction
    data class Keep(val on: Boolean) : SelectionAction
    data class Export(val format: String) : SelectionAction
    data class Rename(val title: String) : SelectionAction
    data object Delete : SelectionAction
}

/** The export formats, in classic's order (Organise.EXPORT_FORMATS), with their names. */
@Composable
fun exportName(format: String): String = stringResource(
    when (format) {
        "docx" -> R.string.v2_lib_fmt_docx
        "txt" -> R.string.v2_lib_fmt_txt
        "md" -> R.string.v2_lib_fmt_md
        "srt" -> R.string.v2_lib_fmt_srt
        "vtt" -> R.string.v2_lib_fmt_vtt
        else -> R.string.v2_lib_fmt_json
    }
)

/**
 * Which of Pin / Unpin and Keep forever / Allow auto clean-up the selection's ⋮ offers: only the one that applies, both
 * only when the selection is mixed (some pinned and some not).
 */
data class OrganiseChoices(val pin: Boolean, val unpin: Boolean, val keep: Boolean, val unkeep: Boolean) {
    companion object {
        /** Every action, for a selection whose recordings are not known. */
        val ALL = OrganiseChoices(pin = true, unpin = true, keep = true, unkeep = true)

        fun of(items: List<com.echoledger.mobile.data.LibraryItem>): OrganiseChoices {
            if (items.isEmpty()) return ALL
            return OrganiseChoices(
                pin = items.any { !it.pinned },
                unpin = items.any { it.pinned },
                keep = items.any { !it.keep },
                unkeep = items.any { it.keep }
            )
        }
    }
}

/**
 * Selection mode's top bar: ✕, "3 selected" on one line, three actions as icons (Move to project, Label, Delete) and
 * the rest in ⋮: Select all or Unselect all, Share; Organise (Pin or Unpin, Keep forever or Allow auto clean-up, as
 * [choices] says, and Rename for one); Export ▸ with each format; Delete last.
 */
@Composable
fun SelectionBar(
    count: Int,
    allSelected: Boolean,
    oneTitle: String?,
    folders: List<ClientDto>,
    labels: List<String>,
    keepHours: Int,
    onClose: () -> Unit,
    onSelectAll: (Boolean) -> Unit,
    onAction: (SelectionAction) -> Unit,
    choices: OrganiseChoices = OrganiseChoices.ALL
) {
    val c = EchoV2.colors
    var sheet by remember { mutableStateOf<String?>(null) }
    var menu by remember { mutableStateOf(false) }
    var exportOpen by remember { mutableStateOf(false) }
    fun close() {
        menu = false
        exportOpen = false
    }
    Row(
        Modifier.fillMaxWidth().background(c.bgSurface).padding(horizontal = EchoTokens.Space.s4, vertical = EchoTokens.Space.s4),
        verticalAlignment = Alignment.CenterVertically
    ) {
        EchoIconButton(EchoIcons.Close, stringResource(R.string.v2_lib_close_selection), onClose)
        Text(
            stringResource(R.string.v2_lib_selected, count),
            style = EchoV2.type.titleS,
            color = c.textPrimary,
            maxLines = 1,
            overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f).semantics { heading() }
        )
        EchoIconButton(EchoIcons.Move, stringResource(R.string.v2_lib_move), { sheet = "move" })
        EchoIconButton(EchoIcons.Label, stringResource(R.string.v2_lib_label), { sheet = "label" })
        EchoIconButton(EchoIcons.Delete, stringResource(R.string.v2_lib_delete), { sheet = "delete" })
        Box {
            EchoIconButton(EchoIcons.More, stringResource(R.string.v2_lib_more), {
                exportOpen = false
                menu = true
            })
            val look = rememberPageLook()
            DropdownMenu(expanded = menu, onDismissRequest = ::close) {
                ProvidePageLook(look) {
                    if (exportOpen) {
                        // TODO-DS: a sub-menu in the shared menu component; until then Export ▸ swaps the menu for its formats.
                        SubMenuHeader(stringResource(R.string.v2_lib_export_header)) { exportOpen = false }
                        Organise.EXPORT_FORMATS.forEach { (format, _) ->
                            MenuItem(EchoIcons.Export, exportName(format)) {
                                close()
                                onAction(SelectionAction.Export(format))
                            }
                        }
                        return@ProvidePageLook
                    }
                    MenuItem(
                        EchoIcons.SelectAll,
                        stringResource(if (allSelected) R.string.v2_lib_unselect_all else R.string.v2_lib_select_all)
                    ) {
                        close()
                        onSelectAll(!allSelected)
                    }
                    MenuItem(EchoIcons.Share, stringResource(R.string.v2_lib_share)) {
                        close()
                        sheet = "export"
                    }
                    MenuHeader(stringResource(R.string.v2_lib_organise))
                    if (choices.pin) {
                        MenuItem(EchoIcons.Pin, stringResource(R.string.v2_lib_pin)) {
                            close()
                            onAction(SelectionAction.Pin(true))
                        }
                    }
                    if (choices.unpin) {
                        MenuItem(EchoIcons.Unpin, stringResource(R.string.v2_lib_unpin)) {
                            close()
                            onAction(SelectionAction.Pin(false))
                        }
                    }
                    if (choices.keep) {
                        MenuItem(EchoIcons.Keep, stringResource(R.string.v2_lib_keep)) {
                            close()
                            onAction(SelectionAction.Keep(true))
                        }
                    }
                    if (choices.unkeep) {
                        MenuItem(com.echoledger.mobile.ui.v2.shell.PendingIcons.AutoDelete, stringResource(R.string.v2_lib_unkeep)) {
                            close()
                            onAction(SelectionAction.Keep(false))
                        }
                    }
                    if (oneTitle != null) {
                        MenuItem(EchoIcons.Rename, stringResource(R.string.v2_lib_rename)) {
                            close()
                            sheet = "rename"
                        }
                    }
                    SubMenuItem(EchoIcons.Export, stringResource(R.string.v2_lib_export_header)) { exportOpen = true }
                    MenuItem(EchoIcons.Delete, stringResource(R.string.v2_lib_delete), danger = true) {
                        close()
                        sheet = "delete"
                    }
                }
            }
        }
    }
    when (sheet) {
        "move" -> MoveSheet(folders, { sheet = null }) {
            sheet = null
            onAction(it)
        }

        "label" -> LabelSheet(labels, { sheet = null }) {
            sheet = null
            onAction(it)
        }

        "export" -> EchoBottomSheet(onDismiss = { sheet = null }, title = stringResource(R.string.v2_lib_export_as)) {
            Organise.EXPORT_FORMATS.forEach { (format, _) ->
                EchoSettingRow(exportName(format), icon = EchoIcons.Export, onClick = {
                    sheet = null
                    onAction(SelectionAction.Export(format))
                })
            }
        }

        "rename" -> SelectionRenameSheet(oneTitle.orEmpty(), { sheet = null }) {
            sheet = null
            onAction(SelectionAction.Rename(it))
        }

        "delete" -> EchoDialog(
            title = if (count ==
                1
            ) {
                stringResource(R.string.v2_lib_delete_one_title)
            } else {
                stringResource(R.string.v2_lib_delete_title, count)
            },
            text = stringResource(
                R.string.v2_lib_delete_body,
                keepWords(androidx.compose.ui.platform.LocalContext.current.resources, keepHours)
            ),
            confirmLabel = stringResource(R.string.v2_lib_delete_confirm, count),
            onConfirm = {
                sheet = null
                onAction(SelectionAction.Delete)
            },
            dismissLabel = stringResource(R.string.v2_lib_cancel),
            onDismiss = { sheet = null },
            destructive = true
        )
    }
}

@Composable
private fun MenuHeader(words: String) = Text(
    words,
    style = EchoV2.type.captionStrong,
    color = EchoV2.colors.textSecondary,
    modifier = Modifier.padding(horizontal = EchoTokens.Space.s16, vertical = EchoTokens.Space.s8).semantics { heading() }
)

@Composable
private fun MenuItem(icon: ImageVector, words: String, danger: Boolean = false, onClick: () -> Unit) {
    val tint = if (danger) EchoV2.colors.danger else EchoV2.colors.textPrimary
    DropdownMenuItem(
        text = { Text(words, style = EchoV2.type.body, color = tint) },
        leadingIcon = { Icon(icon, null, tint = tint) },
        onClick = onClick
    )
}

/** A menu row that opens a sub-menu: its icon, its words and ▸ (mirrored in right-to-left). */
// TODO-DS: belongs with the shared menu component.
@Composable
private fun SubMenuItem(icon: ImageVector, words: String, onClick: () -> Unit) {
    val tint = EchoV2.colors.textPrimary
    DropdownMenuItem(
        text = { Text(words, style = EchoV2.type.body, color = tint) },
        leadingIcon = { Icon(icon, null, tint = tint) },
        trailingIcon = { Icon(EchoIcons.Next, null, tint = EchoV2.colors.textSecondary) },
        onClick = onClick
    )
}

/** A sub-menu's first row: back (‹, mirrored in right-to-left) and the sub-menu's name; it goes back to the menu. */
// TODO-DS: belongs with the shared menu component.
@Composable
private fun SubMenuHeader(words: String, onBack: () -> Unit) {
    val back = stringResource(R.string.v2_back)
    DropdownMenuItem(
        text = { Text(words, style = EchoV2.type.captionStrong, color = EchoV2.colors.textSecondary) },
        leadingIcon = { Icon(EchoIcons.Previous, null, tint = EchoV2.colors.textSecondary) },
        onClick = onBack,
        modifier = Modifier.semantics { contentDescription = "$back, $words" }
    )
}

@Composable
private fun MoveSheet(folders: List<ClientDto>, onDismiss: () -> Unit, onAction: (SelectionAction) -> Unit) {
    var naming by remember { mutableStateOf(false) }
    var name by remember { mutableStateOf("") }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_lib_move_to)) {
        EchoSettingRow(stringResource(R.string.v2_lib_no_project), icon = EchoIcons.Library, onClick = {
            onAction(SelectionAction.Move(null))
        })
        folders.forEach { f ->
            EchoSettingRow(f.name, icon = EchoIcons.Folder, content = true, onClick = { onAction(SelectionAction.Move(f.id)) })
        }
        if (!naming) {
            EchoSettingRow(stringResource(R.string.v2_lib_new_project), icon = EchoIcons.Add, onClick = { naming = true })
        } else {
            Row(
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                verticalAlignment = Alignment.Bottom,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
            ) {
                EchoTextField(name, { name = it.take(80) }, stringResource(R.string.v2_lib_project_name), Modifier.weight(1f))
                EchoButton(stringResource(R.string.v2_lib_create), {
                    onAction(SelectionAction.NewProject(name.trim()))
                }, enabled = name.isNotBlank())
            }
        }
    }
}

@Composable
private fun LabelSheet(labels: List<String>, onDismiss: () -> Unit, onAction: (SelectionAction) -> Unit) {
    var label by remember { mutableStateOf("") }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_lib_labels)) {
        Row(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalAlignment = Alignment.Bottom,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            EchoTextField(label, {
                label = it.take(Organise.MAX_LABEL_CHARS)
            }, stringResource(R.string.v2_lib_new_label), Modifier.weight(1f))
            EchoButton(stringResource(R.string.v2_lib_add), {
                onAction(SelectionAction.Label(label.trim(), true))
            }, enabled = label.isNotBlank())
        }
        labels.forEach { l ->
            Row(
                Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(l, style = EchoV2.type.content(l, EchoV2.type.body), color = EchoV2.colors.textPrimary, modifier = Modifier.weight(1f))
                val add = stringResource(R.string.v2_lib_add_label, l)
                val remove = stringResource(R.string.v2_lib_remove_label, l)
                EchoButton(
                    stringResource(R.string.v2_lib_add),
                    { onAction(SelectionAction.Label(l, true)) },
                    Modifier.semantics { contentDescription = add },
                    kind = EchoButtonKind.Quiet
                )
                EchoButton(
                    stringResource(R.string.v2_lib_remove),
                    { onAction(SelectionAction.Label(l, false)) },
                    Modifier.semantics { contentDescription = remove },
                    kind = EchoButtonKind.Quiet
                )
            }
        }
    }
}

@Composable
private fun SelectionRenameSheet(title: String, onDismiss: () -> Unit, onSave: (String) -> Unit) {
    var words by remember { mutableStateOf(title) }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_lib_rename)) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            EchoTextField(words, { words = it.take(200) }, stringResource(R.string.v2_lib_title))
            EchoButton(stringResource(R.string.v2_lib_save), {
                onSave(words.trim())
            }, Modifier.fillMaxWidth(), enabled = words.isNotBlank())
        }
    }
}

/** Library's filters beyond classic's chips: the saved views (Untranscribed) the classic filters don't have. */
data class V2Filters(val base: LibraryFilters = LibraryFilters(), val untranscribed: Boolean = false) {
    val count: Int get() = base.count + if (untranscribed) 1 else 0
}

/** The chip a filter sheet belongs to. */
enum class FilterChip { Views, Source, Date, Project, Language, Status, Label, Bookmark }

/**
 * One scrollable chip row (docs/PLAN_V2_PARITY.md section 3): Views ▾ · Source ▾ · Date ▾ · Project ▾ · Language ▾ ·
 * Status ▾ · Label ▾ · Bookmark ▾ (Label and Bookmark only when there are some), each with how many choices are on;
 * "Clear all" while any is. Each opens a sheet with its choices, Clear and Apply.
 */
@Composable
fun LibraryFilterRow(
    filters: V2Filters,
    folders: List<ClientDto>,
    labels: List<String>,
    tags: List<String>,
    languages: List<String>,
    onChange: (V2Filters) -> Unit
) {
    var open by remember { mutableStateOf<FilterChip?>(null) }
    val f = filters.base
    Row(
        Modifier.fillMaxWidth().horizontalScroll(rememberScrollState())
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        fun count(chip: FilterChip): Int = when (chip) {
            FilterChip.Views -> (if (filters.untranscribed) 1 else 0) + f.views.count { it in VIEWS }
            FilterChip.Source -> f.devices.size
            FilterChip.Date -> f.views.count { it in DATES }
            FilterChip.Project -> f.folders.size
            FilterChip.Language -> f.languages.size
            FilterChip.Status -> f.views.count { it in STATUSES }
            FilterChip.Label -> f.labels.size
            FilterChip.Bookmark -> f.tags.size
        }
        val chips = FilterChip.entries.filter {
            (it != FilterChip.Label || labels.isNotEmpty()) && (it != FilterChip.Bookmark || tags.isNotEmpty()) &&
                (it != FilterChip.Language || languages.isNotEmpty())
        }
        chips.forEach { chip ->
            val n = count(chip)
            val name = chipName(chip)
            EchoChip(
                if (n > 0) stringResource(R.string.v2_lib_with_count, name, n) + " ▾" else "$name ▾",
                n > 0,
                { open = chip },
                // An applied chip's ✕ clears only its own choices.
                onClear = if (n > 0) ({ onChange(clearChip(chip, filters)) }) else null,
                clearLabel = stringResource(R.string.v2_lib_clear_chip, name)
            )
        }
        if (filters.count > 0) {
            Text(
                stringResource(R.string.v2_lib_clear_all),
                style = EchoV2.type.label,
                color = EchoV2.colors.accentText,
                modifier = Modifier.heightIn(min = EchoTokens.Layout.controlTouchMin)
                    .clickable(role = Role.Button) { onChange(V2Filters()) }
                    .padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s12)
            )
        }
    }
    open?.let { chip ->
        FilterSheet(chip, filters, folders, labels, tags, languages, { open = null }) {
            onChange(it)
            open = null
        }
    }
}

private val VIEWS = setOf(SmartView.PINNED, SmartView.BOOKMARKED)
private val DATES = setOf(SmartView.TODAY, SmartView.THIS_WEEK)
private val STATUSES = setOf(SmartView.WAITING, SmartView.FAILED)

@Composable
private fun chipName(chip: FilterChip): String = stringResource(
    when (chip) {
        FilterChip.Views -> R.string.v2_lib_views
        FilterChip.Source -> R.string.v2_lib_source
        FilterChip.Date -> R.string.v2_lib_date
        FilterChip.Project -> R.string.v2_lib_project
        FilterChip.Language -> R.string.v2_lib_language
        FilterChip.Status -> R.string.v2_lib_status
        FilterChip.Label -> R.string.v2_lib_label
        FilterChip.Bookmark -> R.string.v2_lib_bookmark
    }
)

@Composable
fun smartViewName(view: SmartView): String = stringResource(
    when (view) {
        SmartView.TODAY -> R.string.v2_lib_today
        SmartView.THIS_WEEK -> R.string.v2_lib_this_week
        SmartView.WAITING -> R.string.v2_lib_waiting
        SmartView.BOOKMARKED -> R.string.v2_lib_bookmarked
        SmartView.FAILED -> R.string.v2_lib_failed
        SmartView.PINNED -> R.string.v2_lib_pinned
        SmartView.ALL -> R.string.v2_lib_views
    }
)

@Composable
private fun FilterSheet(
    chip: FilterChip,
    filters: V2Filters,
    folders: List<ClientDto>,
    labels: List<String>,
    tags: List<String>,
    languages: List<String>,
    onDismiss: () -> Unit,
    onApply: (V2Filters) -> Unit
) {
    var draft by remember { mutableStateOf(filters) }
    val f = draft.base
    fun base(change: (LibraryFilters) -> LibraryFilters) {
        draft = draft.copy(base = change(draft.base))
    }
    EchoBottomSheet(onDismiss = onDismiss, title = chipName(chip)) {
        when (chip) {
            FilterChip.Views -> {
                EchoCheckbox(draft.untranscribed, { draft = draft.copy(untranscribed = it) }, v2TextUntranscribed())
                VIEWS.forEach { v -> EchoCheckbox(v in f.views, { base { b -> b.copy(views = b.views.toggle(v)) } }, smartViewName(v)) }
            }

            FilterChip.Date -> DATES.forEach { v ->
                EchoCheckbox(v in f.views, { base { b -> b.copy(views = b.views.toggle(v)) } }, smartViewName(v))
            }

            FilterChip.Status -> STATUSES.forEach { v ->
                EchoCheckbox(v in f.views, { base { b -> b.copy(views = b.views.toggle(v)) } }, smartViewName(v))
            }

            FilterChip.Source -> SourceDevice.entries.forEach { d ->
                EchoCheckbox(d in f.devices, { base { b -> b.copy(devices = b.devices.toggle(d)) } }, deviceName(d))
            }

            FilterChip.Project -> {
                EchoCheckbox("" in f.folders, {
                    base { b -> b.copy(folders = b.folders.toggle("")) }
                }, stringResource(R.string.v2_lib_no_project))
                folders.forEach { p -> EchoCheckbox(p.id in f.folders, { base { b -> b.copy(folders = b.folders.toggle(p.id)) } }, p.name) }
            }

            FilterChip.Language -> languages.forEach { l ->
                EchoCheckbox(l in f.languages, { base { b -> b.copy(languages = b.languages.toggle(l)) } }, l)
            }

            FilterChip.Label -> labels.forEach { l ->
                EchoCheckbox(l in f.labels, { base { b -> b.copy(labels = b.labels.toggle(l)) } }, l)
            }

            FilterChip.Bookmark -> {
                EchoCheckbox(Bookmarks.ANY in f.tags, {
                    base { b -> b.copy(tags = b.tags.toggle(Bookmarks.ANY)) }
                }, stringResource(R.string.v2_lib_any_bookmark))
                tags.forEach { t -> EchoCheckbox(t in f.tags, { base { b -> b.copy(tags = b.tags.toggle(t)) } }, t) }
            }
        }
        Row(
            Modifier.fillMaxWidth().padding(EchoTokens.Space.s16),
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8, Alignment.End)
        ) {
            EchoButton(stringResource(R.string.v2_lib_clear), { onApply(clearChip(chip, draft)) }, kind = EchoButtonKind.Secondary)
            EchoButton(stringResource(R.string.v2_lib_apply), { onApply(draft) })
        }
    }
}

@Composable
private fun v2TextUntranscribed(): String = com.echoledger.mobile.ui.v2.shell.v2Text.untranscribed

@Composable
fun deviceName(d: SourceDevice): String = stringResource(
    when (d) {
        SourceDevice.PHONE -> R.string.v2_lib_phone
        SourceDevice.DESKTOP -> R.string.v2_lib_desktop
        SourceDevice.WEB -> R.string.v2_lib_web
    }
)

/** The filters with one chip's choices cleared. */
fun clearChip(chip: FilterChip, f: V2Filters): V2Filters = when (chip) {
    FilterChip.Views -> f.copy(untranscribed = false, base = f.base.copy(views = f.base.views - VIEWS))
    FilterChip.Date -> f.copy(base = f.base.copy(views = f.base.views - DATES))
    FilterChip.Status -> f.copy(base = f.base.copy(views = f.base.views - STATUSES))
    FilterChip.Source -> f.copy(base = f.base.copy(devices = emptySet()))
    FilterChip.Project -> f.copy(base = f.base.copy(folders = emptySet()))
    FilterChip.Language -> f.copy(base = f.base.copy(languages = emptySet()))
    FilterChip.Label -> f.copy(base = f.base.copy(labels = emptySet()))
    FilterChip.Bookmark -> f.copy(base = f.base.copy(tags = emptySet()))
}

/**
 * Sort and group (the button in the search row): Group by, Sort by and Density as chip groups (as classic has them),
 * so all three fit on one screen; remembered per view on this phone. One chip of each group is on.
 */
@Composable
fun SortGroupSheet(choice: LibraryViewChoice, onDismiss: () -> Unit, onChoose: (LibraryViewChoice) -> Unit) {
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_lib_sort_group)) {
        Column(Modifier.padding(bottom = EchoTokens.Space.s16)) {
            ChoiceGroup(stringResource(R.string.v2_lib_group_by), GroupBy.entries, choice.groupBy, { groupName(it) }) {
                onChoose(choice.copy(group = it.key))
            }
            ChoiceGroup(stringResource(R.string.v2_lib_sort_by), LibrarySort.entries, choice.sortBy, { sortName(it) }) {
                onChoose(choice.copy(sort = it.key))
            }
            ChoiceGroup(stringResource(R.string.v2_lib_density), Density.entries, choice.densityOf, { densityName(it) }) {
                onChoose(choice.copy(density = it.key))
            }
        }
    }
}

/** One group of the sheet: its heading and its choices as chips that wrap, the chosen one on. */
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun <T> ChoiceGroup(title: String, all: List<T>, chosen: T, name: @Composable (T) -> String, onPick: (T) -> Unit) {
    EchoSectionHeader(title)
    androidx.compose.foundation.layout.FlowRow(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        all.forEach { option -> EchoChip(name(option), option == chosen, { onPick(option) }) }
    }
}

@Composable
private fun groupName(g: GroupBy): String = stringResource(
    when (g) {
        GroupBy.DATE -> R.string.v2_lib_g_date
        GroupBy.APP -> R.string.v2_lib_g_app
        GroupBy.PROJECT -> R.string.v2_lib_g_project
        GroupBy.LANGUAGE -> R.string.v2_lib_g_language
        GroupBy.STATUS -> R.string.v2_lib_g_status
        GroupBy.DEVICE -> R.string.v2_lib_g_device
        GroupBy.NONE -> R.string.v2_lib_g_none
    }
)

@Composable
private fun sortName(s: LibrarySort): String = stringResource(
    when (s) {
        LibrarySort.NEWEST -> R.string.v2_lib_s_newest
        LibrarySort.OLDEST -> R.string.v2_lib_s_oldest
        LibrarySort.LONGEST -> R.string.v2_lib_s_longest
        LibrarySort.SHORTEST -> R.string.v2_lib_s_shortest
        LibrarySort.TITLE -> R.string.v2_lib_s_name
        LibrarySort.OPENED -> R.string.v2_lib_s_opened
    }
)

@Composable
private fun densityName(d: Density): String = stringResource(
    if (d ==
        Density.COMPACT
    ) {
        R.string.v2_lib_d_compact
    } else {
        R.string.v2_lib_d_comfortable
    }
)

/** The fixed English group names of LibraryGrouping, in the screen's language; other names (apps, projects) stay. */
@Composable
fun groupLabel(label: String): String = when (label) {
    "Pinned" -> stringResource(R.string.v2_lib_pinned)
    "No project" -> stringResource(R.string.v2_lib_no_project)
    "Other" -> stringResource(R.string.v2_lib_other)
    "Voice notes" -> stringResource(R.string.v2_lib_voice_notes)
    "Unknown device" -> stringResource(R.string.v2_lib_unknown_device)
    "Automatic" -> stringResource(R.string.v2_lib_automatic)
    "Ready" -> stringResource(R.string.v2_lib_ready)
    "Transcribing" -> stringResource(R.string.v2_lib_transcribing)
    "Needs you" -> stringResource(R.string.v2_lib_needs_you)
    else -> label
}

/**
 * A section's header: its name and count; tapping it folds or unfolds the section. In selection mode its box selects
 * the whole section (or long-press the header).
 */
@Composable
fun SectionHeader(
    name: String,
    count: Int,
    folded: Boolean,
    selecting: Boolean,
    allSelected: Boolean,
    onFold: () -> Unit,
    onSelect: () -> Unit
) {
    val c = EchoV2.colors
    val fold = stringResource(if (folded) R.string.v2_lib_unfold else R.string.v2_lib_fold, name)
    Row(
        Modifier.fillMaxWidth().background(c.bgCanvas)
            .heightIn(min = EchoTokens.Layout.controlTouchMin)
            .clickable(onClickLabel = fold, role = Role.Button, onClick = onFold)
            .semantics { heading() }
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        Icon(if (folded) EchoIcons.Unfold else EchoIcons.Expand, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
        Text(
            stringResource(R.string.v2_lib_with_count, name, count),
            style = EchoV2.type.captionStrong,
            color = c.textSecondary,
            modifier = Modifier.weight(1f)
        )
        if (selecting) {
            val select = stringResource(R.string.v2_lib_select_group, name)
            androidx.compose.material3.Checkbox(allSelected, { onSelect() }, Modifier.semantics { contentDescription = select })
        }
    }
}

/** Swipe right to pin or unpin (it springs back), swipe left to delete (with Undo); off while selecting. */
@Composable
fun SwipeRow(pinned: Boolean, enabled: Boolean, onPin: () -> Unit, onDelete: () -> Unit, content: @Composable () -> Unit) {
    val state = rememberSwipeToDismissBoxState()
    LaunchedEffect(state.currentValue) {
        when (state.currentValue) {
            SwipeToDismissBoxValue.StartToEnd -> {
                onPin()
                state.reset()
            }

            SwipeToDismissBoxValue.EndToStart -> {
                onDelete()
                state.reset()
            }

            SwipeToDismissBoxValue.Settled -> Unit
        }
    }
    val c = EchoV2.colors
    SwipeToDismissBox(
        state = state,
        enableDismissFromStartToEnd = enabled,
        enableDismissFromEndToStart = enabled,
        backgroundContent = {
            val toPin = state.dismissDirection == SwipeToDismissBoxValue.StartToEnd
            Row(
                Modifier.fillMaxSize().background(if (toPin) c.accentSoft else c.dangerSoft).padding(horizontal = EchoTokens.Space.s16),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = if (toPin) Arrangement.Start else Arrangement.End
            ) {
                val ink = if (toPin) c.accentText else c.danger
                Icon(if (toPin) EchoIcons.Pin else EchoIcons.Delete, null, tint = ink)
                Text(
                    stringResource(
                        if (!toPin) {
                            R.string.v2_lib_delete
                        } else if (pinned) {
                            R.string.v2_lib_unpin
                        } else {
                            R.string.v2_lib_pin
                        }
                    ),
                    style = EchoV2.type.label,
                    color = ink,
                    modifier = Modifier.padding(start = EchoTokens.Space.s8)
                )
            }
        }
    ) { Box(Modifier.background(c.bgCanvas)) { content() } }
}
