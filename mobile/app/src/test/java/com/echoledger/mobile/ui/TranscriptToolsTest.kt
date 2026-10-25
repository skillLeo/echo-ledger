package com.echoledger.mobile.ui

import androidx.compose.runtime.collectAsState
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.OpenSessionRequest
import com.echoledger.mobile.data.SearchHitDto
import com.echoledger.mobile.data.SearchItemDto
import com.echoledger.mobile.net.ExportNames
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Phone transcript tools (B6, B8, B10-B12): search, export names, share links, language and vocabulary. */
class TranscriptToolsTest : ScreenTest() {
    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private fun settings() = runBlocking { app.graph.settings.current() }

    // ---- pure ---------------------------------------------------------------------------------------------------

    @Test
    fun `a snippet highlights the matching characters and never parses HTML`() {
        val text = snippetText("The <b>invoice</b> is due", listOf(listOf(7, 7)))
        assertEquals("The <b>invoice</b> is due", text.text)
        assertEquals(1, text.spanStyles.size)
        assertEquals(7, text.spanStyles[0].start)
        assertEquals(14, text.spanStyles[0].end)
        assertEquals("abc", snippetText("abc", listOf(listOf(10, 2))).text)
        assertEquals(0, snippetText("abc", listOf(listOf(10, 2))).spanStyles.size)
    }

    @Test
    fun `turns show the speaker once and renamed speakers win`() {
        val speakers = listOf("Me", "Me", "Client", "Me")
        assertEquals(listOf(false, true, false, false), speakers.indices.map { continuesTurn(speakers, it) })
        assertEquals("Jane", speakerName(mapOf("Client" to "Jane"), "Client"))
        assertEquals("Client", speakerName(mapOf("Client" to " "), "Client"))
    }

    @Test
    fun `export names come from the server and are safe for storage`() {
        assertEquals("Acme 2026-10-24.docx", ExportNames.fromDisposition("attachment; filename=\"Acme 2026-10-24.docx\""))
        assertEquals(
            "قیمت.txt",
            ExportNames.fromDisposition("attachment; filename=\"x.txt\"; filename*=utf-8''%D9%82%DB%8C%D9%85%D8%AA.txt")
        )
        assertEquals("evil.txt", ExportNames.fromDisposition("attachment; filename=\"../../evil.txt\""))
        assertNull(ExportNames.fromDisposition(null))
        assertNull(ExportNames.fromDisposition("attachment; filename=\"...\""))
    }

    @Test
    fun `the language goes with an open session and a new recording only when chosen`() {
        val open = ApiJson.encodeToString(
            OpenSessionRequest.serializer(),
            OpenSessionRequest("r", "whatsapp", null, "2026-10-25T09:00:00Z", "en+ur")
        )
        val auto = ApiJson.encodeToString(
            OpenSessionRequest.serializer(),
            OpenSessionRequest("r", "whatsapp", null, "2026-10-25T09:00:00Z")
        )
        assertTrue(open.contains("\"language\":\"en+ur\""))
        assertFalse(auto.contains("language"))
        val created = ApiJson.encodeToString(
            CreateSessionRequest.serializer(),
            CreateSessionRequest("r", "whatsapp", null, null, "2026-10-25T09:00:00Z", null, 1000, "ur", emptyList())
        )
        assertTrue(created.contains("\"language\":\"ur\""))
    }

    // ---- API --------------------------------------------------------------------------------------------------------

    @Test
    fun `search asks the server and an offline search finds nothing`() {
        server.on(
            "GET",
            "/api/search",
            FakeServer.json(
                200,
                """{"data":[{"session":${ServerFixtures.libraryItem(
                    ServerFixtures.SESSION_ID
                )},"hits":[{"transcript_id":"t","segment_index":1,"start_ms":65000,"speaker":"Client","snippet":"the invoice","highlights":[[4,7]]}],"hit_count":1}],"next_page":null}"""
            )
        )

        val found = runBlocking { vm.search("invoice") }

        assertEquals(65_000L, found.single().hits.single().startMs)
        assertTrue(server.requestsTo("GET", "/api/search").single().url.query!!.contains("q=invoice"))
        assertTrue(runBlocking { vm.search("x") }.isEmpty())
    }

