package com.echoledger.mobile.ui

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.hapticfeedback.HapticFeedback
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** C4: haptics on the key actions and predictive back. */
class NativeFeelTest : ScreenTest() {
    private val felt = mutableListOf<HapticFeedbackType>()
    private val recorder = object : HapticFeedback {
        override fun performHapticFeedback(hapticFeedbackType: HapticFeedbackType) {
            felt += hapticFeedbackType
        }
    }

    @Test
    fun `starting a recording gives a toggle-on tick`() {
        runBlocking { app.pairLocally(server) }
        compose.mainClock.autoAdvance = false
        show {
            CompositionLocalProvider(LocalHapticFeedback provides recorder) {
                RecordScreen(
                    vm,
                    Capabilities(
                        34,
                        true,
                        micPermission = true,
                        notificationPermission = true,
                        overlayPermission = true
                    ),
                    onSetup = {
                    }
                )
            }
        }
        compose.mainClock.advanceTimeBy(1_500)
        compose.onNodeWithText("REC").performClick()
        compose.mainClock.advanceTimeBy(100)
        assertEquals(listOf(HapticFeedbackType.ToggleOn), felt)
    }

    @Test
    fun `the app opts in to predictive back`() {
        val manifest = File("src/main/AndroidManifest.xml").readText()
        assertTrue(manifest.contains("android:enableOnBackInvokedCallback=\"true\""))
    }
}
