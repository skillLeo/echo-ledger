package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Text
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.testsupport.ScreenTest
import org.junit.Assert.assertEquals
import org.junit.Test
import org.robolectric.annotation.GraphicsMode

/** The sweep's text check (ClassicSweepTest.kt) on texts whose answer is known; real text sizes need the native graphics. */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class TextCutsTest : ScreenTest() {
    @Test
    fun `finds a text cut without an ellipsis and a word broken across lines, and nothing else`() {
        show {
            Column {
                // Whole: short in a wide box, wrapped between words, shortened with "…".
                Text("Pin", Modifier.width(300.dp))
                Text("Kept whole on two lines", Modifier.width(120.dp))
                Text("Shortened with dots here", Modifier.width(60.dp), maxLines = 1, overflow = TextOverflow.Ellipsis)
                // Not whole: one line kept of several, taller than its box, a word longer than its box.
                Text("Clipped without dots here", Modifier.width(60.dp), maxLines = 1, overflow = TextOverflow.Clip)
                Text("Taller than its box", Modifier.width(60.dp).height(16.dp))
                Text("Organise", Modifier.width(40.dp), fontSize = 20.sp)
            }
        }

        val found = TextCuts.find(compose).map { it.substringBefore(" at ") }

        assertEquals(
            listOf("cut off: \"Clipped without dots here\"", "cut off: \"Taller than its box\"", "word broken: \"Organise\""),
            found
        )
    }
}
