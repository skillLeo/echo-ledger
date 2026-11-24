package com.echoledger.mobile.ui.v2

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.calls.CallLines
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.EchoToastState
import com.echoledger.mobile.ui.v2.shell.CallLinesHost
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

/**
 * Review of FIX-03: turning the phone (the activity made again) is not leaving the app. A call's snackbar still running
 * shows again on the new screen until its own end time, with its Undo; nothing moves to the notification.
 */
class CallLinesRotationTest : AppTest() {
    @get:Rule
    val compose = createEmptyComposeRule()

    /** An app screen with the shell's snackbar host, as the shell has it, on a clock the test moves ([now]). */
    class LinesActivity : ComponentActivity() {
        companion object {
            var now = 1_000_000_000L
        }

        override fun onCreate(savedInstanceState: Bundle?) {
            super.onCreate(savedInstanceState)
            setContent {
                EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                    val toasts = remember { EchoToastState { now } }
                    CallLinesHost(toasts)
                    Box(Modifier.fillMaxSize()) { EchoToastHost(toasts, Modifier.align(Alignment.BottomCenter)) }
                }
            }
        }
    }

    @Test
    fun `a running line survives a rotation with its end time and its Undo, and never moves to the notification`() {
        val controller = buildActivity(LinesActivity::class.java).setup()
        pumpUntil("the host") { CallLines.host != null }
        var undone = 0
        val away = mutableListOf<Long>()
        val ended = mutableListOf<Unit>()
        val actions = CallLines.Actions(action = { undone++ }, ended = { ended += Unit }, away = { away += it })
        var leftMs = 0L
        controller.get().runOnUiThread {
            leftMs = CallLines.host!!.show(CallLines.Line("call-1", CallChoice.Pick.DELETE), CallLines.LONG_MS, actions)
        }
        compose.onNodeWithText("Deleted").assertExists()
        val first = CallLines.host

        controller.recreate()
        pumpUntil("the new screen's host") { CallLines.host != null && CallLines.host !== first }
        compose.onNodeWithText("Deleted").assertExists()
        assertTrue("nothing moved to the notification", away.isEmpty())
        assertTrue("nor ended", ended.isEmpty())
        assertEquals(CallLines.LONG_MS, leftMs)

        compose.onNodeWithText("Undo").performClick()
        compose.waitForIdle()
        assertEquals("its Undo still works", 1, undone)
        // The time a rotation may take passes: nothing was left behind to move out.
        org.robolectric.shadows.ShadowLooper.idleMainLooper(CallLines.CARRY_MS + 1, java.util.concurrent.TimeUnit.MILLISECONDS)
        assertTrue(away.isEmpty())
        assertNotNull(CallLines.host)
    }

    private fun show(
        controller: org.robolectric.android.controller.ActivityController<*>,
        line: CallLines.Line,
        actions: CallLines.Actions
    ) = controller.get().runOnUiThread { CallLines.host!!.show(line, CallLines.LONG_MS, actions) }

    @Test
    fun `after a rotation a line ends at its own end time, not 10 seconds later`() {
        val controller = buildActivity(LinesActivity::class.java).setup()
        pumpUntil("the host") { CallLines.host != null }
        val ended = mutableListOf<Unit>()
        show(controller, CallLines.Line("call-1", CallChoice.Pick.TRANSCRIBE), CallLines.Actions(action = {}, ended = { ended += Unit }))
        compose.onNodeWithText("Transcribing…").assertExists()
        // 4 s of its 10 go by, then the phone turns.
        LinesActivity.now += 4_000
        controller.recreate()
        pumpUntil("the new screen's host") { CallLines.host != null }
        compose.onNodeWithText("Transcribing…").assertExists()
        compose.mainClock.autoAdvance = false
        compose.mainClock.advanceTimeBy(CallLines.LONG_MS - 4_000 + 500)
        compose.onNodeWithText("Transcribing…").assertDoesNotExist()
        assertEquals("over at its own end time", 1, ended.size)
    }

    @Test
    fun `two lines across a rotation keep their order, so the Deleted line is never lost behind a later one`() {
        val controller = buildActivity(LinesActivity::class.java).setup()
        pumpUntil("the host") { CallLines.host != null }
        var undone = 0
        show(controller, CallLines.Line("call-1", CallChoice.Pick.DELETE), CallLines.Actions(action = { undone++ }))
        show(controller, CallLines.Line("call-2", CallChoice.Pick.TRANSCRIBE), CallLines.Actions(action = {}))
        compose.onNodeWithText("Deleted").assertExists()
        controller.recreate()
        pumpUntil("the new screen's host") { CallLines.host != null }
        compose.onNodeWithText("Deleted").assertExists()
        compose.onNodeWithText("Transcribing…").assertDoesNotExist()
        compose.onNodeWithText("Undo").performClick()
        compose.waitForIdle()
        assertEquals("the earlier one's Undo first", 1, undone)
        compose.onNodeWithText("Transcribing…").assertExists()
    }

    @Test
    fun `a line no screen picks up within 2 seconds goes on in the notification, and one already over just ends`() {
        var now = 5_000_000L
        val away = mutableListOf<Long>()
        val ended = mutableListOf<String>()
        CallLines.carry(
            listOf(
                CallLines.Carried(
                    CallLines.Line("running", CallChoice.Pick.DELETE),
                    now + 6_000,
                    CallLines.Actions(action = {}, away = {
                        away +=
                            it
                    })
                ),
                CallLines.Carried(
                    CallLines.Line("short", CallChoice.Pick.AUDIO),
                    now + 1_000,
                    CallLines.Actions(action = {}, ended = {
                        ended +=
                            "short"
                    })
                )
            ),
            { now }
        )
        now += CallLines.CARRY_MS
        org.robolectric.shadows.ShadowLooper.idleMainLooper(CallLines.CARRY_MS + 1, java.util.concurrent.TimeUnit.MILLISECONDS)
        assertEquals("the rest of its time", listOf(6_000L - CallLines.CARRY_MS), away)
        assertEquals(listOf("short"), ended)
        assertTrue("nothing left for a later screen", CallLines.takeCarried().isEmpty())
    }
}
