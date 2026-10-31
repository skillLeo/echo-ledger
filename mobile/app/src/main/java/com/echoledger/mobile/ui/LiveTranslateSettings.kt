package com.echoledger.mobile.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Checkbox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Slider
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.Shadow
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.toClipEntry
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.graphics.drawable.toBitmap
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.translate.BoxBackground
import com.echoledger.mobile.translate.BoxStyle
import com.echoledger.mobile.translate.GamePrefs
import com.echoledger.mobile.translate.SpeedPreset
import com.echoledger.mobile.translate.SubtitleStyle
import com.echoledger.mobile.translate.TranslateApps
import com.echoledger.mobile.translate.TranslateBudgetGuard
import com.echoledger.mobile.translate.TranslateCost
import com.echoledger.mobile.translate.TranslateLanguages
import com.echoledger.mobile.translate.TranslateUsage
import com.echoledger.mobile.translate.WatchPrefs
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoFilterChip
import com.echoledger.mobile.ui.design.SegmentedControl
import kotlinx.coroutines.launch

// ---- Shared pieces of the Game and Watch panels and pages (plan B5, C6) ---------------------------------------------

/** A titled block inside a settings card: label-m semibold title and an optional one-line note. */
@Composable
internal fun PanelTitle(title: String, note: String? = null) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    Column {
        Text(title, style = type.bodyL, color = c.text)
        if (note != null) Text(note, style = type.labelM.copy(fontWeight = FontWeight.Normal), color = c.textMuted)
    }
}

/** A switch with its title and one-line explanation; the whole row toggles. */
@Composable
internal fun SwitchLine(title: String, summary: String?, checked: Boolean, onChange: (Boolean) -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(value = checked, role = Role.Switch, onValueChange = onChange),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f)) {
            Text(title, style = type.bodyM, color = c.text)
            if (summary != null) Text(summary, style = type.caption, color = c.textMuted)
        }
        Spacer(Modifier.width(Space.s3))
        Switch(checked = checked, onCheckedChange = null)
    }
}

/** A slider with its label and the current value ("Text size · 16 sp"). */
@Composable
internal fun LabeledSlider(
    label: String,
    value: Float,
    range: ClosedFloatingPointRange<Float>,
    steps: Int,
    shown: (Float) -> String,
    onDone: (Float) -> Unit
) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    var current by remember(value) { mutableFloatStateOf(value) }
    Column {
        Text("$label · ${shown(current)}", style = type.labelM, color = c.textMuted)
        Slider(
            value = current,
            onValueChange = { current = it },
            onValueChangeFinished = { onDone(current) },
            valueRange = range,
            steps = steps,
            modifier = Modifier.semantics { contentDescription = label }
        )
    }
}

/** Chips for the languages people speak ("Arabic", "English", "Any language"); an empty list means any. */
@Composable
internal fun SpokenChips(selected: List<String>, onChange: (List<String>) -> Unit) {
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
        EchoFilterChip("Any language", active = selected.isEmpty(), onClick = { onChange(emptyList()) })
        listOf("ar", "en", "ur", "hi", "fa", "tr").forEach { code ->
            val on = code in selected
            EchoFilterChip(TranslateLanguages.label(code), active = on, onClick = {
                onChange(if (on) selected - code else (selected + code).take(4))
            })
        }
    }
}

/** Chips for the language the box writes in. */
@Composable
internal fun TargetChips(selected: String, onPick: (String) -> Unit) {
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
        TranslateLanguages.TARGETS.forEach { lang ->
            EchoFilterChip(lang.label, active = lang.code == selected, onClick = { onPick(lang.code) })
        }
    }
}

@Composable
internal fun SpeedControl(selected: String, onPick: (SpeedPreset) -> Unit) {
    val presets = SpeedPreset.entries
    SegmentedControl(presets.map { it.label }, presets.indexOf(SpeedPreset.of(selected)), { onPick(presets[it]) })
}

/** The installed app's icon, or a neutral tile when it is gone. */
@Composable
internal fun AppIcon(pkg: String, size: Dp = 32.dp) {
    val context = LocalContext.current
    val px = with(LocalDensity.current) { size.roundToPx() }
    val icon: ImageBitmap? = com.echoledger.mobile.ui.design.rememberAppIcon("package:$pkg:$px") {
        context.packageManager.getApplicationIcon(pkg).toBitmap(px, px).asImageBitmap()
    }
    if (icon != null) {
        Image(icon, contentDescription = null, modifier = Modifier.size(size))
    } else {
        Box(Modifier.size(size).clip(RoundedCornerShape(Radius.sm)).background(LocalEchoColors.current.surface2))
    }
}

