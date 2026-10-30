package com.echoledger.mobile.ui

import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.Activity
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.notify.ActivityWorker
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The activity centre on the phone (B18): quiet hours, what is new, the API, the list and the settings. */
class ActivityCentreTest : ScreenTest() {
    private fun item(id: String, read: Boolean = false, session: String? = "s-$id") =
        ActivityItemDto(id, "transcript_ready", "Transcript ready", "Call $id", session, read)

    private fun settings() = runBlocking { app.graph.settings.current() }

    @Test
    fun `times and quiet hours are read strictly and wrap past midnight`() {
        assertEquals(1350, Activity.parseHhmm("22:30"))
        listOf("24:00", "7:00", "07:60", "0700", "").forEach { assertNull(it, Activity.parseHhmm(it)) }
        assertTrue(Activity.inQuietHours(true, "22:00", "07:00", 23 * 60))
        assertTrue(Activity.inQuietHours(true, "22:00", "07:00", 6 * 60 + 59))
        assertFalse(Activity.inQuietHours(true, "22:00", "07:00", 7 * 60))
        assertTrue(Activity.inQuietHours(true, "12:00", "13:00", 12 * 60 + 30))
        assertTrue(Activity.inQuietHours(true, "09:00", "09:00", 3 * 60))
        assertFalse(Activity.inQuietHours(false, "22:00", "07:00", 23 * 60))
    }

    @Test
    fun `the first run only learns, later runs show new unread items once, oldest first`() {
        val store = Activity.SeenStore(File(app.noBackupFilesDir, "seen-test.json"))
        assertTrue(store.fresh(listOf(item("old"))).isEmpty())
        val fresh = store.fresh(listOf(item("c"), item("b", read = true), item("a"), item("old")))
        assertEquals(listOf("a", "c"), fresh.map { it.id })
        assertTrue(store.fresh(listOf(item("c"))).isEmpty())
        assertEquals(listOf("EchoLedger" to "4 new notifications"), Activity.notificationText((1..4).map { item("$it") }))
        assertEquals(listOf("Transcript ready" to "Call x"), Activity.notificationText(listOf(item("x"))))
    }

    @Test
    fun `the activity list and read marks go to the server`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/activity",
            FakeServer.json(
                200,
                """{"data":[{"id":"n1","kind":"device_paired","title":"Pixel paired","body":"x","read":false}],"unread_count":1}"""
            )
        )
        server.on("POST", "/api/activity/read", FakeServer.json(200, """{"unread_count":0}"""))

        assertEquals(1, runBlocking { vm.activity() }!!.unreadCount)
        assertTrue(server.requestsTo("GET", "/api/activity").single().url.query!!.contains("limit=30"))
        assertEquals(0, runBlocking { vm.markActivityRead(listOf("n1")) })
        assertEquals("""{"ids":["n1"]}""", server.requestsTo("POST", "/api/activity/read").single().bodyText())
        assertEquals(0, runBlocking { vm.markActivityRead() })
        assertEquals("{}", server.requestsTo("POST", "/api/activity/read").last().bodyText())
        ActivityWorker.schedule(app) // enqueues without crashing
    }

    @Test
    fun `tapping an item marks it read and opens its recording`() {
        val opened = mutableListOf<String>()
        val marked = mutableListOf<List<String>?>()
        var unread = -1
        show {
            ActivityScreen(
                load = { ActivityResponse(listOf(item("n2"), item("n1", read = true, session = null)), 1) },
                markRead = {
                    marked += it
                    0
                },
                onOpen = { opened += it },
                onUnread = { unread = it }
            )
        }
        pumpUntil("the list to load") { runCatching { compose.onNodeWithText("Call n2").assertExists() }.isSuccess }
        assertEquals(1, unread)
        compose.onNodeWithText("Mark all as read").assertExists()
        compose.onNodeWithText("Call n2").performClick()
        pumpUntil("the recording to open") { opened == listOf("s-n2") }
        assertEquals(listOf(listOf("n2")), marked)
        assertEquals(0, unread)
        compose.onNodeWithText("Mark all as read").assertDoesNotExist()
    }

    @Test
    fun `calls waiting for Keep or Discard and problems come first, and an empty list says all is done`() {
        var answer by mutableStateOf(
            ActivityResponse(
                listOf(item("ok"), ActivityItemDto("bad", "transcription_failed", "Transcription failed", "Call bad", "s-bad")),
                1
            )
        )
        var waiting by mutableStateOf(listOf(app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 65_000)))
        var openedWaiting = 0
        var round by mutableStateOf(0)
        show {
            key(round) {
                ActivityScreen(load = {
                    answer
                }, markRead = { 0 }, onOpen = {}, onUnread = {}, waiting = waiting, onOpenWaiting = { openedWaiting++ })
            }
        }
        pumpUntil("the list to load") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }
        compose.onNodeWithText("Keep or discard?").performClick()
        assertEquals(1, openedWaiting)
        compose.onNodeWithText("Call bad").assertExists()

        answer = ActivityResponse(emptyList(), 0)
        waiting = emptyList()
        round++
        pumpUntil("the empty state") { runCatching { compose.onNodeWithText("You're all caught up").assertExists() }.isSuccess }
    }

    @Test
    fun `notifications and quiet hours are set in Settings`() {
        show { NotificationsSection(vm.settings.collectAsState().value) { vm.updateSettings(it) } }
        compose.onNodeWithContentDescription("Show notifications").performClick()
        pumpUntil("notifications off") { !settings().activityNotifications }
        compose.onNodeWithContentDescription("Quiet hours").performClick()
        pumpUntil("quiet hours on") { settings().quietHours }
        compose.onNodeWithText("From").performTextClearance()
        compose.onNodeWithText("From").performTextInput("23:15")
        pumpUntil("the start to be saved") { settings().quietFrom == "23:15" }
        compose.onNodeWithText("To").performTextClearance()
        compose.onNodeWithText("To").performTextInput("6")
        assertEquals("07:00", settings().quietTo)
    }
}
