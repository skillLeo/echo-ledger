package com.echoledger.mobile.ui.v2.settings

import android.content.Intent
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Activity
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSegmented
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoToastResult
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** A setting a search result jumped to: drawn on the accent's soft fill (static; nothing moves). */
@Composable
fun Modifier.highlightedIf(on: Boolean): Modifier = if (on) background(EchoV2.colors.accentSoft) else this

/** Appearance: the theme (System, Light, Dark), the phone's font, and Classic look while v2 is on. */
@Composable
fun AppearancePage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    flags: UiFlags,
    onSwitchToClassic: (Boolean) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val modes = listOf("system", "light", "dark")
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_appearance), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(Modifier.highlightedIf(highlight == "appearance.theme")) {
                EchoSectionHeader(stringResource(R.string.v2_set_theme))
                EchoSegmented(
                    options = listOf(
                        stringResource(R.string.v2_set_value_system),
                        stringResource(R.string.v2_set_value_light),
                        stringResource(R.string.v2_set_value_dark)
                    ),
                    selectedIndex = modes.indexOf(settings.themeMode).coerceAtLeast(0),
                    onSelect = { i -> update { it.copy(themeMode = modes[i]) } },
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
                )
            }
            EchoSwitch(
                checked = settings.phoneFont,
                onCheckedChange = { on -> update { it.copy(phoneFont = on) } },
                label = stringResource(R.string.v2_set_phone_font),
                supporting = stringResource(R.string.v2_set_phone_font_help),
                modifier = Modifier.highlightedIf(highlight == "appearance.font")
            )
            if (flags.offersClassicSwitch) {
                com.echoledger.mobile.ui.v2.shell.SwitchBackToClassic { onSwitchToClassic(true) }
            }
            PageReset("appearance", stringResource(R.string.v2_set_page_appearance), update)
        }
    }
}

/**
 * Notifications: activity notifications; quiet hours with the hours they cover (From and To while on); each kind of
 * notification on or off, with Test and its sound and vibration in Android; Reset to defaults. Every control of today's
 * page (AppearanceNotificationsParityTest).
 */
@Composable
fun NotificationsPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_notifications), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            EchoSwitch(
                checked = settings.activityNotifications,
                onCheckedChange = { on -> update { it.copy(activityNotifications = on) } },
                label = stringResource(R.string.v2_set_item_notifications_activity),
                supporting = stringResource(R.string.v2_set_activity_help),
                modifier = Modifier.highlightedIf(highlight == "notifications.activity")
            )
            EchoSwitch(
                checked = settings.quietHours,
                onCheckedChange = { on -> update { it.copy(quietHours = on) } },
                label = stringResource(R.string.v2_set_item_notifications_quiet),
                supporting = stringResource(R.string.v2_set_quiet_help, settings.quietFrom, settings.quietTo),
                modifier = Modifier.highlightedIf(highlight == "notifications.quiet")
            )
            if (settings.quietHours) QuietHours(settings, update)
            NotificationKinds(settings, update)
            PageReset("notifications", stringResource(R.string.v2_set_page_notifications), update)
        }
    }
}

/**
 * From and To of quiet hours, as 24-hour times; a time is saved once it reads as one. Four digits get their ":" here
 * ([quietTime]), so a keyboard without one still works.
 */
@Composable
private fun QuietHours(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit) {
    var from by remember(settings.quietFrom) { mutableStateOf(settings.quietFrom) }
    var to by remember(settings.quietTo) { mutableStateOf(settings.quietTo) }
    val bad = stringResource(R.string.v2_ntf_quiet_bad)
    Row(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        EchoTextField(
            from,
            {
                from = quietTime(it)
                if (Activity.parseHhmm(from) != null) update { s -> s.copy(quietFrom = from) }
            },
            label = stringResource(R.string.v2_ntf_quiet_from),
            error = bad.takeIf { Activity.parseHhmm(from) == null },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii),
            modifier = Modifier.weight(1f)
        )
        EchoTextField(
            to,
            {
                to = quietTime(it)
                if (Activity.parseHhmm(to) != null) update { s -> s.copy(quietTo = to) }
            },
            label = stringResource(R.string.v2_ntf_quiet_to),
            error = bad.takeIf { Activity.parseHhmm(to) == null },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii),
            modifier = Modifier.weight(1f)
        )
    }
}

/** "22:00". */
private const val HHMM = 5

/** What was typed in a quiet-hours time: "2230" reads as "22:30"; anything else as typed, at most five characters. */
internal fun quietTime(typed: String): String {
    val t = typed.trim()
    return if (t.length == 4 && t.all(Char::isDigit)) t.substring(0, 2) + ":" + t.substring(2) else t.take(HHMM)
}

