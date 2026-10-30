package com.echoledger.mobile.ui

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioTrack
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Slider
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.net.toUri
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.calls.SamsungCallImport
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.notify.CallerNameListener
import com.echoledger.mobile.recording.AutoRecordRules
import com.echoledger.mobile.recording.CaptureHealth
import com.echoledger.mobile.recording.Levels
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.MicTestResult
import com.echoledger.mobile.recording.RecordingNotes
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoFilterChip
import kotlinx.coroutines.launch

/** Sends an action to the running recording service (pause, resume, mark, tag, undo). */
internal fun recordingAction(context: Context, action: String, tag: String? = null) {
    val intent = RecordingService.intent(context, action, from = "Live view in the app")
    if (tag != null) intent.putExtra(RecordingService.EXTRA_TAG, tag)
    runCatching { context.startForegroundService(intent) }
}

/** The language chip of the running recording: live captions reconnect in [language]; the recording keeps going. */
internal fun recordingLanguage(context: Context, language: String?) {
    val intent = RecordingService.intent(context, RecordingService.ACTION_LANGUAGE)
    if (language != null) intent.putExtra(RecordingService.EXTRA_LANGUAGE, language)
    runCatching { context.startForegroundService(intent) }
}

/**
 * A row of language chips (Auto, EN, UR, AR and More ▾ with the other languages); the selected one is [selected].
 * The line under them says what the choice does ("Urdu (with English words)", since v1.3).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun LanguageChips(selected: String?, onPick: (String?) -> Unit) {
    val current = Languages.canonical(selected)
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        LanguageChipRow(current, onPick)
        val meaning = Languages.menu(current)
        Text(meaning, color = Brand.Muted, fontSize = 12.sp, modifier = Modifier.semantics { contentDescription = "Chosen: $meaning" })
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LanguageChipRow(current: String?, onPick: (String?) -> Unit) {
    var moreOpen by remember { mutableStateOf(false) }
    val moreChosen = Languages.MORE.firstOrNull { it.code == current }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Languages.MAIN.forEach { option ->
            EchoFilterChip(
                option.chip,
                active = current == option.code,
                onClick = { onPick(option.code) },

                modifier = Modifier.semantics { contentDescription = "Language: ${option.menu}" }
            )
        }
        // The More languages sit behind one chip, so the row stays short.
        Box {
            EchoFilterChip(
                moreChosen?.chip ?: "More ▾",
                active = moreChosen != null,
                onClick = { moreOpen = true },

                modifier = Modifier.semantics { contentDescription = "More languages" }
            )
            DropdownMenu(expanded = moreOpen, onDismissRequest = { moreOpen = false }) {
                Languages.MORE.forEach { option ->
                    DropdownMenuItem(text = { Text(option.label) }, onClick = {
                        moreOpen = false
                        onPick(option.code)
                    })
                }
            }
        }
    }
}

/** A thin level bar (0-1 RMS on the desktop's scale). */
@Composable
internal fun LevelBar(label: String, rms: Float, colour: Color) {
    val fill = Levels.meter(rms)
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(label, color = Brand.Muted, fontSize = 12.sp, modifier = Modifier.width(48.dp))
        Box(
            Modifier.weight(1f).height(6.dp).clip(RoundedCornerShape(3.dp)).background(Brand.SurfaceAlt)
                .semantics { contentDescription = "$label level ${(fill * 100).toInt()} percent" }
        ) {
            Box(Modifier.fillMaxHeight().fillMaxWidth(fill).background(colour))
        }
    }
}

