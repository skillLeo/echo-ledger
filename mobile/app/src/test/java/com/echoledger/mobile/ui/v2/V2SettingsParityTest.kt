package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasScrollToNodeAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performScrollToNode
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.V2SettingsScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertTrue
import org.junit.Test

/** Settings v2 against classic's Settings (docs/v2/audit/parity.csv PH-SET): Sign out asks first. */
class V2SettingsParityTest : ScreenTest() {
    private fun open(language: EchoLanguage = EchoLanguage.English) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2SettingsScreen(vm, UiFlags.Off, {}, {}, { p, _ -> androidx.compose.material3.Text("classic $p") }, { _, _ -> }, {})
                }
            }
        }
        pumpUntil("signed in") { vm.settings.value.paired }
    }

    private fun press(words: String) {
        // Settings is a lazy list: Sign out is drawn once scrolled to.
        runCatching { compose.onNode(hasScrollToNodeAction()).performScrollToNode(hasText(words)) }
        val node = compose.onAllNodes(hasText(words) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun waitFor(words: String) = compose.waitUntil(10_000) { compose.onAllNodes(hasText(words)).fetchSemanticsNodes().isNotEmpty() }

    @Test
    fun signOutAsksFirstAndCancelKeepsThePhoneSignedIn() {
        open()
        press("Sign out")
        waitFor("Sign out of this phone?")
        waitFor("Recordings that haven't uploaded yet stay on this phone until you sign in again.")
        compose.onNode(hasText("Cancel") and hasClickAction() and hasAnyAncestor(isDialog())).performClick()
        compose.waitForIdle()
        assertTrue(vm.settings.value.paired)
        press("Sign out")
        waitFor("Sign out of this phone?")
        // The dialog's own Sign out signs this phone out.
        compose.onNode(hasText("Sign out") and hasClickAction() and hasAnyAncestor(isDialog())).performClick()
        pumpUntil("signed out") { !vm.settings.value.paired }
    }

    @Test
    fun inUrduSignOutAsksInItsOwnWords() {
        open(EchoLanguage.Urdu)
        val words = V2Testing.resources(EchoLanguage.Urdu)
        press(words.getString(com.echoledger.mobile.R.string.v2_set_sign_out))
        waitFor(words.getString(com.echoledger.mobile.R.string.v2_set_sign_out_title))
    }
}
