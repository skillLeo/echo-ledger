package com.echoledger.mobile.ui

import android.Manifest
import android.content.Intent
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.core.ReadyFix
import com.echoledger.mobile.core.ReadyProblem
import com.echoledger.mobile.recording.ReadinessWatch
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton

/** The status chip (since v1.5, Phase 1C A2): "Calls: Ready", "Not ready — Fix" or "Calls: Off"; one tap opens the check. */
@Composable
fun ReadyChipView(chip: ReadyChip, onClick: () -> Unit, modifier: Modifier = Modifier) {
    if (chip == ReadyChip.NONE) return
    val c = LocalEchoColors.current
    val (dot, fill) = when (chip) {
        ReadyChip.READY -> Brand.Success to c.surface2
        ReadyChip.NOT_READY -> c.warning to c.warningFill.copy(alpha = if (c.isDark) 0.18f else 0.22f)
        else -> c.textFaint to c.surface2
    }
    Row(
        modifier
            .heightIn(min = 32.dp)
            .clip(CircleShape)
            .background(fill)
            .clickable(role = Role.Button, onClick = onClick)
            .padding(horizontal = Space.s3)
            .semantics { contentDescription = chip.text },
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(Modifier.size(8.dp).clip(CircleShape).background(dot))
        Spacer(Modifier.width(Space.s2))
        Text(chip.text, style = LocalEchoType.current.labelM, color = c.text, maxLines = 1)
    }
}

/**
 * The Ready check (since v1.5, Phase 1C A1): "✓ Ready to record calls", or each problem in plain words with its one-tap
 * fix. Opened from the chip, the critical alert (whose button runs [autoFix] at once), Setup and Help.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ReadyCheckSheet(
    vm: MainViewModel,
    readiness: Readiness,
    onDismiss: () -> Unit,
    onSetup: () -> Unit,
    onRecheck: () -> Unit,
    autoFix: ReadyFix? = null
) {
    val context = LocalContext.current
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { onRecheck() }
    fun run(fix: ReadyFix) {
        when (fix) {
            ReadyFix.START_HELPER, ReadyFix.ALLOW_SHIZUKU -> vm.connectShizuku()

            ReadyFix.OPEN_SHIZUKU -> if (!ReadinessWatch.openShizuku(context)) onSetup()

            ReadyFix.RESTART_HELPER -> vm.restartHelper()

            ReadyFix.SET_UP, ReadyFix.TEST, ReadyFix.SIGN_IN -> onSetup()

            ReadyFix.MICROPHONE -> permission.launch(Manifest.permission.RECORD_AUDIO)

            ReadyFix.NOTIFICATIONS -> if (Build.VERSION.SDK_INT >= 33) {
                permission.launch(Manifest.permission.POST_NOTIFICATIONS)
            } else {
                permissionIntent(context, "notifications")?.let { runCatching { context.startActivity(it) } }
            }

            ReadyFix.BATTERY -> permissionIntent(context, "battery")?.let { runCatching { context.startActivity(it) } }

            ReadyFix.TURN_ON -> runCatching {
                context.startForegroundService(RecordingService.intent(context, RecordingService.ACTION_ARM, from = "ready check"))
            }

            ReadyFix.STORAGE -> runCatching {
                context.startActivity(Intent(Settings.ACTION_INTERNAL_STORAGE_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            }
        }
        onRecheck()
    }
    LaunchedEffect(autoFix) { autoFix?.let { run(it) } }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Brand.Surface) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = Space.screen).padding(bottom = Space.s5),
            verticalArrangement = Arrangement.spacedBy(Space.s3)
        ) {
            Text("Ready check", style = LocalEchoType.current.titleM, color = Brand.Text)
            Text(
                readiness.headline,
                style = LocalEchoType.current.bodyL,
                color = if (readiness.ready) Brand.Success else Brand.Text
            )
            readiness.problems.forEach { ProblemRow(it) { fix -> run(fix) } }
            Spacer(Modifier.height(Space.s1))
            EchoButton("Check again", onRecheck, Modifier.fillMaxWidth(), kind = ButtonKind.Secondary)
        }
    }
}

@Composable
private fun ProblemRow(problem: ReadyProblem, onFix: (ReadyFix) -> Unit) {
    val c = LocalEchoColors.current
    val tint: Color = if (problem.blocking) c.warning else c.textMuted
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(problem.title, style = LocalEchoType.current.bodyL, color = c.text)
            Text(problem.detail, style = LocalEchoType.current.caption, color = tint)
        }
        problem.fix?.let { fix -> EchoButton(fix.label, { onFix(fix) }, kind = ButtonKind.Text) }
    }
}