    @Test
    fun `an export is saved in the private cache under the server's name`() {
        server.on(
            "GET",
            "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}/export",
            mockwebserver3.MockResponse.Builder().code(
                200
            ).addHeader("Content-Disposition", "attachment; filename=\"Call 2026-10-24.vtt\"").body("WEBVTT").build()
        )

        val file = runBlocking { vm.export(ServerFixtures.TRANSCRIPT_ID, "vtt") }!!

        assertEquals("Call 2026-10-24.vtt", file.name)
        assertEquals("exports", file.parentFile!!.name)
        assertEquals("WEBVTT", file.readText())
    }

    @Test
    fun `share links are made with the chosen settings, listed and revoked`() {
        server.on(
            "POST",
            "/api/sessions/${ServerFixtures.SESSION_ID}/shares",
            FakeServer.json(201, """{"id":"s1","active":true,"url":"https://ledger.example.test/s/abc"}""")
        )
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}/shares",
            FakeServer.json(200, """{"data":[{"id":"s1","active":true,"view_count":2}]}""")
        )
        server.on("DELETE", "/api/shares/s1", FakeServer.empty(204))

        val link = runBlocking { vm.createShare(ServerFixtures.SESSION_ID, 604_800, "secret12", true) }!!
        val body = server.requestsTo("POST", "/api/sessions/${ServerFixtures.SESSION_ID}/shares").single().bodyText()

        assertEquals("https://ledger.example.test/s/abc", link.url)
        assertTrue(
            body.contains("\"expires_in\":604800") && body.contains("\"password\":\"secret12\"") && body.contains("\"include_audio\":true")
        )
        assertEquals(2, runBlocking { vm.shares(ServerFixtures.SESSION_ID) }.single().viewCount)
        assertTrue(runBlocking { vm.revokeShare("s1") })
    }

    // ---- screens ------------------------------------------------------------------------------------------------------

    @Test
    fun `a transcript match opens the recording at that moment`() {
        var opened: Pair<String, Long>? = null
        val hit = SearchHitDto("t", 1, 65_000, "Client", "the invoice is due", listOf(listOf(4, 7)))
        val result =
            SearchItemDto(
                ApiJson.decodeFromString(
                    com.echoledger.mobile.data.LibraryItem.serializer(),
                    ServerFixtures.libraryItem(ServerFixtures.SESSION_ID)
                ),
                listOf(hit),
                1
            )
        show { SearchResultsSection(listOf(result)) { r, ms -> opened = r.session.id to ms } }

        compose.onNodeWithText("In transcripts").assertExists()
        compose.onNodeWithText("1:05").performClick()

        assertEquals(ServerFixtures.SESSION_ID to 65_000L, opened)
    }

    @Test
    fun `the language of new recordings and the vocabulary are set in Settings`() {
        server.on("POST", "/api/vocabulary/keyterms", FakeServer.json(201, """{"keyterms":["Farah Khan"]}"""))
        show { TranscriptionSection(vm, vm.settings.collectAsState().value) }

        compose.onNodeWithText("English + Urdu").performClick()
        pumpUntil("the language to be saved") { settings().transcriptionLanguage == "en+ur" }
        compose.onNodeWithText("Automatic").performClick()
        pumpUntil("automatic to be saved") { settings().transcriptionLanguage == null }

        compose.onNodeWithText("A name or term the engine gets wrong").performTextInput("Farah Khan")
        compose.onNodeWithText("Add").performClick()
        pumpUntil("the term to be sent") { server.requestsTo("POST", "/api/vocabulary/keyterms").isNotEmpty() }
        assertTrue(server.requestsTo("POST", "/api/vocabulary/keyterms").single().bodyText().contains("\"term\":\"Farah Khan\""))
    }

    @Test
    fun `the share dialog makes a link and shows it once`() {
        server.on("GET", "/api/sessions/${ServerFixtures.SESSION_ID}/shares", FakeServer.json(200, """{"data":[]}"""))
        server.on(
            "POST",
            "/api/sessions/${ServerFixtures.SESSION_ID}/shares",
            FakeServer.json(201, """{"id":"s1","active":true,"url":"https://ledger.example.test/s/abc"}""")
        )
        show { ShareLinkContent(vm, ServerFixtures.SESSION_ID, hasAudio = true) }

        compose.onNodeWithText("7 days").performClick()
        compose.onNodeWithText("Make link").performClick()
        pumpUntil("the link to show") {
            runCatching { compose.onNodeWithText("https://ledger.example.test/s/abc").assertExists() }.isSuccess
        }
        assertTrue(server.requestsTo("POST", "/api/sessions/${ServerFixtures.SESSION_ID}/shares").single().bodyText().contains("604800"))
    }
}
