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
        Notifications.savedWithUndo(ctx, rec.copy(id = "rec-3"), "Call with Omar")
        Notifications.discardedWithUndo(ctx.applicationContext, rec.copy(id = "rec-2"), "WhatsApp call")
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
        assertTrue("رکا ہوا · Omar کے ساتھ کال ریکارڈ ہو رہی ہے" in all)
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
}
