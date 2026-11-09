package com.echoledger.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Logout
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.AudioFile
import androidx.compose.material.icons.outlined.Badge
import androidx.compose.material.icons.outlined.Call
import androidx.compose.material.icons.outlined.ClosedCaption
import androidx.compose.material.icons.outlined.DataUsage
import androidx.compose.material.icons.outlined.Devices
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.outlined.Event
import androidx.compose.material.icons.outlined.FiberManualRecord
import androidx.compose.material.icons.outlined.HelpOutline
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.Key
import androidx.compose.material.icons.outlined.KeyboardVoice
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Palette
import androidx.compose.material.icons.outlined.PhoneAndroid
import androidx.compose.material.icons.outlined.PrivacyTip
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.Shield
import androidx.compose.material.icons.outlined.Spellcheck
import androidx.compose.material.icons.outlined.SportsEsports
import androidx.compose.material.icons.outlined.Storage
import androidx.compose.material.icons.outlined.Subtitles
import androidx.compose.material.icons.outlined.Translate
import androidx.compose.material.icons.outlined.Tune
import androidx.compose.material.icons.outlined.WarningAmber
import androidx.compose.material.icons.outlined.WorkspacePremium
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.core.SettingsCatalogue
import com.echoledger.mobile.core.SettingsEntry
import com.echoledger.mobile.core.SettingsGroup
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.dictation.DictationHistory
import com.echoledger.mobile.recording.RecordingService
import java.time.LocalDate

private fun pageIcon(id: String): ImageVector = when (id) {
    "calls" -> Icons.Outlined.Call
    "mic" -> Icons.Outlined.Mic
    "captions" -> Icons.Outlined.ClosedCaption
    "voice" -> Icons.Outlined.KeyboardVoice
    "language" -> Icons.Outlined.Translate
    "words" -> Icons.Outlined.Spellcheck
    "key" -> Icons.Outlined.Key
    "games" -> Icons.Outlined.SportsEsports
    "videos" -> Icons.Outlined.Subtitles
    "appearance" -> Icons.Outlined.Palette
    "notifications" -> Icons.Outlined.Notifications
    "lock" -> Icons.Outlined.Lock
    "storage" -> Icons.Outlined.Storage
    "plan" -> Icons.Outlined.DataUsage
    "account" -> Icons.Outlined.Shield
    "devices" -> Icons.Outlined.Devices
    "privacy" -> Icons.Outlined.PrivacyTip
    "help" -> Icons.Outlined.HelpOutline
    else -> Icons.Outlined.Info
}

/** The value a root row shows at its end ("Dark", "On", "Pro"), or null when it has none worth showing. */
private fun pageValue(id: String, s: AppSettings): String? = when (id) {
    "mic" -> if (s.micSource == "mic") "Raw microphone" else "Automatic"

    "captions" -> if (s.captionsEnabled) "On" else "Off"

    "voice" -> if (s.dictationMode == "hold") "Hold to talk" else "Tap to start"

    "language" -> s.transcriptionLanguage?.let { code ->
        com.echoledger.mobile.core.Languages.OPTIONS.firstOrNull { it.code == code }?.menu
    }
        ?: "Automatic"

    "key" -> if (s.plan.transcriptionKey?.set == true) "Added" else "Not added"

    "games" -> s.liveTranslate.game.games.size.takeIf { it > 0 }?.let { "$it game${if (it == 1) "" else "s"}" } ?: "Not set up"

    "videos" -> s.liveTranslate.watch.apps.size.takeIf { it > 0 }?.let { "$it app${if (it == 1) "" else "s"}" } ?: "Not set up"

    "appearance" -> when (s.themeMode) {
        "light" -> "Light"
        "dark" -> "Dark"
        else -> "System"
    }

    "notifications" -> when {
        !s.activityNotifications -> "Off"
        s.quietHours -> "Quiet ${s.quietFrom}–${s.quietTo}"
        else -> "On"
    }

    "lock" -> if (s.appLockEnabled) "On" else "Off"

    "plan" -> s.plan.displayName.takeIf { s.plan.name != null || s.plan.key != null }

    "about" -> BuildConfig.VERSION_NAME

    else -> null
}

