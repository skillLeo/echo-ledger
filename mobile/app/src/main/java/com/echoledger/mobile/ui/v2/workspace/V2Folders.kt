package com.echoledger.mobile.ui.v2.workspace

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextDirection
import com.echoledger.mobile.R
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.RequestLinkDto
import com.echoledger.mobile.data.SharedItemDto
import com.echoledger.mobile.ui.LinkExpiry
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.ProjectTree
import com.echoledger.mobile.ui.SmartFolder
import com.echoledger.mobile.ui.copyLink
import com.echoledger.mobile.ui.shareLink
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoSelect
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.library.LINK_DAYS
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.RowSourceLogo
import com.echoledger.mobile.ui.v2.library.shareDate
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.ZonedDateTime
import kotlinx.coroutines.launch

/** A smart folder's name in the screen's language. */
@Composable
fun folderName(folder: SmartFolder): String = stringResource(
    when (folder) {
        SmartFolder.INBOX -> R.string.v2_fold_inbox
        SmartFolder.WHATSAPP -> R.string.v2_fold_whatsapp
        SmartFolder.WHATSAPP_BUSINESS -> R.string.v2_fold_whatsapp_business
        SmartFolder.GOOGLE_MEET -> R.string.v2_fold_google_meet
        SmartFolder.ZOOM -> R.string.v2_fold_zoom
        SmartFolder.TEAMS -> R.string.v2_fold_teams
        SmartFolder.PHONE -> R.string.v2_fold_phone
        SmartFolder.VOICE_NOTES -> R.string.v2_fold_voice_notes
        SmartFolder.IMPORTED -> R.string.v2_fold_imported
        SmartFolder.SHARED -> R.string.v2_fold_shared
    }
)

/** A smart folder's icon, from the one icon set. */
fun folderIcon(folder: SmartFolder): ImageVector = when (folder) {
    SmartFolder.INBOX -> EchoIcons.Inbox
    SmartFolder.WHATSAPP -> EchoIcons.WhatsApp
    SmartFolder.WHATSAPP_BUSINESS -> EchoIcons.WhatsAppBusiness
    SmartFolder.GOOGLE_MEET, SmartFolder.ZOOM -> EchoIcons.Meeting
    SmartFolder.TEAMS -> EchoIcons.Groups
    SmartFolder.PHONE -> EchoIcons.PhoneCall
    SmartFolder.VOICE_NOTES -> EchoIcons.VoiceNote
    SmartFolder.IMPORTED -> EchoIcons.Imported
    SmartFolder.SHARED -> EchoIcons.SharedWithMe
}

/**
 * The Folders of the Projects list (classic's rule, [SmartFolder.listed]): No project always, Shared with me when the
 * server has it, the others once they hold something, each with how many it holds.
 */
@Composable
fun FolderRows(folders: List<Pair<SmartFolder, Int?>>, onOpen: (SmartFolder) -> Unit) {
    folders.forEach { (folder, count) ->
        EchoListRow(
            title = folderName(folder),
            meta = count?.let { recordingsCount(it) },
            leading = { EchoAvatar(icon = folderIcon(folder)) },
            onClick = { onOpen(folder) }
        )
    }
}

/**
 * A smart folder's page (classic's FolderScreen on the new components): the recordings it holds, worked out on this
 * phone or, for Imported, read from the server; Shared with me lists what teammates sent, with Open, Save to my library
 * and Remove. Loading, empty and could-not-load each say so; Retry asks again.
 */
