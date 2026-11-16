package com.echoledger.mobile.capture

import android.media.AudioAttributes
import android.media.AudioManager
import com.echoledger.mobile.testsupport.AppTest
import org.json.JSONArray
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * What the helper reads of each player for Live Translate (the Android 17 report, v1.8): the uid and the state from
 * their getters, else from the player's own public description, and where each came from.
 */
class PlayerReadingTest : AppTest() {
    /** The line `dumpsys audio` printed on the S25 for the video playing in Samsung's player (Android 17). */
    private val s25 = "AudioPlaybackConfiguration piid:2215 deviceIds:[3] type:android.media.MediaPlayer u/pid:10133/26439 " +
        "state:started attr:AudioAttributes: usage=USAGE_MEDIA content=CONTENT_TYPE_MOVIE flags=0x800 tags= bundle=null " +
        "sessionId:1281 mutedState:"

    @Test
    fun `a player's own description gives its uid and state`() {
        assertEquals(10133, PlaybackText.uid(s25))
        assertEquals(2, PlaybackText.state(s25))
        assertEquals("written without colons", 10133, PlaybackText.uid("AudioPlaybackConfiguration u/pid 10133/26439 state started"))
        assertEquals(3, PlaybackText.state("piid:9 u/pid:10200/77 state:paused attr:x"))
        assertEquals(4, PlaybackText.state("u/pid:10200/77 state:stopped"))
        assertEquals(1, PlaybackText.state("u/pid:10200/77 state:idle"))
    }

    @Test
    fun `a description without the uid or a known state says nothing of them`() {
        assertNull("an anonymised copy", PlaybackText.uid("piid:9 u/pid:-1/-1 state:started"))
        assertNull(PlaybackText.uid("piid:9 state:started"))
        assertNull(PlaybackText.state("u/pid:10200/77 state:device updated"))
        assertNull(PlaybackText.state("u/pid:10200/77 mutedState:started"))
        assertNull(PlaybackText.state(""))
    }

    @Test
    fun `the getter wins when it gives a value, else the description, else nothing`() {
        assertEquals(10133 to "getter", PlaybackText.best(10133, 10200) { it > 0 })
        assertEquals("the getter gave -1", 10133 to "text", PlaybackText.best(-1, 10133) { it > 0 })
        assertEquals(-1 to "none", PlaybackText.best(-1, null) { it > 0 })
        assertEquals("state 0 (released) is a state", 0 to "getter", PlaybackText.best(0, 2) { it >= 0 })
    }

    @Test
    fun `on this framework the description agrees with the getters, and the helper's answer says where each came from`() {
        val audio = app.getSystemService(AudioManager::class.java)
        shadowOf(audio).setActivePlaybackConfigurationsFor(
            listOf(
                AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_MEDIA).setContentType(AudioAttributes.CONTENT_TYPE_MOVIE).build()
            )
        )
        val config = audio.activePlaybackConfigurations.single()
        val text = config.toString()
        assertEquals(text, config.clientUid().takeIf { it > 0 }, PlaybackText.uid(text))
        assertEquals(text, config.playerState().takeIf { it >= 0 }, PlaybackText.state(text))

        val player = JSONArray(AppAudioMix.players(audio) { listOf("com.example.video") }).getJSONObject(0)
        assertEquals(AudioAttributes.USAGE_MEDIA, player.getInt("usage"))
        assertTrue(player.toString(), player.getString("uid_from") in setOf("getter", "text", "none"))
        assertTrue(player.toString(), player.getString("state_from") in setOf("getter", "text", "none"))
        assertEquals(config.playerState().takeIf { it >= 0 } ?: -1, player.getInt("state"))
    }
}