/** A root row is shown: Transcription key only on Free or once a key is saved; the rest always (where built). */
private fun pageShown(id: String, s: AppSettings): Boolean = when (id) {
    "key" -> s.plan.needsOwnKey || s.plan.transcriptionKey?.set == true
    "plan" -> s.serverUrl != null
    "account" -> s.account?.email != null || s.serverUrl != null
    else -> true
}

/**
 * Settings (since v1.7, docs/DESIGN_SYSTEM.md "Settings", the same names as the desktop app and the console): who is
 * signed in with the plan and Upgrade on top, one line while something needs the person, search that reaches every
 * setting, then six sections of plain rows with their values, and Sign out. Pages open one level down; search results
 * open their page and highlight the setting.
 */
@Composable
fun SettingsScreen(vm: MainViewModel, onSetup: () -> Unit, home: Int = 0) {
    var page by rememberSaveable { mutableStateOf<String?>(null) }
    var highlight by rememberSaveable { mutableStateOf<String?>(null) }
    var searching by rememberSaveable { mutableStateOf(false) }
    var upgrade by remember { mutableStateOf<FlowStart?>(null) }
    // The Settings tab tapped again (since 1.3.2): back to the root.
    LaunchedEffect(home) {
        if (home > 0) {
            page = null
            highlight = null
            searching = false
        }
    }
    val settings by vm.settings.collectAsState()
    val pending by vm.pending.collectAsState()
    var account by remember { mutableStateOf<com.echoledger.mobile.data.AccountDto?>(null) }
    LaunchedEffect(settings.serverUrl) {
        if (settings.serverUrl != null) account = vm.accountQuietly() ?: account
        if (manualPayments && settings.plan.upgrade?.open == true) vm.loadUpgradeRequest()
    }
    upgrade?.let { start -> UpgradeFlow(vm, start.planKey, null, start.step) { upgrade = null } }
    if (searching) {
        BackHandler { searching = false }
        SettingsSearchScreen(settings, onBack = { searching = false }) { pageId, entry ->
            searching = false
            page = pageId
            highlight = entry
        }
        return
    }
    val open = page?.let { SettingsIndex.page(it) }
    if (open != null) {
        val close = {
            page = null
            highlight = null
        }
        BackHandler(onBack = close)
        if (open.id == "account") {
            AccountScreen(vm, onBack = close)
        } else {
            SettingsPageScreen(vm, open, highlight, onSetup, onBack = close, onSearch = { searching = true }, onOpenPage = { page = it })
        }
        return
    }
    var confirmSignOut by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(bottom = 96.dp)) {
        com.echoledger.mobile.ui.design.EchoTopBar("Settings")
        val plan = settings.plan
        val request = plan.upgrade
        val member = plan.role == "member" || plan.role == "viewer"
        val canUpgrade = manualPayments && !member && request?.open != true && plan.higherPlans.isNotEmpty() && settings.serverUrl != null
        ProfileCard(
            settings,
            account,
            onOpen = { page = "plan" },
            upgradeLabel = if (canUpgrade) "Upgrade" else null,
            filled = plan.isFree,
            onUpgrade = { upgrade = FlowStart(plan.higherPlans.firstOrNull()?.key) }
        )
        StateRow(settings, account, onOpenPlan = { page = "plan" }, onOpenKey = { page = "key" }, onRequest = { step ->
            upgrade =
                FlowStart(request?.plan, step = step)
        })
        com.echoledger.mobile.ui.design.SearchPill(
            "Search settings",
            { searching = true },
            Modifier.padding(horizontal = Space.screen).padding(top = Space.s2, bottom = Space.s4)
        )
        SettingsIndex.sections.forEach { section ->
            val rows = SettingsIndex.pages.filter { it.section == section && pageShown(it.id, settings) }
            if (rows.isEmpty()) return@forEach
            com.echoledger.mobile.ui.design.SettingsSection(section) {
                rows.forEachIndexed { i, p ->
                    if (i > 0) com.echoledger.mobile.ui.design.RowDivider()
                    val locked = when (p.id) {
                        "games", "videos" -> plan.lockFor("live_translate")
                        else -> null
                    }
                    com.echoledger.mobile.ui.design.SettingsRow(
                        pageIcon(p.id),
                        p.title,
                        Modifier.semantics { contentDescription = listOfNotNull(p.title, pageValue(p.id, settings)).joinToString(", ") },
                        value = if (locked == null) pageValue(p.id, settings) else null,
                        onClick = { page = p.id },
                        trailing = {
                            if (locked != null) com.echoledger.mobile.ui.design.PlanTag(locked.badge) else Chevron()
                        }
                    )
                }
            }
        }
        if (settings.serverUrl != null) {
            com.echoledger.mobile.ui.design.SettingsSection(null) {
                com.echoledger.mobile.ui.design.SettingsRow(
                    Icons.AutoMirrored.Outlined.Logout,
                    "Sign out",
                    tint = LocalEchoColors.current.danger,
                    titleColor = LocalEchoColors.current.danger,
                    onClick = { confirmSignOut = true }
                )
            }
        }
    }
    if (confirmSignOut) {
        val waiting = pending.size
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { confirmSignOut = false },
            title = { Text("Sign out of this phone?") },
            text = if (waiting > 0) {
                {
                    Text(
                        if (waiting == 1) {
                            "1 recording hasn't uploaded yet. It'll stay on this phone, but nobody else can see it until you sign in again."
                        } else {
                            "$waiting recordings haven't uploaded yet. They'll stay on this phone, but nobody else can see them until you sign in again."
                        }
                    )
                }
            } else {
                null
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmSignOut = false
                    vm.unpair()
                }) { Text("Sign out", color = LocalEchoColors.current.danger) }
            },
            dismissButton = { TextButton(onClick = { confirmSignOut = false }) { Text("Cancel") } }
        )
    }
}

