package com.echoledger.mobile.notify

import android.app.Notification
import android.app.NotificationManager
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Settings › Appearance's App language reaches what Android draws for the app (docs/v2/audit/parity.csv PH-SET-061):
 * every notification the app writes itself, its buttons and its channels' names are in Urdu when the app is in Urdu,
 * and word for word as before when it is in English.
 */
class AppLanguageNoticesTest : AppTest() {
    private val manager get() = app.getSystemService(NotificationManager::class.java)

    private fun language(choice: String) {
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        runBlocking { app.graph.settings.update { it.copy(appLanguage = choice) } }
        pumpUntil("the language") { app.graph.liveSettings.value?.appLanguage == choice }
    }

    private val rec = com.echoledger.mobile.ui.v2.HomeFixtures.waiting("rec-1", "Omar", 252_000)

    /** Every notification the app can post by itself, once each. */
    private fun postEverything() {
        manager.cancelAll()
        val ctx = app
        val shown = mutableListOf<Notification>()
        shown += Notifications.recording(ctx, "Recording call with Omar", "Both sides · auto · 2 bookmarks", paused = true)
        shown += Notifications.recording(ctx, "Voice note", "Not hearing the other side for 12 s")
        shown += Notifications.armed(ctx, ready = true, askAfterCall = true)
        shown += Notifications.armed(ctx, ready = false)
        Notifications.showSaveDecision(ctx, rec, "Acme", "keep")
        Notifications.savedWithUndo(ctx, rec.copy(id = "rec-3"))
        Notifications.discardedWithUndo(ctx.applicationContext, rec.copy(id = "rec-2", callerName = null))
        Notifications.captureNotReady(ctx)
        Notifications.noAudio(ctx, callNotRecorded = true)
        Notifications.phoneAppRecordingToCheck(ctx, 0L)
        Notifications.phoneAppImported(ctx, 3)
        Notifications.phoneAppCopyWaiting(ctx, 0L)
        Notifications.askRecordPhoneCall(ctx)
        Notifications.autoRecordOff(ctx)
        Notifications.onlyYourVoice(ctx, "The other side of this call gave no sound.")
        Notifications.sharedProgress(ctx, "n1", "Uploading a recording", 40)
        // Each problem the Ready check can raise (the helper after a restart; the permissions; storage).
        listOf(
            com.echoledger.mobile.ui.v2.SetupHealthFixtures.afterRestart,
            com.echoledger.mobile.ui.v2.SetupHealthFixtures.permissionsMissing,
            com.echoledger.mobile.ui.v2.SetupHealthFixtures.staleHelper
        ).flatMap { Readiness.evaluate(it.ready).problems }.forEach {
            Notifications.readinessAlert(ctx, it)
            shown += shadowOf(manager).allNotifications
        }
        NotifyCategory.entries.forEach { Notifications.test(ctx, it) }
        posted = shadowOf(manager).allNotifications + shown
    }

    private var posted = emptyList<Notification>()

    private fun words(n: Notification): List<String> = listOfNotNull(
        n.extras.getCharSequence(Notification.EXTRA_TITLE)?.toString(),
        n.extras.getCharSequence(Notification.EXTRA_TEXT)?.toString(),
        n.extras.getCharSequence(Notification.EXTRA_BIG_TEXT)?.toString(),
        n.extras.getCharSequence(Notification.EXTRA_SUB_TEXT)?.toString()
    ) + n.actions.orEmpty().map { it.title.toString() }

    /** Latin words that are names or kept as they are in Urdu text. */
    private val names = setOf(
        "WhatsApp", "EchoLedger", "Android", "Shizuku",
        // Shizuku's own button, named as it shows it.
        "Start", "Omar", "Ana", "Pixel", "Acme", "Downloads", "MB", "GB", "API", "SIM"
    )

    private fun englishIn(text: String): List<String> =
        Regex("[A-Za-z][A-Za-z']+").findAll(text).map { it.value }.filter { it !in names }.toList()

