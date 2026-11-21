package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.content.pm.PackageInfo
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.share.WhatsAppShare
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.awaitAppIdle
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.library.V2ShareLinkSheet
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.time.Duration
import java.time.Instant
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Sharing from the v2 recording page against classic's (docs/v2/audit/parity.csv PH-OTH-024, PH-REC-056): Share link
 * on the v2 components (who may open it, a password or chosen emails, downloads, how long; the link shown once with
 * Copy; the links with their views, Revoke and Stop sharing, which ask first), also for a project; and Send to
 * WhatsApp's Text only (the view being read) and Link for 7 days.
 */
class V2ShareParityTest : ScreenTest() {
    private val sid = ServerFixtures.SESSION_ID
    private val sharesPath = "/api/sessions/$sid/shares"

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on("GET", "/api/transcripts/.+/export") {
            MockResponse.Builder().code(200).addHeader("Content-Disposition", "attachment; filename=\"call.txt\"")
                .body("Client: Rate kal tak bhej dein.").build()
        }
        server.on("GET", "/api/sessions/$sid", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/sent.*", FakeServer.json(200, """{"data":[]}"""))
    }

    private val item = LibraryItem(
        id = sid,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Bilal Traders",
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 2_530_000,
        status = "completed",
        transcript = TranscriptSummary(finalStatus = "completed", finalTranscriptId = ServerFixtures.TRANSCRIPT_ID, finalLanguage = "en"),
        updatedAt = "2026-10-20T10:05:00+00:00"
    )

    private fun page(language: EchoLanguage = EchoLanguage.English) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            val toasts = rememberEchoToastState()
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                androidx.compose.foundation.layout.Box {
                    V2RecordingScreen(vm, item, onBack = {})
                    EchoToastHost(toasts)
                }
            }
        }
    }

    private fun projectSheet() = show {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            val toasts = rememberEchoToastState()
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                V2ShareLinkSheet(vm, sessionId = null, clientId = "01JCLIENT0000000000000000A", onDismiss = {})
            }
        }
    }

    private fun awaitText(words: String) = pumpUntil("\"$words\"") {
        compose.mainClock.advanceTimeByFrame()
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    /** The question's button: the sheet is a dialog window too, so the newest match is the question's. */
    private fun inDialog(words: String) = compose.onAllNodes(hasText(words) and hasClickAction() and hasAnyAncestor(isDialog())).onLast()

    private fun lastBody(method: String, path: String): JsonObject =
        Json.parseToJsonElement(server.requestsTo(method, path).last().body!!.utf8()).jsonObject

    private fun openShareLink() {
        page()
        awaitText("Hi, thanks for calling.")
        press("Share")
        press("Share link")
        awaitText("Anyone with the link")
    }

    @Test
    fun aPasswordLinkWithoutDownloadsThatWorksForADayIsMadeAndShownOnceWithCopy() {
        server.on("GET", sharesPath, FakeServer.json(200, """{"data":[]}"""))
        server.on("POST", sharesPath, FakeServer.json(201, """{"id":"l1","active":true,"url":"https://echoledger.test/s/abc"}"""))
        openShareLink()
        press("Anyone with the link and a password")
        // Too short a password: Make link waits.
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextInput("12345")
        press("Make link")
        awaitAppIdle()
        assertTrue(server.requestsTo("POST", sharesPath).isEmpty())
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextInput("6")
        press("Allow downloads (audio and transcript)")
        press("1 day")
        val before = Instant.now()
        press("Make link")
        pumpUntil("the link") { server.requestsTo("POST", sharesPath).isNotEmpty() }
        val sent = lastBody("POST", sharesPath)
        assertEquals("password", sent["audience"]!!.jsonPrimitive.content)
        assertEquals("123456", sent["password"]!!.jsonPrimitive.content)
        assertEquals("false", sent["allow_download"]!!.jsonPrimitive.content)
        val hours = Duration.between(before, Instant.parse(sent["expires_at"]!!.jsonPrimitive.content)).toMinutes() / 60.0
        assertTrue("ends in $hours hours", hours in 23.9..24.1)
        awaitText("https://echoledger.test/s/abc")
        awaitText("Copy it now: it is shown only once.")
        press("Copy")
        val clip = app.getSystemService(ClipboardManager::class.java).primaryClip!!
        assertEquals("https://echoledger.test/s/abc", clip.getItemAt(0).text.toString())
        // The list is read again once the link is made.
        pumpUntil("the links read again") { server.requestsTo("GET", sharesPath).size >= 2 }
    }

    @Test
    fun onlyChosenPeopleSendsTheirEmailsAndNeverEndsWhenNever() {
        server.on("GET", sharesPath, FakeServer.json(200, """{"data":[]}"""))
        server.on("POST", sharesPath, FakeServer.json(201, """{"id":"l2","active":true,"url":"https://echoledger.test/s/def"}"""))
        openShareLink()
        press("Only people I choose")
        awaitText("They open it signed in to the console with one of these emails.")
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextInput("ali@bilaltraders.pk, not-an-email, sara@bilaltraders.pk")
        press("Never")
        press("Make link")
        pumpUntil("the link") { server.requestsTo("POST", sharesPath).isNotEmpty() }
        val sent = lastBody("POST", sharesPath)
        assertEquals("people", sent["audience"]!!.jsonPrimitive.content)
        assertEquals(listOf("ali@bilaltraders.pk", "sara@bilaltraders.pk"), sent["people"]!!.jsonArray.map { it.jsonPrimitive.content })
        assertNull(sent["expires_at"])
        assertNull(sent["password"])
    }

    @Test
    fun theLinksSayWhoTheirViewsAndDownloadsAndRevokeAsksFirst() {
        server.on("GET", sharesPath) {
            Thread.sleep(300)
            FakeServer.json(
                200,
                """{"data":[{"id":"l1","active":true,"audience":"people","people":["ali@bilaltraders.pk"],"view_count":3,
                   "allow_download":true,"expires_at":"2026-12-01T00:00:00Z"}]}"""
            )
        }
        server.on("DELETE", "/api/shares/l1", FakeServer.empty(204))
        openShareLink()
        awaitText("Only ali@bilaltraders.pk")
        awaitText("3 views · downloads on · until 1 Dec 2026")
        press("Revoke")
        awaitText("It stops working for everyone who has it.")
        inDialog("Cancel").performClick()
        awaitAppIdle()
        assertTrue("Cancel sends nothing", server.requestsTo("DELETE", "/api/shares/l1").isEmpty())
        press("Revoke")
        inDialog("Revoke").performClick()
        pumpUntil("revoked") { server.requestsTo("DELETE", "/api/shares/l1").isNotEmpty() }
        assertEquals(1, server.requestsTo("DELETE", "/api/shares/l1").size)
    }

    @Test
    fun stopSharingWithATeammateAsksFirstThenStops() {
        server.on("GET", sharesPath, FakeServer.json(200, """{"data":[]}"""))
        server.on(
            "GET",
            "/api/sent.*",
            FakeServer.json(
                200,
                """{"data":[{"id":"m1","to":{"name":"Ana","email":"ana@example.com"},"sent_at":"2026-11-01T10:00:00Z"}]}"""
            )
        )
        server.on("DELETE", "/api/sent/m1", FakeServer.empty(204))
        openShareLink()
        awaitText("ana@example.com · sent 1 Nov 2026")
        press("Stop sharing")
        awaitText("Stop sharing with Ana? It leaves their list")
        inDialog("Cancel").performClick()
        awaitAppIdle()
        assertTrue(server.requestsTo("DELETE", "/api/sent/m1").isEmpty())
        press("Stop sharing")
        inDialog("Stop sharing").performClick()
        pumpUntil("stopped") { server.requestsTo("DELETE", "/api/sent/m1").isNotEmpty() }
    }

    @Test
    fun aProjectsLinkIsMadeForTheProject() {
        server.on("GET", "/api/shares.*", FakeServer.json(200, """{"data":[]}"""))
        server.on(
            "POST",
            "/api/clients/.+/shares",
            FakeServer.json(201, """{"id":"p1","active":true,"url":"https://echoledger.test/p/xyz"}""")
        )
        projectSheet()
        awaitText("Make link")
        press("Make link")
        pumpUntil("the link") { server.requestsTo("POST", "/api/clients/01JCLIENT0000000000000000A/shares").isNotEmpty() }
        assertEquals("01JCLIENT0000000000000000A", server.requestsTo("GET", "/api/shares.*").first().url.queryParameter("client_id"))
        awaitText("https://echoledger.test/p/xyz")
    }

    @Test
    fun inUrduTheShareLinkSheetSpeaksUrdu() {
        server.on("GET", sharesPath, FakeServer.json(200, """{"data":[{"id":"l1","active":true,"view_count":1}]}"""))
        page(EchoLanguage.Urdu)
        awaitText("Hi, thanks for calling.")
        val res = V2Testing.resources(EchoLanguage.Urdu)
        press(V2Testing.text(EchoLanguage.Urdu).share)
        press(res.getString(com.echoledger.mobile.R.string.v2_rec_share_link))
        awaitText("لنک والا کوئی بھی")
        awaitText("لنک بنائیں")
        awaitText("1 بار دیکھا گیا")
    }

    private fun install(pkg: String) = shadowOf(app.packageManager).installPackage(
        PackageInfo().apply {
            packageName = pkg
            applicationInfo = ApplicationInfo().apply { packageName = pkg }
        }
    )

    @Test
    fun whatsAppTextOnlySendsTheTranscriptToWhatsApp() {
        install(WhatsAppShare.WHATSAPP)
        page()
        awaitText("Hi, thanks for calling.")
        press("Share")
        awaitText("Send to WhatsApp")
        press("Text only")
        pumpUntil("the message") { shadowOf(app).peekNextStartedActivity() != null }
        val sent = shadowOf(app).nextStartedActivity
        assertEquals(Intent.ACTION_SEND, sent.action)
        assertEquals(WhatsAppShare.WHATSAPP, sent.`package`)
        assertEquals("Client: Rate kal tak bhej dein.", sent.getStringExtra(Intent.EXTRA_TEXT))
        assertEquals("txt", server.requestsTo("GET", "/api/transcripts/.+/export").single().url.queryParameter("format"))
        assertTrue("no link is made for text", server.requestsTo("POST", ".*").isEmpty())
    }

    @Test
    fun whatsAppLinkMakesALinkForSevenDaysAndSendsIt() {
        install(WhatsAppShare.BUSINESS)
        server.on("POST", sharesPath, FakeServer.json(201, """{"id":"w1","active":true,"url":"https://echoledger.test/s/wa7"}"""))
        page()
        awaitText("Hi, thanks for calling.")
        press("Share")
        // Only WhatsApp Business on the phone: the section is named for it.
        awaitText("Send to WhatsApp Business")
        press("Link (works for 7 days)")
        pumpUntil("the message") { shadowOf(app).peekNextStartedActivity() != null }
        val sent = shadowOf(app).nextStartedActivity
        assertEquals(WhatsAppShare.BUSINESS, sent.`package`)
        assertEquals("https://echoledger.test/s/wa7", sent.getStringExtra(Intent.EXTRA_TEXT))
        val body = lastBody("POST", sharesPath)
        assertEquals((7L * 24 * 3600).toString(), body["expires_in"]!!.jsonPrimitive.content)
        // Without audio on the server the link carries none.
        assertFalse(body["include_audio"]!!.jsonPrimitive.content.toBoolean())
    }
}