/**
 * The running recording on the Record tab (B2, B3, B5): timer, Pause / Resume, Mark with the tag presets for the
 * latest bookmark and Undo, the Me / Client meters and the "not hearing the other side" warning.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun LiveRecordingCard(live: LiveRecording, settings: AppSettings) {
    if (!live.active) return
    val context = LocalContext.current
    val tags = RecordingNotes.presets(settings.markerTags)
    SectionCard {
        // The same controls as the floating pill and the notification (since v1.3).
        val pill by RecordingService.pillState.collectAsState()
        val feed by RecordingService.captionFeed.collectAsState()
        if (live.markers > 0) Text("${live.markers} bookmark${if (live.markers == 1) "" else "s"}", color = Brand.Muted, fontSize = 12.sp)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            EchoButton("Mark", { recordingAction(context, RecordingService.ACTION_MARK) }, kind = ButtonKind.Secondary)
            EchoButton(if (pill.captions) "Hide captions" else "Captions", {
                recordingAction(context, RecordingService.ACTION_CAPTIONS)
            }, kind = ButtonKind.Secondary)
            if (pill.mode == com.echoledger.mobile.recording.PillState.Mode.HIDDEN && !pill.discarding) {
                EchoButton("Show controls", { recordingAction(context, RecordingService.ACTION_SHOW_CONTROLS) }, kind = ButtonKind.Text)
            }
        }
        if (pill.discarding) {
            Text(
                "Discarded: nothing is uploaded. Undo within 5 seconds to keep it.",
                color = Brand.Warning,
                fontSize = 12.sp
            )
        }
        if (live.paused &&
            !pill.discarding
        ) {
            Text("Paused time is not recorded or sent for captions.", color = Brand.Muted, fontSize = 12.sp)
        }
        pill.warning?.let { Text(it, color = Brand.Warning, fontSize = 13.sp) }
        LiveTranscript(feed, pill.captions)
        live.lastMarker?.let { marker ->
            Text("Last bookmark at ${formatDuration(marker.atMs)}${marker.tag?.let { " · $it" } ?: ""}", fontSize = 13.sp)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                tags.forEach { tag ->
                    FilterChip(
                        selected = marker.tag == tag,
                        onClick = { recordingAction(context, RecordingService.ACTION_TAG_LAST, if (marker.tag == tag) "" else tag) },
                        label = { Text(tag) }
                    )
                }
                EchoButton("Undo", { recordingAction(context, RecordingService.ACTION_UNDO_MARK) }, kind = ButtonKind.Text)
            }
        }
        Text("Language", color = Brand.Muted, fontSize = 12.sp)
        LanguageChips(live.language) { recordingLanguage(context, it) }
        LevelBar("Me", live.meLevel, Brand.Violet)
        if (live.twoSided) LevelBar("Client", live.clientLevel, Brand.Cyan)
        live.farSideSilentSeconds?.let {
            Text(
                "Not hearing the other side for $it s. Check the call's speaker or the helper.",
                color = Brand.Warning,
                fontSize = 13.sp,
                modifier = Modifier.semantics { contentDescription = "Warning: not hearing the other side for $it seconds" }
            )
        }
    }
}

/**
 * The Live view's transcript (since v1.3): every caption line of the recording in progress, newest last, scrolling on
 * its own; empty with a hint until captions are switched on.
 */
@Composable
private fun LiveTranscript(feed: com.echoledger.mobile.overlay.CaptionFeed, captionsOn: Boolean) {
    val lines = feed.lines + listOfNotNull(feed.partial)
    if (lines.isEmpty()) {
        Text(
            feed.notice ?: if (captionsOn) "Captions appear here as people speak." else "Tap Captions to see what is said, live.",
            color = if (feed.notice != null) Brand.Warning else Brand.Muted,
            fontSize = 13.sp
        )
        return
    }
    val list = androidx.compose.foundation.lazy.rememberLazyListState()
    androidx.compose.runtime.LaunchedEffect(lines.size) { list.scrollToItem(lines.size - 1) }
    androidx.compose.foundation.lazy.LazyColumn(state = list, modifier = Modifier.fillMaxWidth().height(260.dp)) {
        items(lines.size) { i ->
            val line = lines[i]
            Text(
                "${line.speaker}: ${line.text}",
                color = if (line.speaker == "Client") Brand.Cyan else Brand.Text,
                fontSize = 15.sp,
                modifier = Modifier.padding(vertical = 3.dp)
            )
        }
    }
}

