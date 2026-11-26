package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2TrashScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Before
import org.junit.Test

/**
 * The UX audit: a Trash the server could not give showed "Nothing deleted recently", as if it were empty. It says it
 * could not be loaded (v2_trash_error), in the screen's language, and Try again asks again.
 */
class TrashLoadErrorTest : ScreenTest() {
    private var language by mutableStateOf(EchoLanguage.English)

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private fun showTrash() = show {
        EchoV2Theme(language = language) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                V2TrashScreen(vm, configKeepHours = 48, onBack = {})
            }
        }
    }

    private fun shown(text: String) = compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun `a Trash that could not be loaded says so, in English and in Urdu, and Try again loads it`() {
        server.on("GET", "/api/bin", FakeServer.json(500, """{"message":"Server Error"}"""))
        showTrash()

        pumpUntil("the error") { shown("Couldn't load Recently deleted. Check the internet connection.") }
        compose.onAllNodesWithText("Nothing deleted recently").assertCountEquals(0)
        compose.onNodeWithText("Try again").assertExists()

        language = EchoLanguage.Urdu
        pumpUntil("the error in Urdu") { shown("حال ہی میں حذف شدہ لوڈ نہیں ہو سکیں۔ انٹرنیٹ کنکشن چیک کریں۔") }

        server.on("GET", "/api/bin", FakeServer.json(200, """{"data":[]}"""))
        compose.onNodeWithText("دوبارہ کوشش کریں").performClick()
        pumpUntil("an empty Trash, now that it loaded") { shown("حال ہی میں کچھ حذف نہیں ہوا") }
    }
}
