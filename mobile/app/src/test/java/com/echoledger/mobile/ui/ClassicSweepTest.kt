package com.echoledger.mobile.ui

import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.isRoot
import androidx.compose.ui.test.junit4.ComposeContentTestRule
import androidx.compose.ui.text.TextLayoutResult
import com.echoledger.mobile.testsupport.ServerFixtures
import com.github.takahirom.roborazzi.RoborazziOptions
import com.github.takahirom.roborazzi.RoborazziTaskType
import java.io.File
import org.junit.Assert.assertTrue
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/*
 * F2, the phone screen sweep (docs/team/FIXING.md): every classic screen of ScreenshotsTest again, in the conditions
 * people meet on real phones: a small phone with larger text, the phone in Urdu (right to left, with an Urdu
 * recording), and half of a split screen. Each screen fails when a text is cut off without "…" or a word is broken
 * across two lines; its picture is kept in build/sweep/<condition>/ for a look.
 */

/**
 * A small phone (360 dp, as the Galaxy A series) with the text one step larger (1.3, Samsung's "large"), set as the
 * phone's font size so that dialogs, sheets and menus, each in a window of its own, get it too.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h800dp-hdpi", fontScale = 1.3f)
class ClassicSweepSmallPhoneLargeTextTest : ScreenshotsTest() {
    override val sweep = SweepCondition("360dp-text130")
}

/** The phone in Urdu: the screens laid out right to left, and an Urdu recording in the Library. */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "ur-ldrtl-w360dp-h800dp-hdpi")
class ClassicSweepUrduTest : ScreenshotsTest() {
    override val sweep = SweepCondition("360dp-urdu", urdu = true)
}

/** The app in the top half of a split screen on a 411 dp phone (the S25): full width, about half the height. */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h430dp-hdpi")
class ClassicSweepSplitScreenTest : ScreenshotsTest() {
    override val sweep = SweepCondition("split-half")
}

/**
 * One condition of the sweep. [known] maps a text that may stay cut for now to its bug in docs/v2/audit/bugs.csv
 * (S3 or S4 only: S0 to S2 are fixed, never listed here).
 */
data class SweepCondition(val name: String, val urdu: Boolean = false, val known: Map<String, String> = KNOWN) {
    val dir: File get() = File(System.getProperty("sweep.dir") ?: "build/sweep", name)

    /** The Library's extra rows for this condition (server JSON): an Urdu title and preview in Urdu. */
    fun extraItems(): List<String> = if (!urdu) {
        emptyList()
    } else {
        listOf(
            ServerFixtures.libraryItem(
                "01JE",
                "ادائیگی کا منصوبہ اور قرض کی واپسی",
                "2026-10-21T10:00:00+00:00",
                "2026-10-21T10:00:00+00:00",
                "phone",
                "whatsapp",
                "completed",
                "completed",
                "جی، میں کل تک رقم بھیج دوں گا۔ انوائس نمبر 42 ہے۔",
                "نارتھ ونڈ"
            )
        )
    }

    /** Fails with every text on [screen]'s windows that is cut off without "…" or broken inside a word. */
    fun assertTextWhole(compose: ComposeContentTestRule, screen: String) {
        val problems = TextCuts.find(compose).filterNot { problem ->
            known.keys.any { it in problem } || (problem.startsWith("word broken: ") && WRAPS_ANYWHERE.any { it in problem })
        }
        assertTrue("$name, $screen: ${problems.joinToString("; ")}", problems.isEmpty())
    }

    companion object {
        /** Cut for now, on every condition, each with its bug: none (P-119's preview line grows its frame now). */
        val KNOWN: Map<String, String> = emptyMap()

        /** Texts that may break inside a word (never be cut): Setup's adb command is copied with its button, not read. */
        val WRAPS_ANYWHERE = listOf("\"adb shell ")

        /** The sweep's pictures are written on every run, not only by the Roborazzi record task. */
        val RECORD = RoborazziOptions(taskType = RoborazziTaskType.Record)
    }
}

/** Finds texts that people can't read whole: cut off with no "…", or a word broken across two lines. */
object TextCuts {
    fun find(compose: ComposeContentTestRule): List<String> {
        val problems = mutableListOf<String>()
        fun walk(node: SemanticsNode) {
            node.config.getOrNull(SemanticsActions.GetTextLayoutResult)?.action?.let { layoutOf ->
                val layouts = mutableListOf<TextLayoutResult>()
                if (layoutOf(layouts)) layouts.mapNotNullTo(problems, ::problem)
            }
            node.children.forEach(::walk)
        }
        compose.onAllNodes(isRoot(), useUnmergedTree = true).fetchSemanticsNodes(atLeastOneRootRequired = false).forEach(::walk)
        return problems.distinct()
    }

    /**
     * What is wrong with one laid-out text, or null. "…" on a line is a choice (a long title), not a cut. A plain
     * Text's semantics lay it out again at the widest width it was allowed, so its hasVisualOverflow is true for any
     * text narrower than that: what counts here is its lines against the box it really got.
     */
    fun problem(layout: TextLayoutResult): String? {
        val text = layout.layoutInput.text.text
        if (text.isBlank()) return null
        val quoted = "\"${text.take(60)}\""
        val lines = 0 until layout.lineCount
        val box = layout.size
        val tooWide = lines.any { layout.getLineRight(it) - layout.getLineLeft(it) > box.width + 1 }
        val tooTall = layout.multiParagraph.height > box.height + 1
        val cut = tooWide || tooTall || layout.multiParagraph.didExceedMaxLines
        if (cut && lines.none(layout::isLineEllipsized)) return "cut off: $quoted"
        for (line in 0 until layout.lineCount - 1) {
            val end = layout.getLineEnd(line)
            if (end in 1 until text.length && text[end - 1].isLetterOrDigit() && text[end].isLetterOrDigit()) {
                val before = text.substring((end - 6).coerceAtLeast(0), end)
                val after = text.substring(end, (end + 6).coerceAtMost(text.length))
                return "word broken: $quoted at \"$before|$after\""
            }
        }
        return null
    }
}
