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

    /** Every kind of last-call note has its own words, with what goes in them, in English and in Urdu. */
    @Test
    fun everyLastCallNoteKindHasWordsInBothLanguages() {
        val en = V2Testing.resources(com.echoledger.mobile.ui.v2.theme.EchoLanguage.English)
        val ur = V2Testing.resources(com.echoledger.mobile.ui.v2.theme.EchoLanguage.Urdu)
        val kinds = com.echoledger.mobile.calls.PhoneAppNote.Kind.entries
        val seen = mutableSetOf<Int>()
        for (kind in kinds) {
            val note = com.echoledger.mobile.calls.PhoneAppNote(kind, seconds = 42, app = "WhatsApp", owner = "com.other.app")
            val code = com.echoledger.mobile.ui.v2.settings.LastCallNote.code(note)
            val english = en.getString(code.words, *code.args.toTypedArray())
            val urdu = ur.getString(code.words, *code.args.toTypedArray())
            org.junit.Assert.assertTrue(kind.wire, english.isNotBlank() && urdu.isNotBlank() && english != urdu)
            org.junit.Assert.assertFalse(kind.wire, english.contains("%") || urdu.contains("%"))
            // What goes in the words is said in both languages.
            for (arg in code.args) {
                org.junit.Assert.assertTrue("${kind.wire} $arg", english.contains(arg.toString()) && urdu.contains(arg.toString()))
                // A name (an app, another app's package) keeps its own left-to-right order inside the Urdu line.
                if (arg is String) org.junit.Assert.assertTrue("${kind.wire} isolates $arg", urdu.contains("\u2066$arg\u2069"))
            }
            // English words are the capture log's sentence, as before.
            org.junit.Assert.assertEquals(kind.wire, note.english.replaceFirstChar { it.uppercase() }, english)
            seen += code.words
        }
        org.junit.Assert.assertEquals("one sentence per kind", kinds.size, seen.size)
        // Without the app or another app's package, the general words.
        val bare = com.echoledger.mobile.ui.v2.settings.LastCallNote.code(
            com.echoledger.mobile.calls.PhoneAppNote(com.echoledger.mobile.calls.PhoneAppNote.Kind.OTHER_APP)
        )
        org.junit.Assert.assertEquals(com.echoledger.mobile.R.string.v2_calls_note_other_app, bare.words)
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
