package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test

/** The v2 shell on the real view model and a scripted server: the flags, Library, delete with Undo, Settings. */
class V2ShellTest : ScreenTest() {
    private val switched = mutableListOf<Boolean>()

    private val classic = ClassicScreens(
        home = { Text("Classic home") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic ask") },
        settings = { Text("Classic settings") },
        upgrade = {}
    )

    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem("01JA", title = "Bilal Traders", preview = "New rate for 40 cartons of basmati"),
                        ServerFixtures.libraryItem("01JB", title = "Rehman and Sons Textiles", sourceApp = "phone_call", finalStatus = null)
                    )
                )
            )
        )
        server.on("DELETE", "/api/sessions/01JA", FakeServer.empty(204))
        server.on("POST", "/api/sessions/01JA/restore", FakeServer.empty(204))
        runBlocking { app.graph.library.refresh() }
    }

    private fun shell(flags: UiFlags) = show {
        EchoV2Shell(vm, flags, darkTheme = false, classic = classic, onSwitchToClassic = {
            switched += it
        }, language = EchoLanguage.English)
    }

    private val v2 = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true))

    @Test
    fun libraryIsTheV2OneAndOtherTabsStayClassic() {
        seed()
        shell(v2)
        compose.onNodeWithText("Recordings").assertExists()
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
        compose.onNodeWithText("Home").performClick()
        compose.onNodeWithText("Classic home").assertExists()
    }

    @Test
    fun withoutTheLibraryFlagLibraryStaysClassic() {
        seed()
        shell(UiFlags(mapOf("ui_v2.shell" to true)))
        compose.onNodeWithText("Classic library").assertExists()
    }

    @Test
    fun deleteMovesToTrashAndUndoRestores() {
        seed()
        shell(v2)
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { longClick() }
        compose.onNodeWithText("Delete").performClick()
        compose.waitUntil(5_000) { server.requestsTo("DELETE", "/api/sessions/01JA").isNotEmpty() }
        compose.onNodeWithText("Moved to Trash").assertExists()
        compose.onNodeWithText("Undo").performClick()
        compose.waitUntil(5_000) { server.requestsTo("POST", "/api/sessions/01JA/restore").isNotEmpty() }
    }

    @Test
    fun settingsFromTheAvatarOffersClassicLook() {
        seed()
        shell(v2)
        compose.onNodeWithContentDescription("Account and settings").performClick()
        compose.onNodeWithText("Classic look").performClick()
        assertEquals(listOf(true), switched)
        compose.onNodeWithText("All settings").performClick()
        compose.onNodeWithText("Classic settings").assertExists()
    }

    @Test
    fun theViewModelsMessagesBecomeToasts() {
        seed()
        shell(v2)
        vm.showMessage("Restored")
        compose.waitUntil(5_000) { runCatching { compose.onNodeWithText("Restored").fetchSemanticsNode() }.isSuccess }
    }
}
