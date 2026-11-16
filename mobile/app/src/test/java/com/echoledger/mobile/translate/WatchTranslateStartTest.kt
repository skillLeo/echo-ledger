package com.echoledger.mobile.translate

import android.app.Notification
import android.app.NotificationManager
import android.media.AudioManager
import android.os.ParcelFileDescriptor
import com.echoledger.mobile.capture.ICallListener
import com.echoledger.mobile.capture.ICaptureHelper
import com.echoledger.mobile.recording.CaptureLog
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Watch translate started from its Quick Settings tile with the capture helper running (phone batch A6, S25 on
 * Android 17): a video plays, but the helper can't say which app plays it. It asks Android to share the sound instead
 * of saying that nothing is playing, and Copy diagnostics shows what the helper returned.
 */
class WatchTranslateStartTest : AppTest() {
    /** A helper that answers players() with [json]. */
    private class Helper(private val json: String) : ICaptureHelper.Stub() {
        override fun version() = com.echoledger.mobile.BuildConfig.HELPER_BUILD
        override fun uid() = 2000
        override fun capabilities() = ""
        override fun startCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
        override fun startPhoneCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
        override fun arm(on: Boolean): String = "armed"
        override fun setCallApps(packages: Array<out String>?) = Unit
        override fun selfTest(): String = "ok"
        override fun captureChannels(): Int = 2
        override fun players(): String = json
        override fun armApps(uids: IntArray?, voiceChat: Boolean): String = "armed"
        override fun startAppCapture(sink: ParcelFileDescriptor?, uid: Int, usage: Int, voiceChat: Boolean): Boolean = false
        override fun stopAppCapture() = Unit
        override fun stopCapture() = Unit
        override fun setCallListener(listener: ICallListener?) = Unit
        override fun destroy() = Unit
    }

    private fun notice(): String? = shadowOf(app.getSystemService(NotificationManager::class.java))
        .getNotification(LiveTranslateService.NOTIFICATION_PROBLEM_ID)
        ?.extras?.getCharSequence(Notification.EXTRA_TEXT)?.toString()

    private fun startWatchFromTheTile() {
        val controller = buildService(LiveTranslateService::class.java, LiveTranslateService.startIntent(app, TranslateMode.WATCH)).create()
        controller.startCommand(0, 1)
        pumpUntil("Watch translate to decide") { notice() != null }
    }

    @Test
    fun `a video playing whose app the helper can't read makes Android share the sound, never Nothing is playing`() {
        // Samsung's video player as an older helper reads it on Android 17: no uid, no state.
        app.graph.helper.attach(
            Helper("""[{"uid":-1,"packages":[],"usage":1,"content_type":3,"capture_policy":1,"state":-1,"piid":2215}]"""),
            source = "adb"
        )
        shadowOf(app.getSystemService(AudioManager::class.java)).setIsMusicActive(true)

        startWatchFromTheTile()

        val text = notice()!!
        assertFalse(text, text.startsWith("Nothing is playing yet"))
        assertTrue(text, text.contains("couldn't tell which app is playing"))
        val ask = shadowOf(app).nextStartedActivity
        assertEquals(TranslateToggleActivity::class.java.name, ask?.component?.className)
        assertTrue(
            "Android's own question about sharing the sound",
            ask!!.getBooleanExtra(TranslateToggleActivity.EXTRA_SHARE_SOUND, false)
        )
        assertTrue(
            "Copy diagnostics shows what the helper returned",
            CaptureLog.lines(app).any { it.contains("translate") && it.contains("uid unknown · state unknown · Media") }
        )
        assertEquals(null, LiveTranslateService.running.value)
    }

    @Test
    fun `with everything readable and nothing playing, it still says so`() {
        app.graph.helper.attach(
            Helper(
                """[{"uid":10133,"packages":["com.samsung.android.video"],"usage":1,"content_type":3,"capture_policy":1,"state":3,"piid":2215}]"""
            ),
            source = "adb"
        )
        shadowOf(app.getSystemService(AudioManager::class.java)).setIsMusicActive(false)

        startWatchFromTheTile()

        assertTrue(notice()!!.startsWith("Nothing is playing yet"))
    }
}
