package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.compose.ui.test.swipeRight
import com.echoledger.mobile.core.Notice
import com.echoledger.mobile.core.NoticeKind
import com.echoledger.mobile.core.NoticeTarget
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.NoticeHost
import com.echoledger.mobile.ui.design.NoticeHostState
import org.junit.Assert.assertEquals
import org.junit.Test

/** v1.6.1, docs/DESIGN_SYSTEM.md "Notices": tap to go, × to close, a timer, a swipe either way. */
class NoticeHostTest : ScreenTest() {
    private val host = NoticeHostState()
    private val opened = mutableListOf<NoticeTarget>()

    private fun showHost() = show { Box { NoticeHost(host, onOpen = { opened += it }, now = { compose.mainClock.currentTime }) } }

    private fun gone(text: String) = compose.onAllNodesWithText(text).assertCountEquals(0)

    @Test
    fun `× closes a message and the next one shows`() {
        showHost()
        host.show(Notice("Saved"))
        host.show(Notice("Renamed"))
        compose.onNodeWithText("Saved").assertExists()
        gone("Renamed")

        compose.onNodeWithContentDescription("Dismiss").performClick()
        compose.waitForIdle()

        gone("Saved")
        compose.onNodeWithText("Renamed").assertExists()
    }

    @Test
    fun `a tap goes where the message is about and closes it`() {
        showHost()
        host.show(Notice("Restored — it uploads now", target = NoticeTarget.Library))

        compose.onNodeWithText("Restored — it uploads now").performClick()
        compose.waitForIdle()

        assertEquals(listOf<NoticeTarget>(NoticeTarget.Library), opened)
        gone("Restored — it uploads now")
    }

    @Test
    fun `a message hides itself after 6 seconds, an error after 10`() {
        compose.mainClock.autoAdvance = false
        showHost()
        fun shownAfter(text: String, ms: Long): Boolean {
            repeat((ms / 250).toInt()) {
                compose.mainClock.advanceTimeBy(250)
                compose.waitForIdle() // applies state changed from outside the composition, as the app's own changes are
            }
            return compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()
        }
        host.show(Notice("Saved"))
        assertEquals("a message at 5 s", true, shownAfter("Saved", 5_000))
        assertEquals("a message at 6.75 s", false, shownAfter("Saved", 1_750))

        host.show(Notice("The export failed.", NoticeKind.ERROR))
        assertEquals("an error at 8 s", true, shownAfter("The export failed.", 8_000))
        assertEquals("an error at 11 s", false, shownAfter("The export failed.", 3_000))
    }

    @Test
    fun `a swipe left or right closes a message`() {
        showHost()
        host.show(Notice("Saved"))
        compose.onNodeWithText("Saved").performTouchInput { swipeLeft() }
        compose.waitForIdle()
        gone("Saved")

        host.show(Notice("Copied"))
        compose.onNodeWithText("Copied").performTouchInput { swipeRight() }
        compose.waitForIdle()
        gone("Copied")
    }

    @Test
    fun `a banner closes with × or a swipe, and a tap does what its button does`() {
        var retried = 0
        show {
            InlineBanner("Transcription failed.", kind = BannerKind.Error, action = "Retry", onAction = { retried++ })
        }

        compose.onNodeWithText("Transcription failed.").performClick()
        assertEquals(1, retried)
        compose.onNodeWithContentDescription("Dismiss").performClick()
        compose.waitForIdle()
        gone("Transcription failed.")
    }

    @Test
    fun `a banner closes with a swipe either way`() {
        show { InlineBanner("You're offline. Showing the last synced library.") }

        compose.onNodeWithText("You're offline. Showing the last synced library.").performTouchInput { swipeRight() }
        compose.waitForIdle()

        gone("You're offline. Showing the last synced library.")
    }
}
