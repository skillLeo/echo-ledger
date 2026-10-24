package com.echoledger.mobile.ui

import android.content.Context
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Pause
import androidx.compose.material.icons.outlined.PlayArrow
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.TranscriptText
import kotlinx.coroutines.delay

/**
 * Streams a recording from its temporary signed URL (docs/API.md "GET /api/sessions/{id}" → playback_url) with the
 * platform MediaPlayer; the server decrypts on the fly and nothing is written to the phone.
 */
@Stable
class RecordingPlayerState(private val context: Context) {
    private var player: MediaPlayer? = null

    var ready by mutableStateOf(false)
        private set
    var playing by mutableStateOf(false)
        private set
    var positionMs by mutableLongStateOf(0L)
        private set
    var durationMs by mutableLongStateOf(0L)
        private set
    var error by mutableStateOf<String?>(null)
        private set

    fun load(url: String) {
        release()
        error = null
        player = MediaPlayer().apply {
            setAudioAttributes(
                AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_MEDIA).setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build()
            )
            setOnPreparedListener {
                ready = true
                durationMs = it.duration.toLong().coerceAtLeast(0)
            }
            setOnCompletionListener {
                playing = false
                positionMs = durationMs
            }
            setOnErrorListener { _, _, _ ->
                error = "This recording could not be played. Pull to refresh and try again."
                ready = false
                playing = false
                true
            }
            runCatching {
                setDataSource(context, Uri.parse(url))
                prepareAsync()
            }.onFailure { error = "This recording could not be played." }
        }
    }

    fun toggle() {
        val p = player?.takeIf { ready } ?: return
        if (p.isPlaying) {
            p.pause()
            playing = false
        } else {
            if (positionMs >= durationMs && durationMs > 0) p.seekTo(0)
            p.start()
            playing = true
        }
    }

    fun seekTo(ms: Long) {
        val p = player?.takeIf { ready } ?: return
        p.seekTo(ms.coerceIn(0, durationMs).toInt())
        positionMs = ms.coerceIn(0, durationMs)
        if (!p.isPlaying) {
            p.start()
            playing = true
        }
    }

    fun refreshPosition() {
        player?.takeIf { ready }?.let { positionMs = it.currentPosition.toLong() }
    }

    fun release() {
        player?.release()
        player = null
        ready = false
        playing = false
        positionMs = 0
        durationMs = 0
    }
}

@Composable
fun rememberRecordingPlayer(url: String?): RecordingPlayerState {
    val context = LocalContext.current
    val state = remember { RecordingPlayerState(context.applicationContext) }
    LaunchedEffect(url) { if (url != null) state.load(url) }
    LaunchedEffect(state.playing) {
        while (state.playing) {
            state.refreshPosition()
            delay(250)
        }
    }
    DisposableEffect(Unit) { onDispose { state.release() } }
    return state
}

/** Play/pause, scrubber and elapsed/total time. */
@Composable
fun RecordingPlayer(state: RecordingPlayerState, modifier: Modifier = Modifier) {
    SectionCard(modifier) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            FilledIconButton(
                onClick = { state.toggle() },
                enabled = state.ready && state.error == null,
                colors = IconButtonDefaults.filledIconButtonColors(containerColor = Brand.Violet),
                modifier = Modifier.size(48.dp)
            ) {
                when {
                    state.error == null && !state.ready -> CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                    state.playing -> Icon(Icons.Outlined.Pause, contentDescription = "Pause")
                    else -> Icon(Icons.Outlined.PlayArrow, contentDescription = "Play")
                }
            }
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Slider(
                    value = if (state.durationMs > 0) state.positionMs.toFloat() / state.durationMs else 0f,
                    onValueChange = { fraction -> if (state.durationMs > 0) state.seekTo((fraction * state.durationMs).toLong()) },
                    enabled = state.ready && state.error == null
                )
                Row {
                    Text(TranscriptText.stamp(state.positionMs), color = Brand.Muted, fontSize = 12.sp)
                    Text("  /  ", color = Brand.Muted, fontSize = 12.sp)
                    Text(TranscriptText.stamp(state.durationMs), color = Brand.Muted, fontSize = 12.sp)
                }
            }
        }
        state.error?.let { Text(it, color = Brand.Danger, fontSize = 12.sp) }
    }
}
