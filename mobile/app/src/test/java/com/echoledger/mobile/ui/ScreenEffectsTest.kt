package com.echoledger.mobile.ui

import android.os.Looper
import androidx.compose.material3.Text
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.onAllNodesWithText
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import java.util.concurrent.atomic.AtomicReference
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.junit.Assert.assertEquals
import org.junit.Test

/** Screen tests run a screen's effects the way the app does (P-078, P-100). */
class ScreenEffectsTest : ScreenTest() {
    @Test
    fun `an effect carries on on the main thread after its background work, as in the app`() {
        val resumedOn = AtomicReference<Thread>()
        show {
            var text by remember { mutableStateOf("Loading") }
            LaunchedEffect(Unit) {
                withContext(Dispatchers.IO) { Thread.sleep(20) }
                resumedOn.set(Thread.currentThread())
                text = "Loaded"
            }
            Text(text)
        }

        pumpUntil("the effect to carry on") { resumedOn.get() != null }
        assertEquals(Looper.getMainLooper().thread, resumedOn.get())
        pumpUntil("the screen to show what it loaded") {
            compose.onAllNodesWithText("Loaded").fetchSemanticsNodes().isNotEmpty()
        }
    }
}
