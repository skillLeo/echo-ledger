package com.echoledger.mobile.translate

import android.media.AudioAttributes
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The floating box's state machine and placement, game auto-start, saved subtitles, and which app is heard (plan B8). */
class TranslateBoxTest {
    private var now = 0L
    private val machine = BoxMachine { now }

    // ---- state machine ----------------------------------------------------------------------------------------------

    @Test
    fun `the box starts locked and takes no touch, the grip toggles edit, and edit locks itself after 5 s idle`() {
        assertEquals(BoxState.LOCKED, machine.state)
        assertFalse(machine.touchable)

        assertEquals(BoxState.EDIT, machine.on(BoxEvent.GripTap))
        assertTrue(machine.touchable)
        now += 4_000
        machine.on(BoxEvent.Touched)
        now += 4_000
        assertEquals("a touch keeps it in edit", BoxState.EDIT, machine.on(BoxEvent.Tick(now)))
        now += 1_000
        assertEquals(BoxState.LOCKED, machine.on(BoxEvent.Tick(now)))

        machine.on(BoxEvent.GripTap)
        assertEquals(BoxState.LOCKED, machine.on(BoxEvent.Done))
    }

    @Test
    fun `hidden comes back by itself after 5 minutes and keeps listening, paused does not listen`() {
        assertEquals(BoxState.HIDDEN, machine.on(BoxEvent.Hide))
        assertTrue(machine.listening)
        now += BoxMachine.HIDE_MS - 1
        assertEquals(BoxState.HIDDEN, machine.on(BoxEvent.Tick(now)))
        now += 1
        assertEquals(BoxState.LOCKED, machine.on(BoxEvent.Tick(now)))

        assertEquals(BoxState.PAUSED, machine.on(BoxEvent.Pause))
        assertFalse(machine.listening)
        assertEquals(BoxState.LOCKED, machine.on(BoxEvent.Resume))
    }

    @Test
    fun `stopped is final`() {
        machine.on(BoxEvent.Stop)
        assertEquals(BoxState.STOPPED, machine.on(BoxEvent.GripTap))
        assertEquals(BoxState.STOPPED, machine.on(BoxEvent.Resume))
        assertFalse(machine.listening)
    }

    // ---- placement --------------------------------------------------------------------------------------------------

    private val safe = BoxRect(100, 50, 2000, 1000) // a landscape screen without the cutout and bars

    @Test
    fun `a box is kept inside the safe area and snaps to a nearby preset or edge`() {
        val outside = BoxGeometry.place(BoxRect(-300, -300, 600, 120), safe, snapPx = 48)
        assertEquals(BoxRect(100, 50, 600, 120), outside)

        val nearCentreTop = BoxGeometry.place(BoxRect(790, 80, 600, 120), safe, snapPx = 48)
        assertEquals("snaps to top centre", BoxRect(800, 50, 600, 120), nearCentreTop)

        val free = BoxGeometry.place(BoxRect(500, 400, 600, 120), safe, snapPx = 48)
        assertEquals("away from every spot it stays where it was dropped", BoxRect(500, 400, 600, 120), free)

        val tooWide = BoxGeometry.place(BoxRect(0, 0, 5000, 120), safe, snapPx = 48)
        assertEquals(2000, tooWide.width)
    }

    @Test
    fun `the default box is 60 percent wide at most 520 dp, top centre, and placements round-trip`() {
        val box = BoxGeometry.defaultBox(safe, heightPx = 200, maxWidthPx = 1040)
        assertEquals(BoxRect(580, 50, 1040, 200), box)

        val p = BoxGeometry.toPlacement(box, 2200, 1100)
        assertEquals(box, BoxGeometry.fromPlacement(p, 2200, 1100))
        assertEquals("com.tencent.ig|land", BoxGeometry.key("com.tencent.ig", landscape = true))
        assertEquals("any|port", BoxGeometry.key(null, landscape = false))
    }

    // ---- auto-start -----------------------------------------------------------------------------------------------

    private fun player(pkg: String, usage: Int = AudioAttributes.USAGE_VOICE_COMMUNICATION, state: Int = ProbePlayer.STATE_STARTED) =
        ProbePlayer(10_100, listOf(pkg), usage, 0, AudioAttributes.ALLOW_CAPTURE_BY_ALL, state)

    @Test
    fun `game translate starts with a picked game's voice chat and stops a minute after the game is gone`() {
        val watch = GameWatch()
        val games = listOf("com.tencent.ig")

        assertEquals(
            GameWatch.Action.None,
            watch.decide(listOf(player("com.tencent.ig", AudioAttributes.USAGE_GAME)), games, true, false, false, 0)
        )
        assertEquals(GameWatch.Action.None, watch.decide(listOf(player("com.other.game")), games, true, false, false, 0))
        assertEquals(
            GameWatch.Action.None,
            watch.decide(listOf(player("com.tencent.ig")), games, autoStart = false, running = false, startedByUs = false, nowMs = 0)
        )
        assertEquals(
            GameWatch.Action.Start("com.tencent.ig"),
            watch.decide(listOf(player("com.tencent.ig")), games, true, false, false, 1_000)
        )

        assertEquals(GameWatch.Action.None, watch.decide(emptyList(), games, true, running = true, startedByUs = true, nowMs = 30_000))
        assertEquals(GameWatch.Action.Stop, watch.decide(emptyList(), games, true, running = true, startedByUs = true, nowMs = 61_000))
        assertEquals(
            "a manual start is never stopped here",
            GameWatch.Action.None,
            watch.decide(emptyList(), games, true, true, false, 200_000)
        )
    }

