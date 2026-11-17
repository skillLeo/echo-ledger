package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.CallsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** The Settings v2 pages that replace classic ones, light and dark, English and Urdu, at the S25's size. */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class SettingsPagesShotsTest {
    @get:Rule
    val compose = createComposeRule()

    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")
    private var language by mutableStateOf(EchoLanguage.English)
    private var dark by mutableStateOf(false)

    private fun shoot(name: String, page: @androidx.compose.runtime.Composable () -> Unit) {
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) { page() }
            }
        }
        for ((l, d) in listOf(EchoLanguage.English to false, EchoLanguage.Urdu to false, EchoLanguage.English to true)) {
            language = l
            dark = d
            compose.waitForIdle()
            compose.onRoot().captureRoboImage(
                File(out, "$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png")
            )
        }
    }

    @Test
    fun theImportDialogSpeaksUrdu() {
        val file = com.echoledger.mobile.calls.PhoneAppRecordings.MediaFile(1, "", "", 0, 252_000)
        language = EchoLanguage.Urdu
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                com.echoledger.mobile.ui.ImportRecordingDialog(
                    com.echoledger.mobile.calls.PhoneAppRecordings.Candidate(1, 0),
                    "08:00",
                    { _, _ -> file },
                    onDismiss = {}
                ) {}
            }
        }
        compose.waitForIdle()
        for (words in listOf("یہ ریکارڈنگ لائیں؟", "ریکارڈنگ", "چلائیں", "لائیں", "منسوخ کریں")) {
            compose.onNodeWithText(words, substring = false).assertExists()
        }
        compose.onNodeWithText("08:00", substring = true).assertExists()
        compose.onNodeWithContentDescription("08:00 کی کال کی ریکارڈنگ چلائیں").assertExists()
        compose.onNodeWithText("Import this recording?").assertDoesNotExist()
        compose.onRoot().captureRoboImage(File(out, "settings-calls-import-dialog-light-ur.png"))
    }

    @Test
    @Config(qualifiers = "ur-w411dp-h891dp-420dpi")
    fun todaysPageShowsTheImportDialogInEnglishOnAnUrduPhone() {
        val file = com.echoledger.mobile.calls.PhoneAppRecordings.MediaFile(1, "", "", 0, 252_000)
        compose.setContent {
            com.echoledger.mobile.ui.ClassicImportRecordingDialog(
                com.echoledger.mobile.calls.PhoneAppRecordings.Candidate(1, 0),
                "08:00",
                { _, _ -> file },
                onDismiss = {}
            ) {}
        }
        compose.waitForIdle()
        for (words in listOf(
            "Import this recording?",
            "Recording",
            "Play",
            "Import",
            "Cancel"
        )) {
            compose.onNodeWithText(words).assertExists()
        }
        compose.onNodeWithText("یہ ریکارڈنگ لائیں؟").assertDoesNotExist()
    }

    // TODO(phone core): until lastNote() returns a code, this list is the core's sentences copied here; once the codes
    // land, check them instead.
    @Test
    fun everyLastCallNoteOfTheCoreHasACode() {
        val core = com.echoledger.mobile.calls.PhoneAppRecordings
        val sentences = listOf(
            "Imported the Phone app's recording (12 s).",
            "Imported the Phone app's recording (12 s); EchoLedger's own has one side only.",
            "Imported the Phone app's recording (12 s), as you chose.",
            "Waiting: EchoLedger is still saving its own recording of this call.",
            "EchoLedger's own recording kept (both sides apart); the Phone app's copy stays in its folder.",
            "EchoLedger's own recording kept (both voices on one track); the Phone app's copy stays in its folder.",
            "EchoLedger heard both sides itself; the Phone app's copy stays in its folder.",
            "Not imported, shorter than 10 s (Skip calls shorter than).",
            "Not imported, shorter than 3 s.",
            "Not imported: EchoLedger's recording of this call was discarded.",
            "Not imported: auto-record was off.",
            "Not imported: Record phone calls was not set to Always.",
            "The Phone app's copy did not come: EchoLedger's own recording (your voice only) is kept as usual.",
            "Not imported: it is no longer waiting.",
            "Not imported: the file is gone.",
            "Not imported: the file no longer fits the call.",
            "Not imported yet: the file could not be read.",
            "Not imported: the file changed after the call.",
            "Not imported yet: the Phone app is still saving the file.",
            "Not imported: no permission to read audio files or the call log.",
            "The Phone app's copy took the place of EchoLedger's own (your voice only).",
            core.UNSUPPORTED.replaceFirstChar { it.uppercase() }
        ) + listOf(
            com.echoledger.mobile.calls.PhoneAppRecordings.Trust.UNKNOWN_MAKER,
            com.echoledger.mobile.calls.PhoneAppRecordings.Trust.NOT_THE_PHONE_APP,
            com.echoledger.mobile.calls.PhoneAppRecordings.Trust.TOO_LARGE
        ).flatMap {
            listOf(core.note(it), core.note(it, "com.other.app"))
        }.filter { it.isNotBlank() }.map { it.replaceFirstChar { c -> c.uppercase() } }
        val missing = sentences.filter { com.echoledger.mobile.ui.v2.settings.LastCallNote.code(it) == null }
        org.junit.Assert.assertEquals(emptyList<String>(), missing)
        val other = com.echoledger.mobile.ui.v2.settings.LastCallNote.code(
            core.note(com.echoledger.mobile.calls.PhoneAppRecordings.Trust.NOT_THE_PHONE_APP, "com.other.app")
        )
        org.junit.Assert.assertEquals(listOf<Any>("com.other.app"), other?.args)
        org.junit.Assert.assertEquals(null, com.echoledger.mobile.ui.v2.settings.LastCallNote.code("Not imported: a reason of its own."))
    }

    @Test
    fun notifications() = shoot("settings-notifications") {
        com.echoledger.mobile.ui.v2.settings.NotificationsPage(AppSettings(quietHours = true), {}, highlight = null, onBack = {})
    }

    @Test
    fun calls() = shoot("settings-calls") {
        CallsPage(AppSettings(autoRecordArmed = true, extraCallApps = listOf("com.example.voip")), {
        }, highlight = null, onBack = {}, manufacturer = "samsung")
    }
}