/** A kind of notification's name and line, in the screen's language. */
private fun kindWords(category: NotifyCategory): Pair<Int, Int> = when (category) {
    NotifyCategory.RECORDING -> R.string.v2_ntf_recording to R.string.v2_ntf_recording_help
    NotifyCategory.TRANSCRIPTION -> R.string.v2_ntf_transcription to R.string.v2_ntf_transcription_help
    NotifyCategory.ENHANCEMENT -> R.string.v2_ntf_enhancement to R.string.v2_ntf_enhancement_help
    NotifyCategory.UPLOADS -> R.string.v2_ntf_uploads to R.string.v2_ntf_uploads_help
    NotifyCategory.READINESS -> R.string.v2_ntf_readiness to R.string.v2_ntf_readiness_help
    NotifyCategory.SHARING -> R.string.v2_ntf_sharing to R.string.v2_ntf_sharing_help
    NotifyCategory.ACCOUNT -> R.string.v2_ntf_account to R.string.v2_ntf_account_help
    NotifyCategory.USAGE -> R.string.v2_ntf_usage to R.string.v2_ntf_usage_help
    NotifyCategory.LIVE_TRANSLATE -> R.string.v2_ntf_live_translate to R.string.v2_ntf_live_translate_help
    NotifyCategory.UPDATES -> R.string.v2_ntf_updates to R.string.v2_ntf_updates_help
    NotifyCategory.OTHER -> R.string.v2_ntf_other to R.string.v2_ntf_other_help
}

/**
 * Each kind of notification: on or off (Recording is always on), Test, and its sound and vibration in Android's own
 * page; a kind on here but off in Android says so (checked again on coming back).
 */
@Composable
private fun NotificationKinds(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit) {
    val c = EchoV2.colors
    val context = LocalContext.current
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    var looked by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { looked++ }
    fun open(intent: Intent) = runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
    val allowFirst = stringResource(R.string.v2_ntf_allow_first)
    val openSettings = stringResource(R.string.v2_ntf_open_settings)
    EchoSectionHeader(stringResource(R.string.v2_ntf_kinds))
    NotifyCategory.entries.forEach { category ->
        val (titleRes, lineRes) = kindWords(category)
        val title = stringResource(titleRes)
        val on = category.key !in settings.notifyOff
        val androidOn = remember(looked) { Notifications.channelOn(context, category) }
        if (category == NotifyCategory.RECORDING) {
            EchoSettingRow(title, subtitle = stringResource(lineRes), value = stringResource(R.string.v2_ntf_always_on))
        } else {
            EchoSwitch(
                checked = on,
                onCheckedChange = { v ->
                    update { s -> s.copy(notifyOff = if (v) s.notifyOff - category.key else (s.notifyOff + category.key).distinct()) }
                },
                label = title,
                supporting = stringResource(lineRes)
            )
        }
        val warning = when {
            on && !androidOn -> R.string.v2_ntf_android_off
            category.critical && !on -> R.string.v2_ntf_critical_off
            else -> null
        }
        warning?.let {
            Text(
                stringResource(it),
                style = EchoV2.type.meta,
                color = c.warning,
                modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
            )
        }
        val testWords = stringResource(R.string.v2_ntf_test_cd, title)
        val soundWords = stringResource(R.string.v2_ntf_sound_cd, title)
        Row(
            Modifier.padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s4),
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            EchoButton(
                stringResource(R.string.v2_ntf_test),
                {
                    if (!Notifications.test(context, category)) {
                        scope.launch {
                            if (toasts.show(allowFirst, openSettings) == EchoToastResult.Action) {
                                val page = Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                                open(page.putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName))
                            }
                        }
                    }
                },
                Modifier.semantics { contentDescription = testWords },
                kind = EchoButtonKind.Quiet
            )
            EchoButton(
                stringResource(if (on && !androidOn) R.string.v2_ntf_turn_on else R.string.v2_ntf_sound),
                { open(Notifications.channelSettings(context, category)) },
                Modifier.semantics { contentDescription = soundWords },
                kind = EchoButtonKind.Quiet
            )
        }
    }
}

/**
 * "Reset to defaults" at the end of a page whose choices have defaults (as today's pages): it asks first, then puts only
 * that page's choices back (SettingsReset; picked apps, games and words stay) and says so.
 */
@Composable
fun PageReset(page: String, title: String, update: ((AppSettings) -> AppSettings) -> Unit) {
    val group = when (page) {
        "appearance", "notifications", "lock", "language" -> com.echoledger.mobile.core.SettingsGroup.General
        "voice" -> com.echoledger.mobile.core.SettingsGroup.VoiceNotes
        "calls", "mic", "captions" -> com.echoledger.mobile.core.SettingsGroup.Calls
        "games" -> com.echoledger.mobile.core.SettingsGroup.Game
        "videos" -> com.echoledger.mobile.core.SettingsGroup.Watch
        else -> null
    } ?: return
    val toasts = com.echoledger.mobile.ui.v2.shell.LocalEchoToasts.current
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val done = stringResource(R.string.v2_calls_reset_done, title)
    var confirm by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(false) }
    com.echoledger.mobile.ui.v2.components.EchoButton(
        stringResource(R.string.v2_calls_reset),
        { confirm = true },
        Modifier.padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8),
        kind = com.echoledger.mobile.ui.v2.components.EchoButtonKind.Quiet
    )
    if (confirm) {
        com.echoledger.mobile.ui.v2.components.EchoDialog(
            title = stringResource(R.string.v2_calls_reset),
            text = stringResource(R.string.v2_calls_reset_text, title),
            confirmLabel = stringResource(R.string.v2_calls_reset_confirm),
            onConfirm = {
                confirm = false
                update { com.echoledger.mobile.core.SettingsReset.resetPage(page, group, it) }
                scope.launch { toasts.show(done) }
            },
            dismissLabel = stringResource(R.string.v2_calls_reset_cancel),
            onDismiss = { confirm = false }
        )
    }
}
