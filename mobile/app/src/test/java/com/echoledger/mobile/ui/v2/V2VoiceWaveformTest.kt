package com.echoledger.mobile.ui.v2

import androidx.compose.ui.graphics.toPixelMap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.hasContentDescription
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.live.V2VoiceNoteScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.GraphicsMode

/**
 * The voice note's live waveform is really drawn (parity.csv PH-OTH-006): flat and quiet before recording, then bars
 * in the record colour that grow with the sound, the louder the taller.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class V2VoiceWaveformTest : ScreenTest() {
    @After
    fun idle() {
        RecordingService.live.value = LiveRecording()
    }

    /** How many pixels of the waveform are (nearly) the record colour, and how tall the tallest run of them is. */
    private fun bars(): Pair<Int, Int> {
        val map = compose.onNode(hasContentDescription("Sound level")).captureToImage().toPixelMap()
        val record = EchoV2Colors.Light.record
        fun close(a: Float, b: Float) = kotlin.math.abs(a - b) < 0.08f
        var count = 0
        var tallest = 0
        for (x in 0 until map.width) {
            var column = 0
            for (y in 0 until map.height) {
                val p = map[x, y]
                if (close(p.red, record.red) && close(p.green, record.green) && close(p.blue, record.blue)) {
                    count++
                    column++
                }
            }
            tallest = maxOf(tallest, column)
        }
        return count to tallest
    }

    @Test
    fun theWaveformIsFlatBeforeRecordingAndItsBarsGrowWithTheSound() {
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                androidx.compose.runtime.CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2VoiceNoteScreen(vm, onBack = {}, headsetConnected = { false })
                }
            }
        }
        compose.waitForIdle()
        assertEquals("no record-coloured bars before recording", 0, bars().first)
        // Quiet, then loud: each second's level is one more bar.
        for (i in 1..6) {
            RecordingService.live.value = LiveRecording(active = true, elapsedMs = i * 1_000L, meLevel = 0.1f)
            compose.waitForIdle()
        }
        val (quiet, quietHeight) = bars()
        assertTrue("bars drawn ($quiet px)", quiet > 0)
        for (i in 7..12) {
            RecordingService.live.value = LiveRecording(active = true, elapsedMs = i * 1_000L, meLevel = 0.9f)
            compose.waitForIdle()
        }
        val (loud, loudHeight) = bars()
        pumpUntil("louder is taller") { loud > quiet && loudHeight > quietHeight * 3 }
    }
}
