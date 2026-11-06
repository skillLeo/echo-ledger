package com.echoledger.mobile.ui

import com.echoledger.mobile.ui.design.isConfirmation
import com.echoledger.mobile.ui.design.tabDirection
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** The rules of Phase 7 that can be read without looking at a screen (docs/DESIGN_SYSTEM.md "Icons", "Motion"). */
class MotionAndIconsTest {
    private val sources: List<File> = listOf("src/main/java", "app/src/main/java")
        .map(::File)
        .firstOrNull { it.isDirectory }
        ?.walkTopDown()
        ?.filter { it.isFile && it.extension == "kt" }
        ?.toList()
        .orEmpty()

    @Test
    fun `a tab switch moves towards where the tab sits on the bar`() {
        val order = listOf("library", "projects", "activity", "you")
        assertEquals(1, tabDirection(order, "library", "activity"))
        assertEquals(-1, tabDirection(order, "you", "projects"))
        assertEquals(1, tabDirection(order, "library", "library"))
        assertEquals("a screen not on the bar comes in from the right", 1, tabDirection(order, "you", "record"))
    }

    @Test
    fun `only messages that confirm something carry the check`() {
        listOf("Saved to your library", "Copied", "Copied with timestamps", "Restored — it uploads now", "Renamed").forEach {
            assertTrue(it, isConfirmation(it))
        }
        listOf("The project could not be updated.", "Offline", "Re-transcribing…", "Line 3 could not be read").forEach {
            assertFalse(it, isConfirmation(it))
        }
    }

    @Test
    fun `one icon family, and the same line glyph everywhere`() {
        assertTrue("no Kotlin sources found", sources.size > 50)
        val otherSets = Regex("""Icons\.(Filled|Default|Sharp|TwoTone)\b""")
        val glyphs = "ArrowBack|Close|Search|Stop|MoreHoriz|KeyboardArrowRight|KeyboardReturn|Backspace"
        val lineGlyphs = Regex("""Icons\.(AutoMirrored\.)?Outlined\.($glyphs)\b""")
        val found = sources.flatMap { file ->
            file.readLines().filter { otherSets.containsMatchIn(it) || lineGlyphs.containsMatchIn(it) }.map { "${file.name}: ${it.trim()}" }
        }
        assertEquals(emptyList<String>(), found)
    }
}