/** Settings → Auto-record apps (B1): a switch per app whose calls the phone detects, with the explainer line. */
@Composable
fun AutoRecordSection(vm: MainViewModel, settings: AppSettings) {
    SectionCard {
        Text("Auto-record apps", fontWeight = FontWeight.SemiBold)
        AutoRecordRules.APPS.forEach { app ->
            val on = AutoRecordRules.records(app, settings.autoRecordApps)
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(app.label)
                    Text(
                        AutoRecordRules.explain(app, settings.autoRecordApps, settings.autoRecordArmed),
                        color = Brand.Muted,
                        fontSize = 12.sp
                    )
                }
                Switch(
                    checked = on,
                    onCheckedChange = { value -> vm.updateSettings { it.copy(autoRecordApps = it.autoRecordApps + (app.wire to value)) } },
                    modifier = Modifier.semantics { contentDescription = "Auto-record ${app.label}" }
                )
            }
        }
        // Since v1.3 the list is editable: another call app by its package name (recorded as "Other").
        settings.extraCallApps.forEach { pkg ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(pkg)
                    Text("Added by you. Its calls are recorded as Other.", color = Brand.Muted, fontSize = 12.sp)
                }
                TextButton(
                    onClick = { vm.updateSettings { it.copy(extraCallApps = it.extraCallApps - pkg) } },
                    modifier = Modifier.semantics { contentDescription = "Remove $pkg from the call apps" }
                ) { Text("Remove") }
            }
        }
        var adding by remember { mutableStateOf("") }
        val cleaned = CallTargets.clean(adding)
        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                value = adding,
                onValueChange = { adding = it.take(120) },
                label = { Text("Another call app (package name)") },
                singleLine = true,
                isError = adding.isNotBlank() && cleaned == null,
                modifier = Modifier.weight(1f)
            )
            TextButton(
                enabled = cleaned != null && cleaned !in CallTargets.DEFAULT_PACKAGES && cleaned !in settings.extraCallApps,
                onClick = {
                    val pkg = cleaned ?: return@TextButton
                    vm.updateSettings { it.copy(extraCallApps = (it.extraCallApps + pkg).distinct().take(20)) }
                    adding = ""
                }
            ) { Text("Add") }
        }
        Text(
            "Only voice calls in these apps start a recording. Games and other apps never do.",
            color = Brand.Muted,
            fontSize = 12.sp
        )
        // SIM phone calls (since v1.2): off by default; the call log and contacts are asked for when it is turned on.
        val ask = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { }
        Text("Phone calls", modifier = Modifier.padding(top = 8.dp))
        Text(
            "Calls on your SIM, with auto-record on. Tell the other person before you record: in many places the law needs their consent.",
            color = Brand.Muted,
            fontSize = 12.sp
        )
        Choices("Record phone calls", PhoneCalls.MODES.map { it to PhoneCalls.label(it) }, PhoneCalls.mode(settings.phoneCalls)) { mode ->
            vm.updateSettings { it.copy(phoneCalls = mode) }
            if (mode != PhoneCalls.OFF) {
                // The call log and contacts name the caller; audio files let the Phone app's own recordings be imported.
                ask.launch(arrayOf(Manifest.permission.READ_CALL_LOG, Manifest.permission.READ_CONTACTS, SamsungCallImport.permission()))
            }
        }
    }
}

/** A row of single-choice options. */
@Composable
private fun <T> Choices(label: String, options: List<Pair<T, String>>, selected: T, onSelect: (T) -> Unit) {
    Text(label, color = Brand.Muted, fontSize = 12.sp)
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        options.forEach { (value, text) ->
            FilterChip(selected = value == selected, onClick = { onSelect(value) }, label = { Text(text) })
        }
    }
}

