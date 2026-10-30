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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.outlined.AudioFile
import androidx.compose.material.icons.outlined.Badge
import androidx.compose.material.icons.outlined.Call
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.ClosedCaption
import androidx.compose.material.icons.outlined.Devices
import androidx.compose.material.icons.outlined.FiberManualRecord
import androidx.compose.material.icons.outlined.HelpOutline
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Palette
import androidx.compose.material.icons.outlined.PhoneAndroid
import androidx.compose.material.icons.outlined.PrivacyTip
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.SportsEsports
import androidx.compose.material.icons.outlined.Storage
import androidx.compose.material.icons.outlined.Subtitles
import androidx.compose.material.icons.outlined.Translate
import androidx.compose.material.icons.outlined.Tune
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
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.dictation.DictationHistory
import com.echoledger.mobile.recording.RecordingService
import java.time.LocalDate

private fun SettingsGroup.icon(): ImageVector = when (this) {
    SettingsGroup.General -> Icons.Outlined.Tune
    SettingsGroup.VoiceNotes -> Icons.Outlined.AudioFile
    SettingsGroup.Calls -> Icons.Outlined.Call
    SettingsGroup.Game -> Icons.Outlined.SportsEsports
    SettingsGroup.Watch -> Icons.Outlined.Subtitles
}

/**
 * Settings, laid out like the system settings app (B19): a search box and one row per group; every group is its own
 * screen. Searching lists the matching settings and a tap jumps to that setting inside its group. Back (also the
 * predictive back gesture) returns from a group to this list.
 */
@Composable
fun SettingsScreen(vm: MainViewModel, onSetup: () -> Unit) {
    var group by rememberSaveable { mutableStateOf<String?>(null) }
    var focus by rememberSaveable { mutableStateOf<String?>(null) }
    var query by rememberSaveable { mutableStateOf("") }
    val open = group?.let { name -> SettingsGroup.entries.firstOrNull { it.name == name } }
    if (open != null) {
        // General is a list of its own pages; Back from one of them returns to that list.
        val close = {
            if (open == SettingsGroup.General && focus != null) {
                focus = null
            } else {
                group = null
                focus = null
            }
        }
        BackHandler(onBack = close)
        if (open == SettingsGroup.General) {
            GeneralScreen(vm, focus, onSetup, onOpen = { focus = it }, onBack = close)
        } else {
            SettingsGroupScreen(vm, open, focus, onSetup, onBack = close)
        }
        return
    }
    // You (since v1.3): this phone and its server on top, then the settings as a flat list, and Unpair at the bottom.
    val settings by vm.settings.collectAsState()
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(bottom = 96.dp)) {
        com.echoledger.mobile.ui.design.EchoTopBar("You")
        ProfileCard(settings)
        OutlinedTextField(
            value = query,
            onValueChange = { query = it.take(60) },
            label = { Text("Search settings") },
            leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
            trailingIcon = {
                if (query.isNotEmpty()) {
                    IconButton(onClick = { query = "" }) { Icon(Icons.Outlined.Close, contentDescription = "Clear search") }
                }
            },
            singleLine = true,
            shape = androidx.compose.foundation.shape.CircleShape,
            modifier = Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2)
        )
        val results = SettingsCatalogue.search(query)
        when {
            query.isBlank() -> {
                com.echoledger.mobile.ui.design.SectionHeader("Settings")
                SettingsGroup.entries.forEach { g ->
                    SettingsRow(g.icon(), g.label, g.summary, "Open ${g.label} settings") {
                        group = g.name
                        focus = null
                    }
                }
                Box(Modifier.padding(horizontal = Space.s2, vertical = Space.s4)) {
                    com.echoledger.mobile.ui.design.EchoButton(
                        "Unpair this phone…",
                        {
                            group = SettingsGroup.General.name
                            focus = "this-phone"
                        },
                        kind = com.echoledger.mobile.ui.design.ButtonKind.Text
                    )
                }
            }

            results.isEmpty() -> Text(
                "No settings match “${query.trim()}”.",
                color = Brand.Muted,
                modifier = Modifier.padding(horizontal = Space.screen, vertical = Space.s4)
            )

            else -> results.forEach { e ->
                SettingsRow(e.group.icon(), e.title, e.group.label, "Open ${e.title}") {
                    group = e.group.name
                    focus = e.id
                }
            }
        }
    }
}

