package com.echoledger.mobile.ui

import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.data.AndroidReleaseDto
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.update.AppUpdates
import com.echoledger.mobile.update.SelfUpdate
import com.echoledger.mobile.update.UpdateStatus

/** The words and the one action for where an update stands (since v1.5; pure, tested). */
internal object UpdateText {
    enum class Action { CHECK, UPDATE, ALLOW, NONE }

    data class Line(val text: String, val action: Action, val label: String?, val kind: BannerKind = BannerKind.Info)

    fun line(status: UpdateStatus): Line = when (status) {
        UpdateStatus.Idle -> Line("", Action.CHECK, "Check for updates")

        UpdateStatus.Checking -> Line("Looking for updates…", Action.NONE, null)

        UpdateStatus.UpToDate -> Line("You have the newest version.", Action.CHECK, "Check for updates")

        is UpdateStatus.Available -> Line(
            "${AppUpdates.headline(status.release)} (${AppUpdates.size(status.release.bytes)}).",
            Action.UPDATE,
            "Update"
        )

        is UpdateStatus.Downloading -> Line("Downloading the update… ${status.percent} %", Action.NONE, null)

        is UpdateStatus.NeedsPermission -> Line(
            "Allow EchoLedger to install apps (Android asks once), then tap Update again.",
            Action.ALLOW,
            "Allow",
            BannerKind.Warning
        )

        is UpdateStatus.Installing -> Line("Installing the update: confirm on Android's screen.", Action.NONE, null)

        is UpdateStatus.Failed -> Line(
            status.message,
            if (status.release != null) Action.UPDATE else Action.CHECK,
            if (status.release != null) "Try again" else "Check for updates",
            BannerKind.Error
        )
    }

    /** The banner on the Record tab shows an update in progress, and an offer until it is closed. */
    fun bannerShown(status: UpdateStatus, hiddenCode: Int): Boolean = when (status) {
        is UpdateStatus.Available -> status.release.code != hiddenCode
        is UpdateStatus.Downloading, is UpdateStatus.NeedsPermission, is UpdateStatus.Installing -> true
        is UpdateStatus.Failed -> status.release != null
        else -> false
    }

    fun release(status: UpdateStatus): AndroidReleaseDto? = when (status) {
        is UpdateStatus.Available -> status.release
        is UpdateStatus.Downloading -> status.release
        is UpdateStatus.NeedsPermission -> status.release
        is UpdateStatus.Installing -> status.release
        is UpdateStatus.Failed -> status.release
        else -> null
    }
}

private fun act(context: android.content.Context, vm: MainViewModel, status: UpdateStatus, action: UpdateText.Action) {
    when (action) {
        UpdateText.Action.CHECK -> vm.checkForUpdates()

        UpdateText.Action.UPDATE -> UpdateText.release(status)?.let { vm.installUpdate(it) }

        UpdateText.Action.ALLOW -> runCatching {
            context.startActivity(
                Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        }

        UpdateText.Action.NONE -> Unit
    }
}

/** The website build's update offer on the Record tab (the Google Play build never shows it). */
@Composable
fun UpdateBanner(vm: MainViewModel, modifier: Modifier = Modifier) {
    if (!SelfUpdate.SUPPORTED) return
    val context = LocalContext.current
    val status by vm.updateStatus.collectAsState()
    val hidden by vm.updateHiddenCode.collectAsState()
    if (!UpdateText.bannerShown(status, hidden)) return
    val line = UpdateText.line(status)
    InlineBanner(
        line.text,
        modifier,
        kind = line.kind,
        action = line.label,
        onAction = { act(context, vm, status, line.action) },
        onDismiss = (status as? UpdateStatus.Available)?.release?.let { release -> { vm.hideUpdateBanner(release) } }
    )
}

/** Settings → About: Check for updates, and the update with its notes once there is one. */
@Composable
fun UpdateAbout(vm: MainViewModel) {
    if (!SelfUpdate.SUPPORTED) return
    val context = LocalContext.current
    val status by vm.updateStatus.collectAsState()
    val line = UpdateText.line(status)
    if (line.text.isNotEmpty()) Text(line.text, color = if (line.kind == BannerKind.Error) Brand.Danger else Brand.Muted, fontSize = 12.sp)
    (status as? UpdateStatus.Available)?.release?.notes?.takeIf { it.isNotBlank() }?.let {
        Text(it.take(600), color = Brand.Muted, fontSize = 12.sp)
    }
    line.label?.let { label ->
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = { act(context, vm, status, line.action) }) { Text(label) }
        }
    }
}
