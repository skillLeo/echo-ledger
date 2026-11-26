package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The v2 recording page against the plan (docs/v2/audit/parity.csv PH-REC): ⋮ › Mark important. */
class V2RecordingParityTest : ScreenTest() {
    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("PATCH", "/api/sessions/.+", FakeServer.empty(204))
    }

    private fun item(important: Boolean) = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Call with Acme",
        client = NamedRef("01JCLIENT0000000000000000A", "Acme Holdings"),
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 2_530_000,
        status = "completed",
        transcript = TranscriptSummary(finalStatus = "completed", finalTranscriptId = ServerFixtures.TRANSCRIPT_ID, finalLanguage = "en"),
        updatedAt = "2026-10-20T10:05:00+00:00",
        important = important
    )

    private fun open(item: LibraryItem) = show {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            val toasts = rememberEchoToastState()
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                androidx.compose.foundation.layout.Box {
                    V2RecordingScreen(vm, item, onBack = {})
                    EchoToastHost(toasts)
                }
            }
        }
    }

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun patched(): List<Boolean?> = server.requestsTo("PATCH", "/api/sessions/${ServerFixtures.SESSION_ID}").map {
        Json.parseToJsonElement(it.body!!.utf8()).jsonObject["important"]?.jsonPrimitive?.content?.toBooleanStrictOrNull()
    }

    @Test
    fun theMenuMarksARecordingImportantAndUnmarksAnImportantOne() {
        open(item(important = false))
        pumpUntil("the page") { compose.onAllNodesWithText("Hi, thanks for calling.").fetchSemanticsNodes().isNotEmpty() }
        press("More")
        press("Mark important")
        pumpUntil("marked") { patched().contains(true) }
        pumpUntil("said") { compose.onAllNodesWithText("Marked important").fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun anImportantRecordingsMenuOffersUnmarkImportant() {
        open(item(important = true))
        pumpUntil("the page") { compose.onAllNodesWithText("Hi, thanks for calling.").fetchSemanticsNodes().isNotEmpty() }
        press("More")
        press("Unmark important")
        pumpUntil("unmarked") { patched().contains(false) }
        assertEquals(listOf(false), patched())
    }

    private fun awaitText(words: String) =
        pumpUntil("\"$words\"") { compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty() }

    @Test
    fun aFinishedTranscriptSaysFinalTranscriptOverItsLines() {
        open(item(important = false))
        awaitText("Hi, thanks for calling.")
        awaitText("Final transcript")
    }

    @Test
    fun viewsNotInThePlanSaySoAndSeePlansOpensThePlans() {
        val locked = com.echoledger.mobile.data.FeatureDto(enabled = false, name = "Transcript views", lowestPlan = "pro")
        vm.updateSettings { it.copy(plan = com.echoledger.mobile.core.PlanState(features = mapOf("views" to locked))) }
        pumpUntil("the plan") { !vm.settings.value.plan.allows("views") }
        open(item(important = false))
        awaitText("Roman Urdu and English views are on a higher plan.")
        compose.onAllNodesWithText("Upgrade").assertCountEquals(0)
        press("See plans")
        awaitText("Upgrade")
    }

    @Test
    fun callNotesNotInThePlanSaySoAndSeePlansOpensThePlans() {
        val locked = com.echoledger.mobile.data.FeatureDto(enabled = false, name = "Call notes", lowestPlan = "pro")
        vm.updateSettings { it.copy(plan = com.echoledger.mobile.core.PlanState(aiNotes = false, features = mapOf("ai_notes" to locked))) }
        pumpUntil("the plan") { !vm.settings.value.plan.aiNotes }
        open(item(important = false))
        awaitText("Hi, thanks for calling.")
        press("Summary")
        awaitText("Call notes are on a higher plan.")
        compose.onAllNodesWithText("Upgrade").assertCountEquals(0)
        press("See plans")
        awaitText("Upgrade")
    }

    private fun bodyOf(method: String, path: String) =
        Json.parseToJsonElement(server.requestsTo(method, path).last().body!!.utf8()).jsonObject

    @Test
    fun shareLinkMakesALinkThatWorksForSevenDays() {
        server.on("GET", "/api/sessions/.+/shares", FakeServer.json(200, """{"data":[]}"""))
        server.on("GET", "/api/sent.*", FakeServer.json(200, """{"data":[]}"""))
        server.on("POST", "/api/sessions/.+/shares", FakeServer.json(201, SHARE_MADE))
        open(item(important = false))
        awaitText("Hi, thanks for calling.")
        press("Share")
        press("Share link")
        awaitText("Anyone with the link")
        awaitText("Only people I choose")
        awaitText("7 days")
        val before = java.time.Instant.now()
        press("Make link")
        val path = "/api/sessions/${ServerFixtures.SESSION_ID}/shares"
        pumpUntil("made") { server.requestsTo("POST", path).isNotEmpty() }
        val sent = bodyOf("POST", path)
        assertEquals("public", sent["audience"]?.jsonPrimitive?.content)
        assertEquals("true", sent["allow_download"]?.jsonPrimitive?.content)
        // "Link works for" starts at 7 days.
        val ends = java.time.Instant.parse(sent["expires_at"]!!.jsonPrimitive.content)
        val days = java.time.Duration.between(before, ends).toMinutes() / (60.0 * 24)
        assertTrue("expires in $days days", days in 6.99..7.01)
    }

    @Test
    fun sendToATeammateSendsTheirEmailsAndTheNote() {
        server.on("POST", "/api/sessions/.+/send", FakeServer.json(200, """{"sent": 2}"""))
        open(item(important = false))
        awaitText("Hi, thanks for calling.")
        press("Share")
        press("Send to a teammate")
        awaitText("Their emails")
        awaitText("Note (optional)")
        val fields = compose.onAllNodes(androidx.compose.ui.test.hasSetTextAction())
        fields[0].performTextInput("ali@bilaltraders.pk, sara@bilaltraders.pk")
        fields[1].performTextInput("The rate we agreed")
        press("Send")
        val path = "/api/sessions/${ServerFixtures.SESSION_ID}/send"
        pumpUntil("sent") { server.requestsTo("POST", path).isNotEmpty() }
        val sent = bodyOf("POST", path)
        assertEquals(listOf("ali@bilaltraders.pk", "sara@bilaltraders.pk"), sent["to"]!!.jsonArray.map { it.jsonPrimitive.content })
        assertEquals("The rate we agreed", sent["note"]?.jsonPrimitive?.content)
    }
    private companion object {
        val SHARE_MADE = """{
    "id": "01m6s1x89fzbnywymymcct9ady",
    "kind": "session",
    "session_id": "01m6s1x6v38xtqnqa6exvtgtmn",
    "client_id": null,
    "audience": "public",
    "people": null,
    "allow_download": false,
    "has_password": false,
    "include_audio": false,
    "view": null,
    "expires_at": null,
    "revoked_at": null,
    "active": true,
    "view_count": 0,
    "last_viewed_at": null,
    "created_by": "Sample phone",
    "created_at": "2026-11-06T18:28:34+00:00",
    "url": "https://echoledger.test/s/zlFKflQlb0bEH3wea2oj_KpxXAWHwqYkwxU-3AG6CYE"
}"""
    }
}