    @Test
    fun inUrduEveryNotificationTheAppWritesAndItsButtonsAreInUrdu() {
        language("ur")
        postEverything()
        assertTrue(posted.size > 25)
        val english = posted.flatMap { words(it) }.filter { englishIn(it).isNotEmpty() }
        assertEquals(emptyList<String>(), english)
        // A few exactly.
        val all = posted.flatMap { words(it) }
        assertTrue("یہ ریکارڈنگ محفوظ کریں؟" in all)
        assertTrue("رکی ہوئی · Omar کے ساتھ کال ریکارڈ ہو رہی ہے" in all)
        assertTrue("دونوں طرف · خودکار · 2 بک مارکس" in all)
        assertTrue("محفوظ · Omar کے ساتھ کال · 4:12" in all)
        assertTrue("3 کال ریکارڈنگز لے آئی گئیں" in all)
    }

    @Test
    fun inEnglishTheyAreWordForWordAsBefore() {
        language("en")
        postEverything()
        val all = posted.flatMap { words(it) }
        listOf(
            "Save this recording?",
            "Paused · Recording call with Omar",
            "Both sides · auto · 2 bookmarks",
            "Saved · Call with Omar · 4:12",
            "3 call recordings brought in",
            "Ready to record WhatsApp calls",
            "Calls are recorded automatically; you decide after each call.",
            "Record now",
            "Keep",
            "Undo"
        ).forEach { assertTrue(it, it in all) }
        assertTrue(all.none { Regex("[\\u0600-\\u06FF]").containsMatchIn(it) })
    }

    @Test
    fun theChannelsAreNamedInTheAppLanguageAndRenamedWhenItChanges() {
        language("ur")
        Notifications.createChannels(app)
        val ur = manager.notificationChannels.associate { it.id to it.name.toString() }
        assertEquals("ریکارڈنگ", ur[NotifyCategory.RECORDING.channelId])
        assertEquals("کالز اور مسائل", ur[Notifications.CHANNEL_DECISIONS])
        assertTrue(ur.values.none { englishIn(it).isNotEmpty() })
        // Saving another language renames them (the app does it as it saves).
        language("en")
        pumpUntil("renamed") { manager.getNotificationChannel(NotifyCategory.RECORDING.channelId)?.name == "Recording" }
        assertEquals("Calls and problems", manager.getNotificationChannel(Notifications.CHANNEL_DECISIONS)?.name)
    }

    @Test
    fun theTableKnowsEveryLineTheRecordingServiceWrites() {
        val en = AppWords.resources(app, "en")
        listOf(
            "Recording phone call", "Recording call", "Voice note", "Recording", "Recording call with Omar Khan",
            "Both sides · manual", "Both voices on one track · auto", "Your voice only · manual",
            "Waiting for an answer · the recording starts when the call is picked up", "Microphone · 1 bookmark",
            "Bookmarked 1:02", "This recording already has 500 bookmarks.", "Not hearing the other side for 30 s",
            "Not hearing the other side for 30 s.", "Not hearing the other side", "The other side of this call gave no sound.",
            "Missed recording", "4 calls were not recorded", "Phone call · 2:05", "WhatsApp call · 4:12",
            "Translating to Urdu", "Paused", "Live Translate is not in your plan.", "Saved to Downloads/EchoLedger/game.srt",
            "Choose your games in Game translate first.", "Nothing is playing yet. Start the game or video first, then Live Translate."
        ).forEach { assertTrue(it, NoticeWords.knows(en, it)) }
    }