@Composable
fun V2FolderPage(vm: MainViewModel, folder: SmartFolder, onBack: () -> Unit, onOpenRecording: (LibraryItem) -> Unit) {
    val library by vm.library.collectAsState()
    var server by remember(folder) { mutableStateOf<List<LibraryItem>?>(null) }
    var shared by remember(folder) { mutableStateOf<List<SharedItemDto>?>(null) }
    var failed by remember(folder) { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(folder, reload) {
        failed = false
        when (folder) {
            SmartFolder.SHARED -> vm.sharedWithMe().let {
                shared = it
                failed = it == null
            }

            SmartFolder.IMPORTED -> vm.libraryFolder(folder.key).let {
                server = it
                failed = it == null
            }

            else -> Unit
        }
    }
    val name = folderName(folder)
    Column(Modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(name, onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            when {
                failed -> EchoErrorState(
                    stringResource(R.string.v2_fold_failed_title, name),
                    stringResource(R.string.v2_fold_failed_body),
                    actionLabel = stringResource(R.string.v2_fold_retry),
                    onAction = { reload++ }
                )

                folder == SmartFolder.SHARED -> {
                    val list = shared
                    when {
                        list == null -> EchoSkeletonList(3)

                        list.isEmpty() -> EchoEmptyState(
                            stringResource(R.string.v2_fold_shared_empty_title),
                            stringResource(R.string.v2_fold_shared_empty_body),
                            icon = EchoIcons.SharedWithMe
                        )

                        else -> list.forEach { item ->
                            SharedRow(
                                item,
                                onOpen = onOpenRecording,
                                onSave = { scope.launch { if (vm.saveShared(item.id)) reload++ } },
                                onRemove = { scope.launch { if (vm.removeShared(item.id)) reload++ } }
                            )
                        }
                    }
                }

                else -> {
                    val list = if (folder.server) server else library.filter(folder::matches).sortedByDescending { it.startedAt }
                    when {
                        list == null -> EchoSkeletonList(3)

                        list.isEmpty() -> EchoEmptyState(
                            stringResource(R.string.v2_fold_empty_title, name),
                            when (folder) {
                                SmartFolder.INBOX -> stringResource(R.string.v2_fold_inbox_empty)
                                SmartFolder.IMPORTED -> stringResource(R.string.v2_fold_imported_empty)
                                else -> stringResource(R.string.v2_fold_app_empty, name)
                            },
                            icon = folderIcon(folder)
                        )

                        else -> RecordingRows(list, onOpenRecording)
                    }
                }
            }
        }
    }
}

@Composable
private fun RecordingRows(items: List<LibraryItem>, onOpen: (LibraryItem) -> Unit) {
    val text = v2Text
    val locale = screenLocale()
    val now = remember(items) { ZonedDateTime.now() }
    val c = EchoV2.colors
    items.forEach { item ->
        val row = remember(item, text, locale) { LibraryModel.row(item, now, text, locale) }
        EchoListRow(
            title = row.title,
            meta = row.meta,
            titleEnd = row.time,
            metaEnd = { Text(row.duration, style = EchoV2.type.mono, color = c.textSecondary) },
            leading = { RowSourceLogo(row) },
            tall = true,
            onClick = { onOpen(item) }
        )
    }
}

/** One thing a teammate sent: its title, "From Ayesha · 20 Oct 2026 · their note", then Open, Save or Saved, Remove. */
@Composable
private fun SharedRow(item: SharedItemDto, onOpen: (LibraryItem) -> Unit, onSave: () -> Unit, onRemove: () -> Unit) {
    val c = EchoV2.colors
    val locale = screenLocale()
    val title = item.title ?: item.session?.title ?: item.client?.name ?: stringResource(R.string.v2_fold_shared_item)
    val from = item.from?.name ?: item.from?.email ?: stringResource(R.string.v2_fold_a_teammate)
    val meta = listOfNotNull(
        stringResource(R.string.v2_fold_from, from),
        item.sentAt?.let { shareDate(it, locale) },
        item.note?.takeIf { it.isNotBlank() }
    ).joinToString(" · ")
    Column(Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)) {
        Text(title, style = EchoV2.type.content(title, EchoV2.type.bodyStrong), color = c.textPrimary)
        Text(meta, style = EchoV2.type.content(meta, EchoV2.type.meta), color = c.textSecondary)
        Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            // Each action is named with what it acts on, so TalkBack never hears three bare "Remove"s.
            val openLabel = stringResource(R.string.v2_fold_open_named, title)
            val saveLabel = stringResource(R.string.v2_fold_save_named, title)
            val removeLabel = stringResource(R.string.v2_fold_remove_named, title)
            item.session?.let { s ->
                EchoButton(
                    stringResource(R.string.v2_fold_open),
                    { onOpen(s) },
                    Modifier.semantics { contentDescription = openLabel },
                    kind = EchoButtonKind.Quiet
                )
            }
            if (item.savedAt == null) {
                EchoButton(
                    stringResource(R.string.v2_fold_save),
                    onSave,
                    Modifier.semantics { contentDescription = saveLabel },
                    kind = EchoButtonKind.Quiet
                )
            } else {
                Text(
                    stringResource(R.string.v2_fold_saved),
                    style = EchoV2.type.label,
                    color = c.textSecondary,
                    modifier = Modifier.padding(vertical = EchoTokens.Space.s12, horizontal = EchoTokens.Space.s8)
                )
            }
            EchoButton(
                stringResource(R.string.v2_fold_remove),
                onRemove,
                Modifier.semantics { contentDescription = removeLabel },
                kind = EchoButtonKind.Quiet
            )
        }
    }
}

