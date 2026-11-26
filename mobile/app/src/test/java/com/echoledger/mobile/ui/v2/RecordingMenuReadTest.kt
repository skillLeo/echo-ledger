package com.echoledger.mobile.ui.v2

import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.v2.library.ExportFormat
import com.echoledger.mobile.ui.v2.library.RecordingMenuActions
import com.echoledger.mobile.ui.v2.library.RecordingMenuContent
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Contract 2.0.0-t on the phone: a recording's menu has Mark as unread (Mark as read while it is unread). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RecordingMenuReadTest {
    @get:Rule
    val compose = createComposeRule()

    private var asked: Boolean? = null
    private val actions = object : RecordingMenuActions {
        override fun rename() = Unit
        override fun move() = Unit
        override fun labels() = Unit
        override fun pin(on: Boolean) = Unit
        override fun keep(on: Boolean) = Unit
        override fun important(on: Boolean) = Unit
        override fun retranscribe() = Unit
        override fun skipSilences(on: Boolean) = Unit
        override fun export(format: ExportFormat) = Unit
        override fun delete() = Unit
        override fun read(on: Boolean) {
            asked = on
        }
    }

    private fun show(opened: Boolean?) = compose.setContent {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            RecordingMenuContent(
                LibraryItem(
                    id = "01JA",
                    title = "Call",
                    startedAt = "2026-10-20T10:00:00+00:00",
                    updatedAt = "2026-10-20T10:00:00+00:00",
                    opened = opened
                ),
                hasAudio = true,
                skipSilence = false,
                canExport = true,
                exporting = false,
                onExporting = {},
                actions = actions,
                onDone = {}
            )
        }
    }

    @Test
    fun `a read recording offers Mark as unread`() {
        show(opened = true)
        compose.onNodeWithText("Mark as unread").performClick()
        assertEquals(false, asked)
    }

    @Test
    fun `an unread recording offers Mark as read`() {
        show(opened = false)
        compose.onNodeWithText("Mark as read").performClick()
        assertEquals(true, asked)
    }
}
