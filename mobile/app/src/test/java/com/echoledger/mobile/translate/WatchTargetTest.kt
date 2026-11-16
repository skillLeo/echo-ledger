package com.echoledger.mobile.translate

import android.media.AudioAttributes
import android.os.DeadObjectException
import android.os.IBinder
import android.os.ParcelFileDescriptor
import com.echoledger.mobile.capture.ICallListener
import com.echoledger.mobile.capture.ICaptureHelper
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.translate.TranslateTargets.Pick
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Watch translate's choice of app from the helper's answer (the Android 17 report, v1.8): an uid or a state the helper
 * could not read, or an answer it could not give, is never taken for "nothing is playing".
 */
class WatchTargetTest : AppTest() {
    private val media = AudioAttributes.USAGE_MEDIA

    private fun player(uid: Int, state: Int, usage: Int = media, pkg: String? = "com.samsung.android.video", piid: Int = 57) = ProbePlayer(
        uid,
        listOfNotNull(
            pkg.takeIf {
                uid > 0
            }
        ),
        usage,
        AudioAttributes.CONTENT_TYPE_MOVIE,
        AudioAttributes.ALLOW_CAPTURE_BY_ALL,
        state,
        piid
    )

    private fun watch(answer: HelperPlayers, musicActive: Boolean = true, picked: List<String> = emptyList()) =
        TranslateTargets.decide(TranslateMode.WATCH, null, picked, answer, musicActive)

    @Test
    fun `the S25 report - a video plays but the helper read neither its uid nor its state - shares the sound instead`() {
        // What an older helper answers when its hidden getters give nothing: uid -1, state -1, for Samsung's player.
        val answer =
            HelperPlayers(
                AudioProbe.parse("""[{"uid":-1,"packages":[],"usage":1,"content_type":3,"capture_policy":1,"state":-1,"piid":2215}]""")
            )
        assertTrue(watch(answer) is Pick.CannotTell)
        assertTrue("even before sound is reported", watch(answer, musicActive = false) is Pick.CannotTell)
        assertNull(
            "the old choice took it for nothing playing",
            TranslateTargets.choose(TranslateMode.WATCH, null, emptyList(), answer.players)
        )
    }

    @Test
    fun `an app whose uid is known but whose state is not is the one playing when it alone could be, and music plays`() {
        val answer =
            HelperPlayers(
                listOf(
                    player(10133, -1),
                    player(10200, ProbePlayer.STATE_STARTED, AudioAttributes.USAGE_VOICE_COMMUNICATION, "com.whatsapp")
                )
            )
        assertEquals(10133, (watch(answer) as Pick.App).player.uid)
        assertEquals("no sound playing: nothing to translate", Pick.NothingPlaying, watch(answer, musicActive = false))
        val two = HelperPlayers(listOf(player(10133, -1), player(10300, -1, pkg = "com.spotify.music", piid = 60)))
        assertTrue("two apps could be playing: Android shares the sound", watch(two) is Pick.CannotTell)
    }

    @Test
    fun `a playing player the helper could not name the app of shares the sound`() {
        val answer = HelperPlayers(listOf(player(-1, ProbePlayer.STATE_STARTED)))
        assertTrue(watch(answer) is Pick.CannotTell)
        assertTrue(watch(answer, musicActive = false) is Pick.CannotTell)
    }

    @Test
    fun `an answer the helper could not give shares the sound, with why`() {
        val pick = watch(HelperPlayers(emptyList(), "The capture helper did not answer (RemoteException)."))
        assertEquals(Pick.CannotTell("The capture helper did not answer (RemoteException)."), pick)
    }

    @Test
    fun `sound playing with no player the helper can name shares the sound, and silence is nothing playing`() {
        assertTrue(watch(HelperPlayers(emptyList())) is Pick.CannotTell)
        assertEquals(Pick.NothingPlaying, watch(HelperPlayers(emptyList()), musicActive = false))
        val paused = HelperPlayers(listOf(player(10133, 3)))
        assertEquals("the video is paused and nothing plays", Pick.NothingPlaying, watch(paused, musicActive = false))
    }

    @Test
    fun `everything readable works as before - the playing app, media first`() {
        val answer = HelperPlayers(
            listOf(
                player(10300, ProbePlayer.STATE_STARTED, AudioAttributes.USAGE_GAME, "com.game"),
                player(10133, ProbePlayer.STATE_STARTED)
            )
        )
        assertEquals(10133, (watch(answer, musicActive = false) as Pick.App).player.uid)
        assertEquals(
            "a picked app is taken by its package even when its uid is unknown",
            "com.netflix.mediaclient",
            (watch(HelperPlayers(listOf(player(-1, -1))), picked = listOf("com.netflix.mediaclient")) as Pick.App).player.packageName
        )
    }