/**
 * Who is signed in (since v1.7): initials, the account's name and email, the plan's tag and its minutes, all one target
 * that opens Plan & usage; Upgrade (filled on Free, outlined on Pro, none on Ultra or while a request is open) is its
 * sibling, never inside it.
 */
@Composable
private fun ProfileCard(
    settings: AppSettings,
    account: com.echoledger.mobile.data.AccountDto?,
    onOpen: () -> Unit,
    upgradeLabel: String?,
    filled: Boolean,
    onUpgrade: () -> Unit
) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val email = account?.email ?: settings.account?.email
    val name = account?.name?.takeIf { it.isNotBlank() }
        ?: email?.substringBefore('@')?.replace('.', ' ')?.split(' ')?.joinToString(" ") { w -> w.replaceFirstChar { it.titlecase() } }
        ?: settings.deviceName?.takeIf { it.isNotBlank() }
        ?: "Not signed in"
    val plan = settings.plan
    val signedIn = settings.serverUrl != null
    val planLine = when {
        !signedIn -> null

        plan.isFree -> null

        plan.expiresAt != null && plan.monthlyMinutes == null -> com.echoledger.mobile.core.PlanFormat.dateWithYear(plan.expiresAt)?.let {
            "Paid until $it"
        }

        else -> plan.summary()
    }
    val spoken =
        listOfNotNull(name, email, if (signedIn) "${plan.displayName} plan" else null).joinToString(", ") +
            if (signedIn) ". Opens plan and usage." else ""
    com.echoledger.mobile.ui.design.SettingsSection(null) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Row(
                Modifier.weight(1f).clickable(enabled = signedIn, onClickLabel = "Open plan and usage", onClick = onOpen)
                    .semantics(mergeDescendants = true) { contentDescription = spoken }.padding(Space.s4),
                verticalAlignment = Alignment.CenterVertically
            ) {
                com.echoledger.mobile.ui.design.Avatar(name, size = 56.dp)
                Column(Modifier.padding(start = Space.s3).weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(
                        name,
                        style = type.titleM,
                        color = c.text,
                        maxLines = 1,
                        overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis
                    )
                    email?.let {
                        Text(
                            it,
                            style = type.body,
                            color = c.textMuted,
                            maxLines = 1,
                            overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis
                        )
                    }
                    if (signedIn) {
                        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 4.dp)) {
                            com.echoledger.mobile.ui.design.PlanTag(plan.displayName)
                            planLine?.let {
                                Text(
                                    it,
                                    style = type.labelM.copy(fontWeight = FontWeight.Normal),
                                    color = c.textMuted,
                                    modifier = Modifier.padding(start = Space.s2),
                                    maxLines = 1
                                )
                            }
                        }
                        plan.membership()?.takeIf {
                            plan.role != "owner" || plan.workspace?.equals("Personal", ignoreCase = true) == false
                        }?.let {
                            Text(it, style = type.labelM.copy(fontWeight = FontWeight.Normal), color = c.textMuted, maxLines = 1)
                        }
                    }
                }
            }
            if (upgradeLabel != null) {
                com.echoledger.mobile.ui.design.EchoButton(
                    upgradeLabel,
                    onUpgrade,
                    Modifier.padding(end = Space.s4),
                    kind = if (filled) {
                        com.echoledger.mobile.ui.design.ButtonKind.Primary
                    } else {
                        com.echoledger.mobile.ui.design.ButtonKind.Secondary
                    }
                )
            }
        }
    }
}