/** The picked apps with their icons, or [empty] when none is picked. */
@Composable
internal fun PickedApps(packages: List<String>, empty: String, onEdit: () -> Unit, editLabel: String) {
    val context = LocalContext.current
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    Column(verticalArrangement = Arrangement.spacedBy(Space.s2)) {
        if (packages.isEmpty()) {
            Text(empty, style = type.bodyM, color = c.textMuted)
        } else {
            packages.forEach { pkg ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    AppIcon(pkg, 28.dp)
                    Spacer(Modifier.width(Space.s3))
                    Text(TranslateApps.label(context, pkg), style = type.bodyM, color = c.text)
                }
            }
        }
        EchoButton(editLabel, onEdit, kind = ButtonKind.Secondary)
    }
}

/** A sheet listing the apps on the home screen with a tick each; games first for Game translate. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun AppPickerSheet(
    title: String,
    picked: List<String>,
    gamesFirst: Boolean,
    onDone: (List<String>) -> Unit,
    onDismiss: () -> Unit
) {
    val context = LocalContext.current
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    // Reading every launcher app's label takes a moment: off the main thread, the list fills in when ready.
    val apps by androidx.compose.runtime.produceState<List<com.echoledger.mobile.translate.InstalledApp>?>(null) {
        value = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
            TranslateApps.forPicker(TranslateApps.installed(context), context.packageName, gamesFirst)
        }
    }
    var chosen by remember { mutableStateOf(picked.toSet()) }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = c.surface) {
        Row(Modifier.fillMaxWidth().padding(horizontal = Space.screen), verticalAlignment = Alignment.CenterVertically) {
            Text(title, style = type.titleM, color = c.text, modifier = Modifier.weight(1f))
            EchoButton("Done", { onDone(apps?.map { it.packageName }?.filter { it in chosen } ?: picked) })
        }
        LazyColumn(
            Modifier.fillMaxWidth().heightIn(max = 520.dp),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(bottom = Space.s6)
        ) {
            if (apps == null) items(4, key = { "loading-$it" }) { com.echoledger.mobile.ui.design.SkeletonRow() }
            if (apps?.isEmpty() == true) {
                item { Text("No apps found.", style = type.bodyM, color = c.textMuted, modifier = Modifier.padding(Space.screen)) }
            }
            items(apps.orEmpty(), key = { it.packageName }) { app ->
                val on = app.packageName in chosen
                Row(
                    Modifier.fillMaxWidth().heightIn(min = 56.dp)
                        .toggleable(value = on, role = Role.Checkbox) {
                            chosen =
                                if (it) chosen + app.packageName else chosen - app.packageName
                        }
                        .padding(horizontal = Space.screen),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    AppIcon(app.packageName, 32.dp)
                    Spacer(Modifier.width(Space.s3))
                    Column(Modifier.weight(1f)) {
                        Text(app.label, style = type.bodyL, color = c.text)
                        if (app.game) Text("Game", style = type.caption, color = c.textMuted)
                    }
                    Checkbox(checked = on, onCheckedChange = null)
                }
            }
        }
    }
}

// ---- Previews over a sample frame (plan B5: "box style with a live preview") ----------------------------------------

private const val SAMPLE_ORIGINAL = "يلا يا شباب، العدو عند البيت الأحمر"

private fun sampleTranslation(target: String): String = when (target) {
    "ur" -> "چلو دوستو، دشمن سرخ گھر کے پاس ہے"
    "ar" -> "يلا يا شباب، العدو عند البيت الأحمر"
    else -> "Come on guys, the enemy is at the red house"
}

/** A dark, blurred-looking "game scene" so the box is judged against what it will float over. */
@Composable
private fun SampleFrame(modifier: Modifier = Modifier, content: @Composable androidx.compose.foundation.layout.BoxScope.() -> Unit) {
    Box(
        modifier.fillMaxWidth().aspectRatio(16f / 9f).clip(RoundedCornerShape(Radius.md)).background(
            Brush.linearGradient(listOf(Color(0xFF2B3A2F), Color(0xFF6B5B3E), Color(0xFF8FA3B8)))
        ).semantics { contentDescription = "Preview over a sample game scene" }
    ) { content() }
}

