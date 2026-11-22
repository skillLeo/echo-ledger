package com.echoledger.mobile.ui.v2.settings

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import androidx.core.graphics.drawable.toBitmap
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.translate.BoxBackground
import com.echoledger.mobile.translate.BoxStyle
import com.echoledger.mobile.translate.GamePrefs
import com.echoledger.mobile.translate.InstalledApp
import com.echoledger.mobile.translate.SpeedPreset
import com.echoledger.mobile.translate.SubtitleStyle
import com.echoledger.mobile.translate.TranslateApps
import com.echoledger.mobile.translate.TranslateBudgetGuard
import com.echoledger.mobile.translate.TranslateCost
import com.echoledger.mobile.translate.TranslateLanguages
import com.echoledger.mobile.translate.TranslateUsage
import com.echoledger.mobile.translate.WatchPrefs
import com.echoledger.mobile.ui.BoxPreview
import com.echoledger.mobile.ui.SubtitlePreview
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** The languages people speak that the chips offer (classic's), at most four at once. */
private val SPOKEN = listOf("ar", "en", "ur", "hi", "fa", "tr")
private const val SPOKEN_MAX = 4
private val BUDGETS = listOf(5.0, 10.0, 20.0, 50.0)
private val FADES = listOf(0, 3, 6, 10)
private const val SUB_TEXT_MIN = 14f
private const val SUB_TEXT_MAX = 32f
private const val SUB_OFFSET_MAX = 320f
private const val SHEET_ROWS = 8

/** The apps the picker lists: the phone's launcher apps (read off the main thread), games first for Games. */
fun installedForPicker(context: android.content.Context, gamesFirst: Boolean): List<InstalledApp> =
    TranslateApps.forPicker(TranslateApps.installed(context), context.packageName, gamesFirst)

/** A language's name in the screen's language. */
@Composable
private fun languageName(code: String): String = when (code) {
    "en" -> stringResource(R.string.v2_lt_lang_en)
    "ur" -> stringResource(R.string.v2_lt_lang_ur)
    "ar" -> stringResource(R.string.v2_lt_lang_ar)
    "hi" -> stringResource(R.string.v2_lt_lang_hi)
    "fa" -> stringResource(R.string.v2_lt_lang_fa)
    "tr" -> stringResource(R.string.v2_lt_lang_tr)
    "ru" -> stringResource(R.string.v2_lt_lang_ru)
    "es" -> stringResource(R.string.v2_lt_lang_es)
    "fr" -> stringResource(R.string.v2_lt_lang_fr)
    "de" -> stringResource(R.string.v2_lt_lang_de)
    "pt" -> stringResource(R.string.v2_lt_lang_pt)
    "id" -> stringResource(R.string.v2_lt_lang_id)
    "ja" -> stringResource(R.string.v2_lt_lang_ja)
    "ko" -> stringResource(R.string.v2_lt_lang_ko)
    "zh" -> stringResource(R.string.v2_lt_lang_zh)
    else -> TranslateLanguages.label(code)
}

@Composable
private fun speedName(p: SpeedPreset): String = stringResource(
    when (p) {
        SpeedPreset.FASTEST -> R.string.v2_lt_fastest
        SpeedPreset.BALANCED -> R.string.v2_lt_balanced
        SpeedPreset.ACCURATE -> R.string.v2_lt_accurate
    }
)

/**
 * Games on the v2 components (classic's Game translate, Box style, and Monthly budget and privacy; the same settings):
 * the picked games with Choose or Change games, Start with the game, They speak, Translate to, Speed; the box's look
 * with its preview (text size, lines, background, opacity, Show what was said, Fade out when quiet, the grip dot);
 * Mask swear words, Save session text, the monthly budget with Continue this month anyway; Reset to defaults.
 */
@Composable
fun GamesPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    /** The apps to pick from (the phone's launcher apps, games first). */
    installed: suspend () -> List<InstalledApp>? = { null }
) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val lt = settings.liveTranslate
    val game = lt.game
    fun save(next: (GamePrefs) -> GamePrefs) = update { it.copy(liveTranslate = it.liveTranslate.copy(game = next(it.liveTranslate.game))) }
    fun style(next: (BoxStyle) -> BoxStyle) = save { it.copy(style = next(it.style.sanitized()).sanitized()) }
    var picking by remember { mutableStateOf(false) }
    val box = game.style.sanitized()
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_games), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(Modifier.highlightedIf(highlight == "games.list")) {
                Heading(stringResource(R.string.v2_gm_games), stringResource(R.string.v2_gm_games_help))
                PickedApps(
                    game.games,
                    stringResource(R.string.v2_gm_none),
                    stringResource(if (game.games.isEmpty()) R.string.v2_gm_choose else R.string.v2_gm_change)
                ) { picking = true }
                EchoSwitch(
                    game.autoStart,
                    { on -> save { it.copy(autoStart = on) } },
                    stringResource(R.string.v2_gm_autostart),
                    supporting = stringResource(R.string.v2_gm_autostart_help),
                    modifier = Modifier.highlightedIf(highlight == "games.autostart")
                )
                Spoken(game.spoken) { list -> save { it.copy(spoken = list) } }
                Target(stringResource(R.string.v2_lt_translate_to), game.target) { code -> save { it.copy(target = code) } }
                Speed(game.speed, stringResource(R.string.v2_lt_speed_help)) { p -> save { it.copy(speed = p.name) } }
            }
            Column(Modifier.highlightedIf(highlight == "games.box")) {
                Heading(stringResource(R.string.v2_gm_box), stringResource(R.string.v2_gm_box_help))
                val preview = stringResource(R.string.v2_lt_preview_game)
                Box(
                    Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile).clearAndSetSemantics {
                        contentDescription = preview
                    }
                ) {
                    BoxPreview(box, game.target)
                }
                SliderRow(
                    stringResource(R.string.v2_lt_text_size),
                    { stringResource(R.string.v2_lt_text_size_value, it.toInt()) },
                    box.textSp.toFloat(),
                    BoxStyle.MIN_TEXT_SP.toFloat()..BoxStyle.MAX_TEXT_SP.toFloat(),
                    BoxStyle.MAX_TEXT_SP - BoxStyle.MIN_TEXT_SP - 1
                ) { v -> style { it.copy(textSp = v.toInt()) } }
                Options(stringResource(R.string.v2_lt_lines), (1..4).toList(), box.lines, {
                    it.toString()
                }) { n -> style { it.copy(lines = n) } }
                Options(stringResource(R.string.v2_gm_background), BoxBackground.entries, box.backgroundKind, {
                    stringResource(
                        when (it) {
                            BoxBackground.DARK -> R.string.v2_gm_dark
                            BoxBackground.LIGHT -> R.string.v2_gm_light
                            BoxBackground.NONE -> R.string.v2_gm_none_bg
                        }
                    )
                }) { kind -> style { it.copy(background = kind.name) } }
                SliderRow(
                    stringResource(R.string.v2_gm_opacity),
                    { stringResource(R.string.v2_gm_opacity_value, (it * 100).toInt()) },
                    box.opacity,
                    BoxStyle.MIN_OPACITY..BoxStyle.MAX_OPACITY,
                    4
                ) { v -> style { it.copy(opacity = v) } }
                EchoSwitch(
                    box.showOriginal,
                    { on -> style { it.copy(showOriginal = on) } },
                    stringResource(R.string.v2_lt_show_original),
                    supporting = stringResource(R.string.v2_gm_original_help)
                )
                val never = stringResource(R.string.v2_gm_never)
                Options(stringResource(R.string.v2_gm_fade), FADES, FADES.firstOrNull { it == box.fadeAfterSeconds } ?: FADES.first(), {
                    if (it == 0) never else stringResource(R.string.v2_gm_seconds, it)
                }) { s -> style { it.copy(fadeAfterSeconds = s) } }
                EchoSwitch(
                    lt.showGrip,
                    { on -> update { it.copy(liveTranslate = it.liveTranslate.copy(showGrip = on)) } },
                    stringResource(R.string.v2_gm_grip),
                    supporting = stringResource(R.string.v2_gm_grip_help),
                    modifier = Modifier.highlightedIf(highlight == "games.grip")
                )
            }
            Column(Modifier.highlightedIf(highlight == "games.budget")) {
                EchoSectionHeader(stringResource(R.string.v2_gm_privacy))
                EchoSwitch(
                    game.profanityMask,
                    { on -> save { it.copy(profanityMask = on) } },
                    stringResource(R.string.v2_gm_mask),
                    supporting = stringResource(R.string.v2_gm_mask_help),
                    modifier = Modifier.highlightedIf(highlight == "games.mask")
                )
                EchoSwitch(
                    game.saveText,
                    { on -> save { it.copy(saveText = on) } },
                    stringResource(R.string.v2_gm_save),
                    supporting = stringResource(R.string.v2_gm_save_help),
                    modifier = Modifier.highlightedIf(highlight == "games.save")
                )
                Budget(settings, update)
            }
            Cost()
            PageReset("games", stringResource(R.string.v2_set_page_games), update)
        }
    }
    if (picking) {
        AppPicker(stringResource(R.string.v2_gm_choose), game.games, { installed() ?: installedForPicker(context, gamesFirst = true) }, {
            picking = false
            save { prefs -> prefs.copy(games = it) }
        }) { picking = false }
    }
}

