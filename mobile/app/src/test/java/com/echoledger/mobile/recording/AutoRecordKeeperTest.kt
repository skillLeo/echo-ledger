package com.echoledger.mobile.recording

import android.app.NotificationManager
import android.content.ComponentName
import android.content.Intent
import android.os.Looper
import androidx.work.ListenableWorker
import androidx.work.WorkManager
import androidx.work.testing.TestListenableWorkerBuilder
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import java.time.Duration
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** Auto-record comes back on by itself after an update or a restart, without the app being opened (since v1.3). */
class AutoRecordKeeperTest : AppTest() {
    @Before
    fun notArmedYet() {
        RecordingService.isArmed = false
    }

    @After
    fun clearArmed() {
        RecordingService.isArmed = false
    }

    private fun autoRecord(on: Boolean) = runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = on) } }

    private fun runCheck(): ListenableWorker.Result = runBlocking { TestListenableWorkerBuilder<KeepArmedWorker>(app).build().doWork() }

    private fun checkKept(): Boolean = WorkManager.getInstance(app)
        .getWorkInfosForUniqueWork(AutoRecordKeeper.PERIODIC_WORK)
        .get(10, TimeUnit.SECONDS)
        .any { !it.state.isFinished }

    private fun startService(action: String) =
        buildService(RecordingService::class.java, Intent(app, RecordingService::class.java).setAction(action)).create().startCommand(0, 1)

    @Test
    fun `a new process with auto-record on arms it again and opens no screen`() {
        autoRecord(true)

        AutoRecordKeeper.onAppStart(app)
        shadowOf(Looper.getMainLooper()).idle()
        assertNull("it waits a few seconds first", shadowOf(app).nextStartedService)

        shadowOf(Looper.getMainLooper()).idleFor(Duration.ofSeconds(5))
        pumpUntil("the check to finish") { checkKept() }

        val started = shadowOf(app).nextStartedService
        assertEquals(ComponentName(app, RecordingService::class.java), started.component)
        assertEquals(RecordingService.ACTION_ARM, started.action)
        assertEquals("app started", started.getStringExtra(RecordingService.EXTRA_FROM))
        assertNull("background work never opens a screen", shadowOf(app).nextStartedActivity)
        assertTrue(CaptureLog.lines(app).any { it.contains("auto-record back on: app started") })
        assertTrue("the 15-minute check comes back too", checkKept())
    }

    @Test
    fun `the 15-minute check arms it again`() {
        autoRecord(true)

        assertEquals(ListenableWorker.Result.success(), runCheck())

        assertEquals(RecordingService.ACTION_ARM, shadowOf(app).nextStartedService.action)
        assertTrue(CaptureLog.lines(app).any { it.contains("auto-record back on: periodic check") })
    }

    @Test
    fun `nothing starts when auto-record is already armed`() {
        autoRecord(true)
        RecordingService.isArmed = true

        runCheck()
        runBlocking { AutoRecordKeeper.startCheck(app) }

        assertNull(shadowOf(app).nextStartedService)
    }

    @Test
    fun `with auto-record off nothing starts and the 15-minute check removes itself`() {
        autoRecord(false)
        AutoRecordKeeper.keepChecking(app)

        runCheck()
        runBlocking { AutoRecordKeeper.startCheck(app) }

        assertNull(shadowOf(app).nextStartedService)
        assertFalse(checkKept())
    }

    @Test
    fun `when Android refuses the start a quiet notification turns it back on without opening a screen`() {
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        autoRecord(true)

        val asked = AutoRecordKeeper.ensure(app, runBlocking { app.graph.settings.current() }, "after update") {
            throw IllegalStateException("not allowed")
        }

        assertFalse(asked)
        val posted = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications.single()
        assertEquals(Notifications.CHANNEL_ACTIVITY, posted.channelId)
        assertEquals("Auto-record is off — tap to turn it on", shadowOf(posted).contentTitle.toString())
        val tap = shadowOf(posted.contentIntent)
        assertTrue("the tap starts the service, not a screen", tap.isForegroundService)
        assertEquals(RecordingService.ACTION_ARM, tap.savedIntent.action)
        assertTrue(CaptureLog.lines(app).any { it.contains("auto-record could not restart: after update: IllegalStateException") })
        assertTrue("the setting stays on so the next check tries again", runBlocking { app.graph.settings.current() }.autoRecordArmed)
    }

    @Test
    fun `an app update or a restart arms it again`() {
        autoRecord(true)

        listOf(
            Intent.ACTION_MY_PACKAGE_REPLACED to "after update",
            Intent.ACTION_BOOT_COMPLETED to "after restart"
        ).forEach { (action, why) ->
            app.sendBroadcast(Intent(action).setComponent(ComponentName(app, RearmReceiver::class.java)))
            pumpUntil("the service to be asked to arm ($why)") { CaptureLog.lines(app).any { it.contains("auto-record back on: $why") } }
            assertEquals(RecordingService.ACTION_ARM, shadowOf(app).nextStartedService.action)
        }
    }

    @Test
    fun `turning auto-record on keeps the 15-minute check and turning it off removes it`() {
        startService(RecordingService.ACTION_ARM)
        pumpUntil("armed") { RecordingService.isArmed }
        assertTrue(checkKept())

        startService(RecordingService.ACTION_DISARM)
        pumpUntil("the check to be removed") { !checkKept() }
    }
}
