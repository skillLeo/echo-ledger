package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.workspace.MemoryKind
import com.echoledger.mobile.ui.v2.workspace.V2WorkspaceScreen
import com.echoledger.mobile.ui.v2.workspace.WorkspaceModel
import com.echoledger.mobile.ui.v2.workspace.WorkspaceTab
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** A project's page in the new look (Workspaces): what each tab does on the server, and how it looks. */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class WorkspaceTest : ScreenTest() {
    private val project = WorkspaceFixtures.PROJECT
    private val opened = mutableListOf<Pair<String, Long>>()
    private var language by mutableStateOf(EchoLanguage.English)
    private var dark by mutableStateOf(false)

    @Before
    fun serve() = WorkspaceFixtures.serve(server, vm, app)

    private fun has(text: String) = runCatching { compose.onNodeWithText(text, substring = true).fetchSemanticsNode() }.isSuccess

    private fun waitFor(text: String) = pumpUntil(text) { has(text) }

    private fun open() {
        show {
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2WorkspaceScreen(vm, project, onBack = {}, onOpenProject = {}, onOpenRecording = {}, onOpenAt = { id, ms ->
                        opened +=
                            id to ms
                    })
                }
            }
        }
        waitFor("Payment terms for the March shipment")
    }

    private fun press(text: String) {
        compose.onAllNodes(hasText(text) and hasClickAction()).onFirst().performScrollTo().performClick()
        compose.waitForIdle()
    }

    private fun pressIcon(description: String) {
        compose.onNode(hasContentDescription(description)).performScrollTo().performClick()
        compose.waitForIdle()
    }

    @Test
    fun theTabsFollowThePlanAndTheProjectsAiNotes() {
        assertEquals(WorkspaceTab.entries, WorkspaceTab.shown(aiNotesInPlan = true, aiNotesOn = true))
        assertEquals(
            listOf(WorkspaceTab.Recordings, WorkspaceTab.Files, WorkspaceTab.Memory, WorkspaceTab.Screens),
            WorkspaceTab.shown(aiNotesInPlan = false, aiNotesOn = false)
        )
    }

    @Test
    fun memoryGoesUnderItsKindPinnedFirst() {
        val line = { id: String, kind: String, pinned: Boolean -> com.echoledger.mobile.data.MemoryItemDto(id, kind, id, pinned = pinned) }
        val groups = WorkspaceModel.memoryGroups(
            listOf(line("a", "question", false), line("b", "decision", false), line("c", "decision", true))
        )
        assertEquals(
            listOf(MemoryKind.Decision to listOf("c", "b"), MemoryKind.Question to listOf("a")),
            groups.map { (k, l) ->
                k to
                    l.map { it.id }
            }
        )
        val sections = WorkspaceModel.sections(
            mapOf(com.echoledger.mobile.ui.v2.workspace.BriefField.Deadlines to "• 21 March\n\n- 25 March ")
        )
        assertEquals(listOf("21 March", "25 March"), sections.deadlines)
    }

    @Test
    fun aFileIsRemovedOnlyAfterAskingAndAMemoryLineIsAddedWithItsKind() {
        server.on("DELETE", "/api/clients/$project/files/f1", FakeServer.empty(204))
        server.on(
            "POST",
            "/api/clients/$project/memory",
            FakeServer.json(
                200,
                """{"data":{"id":"m3","kind":"question","text":"Who signs the LC?","pinned":false,"source":{"type":"user"}}}"""
            )
        )
        open()
        press("Files")
        pressIcon("Remove Proforma March.pdf")
        compose.onNodeWithText("Remove Proforma March.pdf from this project?").assertExists()
        assertTrue(server.requestsTo("DELETE", "/api/clients/$project/files/f1").isEmpty())
        compose.onAllNodes(hasText("Remove") and hasClickAction()).onFirst().performClick()
        pumpUntil("the delete") { server.requestsTo("DELETE", "/api/clients/$project/files/f1").isNotEmpty() }

        press("Memory & instructions")
        compose.onNodeWithText("Kind", useUnmergedTree = true).performScrollTo().performClick()
        press("Open question")
        compose.onNodeWithText("Add a line").performScrollTo().performTextInput("Who signs the LC?")
        press("Add to memory")
        pumpUntil("the line") { server.requestsTo("POST", "/api/clients/$project/memory").isNotEmpty() }
        val body = server.requestsTo("POST", "/api/clients/$project/memory").single().bodyText()
        assertTrue(body, body.contains("\"kind\":\"question\"") && body.contains("Who signs the LC?"))
    }

    @Test
    fun aMemoryLineOpensItsCallAndTheFilingRulesAreAddedAndRemovedInPlace() {
        server.on("POST", "/api/clients/$project/rules", FakeServer.json(200, """{"data":{"id":8,"kind":"title","value":"Lawn"}}"""))
        server.on("DELETE", "/api/clients/$project/rules/7", FakeServer.empty(204))
        open()
        compose.onNodeWithText("Title contains “Qureshi”").performScrollTo().assertExists()
        compose.onAllNodes(hasText("Text in the title")).onFirst().performScrollTo().performTextInput("Lawn")
        press("Add rule")
        waitFor("Title contains “Lawn”")
        assertTrue(server.requestsTo("POST", "/api/clients/$project/rules").single().bodyText().contains("\"value\":\"Lawn\""))
        pressIcon("Remove the rule Title contains “Qureshi”")
        pumpUntil("the rule's delete") { server.requestsTo("DELETE", "/api/clients/$project/rules/7").isNotEmpty() }

        press("Memory & instructions")
        pressIcon("Open the call at 0:31")
        assertEquals(listOf("s1" to 31_000L), opened)
    }

    @Test
    fun copyForAiCopiesTheProjectsContext() {
        server.on(
            "GET",
            "/api/clients/$project/bundle",
            FakeServer.json(200, """{"data":{"text":"# Qureshi Textiles: project context"}}""")
        )
        open()
        press("Copy for AI")
        val clip = ApplicationProvider.getApplicationContext<android.content.Context>().getSystemService(ClipboardManager::class.java)
        pumpUntil("the copy") { clip.primaryClip != null }
        assertEquals("# Qureshi Textiles: project context", clip.primaryClip?.getItemAt(0)?.text.toString())
    }

    @Test
    fun screenshots() {
        val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")
        open()
        // The filing rules come in after the page: every shot waits for them.
        waitFor("Title contains “Qureshi”")
        for (tab in listOf("Recordings", "Memory & instructions", "Files", "Call notes")) {
            for ((l, d) in listOf(EchoLanguage.English to false, EchoLanguage.Urdu to false, EchoLanguage.English to true)) {
                language = l
                dark = d
                compose.waitForIdle()
                val label = when (tab) {
                    "Recordings" -> if (l == EchoLanguage.Urdu) "ریکارڈنگز" else tab
                    "Memory & instructions" -> if (l == EchoLanguage.Urdu) "یادداشت اور ہدایات" else tab
                    "Files" -> if (l == EchoLanguage.Urdu) "فائلیں" else tab
                    else -> if (l == EchoLanguage.Urdu) "کال نوٹس" else tab
                }
                press(label)
                // A new language composes the page again, and the rules are read again.
                if (tab == "Recordings") waitFor("“Qureshi”")
                val name = "workspace-" + tab.substringBefore(' ').lowercase()
                val file = "$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                compose.onRoot().captureRoboImage(File(out, file))
            }
        }
    }
}