/** The game box as it will look (plan B2): dark / light / no background, final text solid, original line above. */
@Composable
internal fun BoxPreview(style: BoxStyle, target: String, text: String? = null, original: String? = null) {
    val s = style.sanitized()
    SampleFrame {
        BoxWithConstraints(Modifier.fillMaxWidth().padding(top = Space.s3), contentAlignment = Alignment.TopCenter) {
            val (bg, fg) = when (s.backgroundKind) {
                BoxBackground.DARK -> Color.Black.copy(alpha = 0.72f) to Color.White
                BoxBackground.LIGHT -> Color.White.copy(alpha = 0.85f) to Color(0xFF1C2024)
                BoxBackground.NONE -> Color.Transparent to Color.White
            }
            val outline = if (s.backgroundKind == BoxBackground.NONE) Shadow(Color.Black, blurRadius = 6f) else null
            Column(
                Modifier.width(maxWidth * 0.6f).clip(RoundedCornerShape(14.dp)).background(bg)
                    .padding(horizontal = 12.dp, vertical = 10.dp)
            ) {
                if (s.showOriginal) {
                    Text(
                        original ?: SAMPLE_ORIGINAL,
                        style = TextStyle(
                            fontSize = 12.sp,
                            color = fg.copy(alpha = 0.7f),
                            shadow = outline,
                            textDirection = TextDirection.Rtl
                        ),
                        maxLines = 1,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                val rtl = TranslateLanguages.rtl(target)
                Text(
                    text ?: sampleTranslation(target),
                    style = TextStyle(
                        fontSize = (s.textSp * 0.75f).sp,
                        lineHeight = (s.textSp * 0.75f * if (target == "ur") 1.7f else 1.3f).sp,
                        fontWeight = FontWeight.Medium,
                        color = fg,
                        shadow = outline,
                        textDirection = if (rtl) TextDirection.Rtl else TextDirection.Ltr,
                        fontFamily = if (target == "ur") com.echoledger.mobile.ui.design.UrduNastaliq else null
                    ),
                    maxLines = s.lines,
                    modifier = Modifier.fillMaxWidth()
                )
            }
        }
    }
}

/** Watch subtitles as they will look (plan B4): bottom centre, white with an outline, optional box. */
@Composable
internal fun SubtitlePreview(style: SubtitleStyle, target: String, text: String? = null) {
    val s = style.sanitized()
    SampleFrame {
        Box(Modifier.fillMaxWidth().padding(bottom = (s.offsetDp / 6).dp + Space.s2).align(Alignment.BottomCenter)) {
            val rtl = TranslateLanguages.rtl(target)
            Text(
                text ?: sampleTranslation(target).let { if (target == "en") "The enemy is at the red house" else it },
                style = TextStyle(
                    fontSize = (s.textSp * 0.7f).sp,
                    lineHeight = (s.textSp * 0.7f * if (target == "ur") 1.7f else 1.3f).sp,
                    color = Color.White,
                    shadow = if (s.box) null else Shadow(Color.Black, blurRadius = 6f),
                    textAlign = TextAlign.Center,
                    textDirection = if (rtl) TextDirection.Rtl else TextDirection.Ltr,
                    fontFamily = if (target == "ur") com.echoledger.mobile.ui.design.UrduNastaliq else null
                ),
                maxLines = s.lines,
                modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth(0.88f)
                    .then(
                        if (s.box) {
                            Modifier.background(
                                Color.Black.copy(alpha = 0.6f)
                            ).padding(horizontal = 8.dp, vertical = 4.dp)
                        } else {
                            Modifier
                        }
                    )
            )
        }
    }
}

// ---- Game translate panel -----------------------------------------------------------------------------------------

@Composable
internal fun GameSettingsSection(vm: MainViewModel, settings: AppSettings) {
    val game = settings.liveTranslate.game
    fun save(next: GamePrefs) = vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(game = next)) }
    var picking by remember { mutableStateOf(false) }
    SectionCard {
        PanelTitle("Games", "Teammates' voice chat in these games is translated in a small box.")
        PickedApps(game.games, "No games picked yet.", { picking = true }, if (game.games.isEmpty()) "Choose games" else "Change games")
        SwitchLine("Start with the game", "Starts when a picked game plays voice chat and stops when it closes.", game.autoStart) {
            save(game.copy(autoStart = it))
        }
        PanelTitle("They speak")
        SpokenChips(game.spoken) { save(game.copy(spoken = it)) }
        PanelTitle("Translate to")
        TargetChips(game.target) { save(game.copy(target = it)) }
        PanelTitle("Speed", "Fastest shows words soonest; Accurate waits a little longer for whole sentences.")
        SpeedControl(game.speed) { save(game.copy(speed = it.name)) }
    }
    if (picking) {
        AppPickerSheet("Choose games", game.games, gamesFirst = true, onDone = {
            save(game.copy(games = it))
            picking = false
        }, onDismiss = { picking = false })
    }
}

