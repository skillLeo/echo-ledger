package com.echoledger.mobile.ui.v2

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.ROW_AVATAR_TAG
import com.echoledger.mobile.ui.v2.components.RowAvatarLook
import com.echoledger.mobile.ui.v2.components.RowAvatarLookKey
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.Profile
import com.echoledger.mobile.ui.v2.shell.ProfileCardSlot
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * P-126: the phone's one initials rule (PersonInitials, the web's) where people see it in Urdu: the account card and a
 * contact's row in the Library show two initials kept apart by a ZWNJ ("عائشہ خان" → ع‌خ), never one letter or a joined
 * word. Urdu light and dark at the S25's size, in docs/v2/design-review/P-126.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(qualifiers = "w411dp-h891dp-420dpi")
class InitialsShotsTest : ScreenTest() {
    private val out =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/design-review/P-126")
    private var dark by mutableStateOf(false)

    @Test
    fun theAccountCardShowsAnUrduNamesTwoInitialsApart() {
        val profile = Profile("عائشہ خان", "ayesha@bilaltraders.pk", "Free", 42, 120, 2_100_000_000, 31)
        show {
            EchoV2Theme(darkTheme = dark, language = EchoLanguage.Urdu) {
                Box(Modifier.padding(16.dp)) { ProfileCardSlot(ProfileState.Ready(profile), {}, {}) }
            }
        }
        compose.waitForIdle()
        assertEquals(1, compose.onAllNodes(hasText("ع‌خ"), useUnmergedTree = true).fetchSemanticsNodes().size)
        for (d in listOf(false, true)) {
            dark = d
            compose.waitForIdle()
            compose.onRoot().captureRoboImage(File(out, "account-card-${if (d) "dark" else "light"}-ur.png"))
        }
    }

    @Test
    fun aContactsRowShowsAnUrduNamesTwoInitialsApart() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem("01JA", title = "علی رضا", sourceApp = "whatsapp"),
                        ServerFixtures.libraryItem("01JB", title = "Bilal Traders", sourceApp = "whatsapp")
                    )
                )
            )
        )
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = dark, language = EchoLanguage.Urdu) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2LibraryScreen(vm, initials = com.echoledger.mobile.ui.v2.shell.initialsOf("عائشہ خان"), onOpen = {
                    }, onTrash = {}, onAccount = {})
                }
            }
        }
        pumpUntil("the rows") { compose.onAllNodes(hasTestTag(ROW_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes().size >= 2 }
        val looks = compose.onAllNodes(hasTestTag(ROW_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes().map {
            it.config[RowAvatarLookKey]
        }
        assertEquals(setOf(RowAvatarLook("ع‌ر", null, true), RowAvatarLook("BT", null, true)), looks.toSet())
        for (d in listOf(false, true)) {
            dark = d
            compose.waitForIdle()
            compose.onNode(
                hasClickAction() and hasText("علی رضا", substring = true)
            ).captureRoboImage(File(out, "contact-row-${if (d) "dark" else "light"}-ur.png"))
        }
    }
}
