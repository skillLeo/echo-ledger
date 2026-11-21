package com.echoledger.mobile.ui.v2

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.workspace.V2WorkspacesContent
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import org.junit.Assert.assertEquals
import org.junit.Test

/** The Projects list says when each project's last call was, as classic does (parity.csv PH-OTH-018), and opens it. */
class V2ProjectsListParityTest : ScreenTest() {
    private val now = ZonedDateTime.now()

    private fun call(id: String, project: String?, startedAt: ZonedDateTime) = LibraryItem(
        id = id,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        client = project?.let { NamedRef(it, it) },
        startedAt = startedAt.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME),
        updatedAt = startedAt.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME),
        status = "completed"
    )

    @Test
    fun eachProjectSaysWhenItsLastCallWasAndOneWithoutCallsSaysNothing() {
        val projects = listOf(ClientDto("Acme", "Acme"), ClientDto("Zed", "Zed"), ClientDto("Empty", "Empty"))
        // Acme's newest call (about 40 days back, so a date) wins over its older one; Zed's was in 2025 (the year is said).
        val newest = now.minusDays(40).withHour(9)
        val library = listOf(
            call("a1", "Acme", now.minusDays(90)),
            call("a2", "Acme", newest),
            call("z1", "Zed", ZonedDateTime.parse("2025-03-04T10:00:00+05:00"))
        )
        val opened = mutableListOf<String>()
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2WorkspacesContent(projects, library, onOpen = { opened += it }, onNew = {}, onBack = {})
            }
        }
        val day = newest.format(DateTimeFormatter.ofPattern("MMM d", java.util.Locale.ENGLISH)) +
            if (newest.year == now.year) "" else " ${newest.year}"
        compose.onNodeWithText("2 recordings, last call $day", substring = true).assertExists()
        compose.onNodeWithText("1 recording, last call Mar 4 2025", substring = true).assertExists()
        compose.onAllNodes(hasText("Empty", substring = true) and hasText("last call", substring = true)).assertCountEquals(0)
        compose.onNodeWithText("Acme", substring = true).performClick()
        assertEquals(listOf("Acme"), opened)
    }
}