    @Test
    fun thePersonsOwnWordsGoIntoTheUrduLinesAsTheyAre() {
        language("ur")
        // A contact called "Client" and a title of the person's own that ends in " call": neither is looked up.
        val client = rec.copy(id = "rec-c", callerName = "Client")
        val titled = rec.copy(id = "rec-t", title = "Bilal Traders weekly call")
        Notifications.showSaveDecision(app, client, "Client", "keep")
        Notifications.savedWithUndo(app, titled)
        val all = shadowOf(manager).allNotifications.flatMap { words(it) }
        assertTrue(all.toString(), "Client کے ساتھ کال · 4:12 · Client" in all)
        assertTrue(all.toString(), "محفوظ · Bilal Traders weekly call · 4:12" in all)
        val recording = Notifications.recording(app, "Recording call with Client", "Microphone")
        assertEquals("Client کے ساتھ کال ریکارڈ ہو رہی ہے", recording.extras.getCharSequence(Notification.EXTRA_TITLE).toString())
        val en = AppWords.resources(app, "en")
        val ur = AppWords.resources(app, "ur")
        assertEquals("Client کے ساتھ کال", NoticeWords.say(ur, en, "Call with Client"))
        assertEquals("Bilal Traders weekly call کال", NoticeWords.say(ur, en, "Bilal Traders weekly call call"))
        // The app's own words in a hole still are (a paused recording's state).
        assertEquals("رکی ہوئی · Client کے ساتھ کال ریکارڈ ہو رہی ہے", NoticeWords.say(ur, en, "Paused · Recording call with Client"))
    }

    /** The recording view's text as Android lays it out (its own layout, applied to a view). */
    private fun recordingTextSizes(): Map<Int, Float> {
        val n = Notifications.recording(app, "Recording call", "Microphone")
        val view = n.bigContentView.apply(app, android.widget.FrameLayout(app))
        return Notifications.RECORDING_TEXT_SP.associate { (id, _) -> id to view.findViewById<android.widget.TextView>(id).textSize }
    }

    @Test
    fun theRecordingViewsTextFollowsTheTextSizeAndItsButtonsTheLanguage() {
        language("en")
        val phone = recordingTextSizes()
        runBlocking { app.graph.settings.update { it.copy(textSize = "largest") } }
        pumpUntil("largest") { app.graph.liveSettings.value?.textSize == "largest" }
        val largest = recordingTextSizes()
        val sp = app.resources.displayMetrics.scaledDensity
        Notifications.RECORDING_TEXT_SP.forEach { (id, size) -> assertEquals(size * 1.3f * sp, largest.getValue(id), 0.6f) }
        assertTrue(phone.values.all { it > 0f })
        // Its four buttons in the app language, Captions and Stop too.
        language("ur")
        val view = Notifications.recording(app, "Recording call", "Microphone").bigContentView.apply(app, android.widget.FrameLayout(app))
        val buttons = listOf(
            com.echoledger.mobile.R.id.rec_pause,
            com.echoledger.mobile.R.id.rec_stop,
            com.echoledger.mobile.R.id.rec_captions,
            com.echoledger.mobile.R.id.rec_discard
        )
            .map { view.findViewById<android.widget.TextView>(it).text.toString() }
        assertEquals(listOf("روکیں", "بند کریں", "کیپشنز", "ہٹائیں"), buttons)
    }

    @Test
    fun aChannelSettingThePersonChangedSurvivesTheRename() {
        language("en")
        Notifications.createChannels(app)
        // The person turns Recording's channel down and its badge off in Android's settings.
        val changed = manager.getNotificationChannel(NotifyCategory.RECORDING.channelId).apply {
            importance = NotificationManager.IMPORTANCE_MIN
            enableVibration(true)
        }
        manager.createNotificationChannel(changed)
        language("ur")
        pumpUntil("renamed") { manager.getNotificationChannel(NotifyCategory.RECORDING.channelId)?.name == "ریکارڈنگ" }
        val after = manager.getNotificationChannel(NotifyCategory.RECORDING.channelId)
        assertEquals(NotificationManager.IMPORTANCE_MIN, after.importance)
        assertTrue(after.shouldVibrate())
    }
}