/** This phone (its name and initials), the server it is paired with, and since when the app knows it. */
@Composable
private fun ProfileCard(settings: AppSettings) {
    val type = LocalEchoType.current
    val name = settings.deviceName?.takeIf { it.isNotBlank() } ?: "This phone"
    val server = settings.serverUrl?.let { runCatching { java.net.URI(it).host }.getOrNull() ?: it }
    Row(
        Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2).clip(RoundedCornerShape(Radius.md))
            .background(Brand.Surface).border(1.dp, Brand.Border, RoundedCornerShape(Radius.md)).padding(Space.s4),
        verticalAlignment = Alignment.CenterVertically
    ) {
        com.echoledger.mobile.ui.design.Avatar(name, size = 48.dp)
        Column(Modifier.padding(start = Space.s3).weight(1f)) {
            Text(name, style = type.titleS, color = Brand.Text)
            Text(server?.let { "Paired with $it" } ?: "Not paired", style = type.labelM, color = Brand.Muted)
        }
    }
}

/** A settings group or search result: the design system's row (tinted icon tile, title, summary) with a chevron. */
@Composable
private fun SettingsRow(icon: ImageVector, title: String, summary: String, clickLabel: String, onClick: () -> Unit) {
    com.echoledger.mobile.ui.design.SettingsRow(
        icon,
        title,
        Modifier.semantics {
            onClick(label = clickLabel) {
                onClick()
                true
            }
        },
        summary = summary,
        onClick = onClick,
        trailing = {
            Icon(
                Icons.AutoMirrored.Outlined.KeyboardArrowRight,
                contentDescription = null,
                tint = Brand.Faint,
                modifier = Modifier.size(20.dp)
            )
        }
    )
}

private fun generalIcon(id: String): ImageVector = when (id) {
    "appearance" -> Icons.Outlined.Palette
    "this-phone" -> Icons.Outlined.PhoneAndroid
    "transcription" -> Icons.Outlined.Translate
    "client" -> Icons.Outlined.Badge
    "notifications" -> Icons.Outlined.Notifications
    "app-lock" -> Icons.Outlined.Lock
    "consent" -> Icons.Outlined.PrivacyTip
    "devices" -> Icons.Outlined.Devices
    "storage" -> Icons.Outlined.Storage
    "help" -> Icons.Outlined.HelpOutline
    else -> Icons.Outlined.Info
}

/**
 * General (since v1.3): one row per setting page (Appearance, This phone, App lock…); [page] opens that page alone,
 * Back returns to the list. "Reset this panel" sits under the list.
 */
@Composable
private fun GeneralScreen(vm: MainViewModel, page: String?, onSetup: () -> Unit, onOpen: (String) -> Unit, onBack: () -> Unit) {
    val settings by vm.settings.collectAsState()
    val entry = page?.let { id -> SettingsCatalogue.entries.firstOrNull { it.id == id && it.group == SettingsGroup.General } }
    Column(Modifier.fillMaxSize()) {
        com.echoledger.mobile.ui.design.EchoTopBar(
            entry?.title ?: SettingsGroup.General.label,
            large = false,
            navigation = {
                com.echoledger.mobile.ui.design.BarIcon(Icons.AutoMirrored.Outlined.ArrowBack, "Back", onBack, tint = Brand.Text)
            }
        )
        if (entry != null) {
            Column(
                Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = Space.screen).padding(bottom = Space.s4),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) { SettingsEntryContent(entry, vm, settings, onSetup) }
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = Space.s4)) {
            SettingsCatalogue.inGroup(SettingsGroup.General).forEach { e ->
                SettingsRow(generalIcon(e.id), e.title, generalSummary(e.id), "Open ${e.title}") { onOpen(e.id) }
            }
            var confirmReset by remember { mutableStateOf(false) }
            Box(Modifier.padding(horizontal = Space.s2, vertical = Space.s2)) {
                com.echoledger.mobile.ui.design.EchoButton(
                    "Reset this panel",
                    { confirmReset = true },
                    kind = com.echoledger.mobile.ui.design.ButtonKind.Text
                )
            }
            if (confirmReset) {
                ConfirmDialog(
                    text = "Put the General settings back to their defaults? Your pairing stays.",
                    confirmLabel = "Reset",
                    onConfirm = {
                        confirmReset = false
                        vm.updateSettings { com.echoledger.mobile.core.SettingsReset.reset(SettingsGroup.General, it) }
                        vm.showMessage("General settings reset")
                    },
                    onDismiss = { confirmReset = false }
                )
            }
        }
    }
}