/**
 * Videos on the v2 components (classic's Watch translate and Subtitle style; the same settings): the picked apps with
 * Choose or Change apps and Use whatever is playing, They speak, Subtitles in, Speed; the subtitles' look with its
 * preview (text size, lines, the black box, height above the bottom, Show what was said), Save subtitles (.srt); Reset.
 */
@Composable
fun VideosPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    installed: suspend () -> List<InstalledApp>? = { null }
) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val watch = settings.liveTranslate.watch
    fun save(next: (WatchPrefs) -> WatchPrefs) =
        update { it.copy(liveTranslate = it.liveTranslate.copy(watch = next(it.liveTranslate.watch))) }
    fun style(next: (SubtitleStyle) -> SubtitleStyle) = save { it.copy(style = next(it.style.sanitized()).sanitized()) }
    var picking by remember { mutableStateOf(false) }
    val sub = watch.style.sanitized()
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_videos), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(Modifier.highlightedIf(highlight == "videos.apps")) {
                Heading(stringResource(R.string.v2_vd_apps), stringResource(R.string.v2_vd_apps_help))
                PickedApps(
                    watch.apps,
                    stringResource(R.string.v2_vd_whatever),
                    stringResource(if (watch.apps.isEmpty()) R.string.v2_vd_choose else R.string.v2_vd_change)
                ) { picking = true }
                if (watch.apps.isNotEmpty()) {
                    EchoButton(
                        stringResource(R.string.v2_vd_use_whatever),
                        { save { it.copy(apps = emptyList()) } },
                        Modifier.padding(horizontal = EchoTokens.Space.s8),
                        kind = EchoButtonKind.Quiet
                    )
                }
                Spoken(watch.spoken) { list -> save { it.copy(spoken = list) } }
                Target(stringResource(R.string.v2_lt_subtitles_in), watch.target) { code -> save { it.copy(target = code) } }
                Speed(watch.speed, null) { p -> save { it.copy(speed = p.name) } }
            }
            Column(Modifier.highlightedIf(highlight == "videos.style")) {
                EchoSectionHeader(stringResource(R.string.v2_vd_style))
                val preview = stringResource(R.string.v2_vd_preview)
                Box(
                    Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile).clearAndSetSemantics {
                        contentDescription = preview
                    }
                ) {
                    SubtitlePreview(sub, watch.target)
                }
                SliderRow(
                    stringResource(R.string.v2_lt_text_size),
                    { stringResource(R.string.v2_lt_text_size_value, it.toInt()) },
                    sub.textSp.toFloat(),
                    SUB_TEXT_MIN..SUB_TEXT_MAX,
                    (SUB_TEXT_MAX - SUB_TEXT_MIN).toInt() - 1
                ) { v -> style { it.copy(textSp = v.toInt()) } }
                Options(stringResource(R.string.v2_lt_lines), (1..3).toList(), sub.lines, {
                    it.toString()
                }) { n -> style { it.copy(lines = n) } }
                EchoSwitch(
                    sub.box,
                    { on -> style { it.copy(box = on) } },
                    stringResource(R.string.v2_vd_box),
                    supporting = stringResource(R.string.v2_vd_box_help),
                    modifier = Modifier.highlightedIf(highlight == "videos.box")
                )
                Column(Modifier.highlightedIf(highlight == "videos.height")) {
                    SliderRow(
                        stringResource(R.string.v2_vd_height),
                        { stringResource(R.string.v2_vd_height_value, it.toInt()) },
                        sub.offsetDp.toFloat(),
                        0f..SUB_OFFSET_MAX,
                        15
                    ) { v -> style { it.copy(offsetDp = v.toInt()) } }
                }
                EchoSwitch(
                    sub.showOriginal,
                    { on -> style { it.copy(showOriginal = on) } },
                    stringResource(R.string.v2_lt_show_original),
                    supporting = stringResource(R.string.v2_vd_original_help)
                )
                EchoSwitch(
                    watch.saveSrt,
                    { on -> save { it.copy(saveSrt = on) } },
                    stringResource(R.string.v2_vd_srt),
                    supporting = stringResource(R.string.v2_vd_srt_help),
                    modifier = Modifier.highlightedIf(highlight == "videos.srt")
                )
            }
            Cost()
            PageReset("videos", stringResource(R.string.v2_set_page_videos), update)
        }
    }
    if (picking) {
        AppPicker(stringResource(R.string.v2_vd_choose), watch.apps, { installed() ?: installedForPicker(context, gamesFirst = false) }, {
            picking = false
            save { prefs -> prefs.copy(apps = it) }
        }) { picking = false }
    }
}

