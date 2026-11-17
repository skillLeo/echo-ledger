package com.echoledger.mobile.ui.v2.settings

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
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
