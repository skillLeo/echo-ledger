package com.echoledger.mobile.e2e.support

import androidx.test.uiautomator.By
import androidx.test.uiautomator.UiObject2
import java.util.regex.Pattern

/** Library screen helpers. */
object Library {
    const val SEARCH_HINT = "Search transcripts and titles"

    fun searchField(): UiObject2 = Ui.await(By.clazz("android.widget.EditText"), Ui.NORMAL, "the library search field")

    fun search(query: String) {
        Ui.awaitText("Library")
        searchField().apply {
            click()
            text = query
        }
        Ui.hideKeyboard()
    }

    fun clearSearch() {
        searchField().text = ""
        Ui.hideKeyboard()
    }

    fun sync() {
        Ui.clickDescription("Sync")
    }

    /** The newest seeded row is the first in the list; the list only composes the rows that fit on screen. */
    fun awaitLoaded() {
        Ui.awaitText(SeedData.SILENT, Ui.NORMAL)
    }

    /** A list row titled [title]: the search field may hold the same text, so only plain text views count. */
    fun row(title: String, timeoutMs: Long = Ui.NORMAL): UiObject2 =
        Ui.await(By.text(title).clazz("android.widget.TextView"), timeoutMs, "a library row titled \"$title\"")

    /** Filters the list down to [title] and opens that session. */
    fun open(title: String) {
        search(title)
        row(title).click()
        Ui.awaitDescription("Back")
    }
}

/** The audio player card and transcript rows of the session screen. */
object SessionPage {
    private val TOTAL = By.text(Pattern.compile("\\s*/\\s*\\d+:\\d{2}.*"))
    private val CLOCK = Pattern.compile("\\d+:\\d{2}")

    fun awaitTranscript() {
        Ui.awaitTextContaining("Final transcript", Ui.LONG)
    }

    /** The play button is enabled while the player is still buffering; wait until the recording length is known too. */
    fun awaitPlayable() {
        Ui.awaitDescription("Play", Ui.LONG)
        Ui.awaitCondition("the player to know the length of the recording", Ui.LONG, 500) { totalSeconds() > 0 }
    }

    fun elapsedSeconds(): Int {
        val total = Ui.await(TOTAL, Ui.NORMAL, "the player's total time").visibleBounds
        val node = Ui.device.findObjects(By.text(CLOCK)).firstOrNull {
            Math.abs(it.visibleBounds.centerY() - total.centerY()) < 10 && it.visibleBounds.right <= total.left + 6
        } ?: throw AssertionError("no elapsed-time label next to the total\n${Ui.hierarchySummary()}")
        val (m, s) = node.text.split(":").map(String::toInt)
        return m * 60 + s
    }

    fun totalSeconds(): Int {
        val text = Ui.await(TOTAL, Ui.NORMAL, "the player's total time").text.replace("/", "").trim()
        val (m, s) = text.split(":").map(String::toInt)
        return m * 60 + s
    }

    /** Taps the timestamp of a transcript row (the rows sit below the player card). */
    fun tapStamp(stamp: String) {
        val total = Ui.await(TOTAL).visibleBounds
        val row = Ui.device.findObjects(By.text(stamp))
            .filter { it.visibleBounds.centerY() > total.bottom }
            .minByOrNull { it.visibleBounds.centerY() }
            ?: throw AssertionError("no transcript row stamped $stamp\n${Ui.hierarchySummary()}")
        row.click()
    }
}

/** Record screen helpers (upload queue cards). */
object RecordPage {
    const val UPLOADING = "Uploading 1 recording(s)…"
    const val FAILED = "1 recording(s) could not be uploaded"
}
