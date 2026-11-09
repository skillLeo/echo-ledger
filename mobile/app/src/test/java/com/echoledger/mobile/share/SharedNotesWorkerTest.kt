package com.echoledger.mobile.share

import android.Manifest
import android.app.NotificationManager
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.core.RowProgress
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** v1.6 P5.4: Continue in background — a shared note still on its way keeps a quiet notification that opens it. */
class SharedNotesWorkerTest : AppTest() {
    @After
    fun forget() = RowProgress.doneSending("L1")

    @Test
    fun `a note still uploading gets a quiet notification with its percent that opens the note`() {
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS)
        runBlocking { app.pairLocally(server) }
        app.storeRecording(id = "L1")
        app.graph.recordings.update("L1") { it.copy(state = "uploading") }
        SharedNotes.remember(app, listOf("L1"))
        RowProgress.sent("L1", 2, 5)

        val future = TestListenableWorkerBuilder<SharedNotesWorker>(app)
            .setInputData(workDataOf("ids" to arrayOf("L1"), "round" to 0))
            .build()
            .startWork()
        pumpUntil("the worker") { future.isDone }

        val posted = shadowOf(app.getSystemService(NotificationManager::class.java)).allNotifications
        val note = posted.single { it.extras.getString("android.title") == "Shared voice note" }
        assertEquals("Uploading 40%", note.extras.getCharSequence("android.text").toString())
        assertEquals(40, note.extras.getInt("android.progress"))
        val opens = shadowOf(note.contentIntent).savedIntent
        assertEquals(MainActivity.OPEN_SHARED_PREFIX + "L1", opens.getStringExtra(MainActivity.EXTRA_OPEN))
        assertTrue("silent", note.flags and android.app.Notification.FLAG_ONLY_ALERT_ONCE != 0)
    }
}
