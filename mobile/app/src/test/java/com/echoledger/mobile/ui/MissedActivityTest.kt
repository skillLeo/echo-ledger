package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHasNoClickAction
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import com.echoledger.mobile.calls.MissedFix
import com.echoledger.mobile.calls.MissedItem
import com.echoledger.mobile.calls.MissedReason
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import java.time.LocalDate
import java.time.ZoneId
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Missed recordings in Activity (v1.8 0.2): this phone's own with the reason in plain words and a fix button, counted in
 * the badge and the Needs action group like failed transcripts, and the server's copy never shown twice.
 */
class MissedActivityTest : ScreenTest() {
    private val today = LocalDate.of(2026, 11, 16)
    private val nine = today.atTime(9, 12).atZone(ZoneId.systemDefault()).toInstant().toEpochMilli()

    private fun missed(ref: String, reason: MissedReason, app: String = "whatsapp", read: Boolean = false, serverId: String? = null) =
        MissedItem(ref, app, nine, 312, reason.wire, reason.detail, nine, read = read, sent = serverId != null, serverId = serverId)

    private fun serverMissed(id: String, rowId: String, read: Boolean = false) = ActivityItemDto(
        id,
        "missed_recording",
        "Missed recording",
        "Zoom call · 31:02 · The call was not detected",
        null,
        read,
        "2026-11-16T08:00:00+00:00",
        reason = "detection",
        missedRecordingId = rowId
    )

    private val fixes = mutableListOf<MissedFix>()
    private val reads = mutableListOf<String>()
    private val dismissed = mutableListOf<String>()
    private val serverReads = mutableListOf<List<String>?>()
    private var badge = -1

    private fun showActivity(missed: List<MissedItem>, server: List<ActivityItemDto> = emptyList()) = show {
        ActivityScreen(
            load = { ActivityResponse(server, server.count { !it.read }) },
            markRead = { ids ->
                serverReads += ids
                0
            },
            onOpen = {},
            onUnread = { badge = it },
            today = today,
            missed = missed,
            onFix = { fixes += it },
            onMissedRead = { reads += it },
            onMissedDismiss = { dismissed += it }
        )
    }

    @Test
    fun `a missed recording needs action, says why in plain words, and its button fixes it`() {
        showActivity(listOf(missed("r1", MissedReason.HELPER_NOT_ARMED)))
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }

        compose.onNodeWithText("Missed recording").assertExists()
        compose.onNodeWithText("WhatsApp call · 5:12").assertExists()
        compose.onNodeWithText("The helper was not connected when the call started.").assertExists()
        compose.onNodeWithText("Start the helper").performClick()

        assertEquals(listOf(MissedFix.START_HELPER), fixes)
        assertEquals("looked at: it leaves Needs action", listOf("r1"), reads)
    }

    @Test
    fun `each reason has its own fix button`() {
        val all = MissedReason.entries.mapIndexed { i, reason ->
            missed("r$i", reason, app = listOf("whatsapp", "zoom", "phone_call")[i % 3])
        }
        showActivity(all)
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }
        compose.onAllNodesWithText("Missed recording").assertCountEquals(MissedReason.entries.size)
        listOf(
            "Start the helper" to 1,
            "Check permissions" to 1,
            "Test a call" to 2,
            "Check call capture" to 2,
            "See plans" to 1
        ).forEach { (label, count) ->
            compose.onAllNodesWithText(label).assertCountEquals(count)
        }
        compose.onAllNodesWithText("See plans")[0].performClick()
        compose.onAllNodesWithText("Test a call")[0].performClick()
        assertEquals(listOf(MissedFix.PLANS, MissedFix.TEST), fixes)
    }

    @Test
    fun `the server's copy of this phone's report is shown once, another device's is shown as it is`() {
        val mine = missed("r1", MissedReason.DETECTION, serverId = "row-1")
        showActivity(listOf(mine), server = listOf(serverMissed("a1", "row-1"), serverMissed("a2", "row-from-the-laptop")))
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }

        compose.onAllNodesWithText("Missed recording").assertCountEquals(2)
        compose.onNodeWithText("Zoom call · 31:02 · The call was not detected").assertExists()
        assertEquals(
            "the server's other report and this phone's own, each once",
            2,
            ActivityBadge.needs(listOf(serverMissed("a1", "row-1"), serverMissed("a2", "x")), listOf(mine))
        )
        assertEquals("the screen reports the server's part", 1, badge)
    }

    @Test
    fun `swiping one away dismisses it and reads the server's copy too`() {
        val mine = missed("r1", MissedReason.MIC_BUSY, serverId = "row-1")
        showActivity(listOf(mine), server = listOf(serverMissed("a1", "row-1")))
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }

        compose.onNodeWithText("Missed recording").performTouchInput { swipeLeft() }
        pumpUntil("dismissed") { dismissed.isNotEmpty() }
        assertEquals(listOf("r1"), dismissed)
        pumpUntil("the server's copy read") { serverReads.isNotEmpty() }
        assertEquals(listOf(listOf("a1")), serverReads)
    }

    @Test
    fun `one looked at goes to Today with its button, and is no longer counted`() {
        showActivity(listOf(missed("r1", MissedReason.PERMISSION, read = true)))
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Missed recording").assertExists() }.isSuccess }
        compose.onAllNodesWithText("Needs action").assertCountEquals(0)
        compose.onNodeWithText("Check permissions").assertExists()
        assertEquals(0, ActivityBadge.needs(emptyList(), listOf(missed("r1", MissedReason.PERMISSION, read = true))))
    }

    @Test
    fun `one already read takes no tap, an unread one does, and both keep their button`() {
        showActivity(listOf(missed("r1", MissedReason.PERMISSION, read = true)))
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Missed recording").assertExists() }.isSuccess }
        compose.onNodeWithText("Missed recording").assertHasNoClickAction()
        compose.onNodeWithText("Check permissions").assertHasClickAction()
    }

    @Test
    fun `an unread one takes a tap that marks it read`() {
        showActivity(listOf(missed("r1", MissedReason.PERMISSION)))
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }
        compose.onNodeWithText("Missed recording").assertHasClickAction().performClick()
        assertEquals(listOf("r1"), reads)
    }

    @Test
    fun `auto-record that was not running is turned on from the item`() {
        val off = missed(
            "r1",
            MissedReason.HELPER_NOT_ARMED
        ).copy(detail = "Auto-record was not running when the call started.", fix = MissedFix.TURN_ON.name)
        showActivity(listOf(off))
        pumpUntil("the list") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }
        compose.onNodeWithText("Auto-record was not running when the call started.").assertExists()
        compose.onNodeWithText("Turn on").performClick()
        assertEquals(listOf(MissedFix.TURN_ON), fixes)
    }

    @Test
    fun `the badge counts unread missed recordings like failed transcripts, and dismissed ones not at all`() {
        val failed = ActivityItemDto("f1", "transcription_failed", "Transcription failed", "x", "s1")
        val list = listOf(
            missed("a", MissedReason.CRASH),
            missed("b", MissedReason.CRASH, read = true),
            missed("c", MissedReason.CRASH).copy(dismissed = true)
        )
        assertEquals(2, ActivityBadge.needs(listOf(failed), list))
        assertEquals(1, ActivityBadge.local(list))
        assertTrue("a server missed recording needs action", serverMissed("x", "y").isProblem())
        assertEquals(NotifyCategory.READINESS, NotifyCategory.forActivity("missed_recording"))
    }
}