@Composable
private fun Heading(title: String, help: String?) {
    EchoSectionHeader(title)
    help?.let { Note(it) }
}

@Composable
private fun Note(words: String) {
    Text(
        words,
        style = EchoV2.type.meta,
        color = EchoV2.colors.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
}

/** A setting's few choices as chips, each named with its setting for TalkBack ("Lines: 2"). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun <T> Options(setting: String, options: List<T>, selected: T?, words: @Composable (T) -> String, onPick: (T) -> Unit) =
    OptionChips(setting, options, { it == selected }, words, onPick)

/** The same, where more than one can be chosen (They speak). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun <T> OptionChips(
    setting: String,
    options: List<T>,
    isChosen: (T) -> Boolean,
    words: @Composable (T) -> String,
    onPick: (T) -> Unit
) {
    Text(
        setting,
        style = EchoV2.type.label,
        color = EchoV2.colors.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
    FlowRow(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        options.forEach { option ->
            val label = words(option)
            EchoChip(label, isChosen(option), { onPick(option) }, Modifier.semantics { contentDescription = "$setting: $label" })
        }
    }
}

/** They speak: Any language, or up to four of the six (classic's chips); each chosen one is lit. */
@Composable
private fun Spoken(selected: List<String>, onChange: (List<String>) -> Unit) {
    val any = stringResource(R.string.v2_lt_any)
    OptionChips(stringResource(R.string.v2_lt_they_speak), listOf("") + SPOKEN, {
        if (it.isEmpty()) selected.isEmpty() else it in selected
    }, {
        if (it.isEmpty()) any else languageName(it)
    }) { code ->
        onChange(
            when {
                code.isEmpty() -> emptyList()
                code in selected -> selected - code
                else -> (selected + code).take(SPOKEN_MAX)
            }
        )
    }
}

@Composable
private fun Target(setting: String, selected: String, onPick: (String) -> Unit) {
    Options(setting, TranslateLanguages.TARGETS.map { it.code }, selected, { languageName(it) }, onPick)
}

@Composable
private fun Speed(selected: String, help: String?, onPick: (SpeedPreset) -> Unit) {
    val setting = stringResource(R.string.v2_lt_speed)
    Options(setting, SpeedPreset.entries, SpeedPreset.of(selected), { speedName(it) }, onPick)
    help?.let { Note(it) }
}

/** A slider with its value in words ("Text size · 16 sp"), saved when it is let go, named for TalkBack. */
@Composable
private fun SliderRow(
    name: String,
    shown: @Composable (Float) -> String,
    value: Float,
    range: ClosedFloatingPointRange<Float>,
    steps: Int,
    onDone: (Float) -> Unit
) {
    val c = EchoV2.colors
    var current by remember(value) { mutableFloatStateOf(value) }
    Column(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)) {
        Text(shown(current), style = EchoV2.type.label, color = c.textSecondary)
        Slider(
            value = current,
            onValueChange = { current = it },
            onValueChangeFinished = { onDone(current) },
            valueRange = range,
            steps = steps,
            colors = SliderDefaults.colors(thumbColor = c.accent, activeTrackColor = c.accent, inactiveTrackColor = c.bgSubtle),
            modifier = Modifier.semantics { contentDescription = name }
        )
    }
}

