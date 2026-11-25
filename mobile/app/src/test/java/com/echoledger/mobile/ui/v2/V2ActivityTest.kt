package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasTestTag
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
import com.echoledger.mobile.data.ActorDto
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.activity.ACTOR_AVATAR_TAG
import com.echoledger.mobile.ui.v2.activity.ActivityActions
import com.echoledger.mobile.ui.v2.activity.ActorLook
import com.echoledger.mobile.ui.v2.activity.ActorLookKey
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
    private val savedAsAudio = mutableListOf<String>()
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
                    onSaveAudio = { savedAsAudio += it.id },
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
    fun severalCallsWaitingHaveSaveAllAsAudioOnlyAndDeleteAll() {
        answer = ActivityResponse()
        val calls = listOf(HomeFixtures.waiting("r-a", "Omar", 60_000), HomeFixtures.waiting("r-b", "Sana", 90_000))
        show(waiting = calls)
        // The copy deck's bulk choice (2.0.5): the audio is kept, nothing is transcribed by itself.
        await("Save all as audio only")
        compose.onNode(hasText("Save all as audio only") and hasClickAction()).performClick()
        assertEquals(listOf("r-a", "r-b"), savedAsAudio)
        assertEquals("no transcript by itself", emptyList<String>(), kept)
        compose.onNode(hasText("Delete all") and hasClickAction()).performClick()
        assertEquals(listOf("r-a", "r-b"), discarded)
    }

    @Test
    fun aCallLeftToChooseAgainSaysWhy() {
        answer = ActivityResponse()
        val calls = listOf(
            HomeFixtures.waiting("r-a", "Omar", 60_000).copy(restoredDelete = true),
            HomeFixtures.waiting("r-b", "Sana", 90_000).copy(restoredDelete = true, notDeletedYet = true)
        )
        show(waiting = calls)
        await("Deleted just before EchoLedger closed. Choose again.")
        await("Not deleted yet. Choose again.")
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

    // Contract 2.0.0-aa: the person an item is about takes its icon's place, for the neutral kinds only (decision 27).

    private fun actorLooks() = compose.onAllNodes(hasTestTag(ACTOR_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes().map {
        it.config[ActorLookKey]
    }

    @Test
    fun aSharedRecordingShowsItsSendersInitialsOnTheirColourAndAWarningKeepsItsIcon() {
        answer = ActivityResponse(
            listOf(
                item("a1", "Ayesha Khan sent you Payment terms", "2026-11-21T09:00:00Z", kind = "shared_with_you")
                    .copy(actor = ActorDto(id = 7, name = "Ayesha Khan", avatarColor = 3)),
                item(
                    "a2",
                    "Unusual use",
                    "2026-11-21T08:00:00Z",
                    kind = "unusual_usage"
                ).copy(actor = ActorDto(id = 8, name = "Farah Malik", avatarColor = 4)),
                item(
                    "a3",
                    "We need another receipt",
                    "2026-11-21T07:00:00Z",
                    kind = "upgrade_receipt_requested"
                ).copy(actor = ActorDto(id = 9, name = "Sara Ali")),
                item("a4", "Transcript ready", "2026-11-21T06:00:00Z")
            )
        )
        show()
        await("Ayesha Khan sent you")
        assertEquals(listOf(ActorLook(picture = false, initials = "AK", colour = 3)), actorLooks())
    }

    @Test
    fun anUpgradeRequestShowsThePersonAndAnUrduNamesInitialsStayApart() {
        answer = ActivityResponse(
            listOf(
                item(
                    "a1",
                    "عائشہ خان asked for Pro",
                    "2026-11-21T09:00:00Z",
                    kind = "upgrade_requested"
                ).copy(actor = ActorDto(id = 13, name = "عائشہ خان"))
            )
        )
        show(language = EchoLanguage.Urdu)
        await("asked for Pro")
        // No avatar_color: the id modulo 8 (13 → 5), as the server's own rule.
        assertEquals(listOf(ActorLook(picture = false, initials = "ع\u200Cخ", colour = 5)), actorLooks())
    }

    @Test
    fun noActorOrAnOlderServerKeepsTheKindsIconAndTheRowReadsAsBefore() {
        answer =
            ActivityResponse(listOf(item("a1", "Ayesha Khan sent you Payment terms", "2026-11-21T09:00:00Z", kind = "shared_with_you")))
        show()
        await("Ayesha Khan sent you")
        assertEquals(emptyList<ActorLook>(), actorLooks())
    }

    @Test
    fun talkBackHearsTheRowNotTheInitials() {
        answer = ActivityResponse(
            listOf(
                item(
                    "a1",
                    "Ayesha Khan sent you Payment terms",
                    "2026-11-21T09:00:00Z",
                    kind = "shared_with_you"
                ).copy(actor = ActorDto(id = 7, name = "Ayesha Khan"))
            )
        )
        show()
        await("Ayesha Khan sent you")
        // What TalkBack reads is the merged tree: the avatar's initials are cleared from it (the unmerged tree a test
        // can ask for keeps replaced nodes for debugging, which TalkBack never sees).
        compose.onAllNodesWithText("AK", substring = false).assertCountEquals(0)
        val row = compose.onNode(hasText("Ayesha Khan sent you", substring = true) and hasClickAction()).fetchSemanticsNode()
        assertTrue("the row's words have no initials", !hasText("AK").matches(row))
        assertEquals(1, compose.onAllNodes(hasTestTag(ACTOR_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes().size)
    }
}