private fun generalSummary(id: String): String = when (id) {
    "appearance" -> "Theme and font"
    "this-phone" -> "Server, name, unpair"
    "transcription" -> "Language of new recordings"
    "client" -> "Names and words for a client"
    "notifications" -> "Activity and quiet hours"
    "app-lock" -> "Fingerprint or screen lock"
    "consent" -> "Telling people you record"
    "devices" -> "Paired phones and computers"
    "storage" -> "Space used and how long things are kept"
    "help" -> "Audio probe, capture test, diagnostics"
    else -> "Version, export and import settings"
}

/** One group of settings; [focus] is the entry a search result pointed at, scrolled into view once. */
@Composable
private fun SettingsGroupScreen(vm: MainViewModel, group: SettingsGroup, focus: String?, onSetup: () -> Unit, onBack: () -> Unit) {
    val settings by vm.settings.collectAsState()
    Column(Modifier.fillMaxSize()) {
        com.echoledger.mobile.ui.design.EchoTopBar(
            group.label,
            large = false,
            navigation = {
                com.echoledger.mobile.ui.design.BarIcon(Icons.AutoMirrored.Outlined.ArrowBack, "Back", onBack, tint = Brand.Text)
            }
        )
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = Space.screen).padding(bottom = Space.s4),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            SettingsCatalogue.inGroup(group).forEach { entry ->
                val requester = remember { BringIntoViewRequester() }
                LaunchedEffect(focus) {
                    if (focus == entry.id) {
                        withFrameNanos { }
                        requester.bringIntoView()
                    }
                }
                Column(Modifier.bringIntoViewRequester(requester), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    SettingsEntryContent(entry, vm, settings, onSetup)
                }
            }
            // Every panel can go back to its defaults (plan B5); what the person made or picked stays.
            var confirmReset by remember { mutableStateOf(false) }
            com.echoledger.mobile.ui.design.EchoButton(
                "Reset this panel",
                { confirmReset = true },
                kind = com.echoledger.mobile.ui.design.ButtonKind.Text
            )
            if (confirmReset) {
                ConfirmDialog(
                    text = "Put the ${group.label} settings back to their defaults? Your pairing, picked apps, " +
                        "replacements and snippets stay.",
                    confirmLabel = "Reset",
                    onConfirm = {
                        confirmReset = false
                        vm.updateSettings { com.echoledger.mobile.core.SettingsReset.reset(group, it) }
                        vm.showMessage("${group.label} settings reset")
                    },
                    onDismiss = { confirmReset = false }
                )
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

        "devices" -> SecuritySection(load = { vm.security() }, revoke = { vm.revokeDevice(it) })

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
        Text("Client keyterms", fontWeight = FontWeight.SemiBold)
        Text("Names and jargon of the selected client improve accuracy.", color = Brand.Muted, fontSize = 12.sp)
        (listOf(null) + clients).forEach { client ->
            Row(Modifier.fillMaxWidth().clickable { vm.updateSettings { it.copy(clientId = client?.id) } }.padding(vertical = 6.dp)) {
                Text(if (settings.clientId == client?.id) "●  " else "○  ", color = Brand.Violet)
                Text(client?.name ?: "No client")
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
            "Call-recording laws differ by country. Tell participants you are recording where required. A recording notification is always shown.",
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
            }, colors = ButtonDefaults.buttonColors(containerColor = Brand.Danger)) { Text("Unpair") }
        }
    }
    if (confirmUnpair) {
        ConfirmDialog(
            text = "Unpair this phone? Recordings waiting to upload stay on the phone.",
            confirmLabel = "Unpair",
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
