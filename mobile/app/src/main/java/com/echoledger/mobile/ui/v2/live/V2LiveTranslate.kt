package com.echoledger.mobile.ui.v2.live

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.echoledger.mobile.R
import com.echoledger.mobile.translate.LiveTranslateService
import com.echoledger.mobile.translate.TranslateCost
import com.echoledger.mobile.translate.TranslateMode
import com.echoledger.mobile.translate.TranslateStatus
import com.echoledger.mobile.translate.TranslateToggleActivity
import com.echoledger.mobile.ui.HealthItem
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.PermissionHealth
import com.echoledger.mobile.ui.permissionIntent
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** A Live Translate mode's name in the screen's language. */
@Composable
fun translateModeName(mode: TranslateMode): String =
    stringResource(if (mode == TranslateMode.GAME) R.string.v2_lt_game else R.string.v2_lt_watch)

/** The language the box writes in, in the screen's language for the three that matter here; the rest as named. */
@Composable
private fun targetName(code: String?): String = when (code) {
    "en" -> stringResource(R.string.v2_lt_lang_en)
    "ur" -> stringResource(R.string.v2_lt_lang_ur)
    "ar" -> stringResource(R.string.v2_lt_lang_ar)
    else -> com.echoledger.mobile.translate.TranslateLanguages.label(code)
}

/** Why the last start ended, in the screen's words ("Needs credit"…); null when nothing stops it. */
@Composable
private fun blockedWords(short: String?): String? = when (short) {
    null -> null
    "Needs credit" -> stringResource(R.string.v2_lt_blocked_credit)
    "Not set up on the server" -> stringResource(R.string.v2_lt_blocked_not_set_up)
    "Budget used up" -> stringResource(R.string.v2_lt_blocked_budget)
    else -> short
}

@Composable
private fun healthWords(item: HealthItem, game: Boolean): Pair<String, String?> = when (item.key) {
    "overlay" -> stringResource(R.string.v2_lt_h_overlay) to null

    "helper" -> stringResource(R.string.v2_lt_h_helper) to
        if (item.ok) null else stringResource(if (game) R.string.v2_lt_h_helper_game else R.string.v2_lt_h_helper_watch)

    "notifications" -> stringResource(R.string.v2_lt_h_notifications) to null

    "battery" -> stringResource(R.string.v2_lt_h_battery) to
        if (item.ok) null else stringResource(if (game) R.string.v2_lt_h_battery_game else R.string.v2_lt_h_battery_watch)

    else -> item.title to item.note
}

/**
 * Game translate and Watch translate on the new components (classic's LiveTranslatePage): whether it is on and in
 * which language, Start and Stop; why the last start ended; what it still needs with one-tap fixes; Try it on a short
 * line, with what it costs; and the mode's settings ([onSettings], Settings › Live › Games or Videos).
 */
