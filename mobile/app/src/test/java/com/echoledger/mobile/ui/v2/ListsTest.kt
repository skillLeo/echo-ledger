package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.layout.Column
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.ui.v2.components.EchoColourPicker
import com.echoledger.mobile.ui.v2.components.EchoListChip
import com.echoledger.mobile.ui.v2.components.EchoNewListSheet
import com.echoledger.mobile.ui.v2.components.LABEL_COLOUR_NAMES
import com.echoledger.mobile.ui.v2.components.NewListWords
import com.echoledger.mobile.ui.v2.components.labelColourName
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Lists (D14): the chip, the colour picker and the new-list sheet. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi", application = Application::class)
class ListsTest {
    @get:Rule
    val compose = createComposeRule()

    private val names = LABEL_COLOUR_NAMES.associateWith { it.replaceFirstChar(Char::uppercase) }
    private val words = NewListWords("New list", "List name", "Colour", "Create", { "You already have a list called $it." }, names)

    @Test
    fun aStoredColourMatchesInAnyLetterCase() {
        assertEquals("teal", labelColourName("#26978f"))
        assertEquals("teal", labelColourName(" #26978F "))
        assertNull(labelColourName("#8B5CF6"))
        assertNull(labelColourName(null))
        assertEquals(8, LABEL_COLOUR_NAMES.size)
    }

    @Test
    fun theColourPickerChoosesOneOfEight() {
        var chosen by mutableStateOf("grey")
        compose.setContent { EchoV2Theme(darkTheme = false) { EchoColourPicker(chosen, { chosen = it }, names) } }
        compose.onNodeWithContentDescription("Teal").performClick()
        assertEquals("teal", chosen)
        compose.onNodeWithContentDescription("Teal").assertIsSelected()
    }

    @Test
    fun aListChipOpensItsMenuOnALongPressAndAFixedChipDoesNot() {
        var opened = 0
        var picked = 0
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                Column {
                    EchoListChip("Clients", selected = false, onClick = { picked++ }, stored = "#26978F", onMenu = { opened++ })
                    EchoListChip("Unread", selected = false, onClick = {}, fixed = true, count = 2, onMenu = { opened++ })
                }
            }
        }
        compose.onNodeWithText("Clients").performClick()
        assertEquals(1, picked)
        compose.onNodeWithText("Clients").performTouchInput { longClick() }
        compose.onNodeWithText("Unread").performTouchInput { longClick() }
        assertEquals(1, opened)
    }

    @Test
    fun aNewListNeedsANameThatIsNotTakenYet() {
        var made: Pair<String, String>? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoNewListSheet(existing = listOf("Clients"), usedColours = setOf("grey"), words = words, onDismiss = {}) { n, c ->
                    made = n to c
                }
            }
        }
        compose.onNodeWithText("Create").assertIsNotEnabled()
        compose.onNodeWithText("List name").performTextInput("clients")
        compose.onNodeWithText("You already have a list called clients.").assertExists()
        compose.onNodeWithText("Create").assertIsNotEnabled()
        assertNull(made)
    }
}
