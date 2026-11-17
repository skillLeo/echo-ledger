package com.echoledger.mobile.ui.v2.settings

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSegmented
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTopBar
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
                EchoSwitch(flags.classic, onSwitchToClassic, v2Text.classicLook, supporting = v2Text.classicLookHelp)
            }
        }
    }
}

/** Notifications: activity notifications and quiet hours, with the hours they cover. */
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
                enabled = settings.activityNotifications,
                modifier = Modifier.highlightedIf(highlight == "notifications.quiet")
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