    @Test
    fun `the probe and Copy diagnostics show what the helper returned for every player`() {
        val answer = HelperPlayers(
            AudioProbe.parse(
                """[{"uid":10133,"uid_from":"text","packages":["com.samsung.android.video"],"usage":1,"content_type":3,
                    "capture_policy":1,"state":2,"state_from":"text","piid":2215},
                   {"uid":-1,"uid_from":"none","packages":[],"usage":1,"content_type":3,"capture_policy":1,"state":-1,
                    "state_from":"none","piid":2216}]"""
            ),
            helperBuild = 461_000
        )
        assertEquals(
            listOf(
                "helper build 461000",
                "uid 10133 (text) · started (text) · Media · film · allows capture · piid 2215 · com.samsung.android.video",
                "uid unknown (none) · state unknown (none) · Media · film · allows capture · piid 2216"
            ),
            AudioProbe.lines(answer)
        )
        assertEquals("the app row stays as before", listOf(10133), AudioProbe.byApp(answer.players).map { it.uid })
        assertEquals("and the one it could not place is listed apart", listOf(2216), AudioProbe.unplaced(answer.players).map { it.piid })
        assertEquals(
            listOf("no answer: The capture helper gave no answer."),
            AudioProbe.lines(HelperPlayers(emptyList(), "The capture helper gave no answer."))
        )
    }

    // ---- asking the helper ---------------------------------------------------------------------------------------------

    /** A helper whose players() answers with [answer] (or throws it). */
    private class Helper(private val answer: () -> String?, private val build: Int = com.echoledger.mobile.BuildConfig.HELPER_BUILD) :
        ICaptureHelper.Stub() {
        override fun version() = build
        override fun uid() = 2000
        override fun capabilities() = ""
        override fun startCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
        override fun startPhoneCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
        override fun arm(on: Boolean): String = "armed"
        override fun setCallApps(packages: Array<out String>?) = Unit
        override fun selfTest(): String = "ok"
        override fun captureChannels(): Int = 2
        override fun players(): String? = answer()
        override fun armApps(uids: IntArray?, voiceChat: Boolean): String = "armed"
        override fun startAppCapture(sink: ParcelFileDescriptor?, uid: Int, usage: Int, voiceChat: Boolean): Boolean = false
        override fun stopAppCapture() = Unit
        override fun stopCapture() = Unit
        override fun setCallListener(listener: ICallListener?) = Unit
        override fun destroy() = Unit
    }

    @Test
    fun `a helper that answers is read, one that fails says why, never an empty list in place of an answer`() {
        val good = AudioProbe.read(Helper({ """[{"uid":10133,"packages":["v"],"usage":1,"state":2}]""" }))
        assertNull(good.problem)
        assertEquals(listOf(10133), good.players.map { it.uid })

        val threw = AudioProbe.read(Helper({ throw IllegalStateException("audio service") }))
        assertTrue(threw.problem, threw.problem!!.startsWith("The capture helper did not answer"))
        assertEquals("The capture helper gave no answer.", AudioProbe.read(Helper({ null })).problem)
        assertEquals("The capture helper's answer could not be read.", AudioProbe.read(Helper({ "not json" })).problem)
        assertEquals("The capture helper is not running.", AudioProbe.read(null).problem)
        val stale = AudioProbe.read(Helper({ null }), stale = true, helperBuild = 1)
        assertTrue(stale.problem, stale.problem!!.contains("An older capture helper is still running from before an update: restart it."))
    }

    /** A binder whose process is gone, as a helper left over from before an update can be. */
    private class GoneBinder : IBinder {
        override fun getInterfaceDescriptor(): String = "com.echoledger.mobile.capture.ICaptureHelper"
        override fun pingBinder() = false
        override fun isBinderAlive() = false
        override fun queryLocalInterface(descriptor: String): android.os.IInterface? = null
        override fun dump(fd: java.io.FileDescriptor, args: Array<out String>?) = Unit
        override fun dumpAsync(fd: java.io.FileDescriptor, args: Array<out String>?) = Unit
        override fun transact(code: Int, data: android.os.Parcel, reply: android.os.Parcel?, flags: Int): Boolean =
            throw DeadObjectException()
        override fun linkToDeath(recipient: IBinder.DeathRecipient, flags: Int) = throw DeadObjectException()
        override fun unlinkToDeath(recipient: IBinder.DeathRecipient, flags: Int) = false
    }

    @Test
    fun `a helper whose process is gone is let go and connected again, and the answer says so`() {
        val gone = ICaptureHelper.Stub.asInterface(GoneBinder())
        var dead: ICaptureHelper? = null
        val answer = AudioProbe.read(gone) { dead = it }
        assertTrue(answer.problem, answer.problem!!.contains("stopped answering"))
        assertTrue(dead === gone)
        assertFalse(answer.players.isNotEmpty())
    }
}