/** One line under the card while something is true (a request needs the person, the plan ends soon, no transcription key). */
@Composable
private fun StateRow(
    settings: AppSettings,
    account: com.echoledger.mobile.data.AccountDto?,
    onOpenPlan: () -> Unit,
    onOpenKey: () -> Unit,
    onRequest: (FlowStep) -> Unit
) {
    val c = LocalEchoColors.current
    val plan = settings.plan
    val r = plan.upgrade
    val name = plan.nameOf(r?.plan)
    val ends = com.echoledger.mobile.core.PlanFormat.daysUntil(plan.expiresAt)
    data class Line(val icon: ImageVector, val text: String, val tone: androidx.compose.ui.graphics.Color, val onClick: () -> Unit)
    val line = when {
        account?.deletesAt != null -> Line(
            Icons.Outlined.WarningAmber,
            "Your account will be deleted on ${com.echoledger.mobile.core.PlanFormat.shortDate(account.deletesAt)}.",
            c.warning,
            onOpenPlan
        )

        manualPayments && r?.stage == "needs_receipt" -> Line(Icons.Outlined.ErrorOutline, "We need another receipt for $name", c.warning) {
            onRequest(FlowStep.Receipt)
        }

        manualPayments && r?.stage == "awaiting_payment" -> Line(
            Icons.Outlined.Schedule,
            "$name requested. Waiting for your payment",
            c.textMuted
        ) {
            onRequest(FlowStep.Pay)
        }

        manualPayments && r?.stage == "in_review" -> Line(Icons.Outlined.Schedule, "Checking your payment for $name", c.textMuted) {
            onRequest(FlowStep.Status)
        }

        !plan.isFree && ends != null && ends in 0..7 -> Line(
            Icons.Outlined.Event,
            "${plan.displayName} ends on ${com.echoledger.mobile.core.PlanFormat.shortDate(plan.expiresAt)}",
            c.warning,
            onOpenPlan
        )

        settings.serverUrl != null && plan.needsOwnKey && plan.transcriptionKey?.set != true -> Line(
            Icons.Outlined.Key,
            "Set up free transcription (2 minutes)",
            c.textMuted,
            onOpenKey
        )

        else -> null
    } ?: return
    com.echoledger.mobile.ui.design.SettingsSection(null) {
        com.echoledger.mobile.ui.design.SettingsRow(line.icon, line.text, tint = line.tone, onClick = line.onClick)
    }
}

/**
 * One settings page: the cards of its entries in order. A search result's [highlight] entry is scrolled to and drawn on
 * the accent-soft colour for a few seconds (still under Remove animations: the colour simply stays until then).
 */
