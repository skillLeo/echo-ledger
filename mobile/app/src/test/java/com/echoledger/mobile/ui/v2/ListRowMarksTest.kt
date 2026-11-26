package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.layout.Column
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoRowMarks
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** A row's marks slot (D12) is heard, not only seen: unread, pinned, important, then the labels' names, as on desktop. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi", application = Application::class)
class ListRowMarksTest {
    @get:Rule
    val compose = createComposeRule()

    private val blue = Color(0xFF2563EB)
    private val amber = Color(0xFFD97706)
    private val green = Color(0xFF16A34A)
    private val pink = Color(0xFFDB2777)

    private fun marks(
        unread: Boolean = false,
        pinned: Boolean = false,
        important: Boolean = false,
        labels: List<Pair<String, Color>> = emptyList()
    ) = EchoRowMarks(unread, pinned, important, labels, unreadWord = "Unread", pinnedWord = "Pinned", importantWord = "Important")

    private fun stateOf(title: String) =
        compose.onNode(hasText(title, substring = true)).fetchSemanticsNode().config.getOrNull(SemanticsProperties.StateDescription)

    @Test
    fun theRowSaysItsMarksInTheSlotsOrder() {
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                Column {
                    EchoListRow(
                        "Check-in call",
                        meta = "Hello, can you hear me now?",
                        marks = marks(
                            unread = true,
                            pinned = true,
                            important = true,
                            labels = listOf("Clients" to blue, "Home" to amber)
                        ),
                        onClick = {}
                    )
                    EchoListRow("Weekly sync", marks = marks(pinned = true, labels = listOf("Acme" to green)), onClick = {})
                    EchoListRow("Plain", marks = marks(), onClick = {})
                    EchoListRow("No slot", onClick = {})
                }
            }
        }
        compose.onNode(hasText("Check-in call", substring = true))
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Unread Pinned Important Clients, Home"))
        assertEquals("Pinned Acme", stateOf("Weekly sync"))
        // No marks, nothing said: an empty slot is neither drawn nor heard.
        assertNull(stateOf("Plain"))
        assertNull(stateOf("No slot"))
    }

    @Test
    fun aUrduRowHearsUrduAndOnlyTheThreeDrawnLabels() {
        val urdu = EchoRowMarks(
            unread = true,
            important = true,
            labels = listOf("گاہک" to blue, "گھر" to amber, "ادائیگی" to green, "خاندان" to pink),
            unreadWord = "ابھی کھولی نہیں گئی",
            importantWord = "اہم"
        )
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.Urdu) {
                EchoListRow("بلال ٹریڈرز", marks = urdu, onClick = {})
            }
        }
        // The fourth label isn't drawn, so it isn't said either (the desktop's rule).
        assertEquals("ابھی کھولی نہیں گئی اہم گاہک, گھر, ادائیگی", stateOf("بلال ٹریڈرز"))
    }

    @Test
    fun aMarkThatIsDrawnAlwaysHasItsWord() {
        assertFalse(runCatching { EchoRowMarks(pinned = true) }.isSuccess)
        assertFalse(runCatching { EchoRowMarks(unread = true) }.isSuccess)
        assertFalse(runCatching { EchoRowMarks(important = true) }.isSuccess)
        assertNull(EchoRowMarks().spoken())
    }
}
