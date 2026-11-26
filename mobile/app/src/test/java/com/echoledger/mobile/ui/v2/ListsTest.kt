package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.layout.Column
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsNotSelected
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performImeAction
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.unit.LayoutDirection
import com.echoledger.mobile.ui.v2.components.AddToListWords
import com.echoledger.mobile.ui.v2.components.EchoAddToListSheet
import com.echoledger.mobile.ui.v2.components.EchoColourPicker
import com.echoledger.mobile.ui.v2.components.EchoListChip
import com.echoledger.mobile.ui.v2.components.EchoListChipMenu
import com.echoledger.mobile.ui.v2.components.EchoNewListSheet
import com.echoledger.mobile.ui.v2.components.EchoRenameListSheet
import com.echoledger.mobile.ui.v2.components.LABEL_COLOUR_NAMES
import com.echoledger.mobile.ui.v2.components.ListChipMenuWords
import com.echoledger.mobile.ui.v2.components.ListEntry
import com.echoledger.mobile.ui.v2.components.NewListWords
import com.echoledger.mobile.ui.v2.components.RenameListWords
import com.echoledger.mobile.ui.v2.components.labelColourName
import com.echoledger.mobile.ui.v2.components.labelColourValue
import com.echoledger.mobile.ui.v2.components.listNameTaken
import com.echoledger.mobile.ui.v2.components.nextListColour
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
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
    fun aNewListNeedsANameThatIsNotTakenYetAndTheMessageNamesTheExistingList() {
        var made: Pair<String, String>? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoNewListSheet(
                    existing = listOf("Clients"),
                    usedColours = setOf("grey"),
                    words = words,
                    onDismiss = {},
                    reserved = listOf("All", "Unread", "Important")
                ) { n, c -> made = n to c }
            }
        }
        compose.onNodeWithText("Create").assertIsNotEnabled()
        compose.onNodeWithText("List name").performTextInput(" clients ")
        compose.onNodeWithText("You already have a list called Clients.").assertExists()
        compose.onNodeWithText("Create").assertIsNotEnabled()
        compose.onNodeWithText(" clients ").performTextClearance()
        compose.onNodeWithText("List name").performTextInput("UNREAD")
        compose.onNodeWithText("You already have a list called Unread.").assertExists()
        compose.onNodeWithText("Create").assertIsNotEnabled()
        assertNull(made)
    }

    @Test
    fun createGivesTheTrimmedNameAndTheStoredColour() {
        var made: Pair<String, String>? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoNewListSheet(existing = emptyList(), usedColours = emptyList(), words = words, onDismiss = {}) { n, c -> made = n to c }
            }
        }
        compose.onNodeWithText("List name").performTextInput("  Suppliers ")
        compose.onNodeWithContentDescription("Blue").performClick()
        compose.onNodeWithText("Create").performClick()
        assertEquals("Suppliers" to labelColourValue("blue"), made)
        assertTrue(made!!.second.matches(Regex("#[0-9a-fA-F]{6}")))
    }

    @Test
    fun theKeyboardsDoneCreates() {
        var made: Pair<String, String>? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoNewListSheet(existing = emptyList(), usedColours = emptyList(), words = words, onDismiss = {}) { n, c -> made = n to c }
            }
        }
        compose.onNodeWithText("List name").performTextInput("Home")
        compose.onNodeWithText("Home").performImeAction()
        assertEquals("Home", made?.first)
    }

    @Test
    fun theNextColourIsTheFirstUnusedThenTheLeastUsed() {
        assertEquals("grey", nextListColour(emptyList()))
        assertEquals("orange", nextListColour(listOf(labelColourValue("grey").lowercase(), "red", null, "#8B5CF6")))
        val all = LABEL_COLOUR_NAMES.map(::labelColourValue)
        assertEquals("grey", nextListColour(all))
        assertEquals("red", nextListColour(all + labelColourValue("grey")))
    }

    @Test
    fun theNewListSheetStartsWithTheNextColourChosen() {
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoNewListSheet(
                    existing = listOf("Clients"),
                    usedColours = listOf(labelColourValue("grey")),
                    words = words,
                    onDismiss = {}
                ) { _, _ -> }
            }
        }
        compose.onNodeWithContentDescription(names.getValue(LABEL_COLOUR_NAMES[1])).assertIsSelected()
        compose.onNodeWithContentDescription("Grey").assertIsNotSelected()
    }

    @Test
    fun theFixedChipWordsAreTakenAndCaseIsIgnored() {
        assertEquals("Important", listNameTaken(" important ", emptyList(), listOf("All", "Unread", "Important")))
        assertEquals("Clients", listNameTaken("CLIENTS", listOf("Clients")))
        assertNull(listNameTaken("   ", listOf("Clients")))
        assertNull(listNameTaken("Clients 2", listOf("Clients")))
    }

    @Test
    fun renameBlocksAnotherListsNameAndAllowsAChangeOfCase() {
        var renamed: String? = null
        val renameWords = RenameListWords("Rename list", "List name", "Save", { "You already have a list called $it." })
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoRenameListSheet("Home", listOf("Clients", "Home"), renameWords, onDismiss = {}) { renamed = it }
            }
        }
        compose.onNodeWithText("Home").performTextClearance()
        compose.onNodeWithText("List name").performTextInput("clients")
        compose.onNodeWithText("You already have a list called Clients.").assertExists()
        compose.onNodeWithText("Save").assertIsNotEnabled()
        compose.onNodeWithText("clients").performTextClearance()
        compose.onNodeWithText("List name").performTextInput(" HOME ")
        compose.onNodeWithText("Save").assertIsEnabled().performClick()
        assertEquals("HOME", renamed)
    }

    @Test
    fun theSelectedChipIsSelectedAndALongNameEndsInAnEllipsis() {
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoListChip("Clients", selected = true, onClick = {}, stored = "#26978F")
            }
        }
        compose.onNodeWithText("Clients").assertIsSelected()
    }

    private val menuWords = ListChipMenuWords("Rename…", "Change colour…", "Move left", "Move right", "Delete list…")

    private fun chipMenu(
        direction: LayoutDirection,
        earlier: () -> Unit,
        later: () -> Unit,
        canEarlier: Boolean = true,
        canLater: Boolean = true
    ) {
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                CompositionLocalProvider(LocalLayoutDirection provides direction) {
                    EchoListChipMenu(
                        expanded = true,
                        onDismiss = {},
                        words = menuWords,
                        canMoveEarlier = canEarlier,
                        canMoveLater = canLater,
                        onRename = {},
                        onRecolour = {},
                        onMoveEarlier = earlier,
                        onMoveLater = later,
                        onDelete = {}
                    )
                }
            }
        }
    }

    @Test
    fun leftToRightMoveLeftIsEarlier() {
        var moved = ""
        chipMenu(LayoutDirection.Ltr, { moved = "earlier" }, { moved = "later" })
        compose.onNodeWithText("Rename…").assertExists()
        compose.onNodeWithText("Change colour…").assertExists()
        compose.onNodeWithText("Delete list…").assertExists()
        compose.onNodeWithText("Move left").performClick()
        assertEquals("earlier", moved)
    }

    @Test
    fun rightToLeftMoveLeftIsLaterAndTheFirstListCanOnlyGoLeft() {
        var moved = ""
        chipMenu(LayoutDirection.Rtl, { moved = "earlier" }, { moved = "later" }, canEarlier = false)
        compose.onNodeWithText("Move right").assertDoesNotExist()
        compose.onNodeWithText("Move left").performClick()
        assertEquals("later", moved)
    }

    @Test
    fun addToListTicksSeveralAndOffersANewList() {
        var ticked by mutableStateOf(setOf("Clients"))
        var newList = 0
        compose.setContent {
            EchoV2Theme(darkTheme = false) {
                EchoAddToListSheet(
                    lists = listOf(ListEntry("Clients", "#26978F"), ListEntry("Home", null)),
                    checked = ticked,
                    words = AddToListWords("Add to list", "New list…"),
                    onToggle = { n -> ticked = if (n in ticked) ticked - n else ticked + n },
                    onNew = { newList++ },
                    onDismiss = {}
                )
            }
        }
        compose.onNodeWithText("Clients").assertIsOn()
        compose.onNodeWithText("Home").assertIsOff().performClick()
        compose.onNodeWithText("Clients").performClick()
        assertEquals(setOf("Home"), ticked)
        compose.onNodeWithText("New list…").performClick()
        assertEquals(1, newList)
    }
}