@Composable
private fun SettingsPageScreen(
    vm: MainViewModel,
    page: SettingsIndex.Page,
    highlight: String?,
    onSetup: () -> Unit,
    onBack: () -> Unit,
    onSearch: () -> Unit,
    onOpenPage: (String) -> Unit
) {
    val settings by vm.settings.collectAsState()
    Column(Modifier.fillMaxSize()) {
        com.echoledger.mobile.ui.design.EchoTopBar(
            page.title,
            large = false,
            navigation = {
                com.echoledger.mobile.ui.design.BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = Brand.Text)
            },
            actions = { com.echoledger.mobile.ui.design.BarIcon(Icons.Rounded.Search, "Search settings", onSearch) }
        )
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = Space.s5)) {
            if (page.id == "plan") {
                PlanUsagePage(vm, settings.plan, onOpenKey = { onOpenPage("key") })
                return@Column
            }
            Column(Modifier.padding(horizontal = Space.screen).padding(top = Space.s2), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                page.entries.mapNotNull { id -> SettingsCatalogue.entries.firstOrNull { it.id == id } }.forEach { entry ->
                    val requester = remember { BringIntoViewRequester() }
                    var lit by remember { mutableStateOf(false) }
                    LaunchedEffect(highlight) {
                        if (highlight == entry.id) {
                            withFrameNanos { }
                            requester.bringIntoView()
                            lit = true
                            kotlinx.coroutines.delay(2_500)
                            lit = false
                        }
                    }
                    Column(
                        Modifier.bringIntoViewRequester(requester).clip(RoundedCornerShape(Radius.md))
                            .then(
                                if (lit) Modifier.border(2.dp, LocalEchoColors.current.accent, RoundedCornerShape(Radius.md)) else Modifier
                            ),
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        SettingsEntryContent(entry, vm, settings, onSetup)
                    }
                }
                if (page.id == "privacy") {
                    com.echoledger.mobile.ui.design.EchoButton(
                        "Export or delete my data",
                        { onOpenPage("account") },
                        kind = com.echoledger.mobile.ui.design.ButtonKind.Text
                    )
                }
                PageReset(vm, page)
            }
        }
    }
}

/** "Reset to defaults" for the pages whose choices have defaults; what the person made or picked stays. */
@Composable
private fun PageReset(vm: MainViewModel, page: SettingsIndex.Page) {
    val group = when (page.id) {
        "appearance", "notifications", "lock", "language" -> SettingsGroup.General
        "voice" -> SettingsGroup.VoiceNotes
        "calls", "mic", "captions" -> SettingsGroup.Calls
        "games" -> SettingsGroup.Game
        "videos" -> SettingsGroup.Watch
        else -> null
    } ?: return
    var confirm by remember { mutableStateOf(false) }
    com.echoledger.mobile.ui.design.EchoButton("Reset to defaults", {
        confirm = true
    }, kind = com.echoledger.mobile.ui.design.ButtonKind.Text)
    if (confirm) {
        ConfirmDialog(
            text = "Put the ${page.title} settings back to their defaults? Your picked apps, games and words stay.",
            confirmLabel = "Reset",
            onConfirm = {
                confirm = false
                vm.updateSettings { com.echoledger.mobile.core.SettingsReset.resetPage(page.id, group, it) }
                vm.showMessage("${page.title} reset")
            },
            onDismiss = { confirm = false }
        )
    }
}