/** The speaker colour presets offered on the phone (null = theme colour). */
val COLOUR_PRESETS = listOf<String?>(null, "#a78bfa", "#22d3ee", "#34d399", "#fbbf24", "#fb7185", "#ffffff")

/** Settings → Caption style (B4). */
@Composable
fun CaptionStyleSection(vm: MainViewModel, settings: AppSettings) {
    val style = settings.captionStyle.sanitized()
    fun save(next: CaptionStyle) = vm.updateSettings { it.copy(captionStyle = next.sanitized()) }
    SectionCard {
        Text("Caption style", fontWeight = FontWeight.SemiBold)
        Choices("Text size", listOf("small" to "Small", "medium" to "Medium", "large" to "Large"), style.size) {
            save(style.copy(size = it))
        }
        Choices("Lines", CaptionStyle.LINES.map { it to "$it" }, style.lines) { save(style.copy(lines = it)) }
        Choices("Position", listOf("top" to "Top", "bottom" to "Bottom"), style.position) { save(style.copy(position = it)) }
        Choices("Hide when nobody speaks", listOf(0 to "Never", 5 to "5 s", 10 to "10 s", 30 to "30 s"), style.autoHideSeconds) {
            save(style.copy(autoHideSeconds = it))
        }
        Text("Background opacity ${style.opacity}%", color = Brand.Muted, fontSize = 12.sp)
        var opacity by remember(style.opacity) { mutableFloatStateOf(style.opacity.toFloat()) }
        Slider(
            value = opacity,
            onValueChange = { opacity = it },
            onValueChangeFinished = { save(style.copy(opacity = opacity.toInt())) },
            valueRange = 40f..100f,
            steps = 11,
            modifier = Modifier.semantics { contentDescription = "Background opacity" }
        )
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Compact captions")
                Text("Speakers are shown by colour instead of the Me / Client labels.", color = Brand.Muted, fontSize = 12.sp)
            }
            Switch(checked = style.compact, onCheckedChange = { save(style.copy(compact = it)) })
        }
        Choices("Colour for Me", COLOUR_PRESETS.map { it to (it ?: "Default") }, style.meColor) { save(style.copy(meColor = it)) }
        Choices(
            "Colour for Client",
            COLOUR_PRESETS.map {
                it to (it ?: "Default")
            },
            style.clientColor
        ) { save(style.copy(clientColor = it)) }
    }
}

internal fun granted(context: Context, permission: String) =
    ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED

/** The settings page that fixes a permission row. */
internal fun permissionIntent(context: Context, key: String): Intent? = when (key) {
    "microphone" -> Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null))
    "notifications" -> Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)
    "overlay" -> Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, "package:${context.packageName}".toUri())
    "battery" -> Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
    "caller_names" -> Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)
    "call_log", "contacts" -> Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null))
    else -> null
}?.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

/** What the test result says, in plain words. */
fun micTestVerdict(result: MicTestResult): String = if (result.heard) {
    "Your microphone is working. Play it back to check how you sound."
} else {
    "We did not hear your microphone. Check the microphone above and the microphone permission."
}