@Composable
internal fun GameBoxSection(vm: MainViewModel, settings: AppSettings) {
    val lt = settings.liveTranslate
    val style = lt.game.style.sanitized()
    fun save(next: BoxStyle) =
        vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(game = it.liveTranslate.game.copy(style = next.sanitized()))) }
    SectionCard {
        PanelTitle("Box style", "Touches always pass through the box to the game.")
        BoxPreview(style, lt.game.target)
        LabeledSlider("Text size", style.textSp.toFloat(), BoxStyle.MIN_TEXT_SP.toFloat()..BoxStyle.MAX_TEXT_SP.toFloat(), 15, {
            "${it.toInt()} sp"
        }) {
            save(style.copy(textSp = it.toInt()))
        }
        PanelTitle("Lines")
        SegmentedControl(listOf("1", "2", "3", "4"), style.lines - 1, { save(style.copy(lines = it + 1)) })
        PanelTitle("Background")
        val kinds = BoxBackground.entries
        SegmentedControl(kinds.map { it.label }, kinds.indexOf(style.backgroundKind), { save(style.copy(background = kinds[it].name)) })
        LabeledSlider("Opacity", style.opacity, BoxStyle.MIN_OPACITY..BoxStyle.MAX_OPACITY, 4, { "${(it * 100).toInt()}%" }) {
            save(style.copy(opacity = it))
        }
        SwitchLine("Show what was said", "A small line in the original language above the translation.", style.showOriginal) {
            save(style.copy(showOriginal = it))
        }
        PanelTitle("Fade out when quiet")
        val fades = listOf(0, 3, 6, 10)
        SegmentedControl(listOf("Never", "3 s", "6 s", "10 s"), fades.indexOf(style.fadeAfterSeconds).coerceAtLeast(0), {
            save(style.copy(fadeAfterSeconds = fades[it]))
        })
        SwitchLine("Show the grip dot", "The small dot beside the box: tap to move it, hold for controls.", lt.showGrip) { on ->
            vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(showGrip = on)) }
        }
    }
}

@Composable
internal fun GameMoreSection(vm: MainViewModel, settings: AppSettings) {
    val context = LocalContext.current
    val lt = settings.liveTranslate
    val game = lt.game
    val usage = remember { TranslateUsage(context) }
    val spent = usage.spentUsd()
    SectionCard {
        PanelTitle("Privacy")
        SwitchLine("Mask swear words", "Shows **** instead of common swear words.", game.profanityMask) { on ->
            vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(game = it.liveTranslate.game.copy(profanityMask = on))) }
        }
        SwitchLine(
            "Save session text",
            "Off: nothing is kept. On: the translated text of each game goes to the Library.",
            game.saveText
        ) { on ->
            vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(game = it.liveTranslate.game.copy(saveText = on))) }
        }
        PanelTitle(
            "Monthly budget",
            "This month: ${TranslateBudgetGuard.money(spent)} of ${TranslateBudgetGuard.money(lt.monthlyBudgetUsd)}. " +
                "A warning at 80%; Live Translate pauses at 100%."
        )
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
            listOf(5.0, 10.0, 20.0, 50.0).forEach { usd ->
                EchoFilterChip(TranslateBudgetGuard.money(usd).removeSuffix(".00"), active = lt.monthlyBudgetUsd == usd, onClick = {
                    vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(monthlyBudgetUsd = usd)) }
                })
            }
        }
        val month = usage.month()
        if (spent >= lt.monthlyBudgetUsd && lt.budgetOverrideMonth != month) {
            EchoButton("Continue this month anyway", {
                vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(budgetOverrideMonth = month)) }
            }, kind = ButtonKind.Secondary)
        }
        Text(TranslateCost.estimate(), style = LocalEchoType.current.caption, color = LocalEchoColors.current.textMuted)
        SwitchLine("Show speed details", "Delay, data rate and reconnects in the box, for testing.", lt.debugHud) { on ->
            vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(debugHud = on)) }
        }
    }
}