/** Search every setting (since v1.7): results show where each one is; a tap opens its page and highlights it. */
@Composable
private fun SettingsSearchScreen(settings: AppSettings, onBack: () -> Unit, onOpen: (page: String, entry: String?) -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }
    val type = LocalEchoType.current
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    val results = SettingsIndex.search(query) { item -> pageShown(item.page, settings) }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().height(64.dp).padding(horizontal = Space.s1), verticalAlignment = Alignment.CenterVertically) {
            com.echoledger.mobile.ui.design.BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = Brand.Text)
            androidx.compose.foundation.text.BasicTextField(
                query,
                { query = it.take(60) },
                singleLine = true,
                textStyle = type.bodyL.copy(color = Brand.Text),
                cursorBrush = androidx.compose.ui.graphics.SolidColor(LocalEchoColors.current.accent),
                modifier = Modifier.weight(1f).focusRequester(focus).semantics { contentDescription = "Search settings" },
                decorationBox = { inner ->
                    Box(contentAlignment = Alignment.CenterStart) {
                        if (query.isEmpty()) Text("Search settings", style = type.bodyL, color = Brand.Muted)
                        inner()
                    }
                }
            )
            if (query.isNotEmpty()) com.echoledger.mobile.ui.design.BarIcon(Icons.Rounded.Close, "Clear search", { query = "" })
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = Space.s5)) {
            when {
                query.isBlank() -> Text(
                    "Search every setting, for example dark, fingerprint, Urdu, receipt or upgrade.",
                    style = type.body,
                    color = Brand.Muted,
                    modifier = Modifier.padding(horizontal = Space.screen, vertical = Space.s3)
                )

                results.isEmpty() -> Text(
                    "No settings match “${query.trim()}”. Try a shorter word, like dark, lock or plan.",
                    style = type.body,
                    color = Brand.Muted,
                    modifier = Modifier.padding(horizontal = Space.screen, vertical = Space.s3)
                )

                else -> com.echoledger.mobile.ui.design.SettingsSection(null, Modifier.padding(top = Space.s2)) {
                    results.forEachIndexed { i, hit ->
                        if (i > 0) com.echoledger.mobile.ui.design.RowDivider()
                        com.echoledger.mobile.ui.design.SettingsRow(
                            pageIcon(hit.page.id),
                            hit.item.title,
                            summary = SettingsIndex.path(hit),
                            onClick = { onOpen(hit.page.id, hit.item.entry.ifEmpty { null }) },
                            trailing = { Chevron() }
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun SettingsEntryContent(entry: SettingsEntry, vm: MainViewModel, settings: AppSettings, onSetup: () -> Unit) {
    when (entry.id) {
        "appearance" -> AppearanceSection(settings) { vm.updateSettings(it) }

        "transcription" -> TranscriptionSection(vm, settings)

        "client" -> ClientKeytermsCard(vm, settings)

        "notifications" -> NotificationsSection(settings) { vm.updateSettings(it) }

        "consent" -> ConsentCard(vm, settings)

        "after-call" -> DecisionTimeoutSection(vm, settings)

        "auto-apps" -> AutoRecordSection(vm, settings)

        "after-share" -> AfterShareSection(vm, settings)

        "microphone" -> MicrophoneEntry(vm, settings, onSetup)

        "captions" -> LiveCaptionsCard(vm, settings)

        "caption-style" -> CaptionStyleSection(vm, settings)

        "dictation" -> {
            val context = LocalContext.current
            DictationSettingsContent(vm, settings, remember { DictationHistory.of(context) })
        }

        "app-lock" -> AppLockSection(vm, settings)

        "devices" -> SecuritySection(load = {
            vm.security()
        }, revoke = { vm.revokeDevice(it) }, rename = { id, name -> vm.renameDevice(id, name) })

        "plan" -> PlanSection(vm, settings.plan)

        "transcription-key" -> TranscriptionKeySection(vm, settings.plan)

        "this-phone" -> ThisPhoneCard(vm, settings, onSetup)

        "storage" -> StorageSection(load = { vm.storage() })

        "about" -> AboutSection(vm, settings)

        "help" -> HelpSection(vm, settings, onSetup)

        "game" -> GameSettingsSection(vm, settings)

        "game-box" -> GameBoxSection(vm, settings)

        "game-more" -> GameMoreSection(vm, settings)

        "watch" -> WatchSettingsSection(vm, settings)

        "watch-style" -> WatchStyleSection(vm, settings)
    }
}

@Composable
private fun MicrophoneEntry(vm: MainViewModel, settings: AppSettings, onSetup: () -> Unit) {
    val helper by vm.helper.collectAsState()
    val live by RecordingService.live.collectAsState()
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Near-end microphone source")
                Text(
                    if (settings.micSource == "mic") "Raw microphone" else "Voice communication (echo-cancelled)",
                    color = Brand.Muted,
                    fontSize = 12.sp
                )
            }
            TextButton(onClick = {
                vm.updateSettings { it.copy(micSource = if (it.micSource == "mic") "voice_communication" else "mic") }
            }) { Text("Change") }
        }
        Text(
            "During calls your side always uses the raw microphone (One UI gives the echo-cancelled one silence).",
            color = Brand.Muted,
            fontSize = 12.sp
        )
        // Since v1.3: Opus bitrate per side. Higher keeps more detail for transcription; files grow with it.
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Recording quality")
                Text("${settings.recordingKbps} kbps per side (Opus, 48 kHz)", color = Brand.Muted, fontSize = 12.sp)
            }
            TextButton(onClick = {
                vm.updateSettings { it.copy(recordingKbps = AppSettings.nextRecordingKbps(it.recordingKbps)) }
            }) { Text("Change") }
        }
    }
    CaptureHealthSection(vm, settings, helperConnected = helper.connected, recording = live.active, onSetup = onSetup)
}

