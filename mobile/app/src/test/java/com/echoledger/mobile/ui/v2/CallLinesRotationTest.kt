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

    /** An app screen with the shell's snackbar host, as the shell has it. */
    class LinesActivity : ComponentActivity() {
        override fun onCreate(savedInstanceState: Bundle?) {
            super.onCreate(savedInstanceState)
            setContent {
                EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                    val toasts = remember { EchoToastState() }
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
}
