package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import androidx.compose.material3.Text
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Projects in the new design has what classic's Projects tab has (parity.csv PH-OTH-019, 020, 021): the smart folders
 * with their counts, each opening its recordings; Shared with me with Open, Save to my library and Remove; and Request
 * files, which makes a link (shown once, with Copy), lists the links and turns one off after asking.
 */
class V2ProjectsFoldersParityTest : ScreenTest() {
    private val classic = ClassicScreens(
        home = { Text("Classic activity") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic projects") },
        settings = { Text("Classic settings") },
        upgrade = {}
    )

    private var imported = FakeServer.json(500, """{"message":"boom"}""")

    private fun importedAsked() = server.requestsTo("GET", "/api/library").count { it.url.queryParameter("folder") == "imported" }

    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val library = ServerFixtures.libraryPage(
            listOf(
                ServerFixtures.libraryItem("01JA", title = "Bilal Traders"),
                ServerFixtures.libraryItem("01JB", title = "Kitchen idea", sourceApp = "voice_note", client = null),
                ServerFixtures.libraryItem("01JC", title = "Rehman and Sons", sourceApp = "phone_call", client = null)
            )
        )
        // The Imported folder is the Library asked with ?folder=imported.
        server.on("GET", "/api/library") { r ->
            if (r.url.queryParameter("folder") == "imported") imported else FakeServer.json(200, library)
        }
        server.on("GET", "/api/library/views", FakeServer.json(200, """{"folders":{"imported":1,"shared":1}}"""))
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on("GET", "/api/sessions/.+", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        runBlocking { app.graph.library.refresh() }
    }

    private fun shell() = show {
        EchoV2Shell(
            vm,
            UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.ask" to true)),
            darkTheme = false,
            classic = classic,
            onSwitchToClassic = {},
            language = EchoLanguage.English
        )
    }

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun await(words: String) = pumpUntil("\"$words\"") { seen(words) }

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words, substring = true) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun projects() {
        shell()
        await("Bilal Traders")
        compose.onAllNodes(hasText("Projects") and hasClickAction()).onFirst().performClick()
        await("Folders")
    }

    @Test
    fun theFoldersAreListedWithTheirCountsAndEachOpensItsRecordings() {
        seed()
        projects()
        // The Inbox (no project: the voice note and the phone call), the apps that hold something, and the server's.
        await("Shared with me")
        listOf("Inbox", "WhatsApp", "Voice notes", "Phone", "Imported").forEach { assertTrue(it, seen(it)) }
        assertTrue(compose.onAllNodes(hasText("Inbox") and hasText("2 recordings", substring = true)).fetchSemanticsNodes().isNotEmpty())
        assertTrue(compose.onAllNodes(hasText("Zoom")).fetchSemanticsNodes().isEmpty())
        press("Voice notes")
        await("Kitchen idea")
        assertTrue(!seen("Bilal Traders") && !seen("Rehman and Sons"))
        press("Kitchen idea")
        await("Hi, thanks for calling.")
    }

    @Test
    fun importedComesFromTheServerAndRetryAsksAgain() {
        seed()
        projects()
        await("Imported")
        press("Imported")
        await("Couldn't load Imported")
        imported = FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JI", title = "Proforma call"))))
        press("Retry")
        await("Proforma call")
        assertEquals(2, importedAsked())
    }

    @Test
    fun sharedWithMeOpensSavesAndRemovesWhatATeammateSent() {
        seed()
        val session = ServerFixtures.libraryItem("01JT", title = "Ayesha's call with Omar")
        server.on(
            "GET",
            "/api/shared-with-me",
            FakeServer.json(
                200,
                """{"data":[{"id":"sh1","kind":"session","from":{"name":"Ayesha"},"note":"Have a look","title":"Omar pricing",
                    "sent_at":"2026-10-20T10:00:00+00:00","saved_at":null,"session":$session}]}"""
            )
        )
        server.on("POST", "/api/shared-with-me/sh1/save", FakeServer.json(200, """{"session_id":"01JS"}"""))
        server.on("DELETE", "/api/shared-with-me/sh1", FakeServer.empty(204))
        projects()
        await("Shared with me")
        press("Shared with me")
        await("Omar pricing")
        assertTrue(seen("From Ayesha"))
        assertTrue(seen("Have a look"))
        press("Save to my library")
        pumpUntil("saved") { server.requestsTo("POST", "/api/shared-with-me/sh1/save").isNotEmpty() }
        press("Remove")
        pumpUntil("removed") { server.requestsTo("DELETE", "/api/shared-with-me/sh1").isNotEmpty() }
        // Open shows the teammate's recording, which is not in this phone's Library.
        press("Open")
        await("Hi, thanks for calling.")
    }

    @Test
    fun requestFilesMakesALinkShownOnceAndTurnsOneOffAfterAsking() {
        seed()
        server.on(
            "GET",
            "/api/request-links",
            FakeServer.json(
                200,
                """{"data":[{"id":"rl1","title":"Send me the invoices","client_id":null,"received":3,"revoked_at":null}]}"""
            )
        )
        server.on(
            "POST",
            "/api/request-links",
            FakeServer.json(201, """{"data":{"id":"rl2","url":"https://echo.test/r/abc123","title":"Voice notes please","received":0}}""")
        )
        server.on("DELETE", "/api/request-links/rl1", FakeServer.empty(204))
        projects()
        press("Request files")
        await("Send me the invoices")
        assertTrue(seen("Inbox · 3 files received"))
        press("New request link")
        compose.onAllNodes(hasText("Title they see") and androidx.compose.ui.test.hasSetTextAction()).onFirst()
            .performTextInput("Voice notes please")
        press("7 days")
        press("Make link")
        pumpUntil("made") { server.requestsTo("POST", "/api/request-links").isNotEmpty() }
        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/request-links").single().bodyText()).jsonObject
        assertEquals("Voice notes please", body["title"]?.jsonPrimitive?.content)
        assertTrue(body.toString(), body["expires_at"]?.jsonPrimitive?.content?.isNotBlank() == true)
        await("https://echo.test/r/abc123")
        assertTrue(seen("Copy it now: it is shown only once."))
        press("Copy")
        val clip = app.getSystemService(ClipboardManager::class.java).primaryClip!!.getItemAt(0).text.toString()
        assertEquals("https://echo.test/r/abc123", clip)
        press("Turn off")
        await("Turn off this request link?")
        assertTrue(server.requestsTo("DELETE", "/api/request-links/rl1").isEmpty())
        compose.onAllNodes(hasText("Turn off") and hasClickAction())[1].performClick()
        pumpUntil("turned off") { server.requestsTo("DELETE", "/api/request-links/rl1").isNotEmpty() }
    }

    @Test
    fun aSharedWithMeOrRequestLinkOpenedInTheAppLandsOnItsNewPage() {
        seed()
        server.on("GET", "/api/shared-with-me", FakeServer.json(200, """{"data":[]}"""))
        server.on("GET", "/api/request-links", FakeServer.json(200, """{"data":[]}"""))
        shell()
        await("Bilal Traders")
        vm.openInProjects(com.echoledger.mobile.ui.ProjectsTarget.Folder("shared"))
        await("Nothing shared with you")
        pumpUntil("taken") { vm.projectsTarget.value == null }
        vm.openInProjects(com.echoledger.mobile.ui.ProjectsTarget.Requests)
        await("No request links")
    }
}
