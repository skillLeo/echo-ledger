package com.echoledger.mobile.calls

import android.app.Notification
import android.app.NotificationManager
import android.view.WindowManager
import androidx.core.app.NotificationCompat
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.notify.AfterCallNotice
import com.echoledger.mobile.overlay.AfterCallBar
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.overlay.FloatingWindow
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.installWhatsAppStandIn
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowLooper
import org.robolectric.shadows.ShadowSettings
import org.robolectric.shadows.ShadowWindowManagerImpl

/**
 * v2.1 UX-13: with v21_aftercall_notify on, a quiet notification asks after a call instead of the card. Channel "After a
 * call" (pops up, silent); "Ali · call saved", "WhatsApp · 2:38", [Transcribe] [Delete]; a swipe or nobody choosing
 * for 60 min keeps the audio only; Transcribe → "Transcribing…" → "Transcript ready" in place; Delete → "Deleted · Undo"
 * for 8 s, the file only after that; back-to-back calls share one notification; notifications off: nothing shows and
 * the call is kept as audio only. Never a window over other apps, except the small bar the person turns on.
 */
class AfterCallNoticeTest : AppTest() {
    @Before
    fun flagOn() {
        ShadowSettings.setCanDrawOverlays(true)
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        com.echoledger.mobile.notify.Notifications.createChannels(app)
        notifying(true)
    }

    @After
    fun close() {
        AfterCallActions.drainForTest()
        shadowOf(android.os.Looper.getMainLooper()).idle()
        AfterCallBar.hide()
        DecisionPopup.resetForTest()
        DecisionPopup.drainForTest()
        AfterCallNotice.resetForTest(app)
    }

    /** The flag as the server sends it (contract 2.0.0-r), stored and seen by the app's hot copy of the settings. */
    private fun notifying(
        on: Boolean,
        change: (com.echoledger.mobile.data.AppSettings) -> com.echoledger.mobile.data.AppSettings = { it }
    ) {
        runBlocking { app.graph.settings.update { change(it.copy(uiFlags = it.uiFlags + (CallChoice.NOTIFY_FLAG to on))) } }
        pumpUntil("the app sees the flag") { app.graph.liveSettings.value?.uiFlags?.get(CallChoice.NOTIFY_FLAG) == on }
    }

    private fun call(id: String = "call-1", durationMs: Long = 158_000) =
        app.storeRecording(id = id, state = "pending_decision", sourceApp = "whatsapp", durationMs = durationMs)

    private fun manager() = shadowOf(app.getSystemService(NotificationManager::class.java))

    private fun notifications(): List<Notification> = manager().allNotifications

    private fun noticeOf(id: String = "call-1"): Notification? = manager().getNotification(id.hashCode())

    private fun title(n: Notification?) = n?.let { shadowOf(it).contentTitle?.toString() }

    private fun text(n: Notification?) = n?.let { shadowOf(it).contentText?.toString() }

    private fun actions(n: Notification?) = n?.actions.orEmpty().map { it.title.toString() }

    private fun tap(n: Notification?, action: String) = n!!.actions.first { it.title.toString() == action }.actionIntent.send()

    private fun overlayWindows(): Int =
        Shadow.extract<ShadowWindowManagerImpl>(app.getSystemService(WindowManager::class.java)).views.count {
            (it.layoutParams as? WindowManager.LayoutParams)?.type == WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
        }

    private fun asked(id: String = "call-1"): Notification {
        PostCallPrompt.show(app, call(id), "dark")
        pumpUntil("$id asked in the shade") { noticeOf(id) != null || manager().getNotification(AfterCallNotice.GROUPED_ID) != null }
        return noticeOf(id) ?: manager().getNotification(AfterCallNotice.GROUPED_ID)
    }

    private fun work(name: String) = WorkManager.getInstance(app).getWorkInfosForUniqueWork(name).get(10, TimeUnit.SECONDS)