@Composable
fun V2LiveTranslatePage(vm: MainViewModel, mode: TranslateMode, onBack: () -> Unit, onSetup: () -> Unit, onSettings: () -> Unit) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val settings by vm.settings.collectAsState()
    val helper by vm.helper.collectAsState()
    val running by LiveTranslateService.state.collectAsState()
    val test by vm.testFeed.collectAsState()
    // Permissions can change in the phone's settings: read them again whenever the page comes back.
    var resumed by remember { mutableIntStateOf(0) }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, e -> if (e == Lifecycle.Event.ON_RESUME) resumed++ }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            vm.clearTestTranslate()
        }
    }
    val game = mode == TranslateMode.GAME
    val health = remember(resumed, helper.connected) { PermissionHealth.items(context, helper.connected, game) }
    val on = running == mode
    val target = if (game) settings.liveTranslate.game.target else settings.liveTranslate.watch.target
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    Column(Modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(translateModeName(mode), onBack = onBack)
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = EchoTokens.Space.s24),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            val blocked = blockedWords(remember(resumed, running, test?.status) { TranslateStatus.blocked(context) })
            if (blocked != null && !on) {
                EchoBanner(
                    stringResource(R.string.v2_lt_blocked, blocked),
                    EchoBannerKind.Warning,
                    pad,
                    onClose = {
                        TranslateStatus.remember(context, null)
                        resumed++
                    },
                    closeLabel = stringResource(R.string.v2_lib_server_close)
                )
            }
            EchoCard(pad) {
                Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                        Box(Modifier.size(EchoTokens.Space.s8).background(if (on) c.success else c.borderStrong, CircleShape))
                        Text(
                            if (on) stringResource(R.string.v2_lt_on, targetName(target)) else stringResource(R.string.v2_lt_off),
                            style = EchoV2.type.bodyStrong,
                            color = c.textPrimary
                        )
                    }
                    Text(
                        stringResource(
                            when {
                                game && helper.connected -> R.string.v2_lt_how_game_helper
                                game -> R.string.v2_lt_how_game
                                helper.connected -> R.string.v2_lt_how_watch_helper
                                else -> R.string.v2_lt_how_watch
                            }
                        ),
                        style = EchoV2.type.meta,
                        color = c.textSecondary
                    )
                    if (game) Text(stringResource(R.string.v2_lt_voice_chat), style = EchoV2.type.meta, color = c.textSecondary)
                    EchoButton(
                        stringResource(if (on) R.string.v2_lt_stop else R.string.v2_lt_start),
                        {
                            if (on) {
                                LiveTranslateService.stop(context)
                            } else {
                                context.startActivity(TranslateToggleActivity.intent(context, mode, startOnly = true))
                            }
                        },
                        kind = if (on) EchoButtonKind.Secondary else EchoButtonKind.Primary,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            }
            if (!health.all { it.ok }) {
                Text(
                    stringResource(R.string.v2_lt_before),
                    style = EchoV2.type.label,
                    color = c.textSecondary,
                    modifier = pad.semantics { heading() }
                )
                health.forEach { item ->
                    val (title, note) = healthWords(item, game)
                    EchoSettingRow(
                        title,
                        subtitle = note,
                        icon = if (item.ok) EchoIcons.Check else EchoIcons.Error,
                        modifier = Modifier.semantics {
                            stateDescription = context.getString(if (item.ok) R.string.v2_lt_h_done else R.string.v2_lt_h_needed)
                        },
                        trailing = if (item.ok) {
                            null
                        } else {
                            {
                                EchoButton(
                                    stringResource(if (item.key == "helper") R.string.v2_lt_set_up else R.string.v2_lt_allow),
                                    {
                                        if (item.key == "helper") {
                                            onSetup()
                                        } else {
                                            permissionIntent(context, item.key)?.let { runCatching { context.startActivity(it) } }
                                        }
                                    },
                                    kind = EchoButtonKind.Secondary
                                )
                            }
                        }
                    )
                }
            }
            EchoCard(pad) {
                Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    Text(
                        stringResource(R.string.v2_lt_try),
                        style = EchoV2.type.bodyStrong,
                        color = c.textPrimary,
                        modifier = Modifier.semantics { heading() }
                    )
                    Text(
                        stringResource(if (game) R.string.v2_lt_try_game else R.string.v2_lt_try_watch),
                        style = EchoV2.type.meta,
                        color = c.textSecondary
                    )
                    test?.let { feed ->
                        // What the test heard and wrote: the translation in its script's face, the original under it.
                        val shown = feed.notice ?: feed.shown.ifBlank { "…" }
                        Text(shown, style = EchoV2.type.content(shown, EchoV2.type.body), color = c.textPrimary)
                        feed.shownOriginal.takeIf { game && it.isNotBlank() }?.let {
                            Text(it, style = EchoV2.type.content(it, EchoV2.type.meta), color = c.textSecondary)
                        }
                    }
                    EchoButton(
                        stringResource(if (test == null) R.string.v2_lt_test else R.string.v2_lt_test_again),
                        { vm.testTranslate(mode) },
                        kind = EchoButtonKind.Secondary
                    )
                    Text(
                        stringResource(
                            R.string.v2_lt_cost,
                            "%.2f".format(java.util.Locale.ROOT, TranslateCost.USD_PER_HOUR),
                            TranslateCost.MB_PER_HOUR_SPEAKING
                        ),
                        style = EchoV2.type.caption,
                        color = c.textSecondary
                    )
                }
            }
            EchoSettingRow(
                stringResource(if (game) R.string.v2_lt_settings_game else R.string.v2_lt_settings_watch),
                icon = if (game) EchoIcons.Games else EchoIcons.Videos,
                trailing = { Icon(EchoIcons.Next, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary) },
                onClick = onSettings
            )
        }
    }
}
