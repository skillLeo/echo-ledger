package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isRoot
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.ClientWorkspaceScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.workspace.V2WorkspaceScreen
import java.time.LocalDate
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Parity with today's project page. Today's page (ClientWorkspaceScreen, with the project menu the Projects screen gives
 * it) is rendered with the same project and walked through every tab and state: the menu open, a memory line's long
 * press, the brief and memory dialogs, Paste instructions, a file's Remove, the Screens tab and its viewer, and an
 * answer from Ask. The name of every control it shows is collected, and each one must be mapped below to where the new
 * page (V2WorkspaceScreen) has it, where the test then finds it. A control added to today's page fails this test until
 * the new page has it too (or the table says why it isn't needed). The same walk as desktop's workspace.test.tsx.
 */
class WorkspaceParityTest : ScreenTest() {
    private val project = WorkspaceFixtures.PROJECT
    private val folder = WorkspaceFixtures.FOLDER
    private val client = ClientDto(project, "Qureshi Textiles")
    private val sub = ClientDto(folder, "March order", parentId = project)

    @Before
    fun serve() = WorkspaceFixtures.serve(server, vm, app)

    private fun has(text: String) = runCatching { compose.onNodeWithText(text, substring = true).fetchSemanticsNode() }.isSuccess

    private fun waitFor(text: String) = pumpUntil(text) { has(text) }

    /** A control's name: what it says it is for screen readers, else its first text (a field's label), else its icon's. */
    private fun name(node: SemanticsNode): String? = node.config.getOrNull(SemanticsProperties.ContentDescription)?.firstOrNull()
        ?: firstIn(node) { it.config.getOrNull(SemanticsProperties.Text)?.firstOrNull()?.text }
        ?: firstIn(node) { it.config.getOrNull(SemanticsProperties.ContentDescription)?.firstOrNull() }

    /** The first value [pick] finds in the node or under it, in reading order (the unmerged tree). */
    private fun firstIn(node: SemanticsNode, pick: (SemanticsNode) -> String?): String? =
        pick(node) ?: node.children.firstNotNullOfOrNull { firstIn(it, pick) }

    /** Every control on screen as "kind: name" (textbox, switch or button), dialogs included. */
    private fun collect(into: MutableSet<String>) {
        settle()
        for (root in compose.onAllNodes(isRoot(), useUnmergedTree = true).fetchSemanticsNodes()) {
            fun visit(node: SemanticsNode) {
                val c = node.config
                val kind = when {
                    c.getOrNull(SemanticsActions.SetText) != null -> "textbox"
                    c.getOrNull(SemanticsProperties.ToggleableState) != null -> "switch"
                    c.getOrNull(SemanticsActions.OnClick) != null -> "button"
                    else -> null
                }
                // A switch with no words of its own is named by the row it sits in ("Include its folders").
                val named = name(node) ?: node.parent?.children?.let { siblings ->
                    siblings.take(siblings.indexOfFirst { it.id == node.id }).lastOrNull { it.config.contains(SemanticsProperties.Text) }
                        ?.config?.get(SemanticsProperties.Text)?.firstOrNull()?.text
                }
                if (kind != null) named?.let { into += "$kind: $it" }
                node.children.forEach(::visit)
            }
            visit(root)
        }
    }

    private fun press(text: String) {
        compose.onAllNodes(hasText(text) and hasClickAction()).onFirst().performClick()
        settle()
    }

    private fun settle() {
        compose.waitForIdle()
    }

    /** Every control today's page shows, in every tab and state. */
    private fun todays(): Set<String> {
        val seen = mutableSetOf<String>()
        val recordings = vm.library.value.filter { it.client?.id == project }
        show {
            ClientWorkspaceScreen(
                vm,
                client,
                recordings,
                LocalDate.parse("2026-11-17"),
                onOpenItem = {},
                onOpenAt = { _, _ -> },
                onBack = {},
                subfolders = listOf(sub),
                onMenu = {}
            )
        }
        waitFor("Ship 40,000 metres")
        collect(seen)
        compose.onNode(hasContentDescription("Project menu")).performClick()
        collect(seen)
        press("Rename")
        compose.onNodeWithText("40% in advance, the balance against the bill of lading").performTouchInput { longClick() }
        collect(seen)
        androidx.test.espresso.Espresso.pressBack()
        // Today's text-field dialogs (Edit the brief, Add to memory, Paste instructions) never let Robolectric go idle
        // (a focused field's cursor in a dialog window), so their controls come from [DIALOGS], checked against the source.
        seen += DIALOGS
        press("Recordings")
        collect(seen)
        press("Files")
        collect(seen)
        compose.onNodeWithText("Proforma March.pdf").performTouchInput { longClick() }
        collect(seen)
        press("Cancel")
        press("Screens")
        waitFor("Kick-off")
        collect(seen)
        press("Screen 7 · 14:32")
        waitFor("Play from here")
        collect(seen)
        press("Close")
        compose.onAllNodes(hasText("Ask") and hasClickAction()).onFirst().performClick()
        compose.onNodeWithText("Ask about Qureshi Textiles").performTextInput("What's the advance?")
        compose.onAllNodes(hasText("Ask") and hasClickAction()).onLast().performClick()
        waitFor("40% in advance.")
        collect(seen)
        return seen
    }

    private companion object {
        /** How far before a Text( the source is read for "label = {" or "title = {". */
        const val LOOK_BACK = 16

        /** The controls of today's three text-field dialogs (ClientWorkspace.kt: BriefEditor, MemoryAdder, InstructionsDialog). */
        val DIALOGS = setOf(
            "textbox: Goal",
            "textbox: Scope (one per line)",
            "textbox: Constraints (one per line)",
            "textbox: Deadlines (one per line)",
            "textbox: Budget (one per line)",
            "textbox: People (one per line)",
            "textbox: Glossary (one per line)",
            "button: Save",
            "button: Cancel",
            "button: Decisions",
            "button: Open questions",
            "button: Changes",
            "button: Scope",
            "textbox: Line",
            "button: Add",
            "textbox: Name",
            "textbox: Text"
        )
    }

    private enum class State { Menu, Brief, Paste, RemoveFile, Viewer, Answered }

    private data class Where(val tab: String?, val name: String?, val state: State? = null, val reason: String? = null)

    private fun at(tab: String, name: String, state: State? = null) = Where(tab, name, state)

    private fun not(reason: String) = Where(null, null, reason = reason)

    private val r = "Recordings"
    private val m = "Memory & instructions"

    /** Today's control ("kind: name") → where the new page has it. */
    private val where: Map<String, Where> = linkedMapOf(
        "button: Back" to at(r, "Back"),
        "button: Project menu" to at(r, "Project menu"),
        "button: New folder inside" to at(r, "New folder inside", State.Menu),
        "button: Rename" to at(r, "Rename", State.Menu),
        "button: Move" to at(r, "Move", State.Menu),
        "button: Share link" to at(r, "Share"),
        "button: Send to a teammate" to at(r, "Send to a teammate", State.Menu),
        "button: Filing rules" to at(r, "Add rule"),
        "button: Delete project" to at(r, "Delete project", State.Menu),
        "button: March order" to at(r, "March order"),
        "button: Notes" to at(r, m),
        "button: Recordings" to at(r, "Recordings"),
        "button: Files" to at(r, "Files"),
        "button: Screens" to at(r, "Screens"),
        "button: Ask" to at(r, "Ask"),
        "switch: AI notes" to at(m, "AI notes"),
        "button: Edit" to at(m, "Edit"),
        "button: Make again" to at(m, "Write again"),
        "button: Add" to at(m, "Add to memory"),
        "button: 40% in advance, the balance against the bill of lading" to at(m, "Open the call at 0:31"),
        "button: Which bank opens the LC?" to not("a line of one's own opens nothing on either page; its Pin and Remove are mapped"),
        "button: Pin" to at(m, "Pin “40% in advance, the balance against the bill of lading”"),
        "button: Remove" to at(m, "Remove “40% in advance, the balance against the bill of lading”"),
        "button: • 40% advance" to at("Call notes", "40% advance"),
        "button: Copy for AI" to at(r, "Copy for AI"),
        "textbox: Goal" to at(m, "Goal", State.Brief),
        "textbox: Scope (one per line)" to at(m, "Scope", State.Brief),
        "textbox: Constraints (one per line)" to at(m, "Constraints", State.Brief),
        "textbox: Deadlines (one per line)" to at(m, "Deadlines", State.Brief),
        "textbox: Budget (one per line)" to at(m, "Budget", State.Brief),
        "textbox: People (one per line)" to at(m, "People", State.Brief),
        "textbox: Glossary (one per line)" to at(m, "Glossary", State.Brief),
        "button: Save" to at(m, "Save brief", State.Brief),
        "button: Cancel" to at(m, "Cancel", State.Brief),
        "button: Decisions" to at(m, "Kind"),
        "button: Open questions" to at(m, "Kind"),
        "button: Changes" to at(m, "Kind"),
        "button: Scope" to at(m, "Kind"),
        "textbox: Line" to at(m, "Add a line"),
        "switch: Include its folders" to at(r, "Include its folders"),
        "button: Payment terms for the March shipment" to at(r, "Payment terms for the March shipment"),
        // Today's row has its source tile as a second tap target (it opens the recording on a project's page too).
        "button: WhatsApp" to at(r, "Payment terms for the March shipment"),
        "button: Add files" to at("Files", "Add files"),
        "button: Paste instructions" to at("Files", "Paste instructions"),
        "textbox: Name" to at("Files", "Name", State.Paste),
        "textbox: Text" to at("Files", "Instructions", State.Paste),
        "button: Proforma March.pdf" to at("Files", "Remove Proforma March.pdf"),
        "button: House rules" to at("Files", "Remove House rules"),
        "button: Screen 7 · 14:32, Kick-off" to at("Screens", "Screen 7 · 14:32"),
        "button: Play from here" to at("Screens", "Play from here", State.Viewer),
        "button: Close" to at("Screens", "Back", State.Viewer),
        "textbox: Ask about Qureshi Textiles" to at("Ask", "Your question"),
        "button: English" to at("Ask", "English"),
        "button: Roman Urdu" to at("Ask", "Roman Urdu"),
        "button: Payment terms · 0:31" to at("Ask", "Payment terms", State.Answered),
        "button: Proforma March.pdf · page 2" to not("a file source opens nothing on either page; the new page lists it without a tap")
    )

    /**
     * The controls of today's three text-field dialogs, read from their source (ClientWorkspace.kt): every Text inside
     * BriefEditor, MemoryAdder and InstructionsDialog is a field's label ("label = { Text(…) }"), the dialog's title
     * ("title = { … }", not a control) or a button; the brief's "$heading (one per line)" fields and the kind chips
     * (ClientFiles.KINDS) are expanded from the data they're made from.
     */
    private fun dialogControlsInSource(): Set<String> {
        val source = java.io.File("src/main/java/com/echoledger/mobile/ui/ClientWorkspace.kt").readText()
        val found = mutableSetOf<String>()
        for (dialog in listOf("BriefEditor", "MemoryAdder", "InstructionsDialog")) {
            val from = source.indexOf("private fun $dialog(")
            assertTrue("$dialog is gone from ClientWorkspace.kt", from >= 0)
            val body = source.substring(from, source.indexOf("\n}\n", from))
            for (m in Regex("""Text\("([^"]*)"\)""").findAll(body)) {
                val before = body.substring(maxOf(0, m.range.first - LOOK_BACK), m.range.first)
                val words = m.groupValues[1]
                when {
                    before.contains("title = {") -> Unit

                    before.contains("label = {") && words == "\$heading (one per line)" ->
                        com.echoledger.mobile.data.BriefSections().lists().forEach { found += "textbox: ${it.first} (one per line)" }

                    before.contains("label = {") -> found += "textbox: $words"

                    else -> found += "button: $words"
                }
            }
            if ("ClientFiles.KINDS.forEach" in body) com.echoledger.mobile.ui.ClientFiles.KINDS.forEach { found += "button: ${it.second}" }
        }
        return found
    }

    @Test
    fun todaysDialogsAreTheOnesListed() {
        assertEquals(DIALOGS.sorted(), dialogControlsInSource().sorted())
    }

    @Test
    fun everyControlOfTodaysPageIsMappedAndNothingElse() {
        val today = todays()
        assertTrue("only ${today.size} controls: $today", today.size > 40)
        assertEquals("today's controls with no place on the new page", emptyList<String>(), today.filter { it !in where }.sorted())
        assertEquals("mapped controls today's page doesn't have", emptyList<String>(), where.keys.filter { it !in today })
    }

    @Test
    fun theNewPageHasEveryMappedControl() {
        var state: State? = null
        var tab: String? = null
        val goTo = mutableListOf<Pair<String, Where>>()
        where.forEach { (k, w) -> if (w.reason == null) goTo += k to w }
        showNew()
        val missing = mutableListOf<String>()
        for ((key, w) in goTo.sortedWith(compareBy({ it.second.tab }, { it.second.state?.ordinal ?: -1 }))) {
            if (w.tab != tab || w.state != state) {
                showNew()
                if (w.tab != r) press(w.tab!!)
                if (w.tab == "Screens") waitFor("Kick-off")
                when (w.state) {
                    State.Menu -> compose.onNode(hasContentDescription("Project menu")).performClick()

                    State.Brief -> press("Edit")

                    State.Paste -> press("Paste instructions")

                    State.RemoveFile -> compose.onNode(hasContentDescription("Remove Proforma March.pdf")).performClick()

                    State.Viewer -> press("Screen 7 · 14:32")

                    State.Answered -> {
                        compose.onNodeWithText("Your question").performTextInput("What's the advance?")
                        compose.onAllNodes(hasText("Ask") and hasClickAction()).onLast().performClick()
                        waitFor("40% in advance.")
                    }

                    null -> Unit
                }
                compose.waitForIdle()
                tab = w.tab
                state = w.state
            }
            val found = compose.onAllNodes(nameIs(w.name!!), useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty() ||
                compose.onAllNodes(nameIs(w.name)).fetchSemanticsNodes().isNotEmpty()
            if (!found) missing += "$key → ${w.tab}${w.state?.let { ", $it" } ?: ""}: ${w.name}"
        }
        assertEquals(emptyList<String>(), missing)
    }

    private fun nameIs(name: String) = hasText(name) or hasContentDescription(name) or SemanticsMatcher("onClickLabel $name") {
        it.config.getOrNull(SemanticsActions.OnClick)?.label == name
    }

    private var generation by androidx.compose.runtime.mutableIntStateOf(0)
    private var started = false

    /** The new page, from the top (Recordings), each time a different tab or state is needed. */
    private fun showNew() {
        if (!started) {
            started = true
            show {
                androidx.compose.runtime.key(generation) {
                    EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                        androidx.compose.runtime.CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                            V2WorkspaceScreen(vm, project, onBack = {}, onOpenProject = {}, onOpenRecording = {}, onOpenAt = { _, _ -> })
                        }
                    }
                }
            }
        } else {
            generation++
        }
        compose.waitForIdle()
        waitFor("Payment terms for the March shipment")
    }
}
