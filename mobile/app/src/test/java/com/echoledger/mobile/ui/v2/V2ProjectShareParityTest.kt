package com.echoledger.mobile.ui.v2

import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.workspace.V2WorkspaceScreen
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Share link from the v2 project page (docs/v2/audit/parity.csv PH-OTH-024): the page's Share opens the v2 sheet, which
 * reads the project's links and makes one for the project.
 */
class V2ProjectShareParityTest : ScreenTest() {
    private val project = WorkspaceFixtures.PROJECT

    @Before
    fun serve() = WorkspaceFixtures.serve(server, vm, app)

    private fun shows(words: String) = pumpUntil("\"$words\"") {
        compose.mainClock.advanceTimeByFrame()
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    @Test
    fun theProjectPagesShareOpensTheV2SheetAndMakesTheProjectsLink() {
        server.on(
            "GET",
            "/api/shares",
            FakeServer.json(200, """{"data":[{"id":"p0","active":true,"view_count":2,"allow_download":false}]}""")
        )
        server.on("GET", "/api/sent.*", FakeServer.json(200, """{"data":[]}"""))
        server.on(
            "POST",
            "/api/clients/$project/shares",
            FakeServer.json(201, """{"id":"p1","active":true,"url":"https://echoledger.test/p/xyz"}""")
        )
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    Box { V2WorkspaceScreen(vm, project, onBack = {}, onOpenProject = {}, onOpenRecording = {}, onOpenAt = { _, _ -> }) }
                }
            }
        }
        shows("Payment terms for the March shipment")
        compose.onNode(hasContentDescription("Share") and hasClickAction()).performClick()
        // The v2 sheet: the project's links with their views, then Make link.
        shows("Anyone with the link and a password")
        shows("2 views · no downloads")
        assertEquals(project, server.requestsTo("GET", "/api/shares").first().url.queryParameter("client_id"))
        compose.onAllNodes(hasText("Make link") and hasClickAction()).onFirst().performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("made") { server.requestsTo("POST", "/api/clients/$project/shares").isNotEmpty() }
        val body = server.requestsTo("POST", "/api/clients/$project/shares").single().bodyText()
        assertTrue(body, body.contains("\"audience\":\"public\""))
        shows("https://echoledger.test/p/xyz")
    }
}