    // ---- which app ---------------------------------------------------------------------------------------------------

    @Test
    fun `the app heard is the one asked for, else a picked one that plays, else what is playing for Watch`() {
        val players = listOf(
            player("com.google.android.youtube", AudioAttributes.USAGE_MEDIA),
            player("com.tencent.ig", AudioAttributes.USAGE_GAME),
            player("com.tencent.ig", AudioAttributes.USAGE_VOICE_COMMUNICATION)
        )
        val game = TranslateTargets.choose(TranslateMode.GAME, null, listOf("com.tencent.ig"), players)!!
        assertTrue("voice chat first", game.voiceChat)
        assertEquals("com.google.android.youtube", TranslateTargets.choose(TranslateMode.WATCH, null, emptyList(), players)!!.packageName)
        assertEquals(-1, TranslateTargets.choose(TranslateMode.GAME, "com.not.running", emptyList(), players)!!.uid)
        assertNull(TranslateTargets.choose(TranslateMode.GAME, null, emptyList(), players))
    }

    // ---- saved text ---------------------------------------------------------------------------------------------------

    @Test
    fun `subtitles are saved as SubRip with times from the start, game text with the original under it`() {
        val lines =
            listOf(
                TranslatedSentence("Come on guys.", "يلا يا شباب", 1_500, 3_000),
                TranslatedSentence("Enemy at the red house!", "", 62_250, 62_400)
            )

        assertEquals(
            "1\n00:00:01,500 --> 00:00:03,000\nCome on guys.\n\n2\n00:01:02,250 --> 00:01:02,750\nEnemy at the red house!\n\n",
            SrtWriter.srt(lines)
        )
        assertEquals(
            "Game translate · PUBG Mobile\n\n[0:01] Come on guys.\n    يلا يا شباب\n[1:02] Enemy at the red house!\n",
            SrtWriter.text(lines, "PUBG Mobile")
        )
        assertEquals("01:00:00,000", SrtWriter.stamp(3_600_000))
    }

    @Test
    fun `swear words are masked and everything else is left alone`() {
        assertEquals("What the **** was that", ProfanityMask.mask("What the fuck was that"))
        assertEquals("Shoot the tank", ProfanityMask.mask("Shoot the tank"))
    }

    // ---- test voice ---------------------------------------------------------------------------------------------------

    @Test
    fun `the test voice's WAV is read and brought to 16 kHz frames`() {
        val rate = 24_000
        val samples = ShortArray(rate) { (it % 100).toShort() } // one second
        val file = File.createTempFile("tts", ".wav")
        file.outputStream().use { out ->
            fun le32(v: Int) = byteArrayOf(v.toByte(), (v shr 8).toByte(), (v shr 16).toByte(), (v shr 24).toByte())
            fun le16(v: Int) = byteArrayOf(v.toByte(), (v shr 8).toByte())
            out.write("RIFF".toByteArray() + le32(36 + samples.size * 2) + "WAVE".toByteArray())
            out.write("fmt ".toByteArray() + le32(16) + le16(1) + le16(1) + le32(rate) + le32(rate * 2) + le16(2) + le16(16))
            out.write("data".toByteArray() + le32(samples.size * 2))
            out.write(
                ByteArray(samples.size * 2) { i ->
                    if (i % 2 ==
                        0
                    ) {
                        samples[i / 2].toByte()
                    } else {
                        (samples[i / 2].toInt() shr 8).toByte()
                    }
                }
            )
        }

        val frames = TestTranslate.frames(file)

        assertEquals("one second is ten frames of 100 ms", 10, frames.size)
        assertTrue(frames.all { it.size == SpeechGate.FRAME_SAMPLES })
        file.delete()
        assertEquals(emptyList<ShortArray>(), TestTranslate.frames(File("missing.wav")))
    }

    // ---- console defaults --------------------------------------------------------------------------------------------

    @Test
    fun `the console's defaults apply until the person changes the panel on the phone`() {
        val console = com.echoledger.mobile.data.LiveTranslateConfig(
            monthlyUsd = 25.0,
            game = com.echoledger.mobile.data.LiveTranslateModeConfig("ur", listOf("ar"), "accurate"),
            watch = com.echoledger.mobile.data.LiveTranslateModeConfig("en", null, "fastest")
        )
        val first = ConsoleDefaults.apply(LiveTranslatePrefs(), console)
        assertEquals("ur", first.game.target)
        assertEquals(SpeedPreset.ACCURATE.name, first.game.speed)
        assertEquals(25.0, first.monthlyBudgetUsd, 0.0)
        assertEquals("en", first.watch.target)

        val changedInConsole = ConsoleDefaults.apply(
            first,
            console.copy(game = com.echoledger.mobile.data.LiveTranslateModeConfig("ar", listOf("en"), "fastest"))
        )
        assertEquals("a later console change is followed", "ar", changedInConsole.game.target)

        val mine = changedInConsole.copy(game = changedInConsole.game.copy(target = "en", speed = SpeedPreset.BALANCED.name))
        assertEquals("the phone's own choice stays", "en", ConsoleDefaults.apply(mine, console).game.target)
        assertEquals(mine, ConsoleDefaults.apply(mine, null))
    }
}
