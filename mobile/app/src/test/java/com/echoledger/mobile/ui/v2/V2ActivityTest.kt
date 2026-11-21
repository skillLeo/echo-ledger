package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import com.echoledger.mobile.calls.MissedFix
import com.echoledger.mobile.calls.MissedItem
import com.echoledger.mobile.calls.MissedReason
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.activity.ActivityActions
import com.echoledger.mobile.ui.v2.activity.V2ActivityContent
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.time.LocalDate
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Activity v2 against classic's Activity tab (parity.csv PH-OTH-011…017): Needs action, Today and Earlier; a tap marks
 * read and opens the recording; Mark all as read; a swipe dismisses; missed recordings with why and the fix; "Skipped
 * N short calls" with Show, Hide and Restore; Keep all and Discard all; all caught up; couldn't load with Retry.
 */
class V2ActivityTest : ScreenTest() {
    private val today = LocalDate.parse("2026-11-21")
    private val read = mutableListOf<List<String>?>()
    private val opened = mutableListOf<String>()
    private val fixes = mutableListOf<MissedFix>()
    private val restored = mutableListOf<String>()
    private val kept = mutableListOf<String>()
    private val discarded = mutableListOf<String>()
    private val missedRead = mutableListOf<String>()
    private var answer: ActivityResponse? = null
    private var loads = 0

    private fun item(id: String, title: String, at: String, kind: String = "transcript_ready", read: Boolean = false) =
        ActivityItemDto(id = id, kind = kind, title = title, body = "Bilal Traders", sessionId = "s-$id", read = read, at = at)

    private val missed = MissedItem(
        ref = "m1",
        app = "whatsapp",
        startedAtMs = java.time.Instant.parse("2026-11-21T08:00:00Z").toEpochMilli(),
        durationS = 312,
        reason = MissedReason.MIC_BUSY.wire,
        detail = MissedReason.MIC_BUSY.detail,
        foundAtMs = 0
    )

    private fun show(
        language: EchoLanguage = EchoLanguage.English,
        waiting: List<LocalRecording> = emptyList(),
        skipped: List<LocalRecording> = emptyList(),
        missedItems: List<MissedItem> = emptyList()
    ) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                V2ActivityContent(
                    load = {
                        loads++
                        answer
                    },
                    markRead = { ids ->
                        read += listOf(ids)
                        0
                    },
                    waiting = waiting,
                    failedUploads = emptyList(),
                    untranscribed = 0,
                    failedTranscripts = 0,
                    skipped = skipped,
                    missed = missedItems,
                    actions = ActivityActions(onOpenRecording = { opened += it }, onFix = { fixes += it }, onOpenUntranscribed = {}),
                    onKeep = { kept += it.id },
                    onDiscard = { discarded += it.id },
                    onRetryUploads = {},
                    onRestore = { restored += it },
                    onMissedRead = { missedRead += it },
                    onMissedDismiss = {},
                    today = today
                )
            }
        }
    }

    private fun await(words: String) = pumpUntil("\"$words\"") {
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    @Test
    fun needsActionTodayAndEarlierAndATapMarksReadAndOpens() {
        answer = ActivityResponse(
            listOf(
                item("a1", "Upload failed", "2026-11-21T09:00:00Z", kind = "upload_failed"),
                item("a2", "Transcript ready", "2026-11-21T07:00:00Z", read = true),
                item("a3", "New device signed in", "2026-11-10T07:00:00Z", read = true)
            ),
            unreadCount = 1
        )
        show()
        await("Needs action")
        compose.onNodeWithText("Today").assertExists()
        compose.onNodeWithText("Earlier").assertExists()
        compose.onNodeWithText("Upload failed").performClick()
        pumpUntil("opened") { opened.isNotEmpty() }
        assertEquals(listOf("s-a1"), opened)
        assertTrue(read.contains(listOf("a1")))
    }

    @Test
    fun markAllAsReadClearsTheBadge() {
        answer = ActivityResponse(listOf(item("a1", "Upload failed", "2026-11-21T09:00:00Z", kind = "upload_failed")), unreadCount = 1)
        show(missedItems = listOf(missed))
        compose.onNode(hasText("Mark all as read") and hasClickAction()).performClick()
        pumpUntil("marked") { read.contains(null) }
        assertEquals(listOf("m1"), missedRead)
    }

    @Test
    fun aSwipeDismissesARowAndMarksItRead() {
        answer = ActivityResponse(listOf(item("a1", "Upload failed", "2026-11-21T09:00:00Z", kind = "upload_failed")), unreadCount = 1)
        show()
        await("Upload failed")
        compose.onNodeWithText("Upload failed").performTouchInput { swipeLeft() }
        pumpUntil("dismissed") { compose.onAllNodesWithText("Upload failed").fetchSemanticsNodes().isEmpty() }
        assertTrue(read.contains(listOf("a1")))
    }

    @Test
    fun aMissedRecordingSaysTheCallWhyAndItsFix() {
        answer = ActivityResponse()
        show(missedItems = listOf(missed))
        await("Missed recording")
        compose.onNodeWithText("WhatsApp call · 5:12").assertExists()
        compose.onNodeWithText("Another app was using the microphone, so the call could not be recorded.").assertExists()
        compose.onNode(hasText("Test a call") and hasClickAction()).performClick()
        assertEquals(listOf(MissedFix.TEST), fixes)
    }

    @Test
    fun aMissedRecordingInUrdu() {
        answer = ActivityResponse()
        show(language = EchoLanguage.Urdu, missedItems = listOf(missed))
        await("ریکارڈنگ رہ گئی")
        compose.onNodeWithText("مائیکروفون کوئی اور ایپ استعمال کر رہی تھی، اس لیے کال ریکارڈ نہیں ہو سکی۔").assertExists()
        compose.onNodeWithText("کال ٹیسٹ کریں").assertExists()
    }

    @Test
    fun skippedShortCallsShowHideAndRestore() {
        answer = ActivityResponse()
        val skip = HomeFixtures.waiting("r-s", "Omar", 2_000, state = "skipped").copy(skippedReason = "too_short")
        show(skipped = listOf(skip))
        await("Skipped 1 short call")
        compose.onNode(hasText("Show") and hasClickAction()).performClick()
        compose.onNodeWithText("WhatsApp · 0:02 · Too short").assertExists()
        compose.onNode(hasText("Restore") and hasClickAction()).performClick()
        assertEquals(listOf("r-s"), restored)
        compose.onNode(hasText("Hide") and hasClickAction()).performClick()
        compose.onNodeWithText("WhatsApp · 0:02 · Too short").assertDoesNotExist()
    }

    @Test
    fun severalCallsWaitingHaveKeepAllAndDiscardAll() {
        answer = ActivityResponse()
        val calls = listOf(HomeFixtures.waiting("r-a", "Omar", 60_000), HomeFixtures.waiting("r-b", "Sana", 90_000))
        show(waiting = calls)
        await("Keep all")
        compose.onNode(hasText("Keep all") and hasClickAction()).performClick()
        assertEquals(listOf("r-a", "r-b"), kept)
        compose.onNode(hasText("Discard all") and hasClickAction()).performClick()
        assertEquals(listOf("r-a", "r-b"), discarded)
    }

    @Test
    fun allCaughtUpAndCouldNotLoadWithRetry() {
        answer = null
        show()
        await("Couldn't load activity. Check the connection.")
        answer = ActivityResponse()
        compose.onNode(hasText("Retry") and hasClickAction()).performClick()
        await("You're all caught up")
        assertEquals(2, loads)
    }
}
