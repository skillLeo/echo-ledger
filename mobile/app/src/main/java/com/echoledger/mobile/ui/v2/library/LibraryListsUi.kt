package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.core.LibraryLists
import com.echoledger.mobile.data.ListDto
import com.echoledger.mobile.ui.v2.components.AddToListWords
import com.echoledger.mobile.ui.v2.components.DeleteListWords
import com.echoledger.mobile.ui.v2.components.EchoAddToListSheet
import com.echoledger.mobile.ui.v2.components.EchoDeleteListDialog
import com.echoledger.mobile.ui.v2.components.EchoListChip
import com.echoledger.mobile.ui.v2.components.EchoListChipMenu
import com.echoledger.mobile.ui.v2.components.EchoListChipRow
import com.echoledger.mobile.ui.v2.components.EchoListColourSheet
import com.echoledger.mobile.ui.v2.components.EchoNewListSheet
import com.echoledger.mobile.ui.v2.components.EchoRenameListSheet
import com.echoledger.mobile.ui.v2.components.ListChipMenuWords
import com.echoledger.mobile.ui.v2.components.ListEntry
import com.echoledger.mobile.ui.v2.components.NewListWords
import com.echoledger.mobile.ui.v2.components.RenameListWords
import com.echoledger.mobile.ui.v2.shell.v2Text
import kotlinx.coroutines.launch

/** What the chip row asks of the screen: create answers the new list (null when it was refused), rename whether it worked. */
interface ListActions {
    suspend fun create(name: String, colour: String): ListDto?
    suspend fun rename(list: ListDto, name: String): Boolean
    suspend fun recolour(list: ListDto, colour: String)
    suspend fun move(list: ListDto, delta: Int)
    suspend fun delete(list: ListDto)
}

/** The eight colours' names in the screen's language, for the picker's screen-reader names. */
@Composable
fun colourNames(): Map<String, String> = mapOf(
    "grey" to stringResource(R.string.v2_colour_grey),
    "red" to stringResource(R.string.v2_colour_red),
    "orange" to stringResource(R.string.v2_colour_orange),
    "amber" to stringResource(R.string.v2_colour_amber),
    "green" to stringResource(R.string.v2_colour_green),
    "teal" to stringResource(R.string.v2_colour_teal),
    "blue" to stringResource(R.string.v2_colour_blue),
    "pink" to stringResource(R.string.v2_colour_pink)
)

/** The fixed chips' words on this screen, refused as names with those of both languages. */
@Composable
fun fixedChipWords(): List<String> = listOf(
    stringResource(R.string.v2_lists_all),
    stringResource(R.string.v2_lists_unread),
    stringResource(R.string.v2_lists_important)
)

/** "You already have a list called Clients.", or for a fixed chip's word, that it is one of the Library's own. */
@Composable
fun nameClash(): (String) -> String {
    val res = LocalContext.current.resources
    val screen = fixedChipWords()
    return { clash ->
        if (LibraryLists.reserved(clash, screen)) {
            res.getString(R.string.v2_lists_reserved, clash)
        } else {
            res.getString(R.string.v2_lists_duplicate, clash)
        }
    }
}

/** The new-list sheet's words. */
@Composable
fun newListWords(): NewListWords = NewListWords(
    title = stringResource(R.string.v2_lists_new_title),
    name = stringResource(R.string.v2_lists_name),
    colour = stringResource(R.string.v2_lists_colour),
    create = stringResource(R.string.v2_lists_create),
    duplicate = nameClash(),
    colourNames = colourNames()
)

/**
 * The title menu's Labels (lists are labels, contract 2.0.0-m): + New label makes one in two steps (its name, then its
 * colour with the least used one chosen) and shows it; a label's ⋮ renames it, changes its colour or deletes it after
 * asking. One sheet at a time, the one whose state is set; [onDone] closes it.
 */
@Composable
fun LabelSheets(
    lists: List<ListDto>,
    creating: Boolean,
    renaming: ListDto?,
    recolouring: ListDto?,
    deleting: ListDto?,
    actions: ListActions,
    onDone: () -> Unit,
    onMade: (ListDto) -> Unit
) {
    val scope = rememberCoroutineScope()
    val names = lists.map { it.name }
    val reserved = LibraryLists.RESERVED + fixedChipWords()
    if (creating) {
        EchoNewListSheet(
            existing = names,
            usedColours = lists.map { it.color },
            words = newListWords().copy(title = stringResource(R.string.v2_lf_new_label), name = stringResource(R.string.v2_lf_label_name)),
            onDismiss = onDone,
            reserved = reserved
        ) { name, colour ->
            scope.launch {
                actions.create(name, colour)?.let { made ->
                    onDone()
                    onMade(made)
                }
            }
        }
    }
    renaming?.let { list ->
        EchoRenameListSheet(
            current = list.name,
            existing = names,
            words = RenameListWords(
                stringResource(R.string.v2_lf_rename_label),
                stringResource(R.string.v2_lf_label_name),
                stringResource(R.string.v2_lists_save),
                nameClash()
            ),
            onDismiss = onDone,
            reserved = reserved
        ) { name -> scope.launch { if (actions.rename(list, name)) onDone() } }
    }
    recolouring?.let { list ->
        EchoListColourSheet(
            title = stringResource(R.string.v2_lists_recolour_title),
            stored = list.color,
            names = colourNames(),
            saveLabel = stringResource(R.string.v2_lists_save),
            onDismiss = onDone
        ) { colour ->
            onDone()
            scope.launch { actions.recolour(list, colour) }
        }
    }
    deleting?.let { list ->
        val res = LocalContext.current.resources
        EchoDeleteListDialog(
            name = list.name,
            words = DeleteListWords(
                title = { res.getString(R.string.v2_lists_delete_title, it) },
                body = stringResource(R.string.v2_lf_delete_label_body),
                confirm = stringResource(R.string.v2_lists_delete_confirm),
                cancel = v2Text.cancel
            ),
            onConfirm = {
                onDone()
                scope.launch { actions.delete(list) }
            },
            onDismiss = onDone
        )
    }
}

/**
 * Add to list for the selected recordings: each list ticked when all of them are in it; a tap puts them in (only the
 * ones not in it yet) or takes them out; New list… makes one and puts them in it.
 */
@Composable
fun AddToListFlow(
    lists: List<ListDto>,
    inAll: (ListDto) -> Boolean,
    onToggle: (ListDto, add: Boolean) -> Unit,
    onCreate: suspend (name: String, colour: String) -> ListDto?,
    onDismiss: () -> Unit
) {
    val scope = rememberCoroutineScope()
    var making by remember { mutableStateOf(false) }
    if (making) {
        EchoNewListSheet(
            existing = lists.map { it.name },
            usedColours = lists.map { it.color },
            words = newListWords(),
            onDismiss = { making = false },
            reserved = LibraryLists.RESERVED + fixedChipWords()
        ) { name, colour ->
            scope.launch {
                onCreate(name, colour)?.let { made ->
                    making = false
                    onToggle(made, true)
                }
            }
        }
        return
    }
    EchoAddToListSheet(
        lists = lists.map { ListEntry(it.name, it.color) },
        checked = lists.filter(inAll).map { it.name }.toSet(),
        words = AddToListWords(stringResource(R.string.v2_lists_add_to), stringResource(R.string.v2_lists_new_list)),
        onToggle = { name -> lists.firstOrNull { it.name == name }?.let { onToggle(it, !inAll(it)) } },
        onNew = { making = true },
        onDismiss = onDismiss
    )
}
