package com.echoledger.mobile.calls

import android.Manifest
import android.app.NotificationManager
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.overlay.DecisionCard
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.EchoLedgerTheme
import java.time.Duration
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadows.ShadowLooper
import org.robolectric.shadows.ShadowSettings

/**
 * The small "Save this call recording?" card after a WhatsApp call: shown on call end, Keep / Discard, the 10-second
 * auto-hide that decides nothing, and the notification when the overlay permission is missing.
 */
class PostCallPromptTest : AppTest() {
    @get:Rule
    val compose = createComposeRule()

    @After
    fun closeCard() {
        DecisionPopup.hide()
    }

    private fun overlayAllowed(allowed: Boolean) = ShadowSettings.setCanDrawOverlays(allowed)

    private fun pendingCall(id: String = "call-1", app: String = "whatsapp") =
        this.app.storeRecording(id = id, state = "pending_decision", sourceApp = app, durationMs = 272_000)

    private fun uploadWork(id: String) = WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS)

    private fun shown() = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications.size

    @Test
    fun `the card is shown when a WhatsApp call recording ends`() {
        overlayAllowed(true)
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS)
        val rec = pendingCall()

        PostCallPrompt.show(app, rec, afterCallTimeout = "wait", themeMode = "dark")

        assertTrue(DecisionPopup.isShowing)
        assertEquals("call-1", DecisionPopup.showingFor)
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `a WhatsApp Business call gets the card too and another app does not`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, pendingCall("biz", "whatsapp_business"), "wait", "dark")
        assertEquals("biz", DecisionPopup.showingFor)
        DecisionPopup.hide()

        PostCallPrompt.show(app, pendingCall("zoom-1", "zoom"), "wait", "dark")
        assertFalse(DecisionPopup.isShowing)
    }

    @Test
    fun `doing nothing for ten seconds only hides the card and nothing is uploaded or discarded`() {
        overlayAllowed(true)
        val rec = pendingCall()
        PostCallPrompt.show(app, rec, afterCallTimeout = "wait", themeMode = "dark")

        ShadowLooper.idleMainLooper(DecisionPopup.AUTO_HIDE_MS - 1, TimeUnit.MILLISECONDS)
        assertTrue("still there just before the ten seconds", DecisionPopup.isShowing)
        ShadowLooper.idleMainLooper(2, TimeUnit.MILLISECONDS)

        assertFalse(DecisionPopup.isShowing)
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
        assertTrue("no upload was queued", uploadWork("call-1").isEmpty())
        assertNotNull("the recording is kept for later", app.graph.recordings.get("call-1"))
        assertTrue(app.graph.recordings.encryptedFile("call-1").exists())
    }

    @Test
    fun `Keep queues the upload and closes the card`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, pendingCall(), "wait", "dark")

        runBlocking { Decisions.apply(app, "call-1", keep = true) }
        ShadowLooper.idleMainLooper()

        assertEquals("queued", app.peekRecording("call-1")?.state)
        assertEquals(listOf(WorkInfo.State.ENQUEUED), uploadWork("call-1").map { it.state })
        assertFalse(DecisionPopup.isShowing)
    }

    @Test
    fun `Discard deletes the files and requests no upload`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, pendingCall(), "wait", "dark")

        runBlocking { Decisions.apply(app, "call-1", keep = false) }
        ShadowLooper.idleMainLooper()

        assertNull(app.graph.recordings.get("call-1"))
        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue(uploadWork("call-1").isEmpty())
        assertFalse(DecisionPopup.isShowing)
    }

    @Test
    fun `without the overlay permission the heads-up notification is used instead`() {
        overlayAllowed(false)
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS)

        PostCallPrompt.show(app, pendingCall(), afterCallTimeout = "wait", themeMode = "dark")

        assertFalse(DecisionPopup.isShowing)
        assertEquals(1, shown())
        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `a second card replaces the first and a decision elsewhere closes it`() {
        overlayAllowed(true)
        PostCallPrompt.show(app, pendingCall("a"), "wait", "dark")
        PostCallPrompt.show(app, pendingCall("b"), "wait", "dark")
        assertEquals("b", DecisionPopup.showingFor)

        runBlocking { Decisions.apply(app, "a", keep = false) }
        ShadowLooper.idleMainLooper()
        assertEquals("a decision about another recording leaves the card alone", "b", DecisionPopup.showingFor)

        runBlocking { Decisions.apply(app, "b", keep = true) }
        ShadowLooper.idleMainLooper()
        assertNull(DecisionPopup.showingFor)
    }

    @Test
    fun `the card shows the question, the app and the length, and its two buttons call back`() {
        var kept = 0
        var discarded = 0
        compose.setContent {
            EchoLedgerTheme("light") { DecisionCard("whatsapp", 272_000, onKeep = { kept++ }, onDiscard = { discarded++ }) }
        }

        compose.onNodeWithText("Save this call recording?").assertExists()
        compose.onNodeWithText("WhatsApp", substring = true).assertExists()
        compose.onNodeWithText("Keep").performClick()
        compose.onNodeWithText("Discard").performClick()

        assertEquals(1, kept)
        assertEquals(1, discarded)
    }

    @Test
    fun `the language chip on the card steps to the next language`() {
        var language: String? = "ur"
        compose.setContent {
            val shown = androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(language) }
            EchoLedgerTheme("light") {
                DecisionCard("whatsapp", 272_000, onKeep = {}, onDiscard = {}, language = shown.value, onLanguage = {
                    shown.value = com.echoledger.mobile.core.Languages.next(shown.value)
                    language = shown.value
                })
            }
        }

        compose.onNodeWithText("UR").performClick()
        compose.onNodeWithText("AR").assertExists()
        assertEquals("ar", language)
    }

    @Test
    fun `a recording that captured no sound says so in a notification`() {
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS)

        com.echoledger.mobile.notify.Notifications.noAudio(app)

        val posted = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications.single()
        assertEquals("No audio was captured", posted.extras.getString(android.app.Notification.EXTRA_TITLE))
    }

    @Test
    fun `the auto-hide window is ten seconds`() {
        assertEquals(Duration.ofSeconds(10).toMillis(), DecisionPopup.AUTO_HIDE_MS)
    }
}