/** The picked apps with their icons (or [empty]), and the button that opens the picker. */
@Composable
private fun PickedApps(packages: List<String>, empty: String, editLabel: String, onEdit: () -> Unit) {
    val context = LocalContext.current
    val c = EchoV2.colors
    Column(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        if (packages.isEmpty()) {
            Text(empty, style = EchoV2.type.body, color = c.textSecondary)
        } else {
            packages.forEach { pkg ->
                val label = remember(pkg) { TranslateApps.label(context, pkg) }
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                    AppIcon(pkg, EchoTokens.Icon.lg)
                    Text(label, style = EchoV2.type.content(label, EchoV2.type.body), color = c.textPrimary)
                }
            }
        }
        EchoButton(editLabel, onEdit, kind = EchoButtonKind.Secondary)
    }
}

/** An installed app's own icon, or a plain tile when it is gone. */
@Composable
private fun AppIcon(pkg: String, size: Dp) {
    val context = LocalContext.current
    val px = with(LocalDensity.current) { size.roundToPx() }
    val icon: ImageBitmap? = com.echoledger.mobile.ui.design.rememberAppIcon("package:$pkg:$px") {
        context.packageManager.getApplicationIcon(pkg).toBitmap(px, px).asImageBitmap()
    }
    if (icon != null) {
        Image(icon, contentDescription = null, modifier = Modifier.size(size))
    } else {
        Box(Modifier.size(size).clip(RoundedCornerShape(EchoTokens.Radius.sm)).background(EchoV2.colors.bgSubtle))
    }
}

