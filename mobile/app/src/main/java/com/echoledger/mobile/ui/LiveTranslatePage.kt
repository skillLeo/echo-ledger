package com.echoledger.mobile.ui

import android.Manifest
import android.content.Context
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ErrorOutline
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.echoledger.mobile.translate.LiveTranslateService
import com.echoledger.mobile.translate.TranslateCost
import com.echoledger.mobile.translate.TranslateLanguages
import com.echoledger.mobile.translate.TranslateMode
import com.echoledger.mobile.translate.TranslateToggleActivity
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoTopBar

/** One line of the permissions health card: what it is, whether it is fine, and the one-tap fix. */
data class HealthItem(val key: String, val title: String, val ok: Boolean, val fix: String?, val note: String? = null)

object PermissionHealth {
    /** What Live Translate needs: the box over other apps, the helper (voice chat), notifications, no sleeping. */
    fun items(context: Context, helperConnected: Boolean, forGame: Boolean): List<HealthItem> {
        val overlay = Settings.canDrawOverlays(context)
        val notifications = Build.VERSION.SDK_INT < 33 || granted(context, Manifest.permission.POST_NOTIFICATIONS)
        val awake = context.getSystemService(PowerManager::class.java)?.isIgnoringBatteryOptimizations(context.packageName) == true
        return listOf(
            HealthItem("overlay", "Display over other apps", overlay, if (overlay) null else "Allow"),
            HealthItem(
                "helper",
                "Capture helper",
                helperConnected,
                if (helperConnected) null else "Set up",
                note = if (helperConnected) {
                    null
                } else if (forGame) {
                    "Needed to hear voice chat."
                } else {
                    "Without it Android asks each time."
                }
            ),
            HealthItem("notifications", "Notifications", notifications, if (notifications) null else "Allow"),
            HealthItem(
                "battery",
                "Never sleeping app",
                awake,
                if (awake) null else "Allow",
                note = if (awake) null else "Keeps it running during long games."
            )
        )
    }
}

/** The health card with one-tap fixes (plan B5/C8). [onHelper] opens the helper setup. */
@Composable
fun PermissionHealthCard(items: List<HealthItem>, onHelper: () -> Unit) {
    val context = LocalContext.current
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    SectionCard {
        PanelTitle(if (items.all { it.ok }) "Ready" else "Before you start")
        items.forEach { item ->
            Row(
                Modifier.fillMaxWidth().semantics { stateDescription = if (item.ok) "Done" else "Needs attention" },
                verticalAlignment = Alignment.CenterVertically
            ) {
                Icon(
                    if (item.ok) Icons.Rounded.CheckCircle else Icons.Rounded.ErrorOutline,
                    contentDescription = null,
                    tint = if (item.ok) c.success else c.warning,
                    modifier = Modifier.size(20.dp)
                )
                Spacer(Modifier.width(Space.s3))
                Column(Modifier.weight(1f)) {
                    Text(item.title, style = type.bodyM, color = c.text)
                    item.note?.let { Text(it, style = type.caption, color = c.textMuted) }
                }
                item.fix?.let { label ->
                    EchoButton(label, {
                        if (item.key == "helper") {
                            onHelper()
                        } else {
                            permissionIntent(context, item.key)?.let { runCatching { context.startActivity(it) } }
                        }
                    }, kind = ButtonKind.Secondary)
                }
            }
        }
    }
}

/**
 * Game translate and Watch translate (since v1.3, plan C6): Start / Stop, the health card, a test over a sample frame,
 * and the mode's settings (the same panels as in You).
 */
@Composable
fun LiveTranslatePage(vm: MainViewModel, mode: TranslateMode, onSetup: () -> Unit) {
    val context = LocalContext.current
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
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
    val health = remember(resumed, helper.connected) { PermissionHealth.items(context, helper.connected, mode == TranslateMode.GAME) }
    val on = running == mode
    val target = if (mode == TranslateMode.GAME) settings.liveTranslate.game.target else settings.liveTranslate.watch.target
    Column(Modifier.fillMaxSize()) {
        EchoTopBar(mode.label)
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = Space.screen).padding(bottom = 96.dp),
            verticalArrangement = Arrangement.spacedBy(Space.s3)
        ) {
            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(10.dp).clip(CircleShape).background(if (on) c.success else c.border))
                    Spacer(Modifier.width(Space.s2))
                    Text(
                        if (on) "On · translating to ${TranslateLanguages.label(target)}" else "Off",
                        style = type.bodyL,
                        color = c.text,
                        modifier = Modifier.weight(1f)
                    )
                }
                Text(
                    when {
                        mode == TranslateMode.GAME && helper.connected -> "Hears the game through the capture helper, voice chat included. Touches always reach the game."
                        mode == TranslateMode.GAME -> "Without the capture helper Android asks to share the sound each time, and voice chat may be missing."
                        helper.connected -> "Subtitles appear over the video. Apps that don't allow captions say so."
                        else -> "Android asks to share the sound each time. Apps that don't allow captions stay silent."
                    },
                    style = type.labelM,
                    color = c.textMuted
                )
                if (mode == TranslateMode.GAME) {
                    Text("Works over the game's own voice chat; typed chat is not translated.", style = type.caption, color = c.textMuted)
                }
                EchoButton(
                    if (on) "Stop" else "Start",
                    {
                        if (on) {
                            LiveTranslateService.stop(context)
                        } else {
                            context.startActivity(TranslateToggleActivity.intent(context, mode, startOnly = true))
                        }
                    },
                    Modifier.fillMaxWidth(),
                    kind = if (on) ButtonKind.Secondary else ButtonKind.Primary
                )
            }
            if (!health.all { it.ok }) PermissionHealthCard(health, onHelper = onSetup)
            SectionCard {
                PanelTitle(
                    "Try it",
                    if (mode ==
                        TranslateMode.GAME
                    ) {
                        "Plays a short Arabic line and translates it, no game needed."
                    } else {
                        "Plays a short English line and subtitles it."
                    }
                )
                val feed = test
                if (mode == TranslateMode.GAME) {
                    BoxPreview(
                        settings.liveTranslate.game.style,
                        target,
                        text = feed?.let {
                            it.notice ?: it.shown.ifBlank { "…" }
                        },
                        original = feed?.shownOriginal?.ifBlank { null }
                    )
                } else {
                    SubtitlePreview(settings.liveTranslate.watch.style, target, text = feed?.let { it.notice ?: it.shown.ifBlank { "…" } })
                }
                EchoButton(if (feed == null) "Test translate" else "Test again", { vm.testTranslate(mode) }, kind = ButtonKind.Secondary)
                Text(TranslateCost.estimate(), style = type.caption, color = c.textMuted)
            }
            if (mode == TranslateMode.GAME) {
                GameSettingsSection(vm, settings)
                GameBoxSection(vm, settings)
                GameMoreSection(vm, settings)
            } else {
                WatchSettingsSection(vm, settings)
                WatchStyleSection(vm, settings)
            }
        }
    }
}
