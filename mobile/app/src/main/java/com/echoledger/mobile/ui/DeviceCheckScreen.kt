package com.echoledger.mobile.ui

import android.Manifest
import android.app.ActivityManager
import android.content.ClipData
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.toClipEntry
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.core.CheckFix
import com.echoledger.mobile.core.CheckRow
import com.echoledger.mobile.core.CheckState
import com.echoledger.mobile.core.DeviceCheck
import com.echoledger.mobile.core.PhoneBrand
import com.echoledger.mobile.core.PhoneFacts
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.recording.CaptureHealth
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import kotlin.math.ceil
import kotlinx.coroutines.launch

/** What this phone says about itself for the device check (since v1.5). */
fun Context.phoneFacts(helperConnected: Boolean, settings: AppSettings): PhoneFacts {
    val memory = ActivityManager.MemoryInfo().also { getSystemService(ActivityManager::class.java)?.getMemoryInfo(it) }
    return PhoneFacts(
        manufacturer = Build.MANUFACTURER.orEmpty(),
        brand = Build.BRAND.orEmpty(),
        model = Build.MODEL.orEmpty(),
        release = Build.VERSION.RELEASE.orEmpty(),
        appVersion = BuildConfig.VERSION_NAME,
        capabilities = capabilities(helperConnected),
        batteryExempt = CaptureHealth.ignoresBatteryOptimisation(this),
        hasPhoneLine = packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY),
        phoneCallsInBuild = BuildConfig.CALL_LOG,
        signedIn = settings.paired,
        liveTranslateInPlan = settings.plan.liveTranslate,
        memoryGb = ceil(memory.totalMem / GIGABYTE).toInt()
    )
}

private const val GIGABYTE = 1024.0 * 1024.0 * 1024.0

/** Opens the phone maker's own screen, else this app's settings page, where the battery and autostart rows are. */
internal fun openBrandSettings(context: Context, brand: PhoneBrand) {
    val intents = brand.backgroundScreens.map { screen -> Intent().setComponent(ComponentName(screen.pkg, screen.cls)) } +
        Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null))
    intents.firstOrNull { runCatching { context.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess }
}

/** The row itself: what works, the plain reason, and its one-tap fix. */
@Composable
internal fun CheckRowView(row: CheckRow, onFix: ((CheckFix) -> Unit)? = null) {
    val c = LocalEchoColors.current
    val color = when (row.state) {
        CheckState.OK -> Brand.Success
        CheckState.LIMITED -> Brand.Warning
        CheckState.OFF -> Brand.Muted
    }
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(row.title, color = c.text, style = LocalEchoType.current.bodyL)
            Text("${row.state.word}. ${row.detail}", color = color, style = LocalEchoType.current.caption)
        }
        val fix = row.fix
        if (fix != null && onFix != null) EchoButton(fix.label, { onFix(fix) }, kind = ButtonKind.Text)
    }
}

/** The phone maker's own steps that keep EchoLedger running, with its settings screen one tap away. */
@Composable
internal fun BrandStepsCard(brand: PhoneBrand) {
    val context = LocalContext.current
    Column(verticalArrangement = Arrangement.spacedBy(Space.s1)) {
        Text("Keep EchoLedger running on ${brand.label}", style = LocalEchoType.current.bodyL, color = LocalEchoColors.current.text)
        brand.backgroundSteps.forEach { Text(it, style = LocalEchoType.current.caption, color = LocalEchoColors.current.textMuted) }
        EchoButton("Open settings", { openBrandSettings(context, brand) }, kind = ButtonKind.Text)
    }
}

/**
 * Help & diagnostics → Device check (since v1.5): what works on this phone and why, one-tap fixes, the steps for this
 * phone's maker, Copy report, and Send to my admin, which is the only thing that ever uploads it.
 */
@Composable
fun DeviceCheckSection(vm: MainViewModel, settings: AppSettings, onSetup: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val clipboard = LocalClipboard.current
    val helper by vm.helper.collectAsState()
    var refresh by remember { mutableIntStateOf(0) }
    var sending by remember { mutableStateOf(false) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { refresh++ }
    val mic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { refresh++ }
    val facts = remember(refresh, helper.connected, settings.plan, settings.paired) { context.phoneFacts(helper.connected, settings) }
    val rows = remember(facts) { DeviceCheck.rows(facts) }
    val brand = remember(facts) { PhoneBrand.of(facts.manufacturer, facts.brand) }
    SectionCard {
        PanelTitle("Device check", "What works on this phone, and why.")
        Column(verticalArrangement = Arrangement.spacedBy(Space.s3)) {
            rows.forEach { row ->
                CheckRowView(row) { fix ->
                    when (fix) {
                        CheckFix.MICROPHONE -> mic.launch(Manifest.permission.RECORD_AUDIO)
                        CheckFix.HELPER -> onSetup()
                        CheckFix.OVERLAY -> permissionIntent(context, "overlay")?.let { runCatching { context.startActivity(it) } }
                        CheckFix.BATTERY -> permissionIntent(context, "battery")?.let { runCatching { context.startActivity(it) } }
                    }
                }
            }
            BrandStepsCard(brand)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
            EchoButton("Copy report", {
                scope.launch {
                    clipboard.setClipEntry(ClipData.newPlainText("device check", DeviceCheck.report(facts, rows)).toClipEntry())
                    vm.showMessage("Copied the report")
                }
            }, kind = ButtonKind.Secondary)
            EchoButton(if (sending) "Sending…" else "Send to my admin", {
                sending = true
                scope.launch {
                    vm.sendDeviceReport(facts, rows)
                    sending = false
                }
            }, kind = ButtonKind.Secondary, enabled = settings.paired && !sending)
        }
        Text(
            if (settings.paired) {
                "The report holds this phone's maker, model and Android version and the lines above. It goes to your admin only when you tap Send."
            } else {
                "Sign in to send the report to your admin. Copy report works now."
            },
            style = LocalEchoType.current.caption,
            color = LocalEchoColors.current.textMuted
        )
    }
}