/** A request link's line: "Off · Acme · 3 files received · until 29 Nov 2026", in the screen's words. */
@Composable
private fun requestMeta(link: RequestLinkDto, projects: List<ClientDto>): String {
    val res = LocalContext.current.resources
    val locale = screenLocale()
    return listOfNotNull(
        if (link.revokedAt != null) res.getString(R.string.v2_req_off) else null,
        projects.firstOrNull { it.id == link.clientId }?.name ?: res.getString(R.string.v2_fold_inbox),
        res.getQuantityString(R.plurals.v2_req_received, link.received, link.received),
        link.expiresAt?.let { res.getString(R.string.v2_req_until, shareDate(it, locale)) }
    ).joinToString(" · ")
}

/**
 * Request files (classic's RequestLinksScreen on the new components): a request link lets anyone send voice notes and
 * audio (and files, for a project) into No project or a project. New request link takes the title they see, where the
 * files go and how long it works; the new link is shown once with Copy and Share…; Turn off asks first, as it stops
 * the link for everyone who has it.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun V2RequestsPage(vm: MainViewModel, onBack: () -> Unit) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    val toasts = LocalEchoToasts.current
    val clients by vm.clients.collectAsState()
    val projects = remember(clients) { ProjectTree.flatten(clients).map { it.first } }
    var links by remember { mutableStateOf<List<RequestLinkDto>?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var creating by remember { mutableStateOf(false) }
    var title by remember { mutableStateOf("") }
    var projectId by remember { mutableStateOf<String?>(null) }
    var days by remember { mutableIntStateOf(30) }
    var working by remember { mutableStateOf(false) }
    var made by remember { mutableStateOf<RequestLinkDto?>(null) }
    var turningOff by remember { mutableStateOf<String?>(null) }
    var failed by remember { mutableStateOf(false) }
    LaunchedEffect(reload) {
        val answer = vm.requestLinks()
        failed = answer == null
        if (answer != null) links = answer
    }
    turningOff?.let { id ->
        EchoDialog(
            title = stringResource(R.string.v2_req_turn_off_title),
            text = stringResource(R.string.v2_req_turn_off_question),
            confirmLabel = stringResource(R.string.v2_req_turn_off),
            onConfirm = {
                turningOff = null
                scope.launch { if (vm.revokeRequestLink(id)) reload++ }
            },
            dismissLabel = stringResource(R.string.v2_cancel),
            onDismiss = { turningOff = null },
            destructive = true
        )
    }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    Column(Modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_req_title), onBack = onBack) {
            EchoIconButton(EchoIcons.Add, stringResource(R.string.v2_req_new), { creating = true })
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            Text(stringResource(R.string.v2_req_intro), style = EchoV2.type.body, color = c.textSecondary, modifier = pad)
            if (creating) {
                EchoCard(pad) {
                    Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                        EchoTextField(title, { title = it.take(TITLE_LIMIT) }, stringResource(R.string.v2_req_title_they_see))
                        val inbox = stringResource(R.string.v2_fold_inbox)
                        EchoSelect(
                            stringResource(R.string.v2_req_files_go_to),
                            listOf<String?>(null) + projects.map { it.id },
                            projectId,
                            { projectId = it },
                            { id -> projects.firstOrNull { it.id == id }?.name ?: inbox }
                        )
                        Text(stringResource(R.string.v2_share_works_for), style = EchoV2.type.label, color = c.textSecondary)
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                            LINK_DAYS.forEach { (d, words) ->
                                EchoChip(stringResource(words), selected = days == d, onClick = { days = d })
                            }
                        }
                        EchoButton(
                            stringResource(R.string.v2_req_make),
                            onClick = {
                                working = true
                                scope.launch {
                                    vm.createRequestLink(title, projectId, LinkExpiry.at(days))?.let {
                                        made = it
                                        creating = false
                                        title = ""
                                        reload++
                                    }
                                    working = false
                                }
                            },
                            enabled = title.isNotBlank(),
                            loading = working,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                }
            }
            made?.url?.let { url ->
                EchoCard(pad) {
                    Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                        // A link reads left to right in every language.
                        Text(url, style = EchoV2.type.mono.copy(textDirection = TextDirection.Ltr), color = c.textPrimary)
                        Text(stringResource(R.string.v2_share_shown_once), style = EchoV2.type.meta, color = c.textSecondary)
                        val copied = stringResource(R.string.v2_share_copied)
                        Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                            EchoButton(stringResource(R.string.v2_share_copy), {
                                copyLink(context, url)
                                scope.launch { toasts.show(copied) }
                            }, kind = EchoButtonKind.Secondary)
                            EchoButton(stringResource(R.string.v2_share_share), { shareLink(context, url) }, kind = EchoButtonKind.Quiet)
                        }
                    }
                }
            }
            val list = links
            when {
                // Could not be read: say so with Retry, never "No request links".
                failed && list == null -> EchoErrorState(
                    stringResource(R.string.v2_fold_failed_title, stringResource(R.string.v2_req_title)),
                    stringResource(R.string.v2_fold_failed_body),
                    actionLabel = stringResource(R.string.v2_fold_retry),
                    onAction = { reload++ }
                )

                list == null -> EchoSkeletonList(2, avatar = false)

                list.isEmpty() && !creating -> EchoEmptyState(
                    stringResource(R.string.v2_req_empty_title),
                    stringResource(R.string.v2_req_empty_body),
                    icon = EchoIcons.Link,
                    actionLabel = stringResource(R.string.v2_req_new),
                    onAction = { creating = true }
                )

                else -> list.forEach { link ->
                    EchoListRow(
                        title = link.title ?: stringResource(R.string.v2_req_link),
                        meta = requestMeta(link, projects),
                        leading = { EchoAvatar(icon = EchoIcons.Link) },
                        trailing = if (link.revokedAt == null) {
                            {
                                val named = stringResource(
                                    R.string.v2_req_turn_off_named,
                                    link.title ?: stringResource(R.string.v2_req_link)
                                )
                                EchoButton(
                                    stringResource(R.string.v2_req_turn_off),
                                    { turningOff = link.id },
                                    Modifier.semantics { contentDescription = named },
                                    kind = EchoButtonKind.Quiet
                                )
                            }
                        } else {
                            null
                        }
                    )
                }
            }
        }
    }
}

private const val TITLE_LIMIT = 120