/**
 * Settings → Microphone and capture health (B5): which microphone to record from, the 10-second test with a meter
 * and playback, the permissions centre and the silent-other-side warning.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CaptureHealthSection(vm: MainViewModel, settings: AppSettings, helperConnected: Boolean, recording: Boolean, onSetup: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var refresh by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { refresh++ }
    val choices = remember(refresh) { CaptureHealth.inputChoices(context) }
    var testing by remember { mutableStateOf(false) }
    var level by remember { mutableFloatStateOf(0f) }
    var result by remember { mutableStateOf<MicTestResult?>(null) }
    var track by remember { mutableStateOf<AudioTrack?>(null) }
    DisposableEffect(Unit) { onDispose { track?.release() } }
    val phoneCalls = PhoneCalls.mode(settings.phoneCalls) != PhoneCalls.OFF
    val items = remember(refresh, helperConnected, phoneCalls) {
        CaptureHealth.permissionItems(
            microphone = granted(context, Manifest.permission.RECORD_AUDIO),
            notifications = Build.VERSION.SDK_INT < 33 || granted(context, Manifest.permission.POST_NOTIFICATIONS),
            overlay = Settings.canDrawOverlays(context),
            battery = CaptureHealth.ignoresBatteryOptimisation(context),
            helper = helperConnected,
            callerNames = CallerNameListener.allowed(context),
            callLog = granted(context, Manifest.permission.READ_CALL_LOG).takeIf { phoneCalls },
            contacts = granted(context, Manifest.permission.READ_CONTACTS).takeIf { phoneCalls }
        )
    }
    SectionCard {
        Text("Microphone", fontWeight = FontWeight.SemiBold)
        Text("Record from", color = Brand.Muted, fontSize = 12.sp)
        (listOf<Pair<String?, String>>(null to "Automatic") + choices.map { it.key to it.label }).forEach { (key, label) ->
            Row(
                Modifier.fillMaxWidth().selectable(
                    selected = settings.inputDevice == key,
                    role = Role.RadioButton,
                    onClick = { vm.updateSettings { it.copy(inputDevice = key) } }
                ),
                verticalAlignment = Alignment.CenterVertically
            ) {
                RadioButton(selected = settings.inputDevice == key, onClick = null)
                Text(label, modifier = Modifier.padding(start = 8.dp))
            }
        }
        if (settings.inputDevice != null && choices.none { it.key == settings.inputDevice }) {
            Text("That microphone is not connected, so the phone chooses one.", color = Brand.Muted, fontSize = 12.sp)
        }
        Text("Calls captured through the helper use the microphone the call uses.", color = Brand.Muted, fontSize = 12.sp)
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Test recording")
                Text("Records 10 seconds of your microphone, then plays it back.", color = Brand.Muted, fontSize = 12.sp)
            }
            Button(
                enabled = !testing && !recording && granted(context, Manifest.permission.RECORD_AUDIO),
                onClick = {
                    testing = true
                    result = null
                    scope.launch {
                        result = runCatching { CaptureHealth.record(context, settings.inputDevice) { level = it } }.getOrNull()
                        testing = false
                    }
                }
            ) { Text(if (testing) "Recording…" else "Test 10 s") }
        }
        if (testing) LevelBar("Me", level, Brand.Violet)
        result?.let { r ->
            LevelBar("Peak", r.peak, Brand.Violet)
            Text(micTestVerdict(r), fontSize = 13.sp)
            TextButton(onClick = {
                track?.release()
                track = CaptureHealth.play(r.samples)
            }) { Text("Play back") }
        }
        if (recording) Text("Finish the current recording to run a test.", color = Brand.Muted, fontSize = 12.sp)
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Warn me when the other side is silent")
                Text("If a call has not heard the other side for 30 seconds.", color = Brand.Muted, fontSize = 12.sp)
            }
            Switch(checked = settings.farSideWarning, onCheckedChange = { v -> vm.updateSettings { it.copy(farSideWarning = v) } })
        }
    }
    SectionCard {
        Text("Permissions", fontWeight = FontWeight.SemiBold)
        items.forEach { item ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(item.title)
                    Text(
                        if (item.granted) "Allowed" else item.why,
                        color = if (item.granted) Brand.Success else Brand.Muted,
                        fontSize = 12.sp
                    )
                }
                if (!item.granted) {
                    TextButton(onClick = {
                        if (item.key ==
                            "helper"
                        ) {
                            onSetup()
                        } else {
                            permissionIntent(context, item.key)?.let { runCatching { context.startActivity(it) } }
                        }
                    }) { Text("Fix") }
                }
            }
        }
    }
}