    @Test
    fun `a call is asked in a quiet notification that pops up, with Transcribe and Delete, and never a window`() {
        val notice = asked()
        assertEquals("never a window over other apps", 0, overlayWindows())
        assertFalse(DecisionPopup.isShowing)
        assertEquals(AfterCallNotice.CHANNEL, notice.channelId)
        assertEquals("WhatsApp · call saved", title(notice))
        assertEquals("WhatsApp · 2:38", text(notice))
        assertEquals(listOf("Transcribe", "Delete"), actions(notice))
        assertEquals("in the shade for an hour", CallChoice.NOTICE_MS, notice.timeoutAfter)
        assertNotNull("a swipe has an answer", notice.deleteIntent)
        assertEquals("it pops up", NotificationCompat.GROUP_ALERT_ALL, notice.groupAlertBehavior)
        val channel = app.getSystemService(NotificationManager::class.java).getNotificationChannel(AfterCallNotice.CHANNEL)
        assertEquals("After a call", channel.name.toString())
        assertEquals(NotificationManager.IMPORTANCE_HIGH, channel.importance)
        assertNull("silent", channel.sound)
        assertFalse("no vibration", channel.shouldVibrate())
        assertEquals("it waits for the person meanwhile", "pending_decision", app.peekRecording("call-1")?.state)
        val keep = work(DecisionTimeoutWorker.workName("call-1")).single { it.state == WorkInfo.State.ENQUEUED }
        assertTrue("nobody choosing keeps the audio only after the hour", keep.initialDelayMillis >= CallChoice.NOTICE_MS)
    }

    @Test
    fun `the name of the call goes first, and the first notification says once how to keep the audio only`() {
        call()
        app.graph.recordings.update("call-1") { it.copy(callerName = "Ali") }
        PostCallPrompt.show(app, app.peekRecording("call-1")!!, "dark")
        pumpUntil("asked") { noticeOf() != null }
        assertEquals("Ali · call saved", title(noticeOf()))
        val big = noticeOf()!!.extras.getCharSequence(NotificationCompat.EXTRA_BIG_TEXT)?.toString().orEmpty()
        assertTrue(big, big.contains("Swipe away to keep the audio only. Change this in Settings › After each call."))
        pumpUntil("remembered") { app.graph.liveSettings.value?.callChoiceHintShown == true }

        tap(noticeOf(), "Transcribe")
        pumpUntil("decided") { app.peekRecording("call-1")?.state == "queued" }
        val second = asked("call-2")
        assertNull("only the first time", second.extras.getCharSequence(NotificationCompat.EXTRA_BIG_TEXT))
    }