/** The apps on the phone with a tick each (games marked); Done saves the ticked ones in the list's order. */
@Composable
private fun AppPicker(
    title: String,
    picked: List<String>,
    load: suspend () -> List<InstalledApp>,
    onDone: (List<String>) -> Unit,
    onDismiss: () -> Unit
) {
    val apps by produceState<List<InstalledApp>?>(null) { value = withContext(Dispatchers.IO) { load() } }
    var chosen by remember { mutableStateOf(picked.toSet()) }
    EchoBottomSheet(onDismiss, title) {
        EchoButton(
            stringResource(R.string.v2_lt_done),
            { onDone(apps?.map { it.packageName }?.filter { it in chosen } ?: picked) },
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile).fillMaxWidth()
        )
        LazyColumn(Modifier.fillMaxWidth().heightIn(max = EchoTokens.Space.s64 * SHEET_ROWS)) {
            if (apps == null) item { EchoSkeletonList(4) }
            if (apps?.isEmpty() == true) item { Note(stringResource(R.string.v2_lt_no_apps)) }
            items(apps.orEmpty(), key = { it.packageName }) { app ->
                val on = app.packageName in chosen
                EchoCheckbox(
                    on,
                    { chosen = if (it) chosen + app.packageName else chosen - app.packageName },
                    app.label,
                    Modifier.padding(horizontal = EchoTokens.Space.s8),
                    supporting = if (app.game) stringResource(R.string.v2_lt_game_tag) else null
                )
            }
        }
    }
}

/** Live Translate's price and data in plain words. */
@Composable
private fun Cost() {
    Note(
        stringResource(
            R.string.v2_lt_cost,
            "%.2f".format(java.util.Locale.US, TranslateCost.USD_PER_HOUR),
            TranslateCost.MB_PER_HOUR_SPEAKING
        )
    )
}

/** Monthly budget: this month's spending, $5 to $50, and Continue this month anyway once it is reached. */
@Composable
private fun Budget(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit) {
    val context = LocalContext.current
    val lt = settings.liveTranslate
    val usage = remember { TranslateUsage(context) }
    val spent = usage.spentUsd()
    val setting = stringResource(R.string.v2_gm_budget)
    EchoSectionHeader(setting)
    Note(stringResource(R.string.v2_gm_budget_help, TranslateBudgetGuard.money(spent), TranslateBudgetGuard.money(lt.monthlyBudgetUsd)))
    Options(
        setting,
        BUDGETS,
        BUDGETS.firstOrNull {
            it == lt.monthlyBudgetUsd
        },
        { TranslateBudgetGuard.money(it).removeSuffix(".00") }
    ) { usd ->
        update { it.copy(liveTranslate = it.liveTranslate.copy(monthlyBudgetUsd = usd)) }
    }
    val month = usage.month()
    if (spent >= lt.monthlyBudgetUsd && lt.budgetOverrideMonth != month) {
        EchoButton(
            stringResource(R.string.v2_gm_continue),
            { update { it.copy(liveTranslate = it.liveTranslate.copy(budgetOverrideMonth = month)) } },
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            kind = EchoButtonKind.Secondary
        )
    }
}