@Composable
private fun LiveCaptionsCard(vm: MainViewModel, settings: AppSettings) {
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Live captions while recording")
                Text("Floating bubble; needs the overlay permission", color = Brand.Muted, fontSize = 12.sp)
            }
            Switch(
                checked = settings.captionsEnabled,
                onCheckedChange = { v -> vm.updateSettings { it.copy(captionsEnabled = v) } },
                modifier = Modifier.semantics { contentDescription = "Live captions while recording" }
            )
        }
    }
}

@Composable
private fun ClientKeytermsCard(vm: MainViewModel, settings: AppSettings) {
    val clients by vm.clients.collectAsState()
    SectionCard {
        Text("Project keyterms", fontWeight = FontWeight.SemiBold)
        Text("Names and jargon of the selected project improve accuracy.", color = Brand.Muted, fontSize = 12.sp)
        (listOf(null) + clients).forEach { client ->
            Row(Modifier.fillMaxWidth().clickable { vm.updateSettings { it.copy(clientId = client?.id) } }.padding(vertical = 6.dp)) {
                Text(if (settings.clientId == client?.id) "●  " else "○  ", color = Brand.Violet)
                Text(client?.name ?: "No project")
            }
        }
    }
}

/** The same sentence as the desktop and the setup guide; switching it on records that the user has read it. */
@Composable
private fun ConsentCard(vm: MainViewModel, settings: AppSettings) {
    SectionCard {
        Text("Privacy & consent", fontWeight = FontWeight.SemiBold)
        Text(
            "Recording laws differ by country. Tell the people in your meeting that you are recording where the law requires it. A recording notification is always shown.",
            color = Brand.Muted,
            fontSize = 13.sp
        )
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("I understand", modifier = Modifier.weight(1f))
            Switch(
                checked = settings.consentAcknowledged,
                onCheckedChange = { v -> vm.updateSettings { it.copy(consentAcknowledged = v) } },
                modifier = Modifier.semantics { contentDescription = "I understand" }
            )
        }
    }
}

@Composable
private fun ThisPhoneCard(vm: MainViewModel, settings: AppSettings, onSetup: () -> Unit) {
    var confirmUnpair by remember { mutableStateOf(false) }
    SectionCard {
        Text("This phone", fontWeight = FontWeight.SemiBold)
        Text("${settings.deviceName ?: ""} · ${settings.serverUrl ?: ""}", color = Brand.Muted, fontSize = 13.sp)
        Text(
            if (settings.pins.isNotEmpty()) {
                "Server certificate pinned (${settings.pins.size} pins)"
            } else {
                "Certificate pinning not configured on the server"
            },
            color = Brand.Muted,
            fontSize = 12.sp
        )
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = onSetup) { Text("Setup guide") }
            Button(onClick = {
                confirmUnpair = true
            }, colors = ButtonDefaults.buttonColors(containerColor = Brand.Danger)) { Text("Sign out") }
        }
    }
    if (confirmUnpair) {
        ConfirmDialog(
            text = "Sign out of this phone? Recordings waiting to upload stay on the phone.",
            confirmLabel = "Sign out",
            onConfirm = {
                confirmUnpair = false
                vm.unpair()
            },
            onDismiss = { confirmUnpair = false }
        )
    }
}