    @Test
    fun `a swipe keeps the audio only, and nothing else shows`() {
        asked().deleteIntent.send()
        pumpUntil("kept as audio only") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == false } == true }
        pumpUntil("sent") { work("upload-call-1").isNotEmpty() }
        assertTrue("nothing else shows", notifications().isEmpty())
        assertEquals(0, overlayWindows())
    }

    @Test
    fun `nobody choosing keeps the audio only once the hour is over`() {
        asked()
        val keep = work(DecisionTimeoutWorker.workName("call-1")).single { it.state == WorkInfo.State.ENQUEUED }
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.setInitialDelayMet(keep.id)
        pumpUntil("kept as audio only") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == false } == true }
        assertNull(noticeOf())
    }

    /**
     * The hour's wait is WorkManager's own stored work, which Android keeps across the process's death and a reboot,
     * never a timer of the process: a held call is always kept as audio only and sent in the end.
     */
    @Test
    fun `the hour's wait survives the process dying mid-wait, and the held call is sent in the end`() {
        asked()
        val keep = work(DecisionTimeoutWorker.workName("call-1")).single { it.state == WorkInfo.State.ENQUEUED }
        assertTrue(keep.initialDelayMillis >= CallChoice.NOTICE_MS)
        // The process dies mid-wait: what lived only in it (the card, the queues, the main thread's timers) is gone.
        DecisionPopup.resetForTest()
        AfterCallBar.hide()
        // More than the hour of the process's own time decides nothing: no timer of the process keeps the call.
        ShadowLooper.idleMainLooper(CallChoice.NOTICE_MS + 60_000, TimeUnit.MILLISECONDS)
        assertEquals("still waiting on the phone", "pending_decision", app.peekRecording("call-1")?.state)
        // The new process: WorkManager runs the stored work once its time has come.
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.setInitialDelayMet(keep.id)
        pumpUntil("kept as audio only") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == false } == true }
        pumpUntil("and sent") { work("upload-call-1").isNotEmpty() }
    }

    @Test
    fun `Transcribe sends it at once and the same notification says Transcribing…, then Transcript ready`() {
        tap(asked(), "Transcribe")
        pumpUntil("kept to be transcribed") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == true } == true }
        pumpUntil("Transcribing… in its place") { title(noticeOf()) == "Transcribing…" }
        assertEquals("WhatsApp · 2:38", text(noticeOf()))
        assertTrue("no actions while it works", actions(noticeOf()).isEmpty())
        assertNull("a swipe on it decides nothing", noticeOf()!!.deleteIntent)
        assertTrue("sent at once", work("upload-call-1").any { it.state != WorkInfo.State.CANCELLED })

        // The upload reached the server: once the transcript is ready the same notification says so.
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/library.*",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JS1", title = null))))
        )
        AfterCallNotice.uploaded(app, "call-1", "01JS1")
        val watch = work("ready-watch:01JS1").single()
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.apply {
            setAllConstraintsMet(watch.id)
            setInitialDelayMet(watch.id)
        }
        pumpUntil("Transcript ready in its place") { title(noticeOf()) == "Transcript ready" }
        assertEquals("one notification for the call", 1, notifications().size)
        pumpUntil("no longer watched") { !AfterCallNotice.isTranscribing(app, "call-1") }
    }

    /** Review of UX-13: Android sends the swipe's answer when the hour is over too; that is nobody choosing. */
    @Test
    fun `the hour running out keeps the audio only but never counts toward Always audio only`() {
        notifying(true) { it.copy(callChoiceHintShown = true) }
        asked()
        fun gone(id: String, postedAt: Long) = app.sendBroadcast(
            android.content.Intent(app, SaveDecisionReceiver::class.java)
                .setAction(SaveDecisionReceiver.ACTION_AC_SWIPED)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, id)
                .putExtra(SaveDecisionReceiver.EXTRA_IDS, arrayOf(id))
                .putExtra(SaveDecisionReceiver.EXTRA_POSTED_AT, postedAt)
        )
        gone("call-1", android.os.SystemClock.elapsedRealtime() - CallChoice.NOTICE_MS)
        pumpUntil("kept as audio only") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == false } == true }
        AfterCallActions.drainForTest()
        assertEquals("nobody chose", 0, app.graph.liveSettings.value?.callChoiceStreakCount)

        // A swipe within the hour is the person's choice.
        asked("call-2")
        assertNotNull("the swipe carries when it was posted", noticeOf("call-2")!!.deleteIntent)
        gone("call-2", android.os.SystemClock.elapsedRealtime() - 5_000)
        pumpUntil("counted") { app.graph.liveSettings.value?.callChoiceStreakCount == 1 }
        assertEquals(CallChoice.Pick.AUDIO.wire, app.graph.liveSettings.value?.callChoiceStreak)
    }

    /** Review of UX-13: the channel follows the flag as the server's flags are saved, whatever page is open. */
    @Test
    fun `the After a call channel exists only while the flag is on`() {
        val nm = app.getSystemService(NotificationManager::class.java)
        notifying(false)
        pumpUntil("gone with the flag") { nm.getNotificationChannel(AfterCallNotice.CHANNEL) == null }
        notifying(true)
        pumpUntil("back with the flag") { nm.getNotificationChannel(AfterCallNotice.CHANNEL) != null }
        notifying(false)
        pumpUntil("gone again") { nm.getNotificationChannel(AfterCallNotice.CHANNEL) == null }
        // The flag on again: and the first notification finds its channel.
        notifying(true)
        assertEquals(AfterCallNotice.CHANNEL, asked().channelId)
    }

    /** Review of UX-13: a call sent with Transcribe all that never reaches the server ends its part of the group. */
    @Test
    fun `a member of Transcribe all deleted or never uploaded counts as done, so the group never waits the hour`() {
        (1..2).forEach { PostCallPrompt.show(app, call("call-$it"), "dark") }
        pumpUntil("grouped") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "2 calls saved" }
        tap(manager().getNotification(AfterCallNotice.GROUPED_ID), "Transcribe all")
        pumpUntil("Transcribing…") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "Transcribing…" }
        // call-1 deleted before it was sent; call-2's file gone, so its upload fails for good.
        runBlocking { Decisions.apply(app, "call-1", keep = false) }
        app.graph.recordings.encryptedFile("call-2").delete()
        val result = runBlocking {
            androidx.work.testing.TestListenableWorkerBuilder<com.echoledger.mobile.net.UploadWorker>(app)
                .setInputData(androidx.work.workDataOf("recording_id" to "call-2"))
                .build()
                .doWork()
        }
        assertTrue(result is androidx.work.ListenableWorker.Result.Failure)
        pumpUntil("the group's notification goes") { manager().getNotification(AfterCallNotice.GROUPED_ID) == null }
    }

    /** Review of UX-13: the ready watchers run side by side; each count reaches the next. */
    @Test
    fun `members done at once all count, and the group says so`() {
        (1..8).forEach { PostCallPrompt.show(app, call("call-$it"), "dark") }
        pumpUntil("grouped") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "8 calls saved" }
        tap(manager().getNotification(AfterCallNotice.GROUPED_ID), "Transcribe all")
        pumpUntil("Transcribing…") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "Transcribing…" }
        val threads = (1..8).map { i -> Thread { AfterCallNotice.groupMemberDone(app, "call-$i", ready = true) } }
        threads.forEach(Thread::start)
        threads.forEach { it.join(10_000) }
        pumpUntil("8 transcripts ready") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "8 transcripts ready" }
    }

    @Test
    fun `the notification shows the call's app's own icon, and none when the app is not on the phone`() {
        assertNull("no placeholder", asked("call-1").getLargeIcon())
        app.installWhatsAppStandIn()
        AfterCallNotice.resetForTest(app)
        tap(noticeOf("call-1"), "Transcribe")
        pumpUntil("decided") { app.peekRecording("call-1")?.state == "queued" }
        assertNotNull("WhatsApp's own icon", asked("call-2").getLargeIcon())
    }

    @Test
    fun `after Transcribe all the one notification says 3 transcripts ready once the last one is, never left Transcribing…`() {
        (1..3).forEach { PostCallPrompt.show(app, call("call-$it"), "dark") }
        pumpUntil("grouped") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "3 calls saved" }
        tap(manager().getNotification(AfterCallNotice.GROUPED_ID), "Transcribe all")
        pumpUntil("Transcribing…") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "Transcribing…" }

        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/library.*",
            FakeServer.json(200, ServerFixtures.libraryPage((1..3).map { ServerFixtures.libraryItem("01JS$it", title = null) }))
        )
        val driver = androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!
        (1..3).forEach { i ->
            AfterCallNotice.uploaded(app, "call-$i", "01JS$i")
            val watch = work("ready-watch:01JS$i").single()
            driver.setAllConstraintsMet(watch.id)
            driver.setInitialDelayMet(watch.id)
            pumpUntil("call-$i's transcript seen") { work("ready-watch:01JS$i").single().state == WorkInfo.State.SUCCEEDED }
            if (i < 3) {
                assertEquals("still working on the rest", "Transcribing…", title(manager().getNotification(AfterCallNotice.GROUPED_ID)))
            }
        }
        pumpUntil("3 transcripts ready") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "3 transcripts ready" }
        assertEquals("one notification for them all", 1, notifications().size)
    }

    @Test
    fun `Delete says Deleted · Undo for 8 seconds, and the file goes only after that`() {
        tap(asked(), "Delete")
        pumpUntil("on its way out") { app.peekRecording("call-1")?.state == Decisions.DISCARDING }
        pumpUntil("Deleted · Undo in its place") { title(noticeOf())?.startsWith("Deleted") == true }
        assertEquals("where the question was", AfterCallNotice.CHANNEL, noticeOf()!!.channelId)
        assertEquals(listOf("Undo"), actions(noticeOf()))
        assertEquals(CallChoice.DELETED_MS, noticeOf()!!.timeoutAfter)
        assertTrue("the tap deletes nothing", app.graph.recordings.encryptedFile("call-1").exists())
        val delete = work(DiscardLaterWorker.workName("call-1")).single()
        assertTrue("the file waits for the 8 s", delete.initialDelayMillis >= CallChoice.DELETED_MS)

        // Undo: asked again, in the notification.
        tap(noticeOf(), "Undo")
        pumpUntil("back") { app.peekRecording("call-1")?.state == "pending_decision" }
        pumpUntil("asked again") { actions(noticeOf()) == listOf("Transcribe", "Delete") }
        assertEquals(0, overlayWindows())
    }

    @Test
    fun `10 back-to-back calls give one grouped notification and no window`() {
        (1..10).forEach { PostCallPrompt.show(app, call("call-$it"), "dark") }
        pumpUntil("one notification for all ten") {
            title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "10 calls saved" && notifications().size == 1
        }
        assertEquals("never a window", 0, overlayWindows())
        val grouped = manager().getNotification(AfterCallNotice.GROUPED_ID)
        assertEquals(listOf("Transcribe all"), actions(grouped))
        assertEquals(CallChoice.NOTICE_MS, grouped.timeoutAfter)

        tap(grouped, "Transcribe all")
        (1..10).forEach { i ->
            pumpUntil("call-$i to be transcribed") { app.peekRecording("call-$i")?.transcribe == true }
        }
        pumpUntil("the one notification says so") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "Transcribing…" }
        assertEquals(1, notifications().size)
    }

    @Test
    fun `a swipe on the grouped notification keeps every call as audio only`() {
        (1..3).forEach { PostCallPrompt.show(app, call("call-$it"), "dark") }
        pumpUntil("grouped") { title(manager().getNotification(AfterCallNotice.GROUPED_ID)) == "3 calls saved" }
        manager().getNotification(AfterCallNotice.GROUPED_ID).deleteIntent.send()
        (1..3).forEach { i ->
            pumpUntil("call-$i kept as audio only") {
                app.peekRecording("call-$i")?.let { it.state == "queued" && it.transcribe == false } ==
                    true
            }
        }
    }

    @Test
    fun `after the same choice 3 times in a row the next notification offers Always transcribe, once`() {
        notifying(true) { it.copy(callChoiceHintShown = true) }
        (1..3).forEach { i ->
            val notice = asked("call-$i")
            assertEquals(listOf("Transcribe", "Delete"), actions(notice))
            tap(notice, "Transcribe")
            pumpUntil("call-$i transcribed") { app.peekRecording("call-$i")?.transcribe == true }
        }
        pumpUntil("three in a row") { app.graph.liveSettings.value?.callChoiceStreakCount == 3 }
        val fourth = asked("call-4")
        assertEquals(listOf("Transcribe", "Delete", "Always transcribe"), actions(fourth))
        pumpUntil("offered once") { app.graph.liveSettings.value?.callChoiceAlwaysOffered == true }
        tap(fourth, "Always transcribe")
        pumpUntil("Transcribe every call") { app.graph.liveSettings.value?.afterEachCall == CallChoice.TRANSCRIBE }
        pumpUntil("and this call too") { app.peekRecording("call-4")?.transcribe == true }
        // From now on no question at all.
        val settings = runBlocking { app.graph.settings.current() }
        assertEquals(CallChoice.Outcome.TRANSCRIBE, PostCallPrompt.outcome(app, settings))
    }

    @Test
    fun `with notifications off nothing shows and the call is kept as audio only`() {
        shadowOf(app).denyPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        PostCallPrompt.show(app, call(), "dark")
        pumpUntil("kept as audio only") { app.peekRecording("call-1")?.let { it.state == "queued" && it.transcribe == false } == true }
        assertTrue(notifications().isEmpty())
        assertEquals(0, overlayWindows())
    }

    @Test
    fun `Do Not Disturb or a choice on the pill asks nothing at all`() {
        val nm = app.getSystemService(NotificationManager::class.java)
        shadowOf(nm).setNotificationPolicyAccessGranted(true)
        nm.setInterruptionFilter(NotificationManager.INTERRUPTION_FILTER_PRIORITY)
        val settings = runBlocking { app.graph.settings.current() }
        assertEquals(CallChoice.Outcome.AUDIO_QUIET, PostCallPrompt.outcome(app, settings))
        nm.setInterruptionFilter(NotificationManager.INTERRUPTION_FILTER_ALL)
        listOf(CallChoice.Pick.TRANSCRIBE, CallChoice.Pick.AUDIO).forEach { pick ->
            val rec = PostCallPrompt.stored(call("pill-${pick.wire}"), PostCallPrompt.outcome(app, settings, duringCall = pick))
            app.graph.recordings.update(rec.id) { rec }
            PostCallPrompt.show(app, rec, "dark")
        }
        AfterCallActions.drainForTest()
        ShadowLooper.idleMainLooper()
        assertTrue("nothing asks", notifications().isEmpty())
        assertEquals(0, overlayWindows())
    }

    @Test
    fun `with the flag off the card asks as before`() {
        notifying(false)
        PostCallPrompt.show(app, call(), "dark")
        assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
        assertTrue(notifications().isEmpty())
    }

    @Test
    fun `the small bar the person turns on shows under the status bar for 6 seconds, and the notification only in the shade`() {
        notifying(true) { it.copy(afterCallBar = true) }
        PostCallPrompt.show(app, call(), "dark")
        assertTrue(AfterCallBar.isShowing)
        val bar = Shadow.extract<ShadowWindowManagerImpl>(app.getSystemService(WindowManager::class.java)).views.single {
            (it.layoutParams as? WindowManager.LayoutParams)?.title == FloatingWindow.AFTER_CALL_BAR.title
        }
        val params = bar.layoutParams as WindowManager.LayoutParams
        assertEquals(android.view.Gravity.TOP, params.gravity and android.view.Gravity.VERTICAL_GRAVITY_MASK)
        assertTrue("never takes focus", params.flags and WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE != 0)
        assertFalse("no sound", bar.isSoundEffectsEnabled)
        pumpUntil("in the shade") { noticeOf() != null }
        assertEquals("it does not pop up over the bar", NotificationCompat.GROUP_ALERT_SUMMARY, noticeOf()!!.groupAlertBehavior)
        ShadowLooper.idleMainLooper(CallChoice.BAR_MS + 1, TimeUnit.MILLISECONDS)
        assertFalse("gone after 6 s", AfterCallBar.isShowing)
        assertEquals("closing it decides nothing", "pending_decision", app.peekRecording("call-1")?.state)
        assertEquals(0, overlayWindows())
    }

    @Test
    fun `with the bar off, or without the overlay permission, no bar`() {
        PostCallPrompt.show(app, call(), "dark")
        assertFalse(AfterCallBar.isShowing)
        notifying(true) { it.copy(afterCallBar = true) }
        ShadowSettings.setCanDrawOverlays(false)
        PostCallPrompt.show(app, call("call-2"), "dark")
        assertFalse("not without the overlay permission", AfterCallBar.isShowing)
    }
}
