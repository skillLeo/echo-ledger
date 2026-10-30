package com.echoledger.mobile.translate

import android.media.AudioAttributes
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Live Translate's pure parts (plan B8): the speech gate, the probe's player list, the budget, the app picker. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class TranslateLogicTest {
    private fun tone(level: Int, period: Int = 16) = ShortArray(SpeechGate.FRAME_SAMPLES) { i ->
        (
            if (i % period <
                period / 2
            ) {
                level
            } else {
                -level
            }
            ).toShort()
    }
    private fun silence() = ShortArray(SpeechGate.FRAME_SAMPLES)

    // ---- speech gate --------------------------------------------------------------------------------------------

    @Test
    fun `silence sends nothing, and speech starts with the 300 ms before it`() {
        val gate = SpeechGate()
        repeat(10) { assertTrue(gate.offer(silence()).frames.isEmpty()) }

        val onset = gate.offer(tone(4_000))

        assertTrue(onset.onset)
        assertEquals("three frames of pre-roll and the speech frame", SpeechGate.PRE_ROLL_FRAMES + 1, onset.frames.size)
        assertEquals(1, gate.offer(tone(4_000)).frames.size)
    }

    @Test
    fun `short pauses between words stay in, a long quiet ends the speech and is counted`() {
        val gate = SpeechGate()
        gate.offer(tone(4_000))
        repeat(SpeechGate.HANG_FRAMES) { assertTrue("pause $it is still sent", gate.offer(silence()).speaking) }

        assertFalse(gate.offer(silence()).speaking)
        repeat(20) { gate.offer(silence()) }
        assertEquals(SpeechGate.HANG_FRAMES + 21, gate.quietFrames)
    }

    @Test
    fun `with game sounds, hiss and rumble do not open the gate but a voice does`() {
        val gate = SpeechGate(mixed = true)
        assertFalse("hiss (every sample flips)", gate.offer(tone(5_000, period = 2)).speaking)
        assertFalse("rumble (very slow)", gate.offer(tone(5_000, period = 400)).speaking)
        assertTrue("voice-like", gate.offer(tone(5_000, period = 16)).speaking)
    }

    // ---- audio probe --------------------------------------------------------------------------------------------

    @Test
    fun `the probe reads the helper's player list and says which apps allow captions`() {
        val players = AudioProbe.parse(
            """[
              {"uid":10234,"packages":["com.tencent.ig"],"usage":2,"content_type":1,"capture_policy":3,"state":2,"piid":55},
              {"uid":10234,"packages":["com.tencent.ig"],"usage":14,"content_type":4,"capture_policy":1,"state":2,"piid":56},
              {"uid":10500,"packages":["com.netflix.mediaclient"],"usage":1,"content_type":3,"capture_policy":3,"state":2,"piid":57}
            ]"""
        )

        assertEquals(3, players.size)
        assertTrue("voice chat is heard like call audio", players[0].capturable)
        assertTrue(players[1].capturable)
        assertFalse("Netflix opts out", players[2].capturable)
        assertEquals("Voice chat · speech · blocks capture", AudioProbe.describe(players[0]))
        assertEquals("Game · effects · allows capture", AudioProbe.describe(players[1]))
        assertEquals(emptyList<ProbePlayer>(), AudioProbe.parse("not json"))
        assertEquals(emptyList<ProbePlayer>(), AudioProbe.parse(null))
    }

    @Test
    fun `the probe lists playing voice chat first, one row per app and usage`() {
        val list = AudioProbe.byApp(
            listOf(
                ProbePlayer(1, listOf("a"), AudioAttributes.USAGE_MEDIA, 0, 1, 3),
                ProbePlayer(2, listOf("b"), AudioAttributes.USAGE_VOICE_COMMUNICATION, 0, 1, ProbePlayer.STATE_STARTED),
                ProbePlayer(2, listOf("b"), AudioAttributes.USAGE_VOICE_COMMUNICATION, 0, 1, ProbePlayer.STATE_STARTED),
                ProbePlayer(-1, emptyList(), AudioAttributes.USAGE_MEDIA, 0, 1, ProbePlayer.STATE_STARTED)
            )
        )
        assertEquals(listOf(2, 1), list.map { it.uid })
    }

    // ---- budget ---------------------------------------------------------------------------------------------------

    @Test
    fun `the budget warns at 80 percent, pauses at 100 and can be passed for the month`() {
        assertEquals(BudgetState.OK, TranslateBudgetGuard.state(7.99, 10.0, null, "2026-10"))
        assertEquals(BudgetState.WARN, TranslateBudgetGuard.state(8.0, 10.0, null, "2026-10"))
        assertEquals(BudgetState.PAUSED, TranslateBudgetGuard.state(10.0, 10.0, null, "2026-10"))
        assertEquals(BudgetState.OVERRIDDEN, TranslateBudgetGuard.state(12.0, 10.0, "2026-10", "2026-10"))
        assertEquals(
            "last month's choice does not carry over",
            BudgetState.PAUSED,
            TranslateBudgetGuard.state(12.0, 10.0, "2026-09", "2026-10")
        )
        assertEquals(0.18, TranslateCost.usd(3_600_000), 1e-9)
        assertTrue(TranslateBudgetGuard.message(BudgetState.PAUSED, 10.0, 10.0)!!.contains("paused"))
    }

    // ---- app picker -----------------------------------------------------------------------------------------------

    @Test
    fun `games come first in the picker, and call apps and EchoLedger are never offered`() {
        val apps = listOf(
            InstalledApp("com.whatsapp", "WhatsApp", false),
            InstalledApp("com.echoledger.mobile", "EchoLedger", false),
            InstalledApp("com.example.notes", "Notes", false),
            InstalledApp("com.example.chess", "Chess", true),
            InstalledApp("com.tencent.ig", "PUBG Mobile", true)
        )

        val games = TranslateApps.forPicker(apps, "com.echoledger.mobile", gamesFirst = true).map { it.packageName }

        assertEquals(listOf("com.tencent.ig", "com.example.chess", "com.example.notes"), games)
    }

    @Test
    fun `styles are kept inside the limits Android and the design allow`() {
        val box = BoxStyle(textSp = 99, lines = 9, background = "PINK", opacity = 1f).sanitized()
        assertEquals(BoxStyle.MAX_TEXT_SP, box.textSp)
        assertEquals(4, box.lines)
        assertEquals(BoxBackground.DARK, box.backgroundKind)
        assertEquals("touches only pass through up to 0.8", 0.8f, box.opacity, 0f)
        assertTrue(TranslateLanguages.rtl("ur"))
        assertEquals("Arabic + English", TranslateLanguages.spoken(listOf("ar", "en")))
        assertEquals(SpeedPreset.FASTEST, SpeedPreset.of("fastest"))
    }
}