/** About: the version and server, and settings export / import through the system file picker (never any secrets). */
@Composable
private fun AboutSection(vm: MainViewModel, settings: AppSettings) {
    val export = rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("application/json")) { uri ->
        if (uri != null) vm.exportSettingsTo(uri)
    }
    val import = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) vm.importSettingsFrom(uri)
    }
    SectionCard {
        Text("About", fontWeight = FontWeight.SemiBold)
        Text("EchoLedger ${BuildConfig.VERSION_NAME}", fontSize = 13.sp)
        UpdateAbout(vm)
        settings.serverUrl?.let { url ->
            Text(
                "Server ${url.removePrefix("https://").removePrefix("http://")}" +
                    (settings.serverApiVersion?.let { " · API $it" } ?: ""),
                color = Brand.Muted,
                fontSize = 12.sp
            )
        }
        Text(
            "Save your preferences to a file or load them on another phone. The file never holds your pairing, token or lock.",
            color = Brand.Muted,
            fontSize = 12.sp
        )
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = { export.launch("EchoLedger settings ${LocalDate.now()}.json") }) { Text("Export settings") }
            OutlinedButton(onClick = { import.launch(arrayOf("application/json", "text/plain")) }) { Text("Import settings") }
        }
    }
}

/** Settings → Appearance (C4): light, dark or the phone's theme, and the wallpaper's colours on Android 12+. */
@Composable
fun AppearanceSection(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit) {
    val modes = listOf("system" to "System", "light" to "Light", "dark" to "Dark")
    SectionCard {
        Text("Appearance", fontWeight = FontWeight.SemiBold)
        Text("Theme", color = Brand.Muted, fontSize = 12.sp)
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            modes.forEachIndexed { index, (value, label) ->
                SegmentedButton(
                    selected = settings.themeMode == value,
                    onClick = { update { it.copy(themeMode = value) } },
                    shape = SegmentedButtonDefaults.itemShape(index, modes.size),
                    label = { Text(label) }
                )
            }
        }
        if (supportsDynamicColour) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Wallpaper colours")
                    Text("Use the colours of your wallpaper instead of EchoLedger blue.", color = Brand.Muted, fontSize = 12.sp)
                }
                Switch(
                    checked = settings.dynamicColour,
                    onCheckedChange = { v -> update { it.copy(dynamicColour = v) } },
                    modifier = Modifier.semantics { contentDescription = "Wallpaper colours" }
                )
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Use phone font")
                Text(
                    "Show text in your phone's font instead of Inter. Text size always follows your phone.",
                    color = Brand.Muted,
                    fontSize = 12.sp
                )
            }
            Switch(
                checked = settings.phoneFont,
                onCheckedChange = { v -> update { it.copy(phoneFont = v) } },
                modifier = Modifier.semantics { contentDescription = "Use phone font" }
            )
        }
    }
}

/** "Voice notes & files": what happens after a voice note is shared to EchoLedger from another app (since v1.3). */
@Composable
internal fun AfterShareSection(vm: MainViewModel, settings: AppSettings) {
    SectionCard {
        Text("After sharing a voice note", fontWeight = FontWeight.SemiBold)
        Text("When you share a voice note or audio file to EchoLedger from WhatsApp or another app", color = Brand.Muted, fontSize = 12.sp)
        listOf(
            SharedNotes.OPEN to "Open the voice note (default)",
            SharedNotes.STAY to "Stay in the app I shared from, and notify me quietly when it is ready"
        ).forEach { (value, label) ->
            Row(
                Modifier.fillMaxWidth().selectable(
                    selected = settings.afterShare == value,
                    role = Role.RadioButton,
                    onClick = { vm.updateSettings { it.copy(afterShare = value) } }
                ),
                verticalAlignment = Alignment.CenterVertically
            ) {
                RadioButton(selected = settings.afterShare == value, onClick = null)
                Text(label, modifier = Modifier.padding(start = 8.dp))
            }
        }
    }
}
