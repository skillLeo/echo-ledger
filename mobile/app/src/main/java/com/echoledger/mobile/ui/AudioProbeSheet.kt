package com.echoledger.mobile.ui

import android.content.ClipData
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioTrack
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.toClipEntry
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.translate.AudioProbe
import com.echoledger.mobile.translate.ProbePlayer
import com.echoledger.mobile.translate.SpeechGate
import com.echoledger.mobile.translate.TranslateApps
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import kotlin.concurrent.thread
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** What a 5-second capture of one player gave. */
private data class ProbeResult(val player: ProbePlayer, val pcm: ShortArray, val problem: String?) {
    val peak: Int get() = pcm.maxOfOrNull { kotlin.math.abs(it.toInt()) } ?: 0
    val heard: Boolean get() = problem == null && peak > 300

    /** Share of 100 ms frames the speech gate would send: how much of it is speech-like sound. */
    val speechShare: Int get() {
        val gate = SpeechGate(mixed = !player.voiceChat)
        val frames = pcm.toList().chunked(SpeechGate.FRAME_SAMPLES).filter { it.size == SpeechGate.FRAME_SAMPLES }
        if (frames.isEmpty()) return 0
        return frames.count { gate.offer(it.toShortArray()).speaking } * 100 / frames.size
    }
}

/**
 * Settings → Help & diagnostics → Audio probe (plan B0): every app playing sound right now, as the helper sees it
 * (who, what kind of sound, whether it may be captured), and "Capture 5 s" to hear what Live Translate would hear.
 * "Copy findings" puts the table on the clipboard for the support report.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AudioProbeSheet(vm: MainViewModel, helperReady: Boolean, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val clipboard = LocalClipboard.current
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val scope = rememberCoroutineScope()
    var players by remember { mutableStateOf<List<ProbePlayer>>(emptyList()) }
    var capturing by remember { mutableStateOf<Int?>(null) }
    var level by remember { mutableFloatStateOf(0f) }
    var results by remember { mutableStateOf<Map<Int, ProbeResult>>(emptyMap()) }
    LaunchedEffect(helperReady) {
        while (helperReady) {
            players = AudioProbe.byApp(vm.probePlayers())
            delay(1_000)
        }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = c.surface) {
        Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
            Text("Audio probe", style = type.titleM, color = c.text)
            Text(
                "Apps playing sound now, and what Live Translate would hear from each. Start the game or video first; " +
                    "if a capture is silent, leave and rejoin voice chat (Android routes sound when it starts) and try again.",
                style = type.labelM,
                color = c.textMuted
            )
        }
        LazyColumn(
            Modifier.fillMaxWidth().heightIn(max = 560.dp),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(Space.screen)
        ) {
            if (!helperReady) {
                item {
                    Text(
                        "The capture helper is not running. Start it in Calls & meetings → Microphone → Set up, then open the probe again.",
                        style = type.bodyM,
                        color = c.text
                    )
                }
            } else if (players.isEmpty()) {
                item { Text("Nothing is playing sound right now.", style = type.bodyM, color = c.textMuted) }
            }
            items(players, key = { "${it.uid}-${it.usage}" }) { p ->
                val label = p.packageName?.let { TranslateApps.label(context, it) } ?: "uid ${p.uid}"
                val result = results[p.uid * 100 + p.usage]
                Column(Modifier.fillMaxWidth().padding(vertical = Space.s2), verticalArrangement = Arrangement.spacedBy(Space.s1)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        p.packageName?.let { AppIcon(it, 28.dp) }
                        Spacer(Modifier.width(Space.s3))
                        Column(Modifier.weight(1f)) {
                            Text(label, style = type.bodyL, color = c.text)
                            Text(AudioProbe.describe(p) + if (p.playing) "" else " · paused", style = type.caption, color = c.textMuted)
                        }
                    }
                    when {
                        !p.capturable -> Text(AudioProbe.NOT_ALLOWED, style = type.labelM, color = c.textMuted)

                        capturing == p.uid * 100 + p.usage -> LinearProgressIndicator(progress = {
                            level
                        }, modifier = Modifier.fillMaxWidth())

                        else -> Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                            EchoButton("Capture 5 s", {
                                val key = p.uid * 100 + p.usage
                                capturing = key
                                scope.launch {
                                    val (pcm, problem) = vm.probeCapture(p, 5) { level = it }
                                    results = results + (key to ProbeResult(p, pcm, problem))
                                    capturing = null
                                }
                            }, kind = ButtonKind.Secondary, enabled = capturing == null)
                            if (result?.heard == true) EchoButton("Play", { play(result.pcm) }, kind = ButtonKind.Text)
                        }
                    }
                    result?.let { r ->
                        Text(
                            r.problem ?: if (r.heard) {
                                "Heard: peak ${r.peak}, speech-like ${r.speechShare}% of the time."
                            } else {
                                "Silent. Leave and rejoin voice chat (or restart the video), then capture again."
                            },
                            style = type.labelM,
                            color = if (r.heard) c.text else c.danger
                        )
                    }
                }
            }
            item {
                EchoButton("Copy findings", {
                    val text = findings(players, results.values.toList()) { pkg -> TranslateApps.label(context, pkg) }
                    scope.launch { clipboard.setClipEntry(ClipData.newPlainText("EchoLedger audio probe", text).toClipEntry()) }
                    vm.showMessage("Findings copied")
                }, kind = ButtonKind.Text)
            }
        }
    }
}

/** The probe as a plain table: app, uid, usage, content, capture policy, and the capture result. */
private fun findings(players: List<ProbePlayer>, results: List<ProbeResult>, label: (String) -> String): String = buildString {
    appendLine("EchoLedger audio probe")
    players.forEach { p ->
        val r = results.firstOrNull { it.player.uid == p.uid && it.player.usage == p.usage }
        append(p.packageName?.let(label) ?: "uid ${p.uid}").append(" | uid ").append(p.uid).append(" | ").append(AudioProbe.describe(p))
        append(" | ").append(
            when {
                r == null -> "not captured"
                r.problem != null -> "error: ${r.problem}"
                r.heard -> "heard (peak ${r.peak}, speech ${r.speechShare}%)"
                else -> "silent"
            }
        )
        appendLine()
    }
}

/** Plays a probe capture back once (16 kHz mono). */
private fun play(pcm: ShortArray) {
    thread(name = "el-probe-play", isDaemon = true) {
        val track = runCatching {
            AudioTrack.Builder()
                .setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_MEDIA).build())
                .setAudioFormat(
                    AudioFormat.Builder().setSampleRate(16_000).setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                        .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
                )
                .setTransferMode(AudioTrack.MODE_STATIC)
                .setBufferSizeInBytes(pcm.size * 2)
                .build()
        }.getOrNull() ?: return@thread
        track.write(pcm, 0, pcm.size)
        track.play()
        Thread.sleep(pcm.size / 16L + 200)
        track.release()
    }
}