// ---- Watch translate panel ----------------------------------------------------------------------------------------

@Composable
internal fun WatchSettingsSection(vm: MainViewModel, settings: AppSettings) {
    val watch = settings.liveTranslate.watch
    fun save(next: WatchPrefs) = vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(watch = next)) }
    var picking by remember { mutableStateOf(false) }
    SectionCard {
        PanelTitle("Apps", "Films and videos in these apps get subtitles. Apps that don't allow it say so.")
        PickedApps(watch.apps, "Whatever is playing.", { picking = true }, if (watch.apps.isEmpty()) "Choose apps" else "Change apps")
        if (watch.apps.isNotEmpty()) {
            EchoButton("Use whatever is playing", { save(watch.copy(apps = emptyList())) }, kind = ButtonKind.Text)
        }
        PanelTitle("They speak")
        SpokenChips(watch.spoken) { save(watch.copy(spoken = it)) }
        PanelTitle("Subtitles in")
        TargetChips(watch.target) { save(watch.copy(target = it)) }
        PanelTitle("Speed")
        SpeedControl(watch.speed) { save(watch.copy(speed = it.name)) }
    }
    if (picking) {
        AppPickerSheet("Choose apps", watch.apps, gamesFirst = false, onDone = {
            save(watch.copy(apps = it))
            picking = false
        }, onDismiss = { picking = false })
    }
}

@Composable
internal fun WatchStyleSection(vm: MainViewModel, settings: AppSettings) {
    val watch = settings.liveTranslate.watch
    val style = watch.style.sanitized()
    fun save(next: SubtitleStyle) =
        vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(watch = it.liveTranslate.watch.copy(style = next.sanitized()))) }
    SectionCard {
        PanelTitle("Subtitle style")
        SubtitlePreview(style, watch.target)
        LabeledSlider("Text size", style.textSp.toFloat(), 14f..32f, 17, { "${it.toInt()} sp" }) { save(style.copy(textSp = it.toInt())) }
        PanelTitle("Lines")
        SegmentedControl(listOf("1", "2", "3"), style.lines - 1, { save(style.copy(lines = it + 1)) })
        SwitchLine("Black box behind the text", "Off: white text with a dark outline.", style.box) { save(style.copy(box = it)) }
        LabeledSlider("Height above the bottom", style.offsetDp.toFloat(), 0f..320f, 15, { "${it.toInt()} dp" }) {
            save(style.copy(offsetDp = it.toInt()))
        }
        SwitchLine("Show what was said", "A small line in the original language.", style.showOriginal) {
            save(style.copy(showOriginal = it))
        }
        SwitchLine("Save subtitles (.srt)", "Off: nothing is kept. On: each video's subtitles go to the Library.", watch.saveSrt) { on ->
            vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(watch = it.liveTranslate.watch.copy(saveSrt = on))) }
        }
        Text(TranslateCost.estimate(), style = LocalEchoType.current.caption, color = LocalEchoColors.current.textMuted)
    }
}

// ---- Help & diagnostics (General) ---------------------------------------------------------------------------------

@Composable
internal fun HelpSection(vm: MainViewModel, settings: AppSettings, onSetup: () -> Unit) {
    val helper by vm.helper.collectAsState()
    var probing by remember { mutableStateOf(false) }
    val clipboard = androidx.compose.ui.platform.LocalClipboard.current
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    SectionCard {
        PanelTitle("Help & diagnostics")
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
            EchoButton("Capture test", onSetup, kind = ButtonKind.Secondary)
            EchoButton("Audio probe", { probing = true }, kind = ButtonKind.Secondary)
        }
        EchoButton("Copy diagnostics", {
            scope.launch {
                val text = vm.captureDiagnostics()
                clipboard.setClipEntry(android.content.ClipData.newPlainText("diagnostics", text).toClipEntry())
                vm.showMessage("Diagnostics copied")
            }
        }, kind = ButtonKind.Text)
        Text(
            "Diagnostics hold the app and Android versions, the helper's state and recent capture events. " +
                "No recordings, transcripts or keys.",
            style = LocalEchoType.current.caption,
            color = LocalEchoColors.current.textMuted
        )
    }
    if (probing) AudioProbeSheet(vm, helperReady = helper.connected, onDismiss = { probing = false })
}
