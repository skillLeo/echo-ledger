package com.echoledger.mobile.dictation

import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.net.SttEvent
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.DictationSettingsPage
import com.echoledger.mobile.ui.KeyboardKeys
import com.echoledger.mobile.ui.VoiceKeyboard
import com.echoledger.mobile.ui.keyboardStatus
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Dictation 2.0 on the phone (B14): the rules, the history, the dictation token, the voice keyboard and its settings. */
class DictationTest : ScreenTest() {
    private fun r(from: String, to: String) = Replacement(from, to)

    private fun settings() = runBlocking { app.graph.settings.current() }

    // ---- rules (the same cases as the desktop) ----------------------------------------------------------------------

    @Test
    fun `spoken commands break lines and capitalise after them`() {
        assertEquals(
            "Hello team.\n\nThanks for the call.",
            DictationText.apply("Hello team. New paragraph. thanks for the call.", emptyList(), emptyList())
        )
        assertEquals("First item\nSecond item", DictationText.apply("First item new line second item", emptyList(), emptyList()))
        assertEquals("The newline character", DictationText.apply("The newline character", emptyList(), emptyList()))
    }

    @Test
    fun `the dictionary fixes spelling and replacements rewrite whole words only`() {
        val dictionary = listOf(r("echo ledger", "EchoLedger"), r("farah", "Farah"), r("asap", "as soon as possible"))
        assertEquals(
            "Send it to EchoLedger and Farah as soon as possible.",
            DictationText.apply("Send it to Echo Ledger and farah asap.", dictionary, emptyList())
        )
        assertEquals("Farahnaz called.", DictationText.apply("Farahnaz called.", dictionary, emptyList()))
        assertEquals("قیمت درست ہے", DictationText.apply("قیمت ٹھیک ہے", listOf(r("ٹھیک", "درست")), emptyList()))
    }

    @Test
    fun `snippets insert their text`() {
        val snippets = listOf(Snippet("insert signature", "Best regards,\nSam Khan"))
        assertEquals("Thanks again. Best regards,\nSam Khan", DictationText.apply("Thanks again. Insert signature.", emptyList(), snippets))
    }

    @Test
    fun `clean-up can be switched off`() {
        val turns = listOf("um so the meeting is at three", "new line see you")
        assertEquals("So the meeting is at three\nSee you.", DictationText.compose(turns, true, emptyList(), emptyList()))
        assertEquals("um so the meeting is at three\nSee you", DictationText.compose(turns, false, emptyList(), emptyList()))
        assertTrue(DictationText.compose(turns, false, listOf(r("three", "3 pm")), emptyList()).contains("at 3 pm"))
        assertEquals(
            "I think I'm fine, version 2.1 works.",
            DictationText.professionalize(listOf("i think i'm fine ,", "version 2.1 works"))
        )
    }

    @Test
    fun `only usable entries are saved`() {
        val cleaned = DictationText.cleanReplacements(
            listOf(r(" asap ", "as soon as possible"), r("ASAP", "x"), r("", "y"), r("a".repeat(101), "z"))
        )
        assertEquals(listOf(r("asap", "as soon as possible")), cleaned)
        val snippets = DictationText.cleanSnippets(listOf(Snippet("sig", " Regards "), Snippet("SIG", "x"), Snippet("empty", "  ")))
        assertEquals(listOf(Snippet("sig", "Regards")), snippets)
    }

    @Test
    fun `inserted text gets a space between words but not after a break`() {
        assertEquals(" Hello.", DictationText.forInsertion("Hi", "Hello."))
        assertEquals("Hello.", DictationText.forInsertion("Hi ", "Hello."))
        assertEquals("Hello.", DictationText.forInsertion("Hi\n", "Hello."))
        assertEquals("Hello.", DictationText.forInsertion("", "Hello."))
        assertEquals("Hello.", DictationText.forInsertion(null, "Hello."))
        assertEquals(", then", DictationText.forInsertion("Hi", ", then"))
    }

    @Test
    fun `the phrase so far joins finished turns and the one in progress`() {
        val turns = DictationTurns()
        turns.on(SttEvent.Partial("send the inv"))
        assertEquals("send the inv", turns.preview())
        turns.on(SttEvent.Final("Send the invoice.", 0, 900))
        turns.on(SttEvent.Partial("by friday"))
        assertEquals("Send the invoice. by friday", turns.preview())
        turns.on(SttEvent.Final(" ", null, null))
        assertEquals(listOf("Send the invoice."), turns.finished())
    }

    @Test
    fun `settings saved before dictation existed load with the defaults`() {
        val old = ApiJson.decodeFromString(AppSettings.serializer(), """{"captionsEnabled":false}""")
        assertEquals("toggle", old.dictationMode)
        assertTrue(old.dictationCleanup && old.dictationSounds)
        assertTrue(old.dictationReplacements.isEmpty() && old.dictationSnippets.isEmpty())
    }

    // ---- history ----------------------------------------------------------------------------------------------------

    @Test
    fun `the history keeps the newest fifty on this phone and survives a restart`() {
        val file = File(app.noBackupFilesDir, "dictation_history.json")
        val history = DictationHistory(file)
        repeat(DictationHistory.LIMIT + 5) { history.add("2026-10-25T10:00:00Z", "note $it") }
        history.add("2026-10-25T11:00:00Z", "   ")

        val reloaded = DictationHistory(file).all()
        assertEquals(DictationHistory.LIMIT, reloaded.size)
        assertEquals("note ${DictationHistory.LIMIT + 4}", reloaded.first().text)
        history.clear()
        assertTrue(DictationHistory(file).all().isEmpty())
    }

    // ---- dictation token ----------------------------------------------------------------------------------------------

    @Test
    fun `a dictation token asks for the microphone only and never opens a session`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/stt/token", FakeServer.json(200, ServerFixtures.assemblyAiGrants()))

        val outcome = runBlocking { DictationTokens(app.graph.api()!!).fetch(null) {} }

        assertTrue(outcome is DictationTokens.Outcome.Token)
        val body = server.requestsTo("POST", "/api/stt/token").single().bodyText()
        assertTrue(body.contains("\"use_case\":\"dictation\"") && body.contains("[\"me\"]"))
        assertFalse(body.contains("session_id"))
        assertTrue(server.requestsTo("POST", "/api/sessions").isEmpty())
    }

    @Test
    fun `refusals become short keyboard messages and a deleted client is forgotten`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "POST",
            "/api/stt/token",
            FakeServer.json(403, """{"message":"Monthly transcription limit reached","code":"budget_exceeded"}""")
        )
        assertEquals(
            DictationTokens.Outcome.Refused(DictationTokens.BUDGET),
            runBlocking {
                DictationTokens(app.graph.api()!!).fetch(null) {}
            }
        )

        var calls = 0
        var cleared = false
        server.on("POST", "/api/stt/token") { _ ->
            if (++calls ==
                1
            ) {
                FakeServer.json(404, """{"message":"Client not found.","code":"client_not_found"}""")
            } else {
                FakeServer.json(200, ServerFixtures.assemblyAiGrants())
            }
        }
        val outcome = runBlocking { DictationTokens(app.graph.api()!!).fetch("01JCLIENT00000000000000000") { cleared = true } }
        assertTrue(outcome is DictationTokens.Outcome.Token && cleared)
    }

    @Test
    fun `the voice keyboard is offered to the system as an input method`() {
        val found = app.packageManager.queryIntentServices(Intent("android.view.InputMethod").setPackage(app.packageName), 0)
        val service = found.single().serviceInfo
        assertEquals(VoiceKeyboardService::class.java.name, service.name)
        assertEquals("android.permission.BIND_INPUT_METHOD", service.permission)
    }

    // ---- the keyboard ------------------------------------------------------------------------------------------------

    private class Keys : KeyboardKeys {
        val pressed = mutableListOf<String>()

        override fun start() {
            pressed += "start"
        }

        override fun finish() {
            pressed += "finish"
        }

        override fun space() {
            pressed += "space"
        }

        override fun backspace() {
            pressed += "backspace"
        }

        override fun enter() {
            pressed += "enter"
        }

        override fun switchKeyboard() {
            pressed += "switch"
        }

        override fun openApp() {
            pressed += "open"
        }
    }

    @Test
    fun `the status line says what is happening`() {
        assertEquals("Tap the microphone and speak", keyboardStatus(DictationUi(), holdToTalk = false))
        assertEquals("Hold the microphone while you speak", keyboardStatus(DictationUi(), holdToTalk = true))
        assertEquals("send it", keyboardStatus(DictationUi(DictationPhase.Listening, preview = "send it"), false))
        assertEquals("Listening… let go to insert", keyboardStatus(DictationUi(DictationPhase.Listening), true))
        assertEquals(DictationTokens.BUDGET, keyboardStatus(DictationUi(message = DictationTokens.BUDGET), false))
    }

    @Test
    fun `tap to start and tap again to insert, plus the editing keys`() {
        val keys = Keys()
        show { VoiceKeyboard(DictationUi(), holdToTalk = false, needsMic = false, keys = keys) }
        compose.onNodeWithContentDescription("Start dictation").performClick()
        listOf("Space", "Delete", "Enter", "Switch keyboard").forEach { compose.onNodeWithContentDescription(it).performClick() }
        assertEquals(listOf("start", "space", "backspace", "enter", "switch"), keys.pressed)
    }

    @Test
    fun `while listening the microphone stops and inserts`() {
        val keys = Keys()
        show {
            VoiceKeyboard(
                DictationUi(DictationPhase.Listening, preview = "send the invoice", level = 0.1f),
                holdToTalk = false,
                needsMic = false,
                keys = keys
            )
        }
        compose.onNodeWithText("send the invoice").assertExists()
        compose.onNodeWithContentDescription("Stop and insert").performClick()
        assertEquals(listOf("finish"), keys.pressed)
    }

    @Test
    fun `hold to talk starts on press and inserts on release`() {
        val keys = Keys()
        show { VoiceKeyboard(DictationUi(), holdToTalk = true, needsMic = false, keys = keys) }
        compose.onNodeWithContentDescription("Hold to dictate").performTouchInput {
            down(center)
            advanceEventTime(800)
            up()
        }
        compose.waitForIdle()
        assertEquals(listOf("start", "finish"), keys.pressed)
    }

    @Test
    fun `without the microphone permission the keyboard sends you to the app`() {
        val keys = Keys()
        show { VoiceKeyboard(DictationUi(), holdToTalk = false, needsMic = true, keys = keys) }
        compose.onNodeWithText("Allow the microphone in EchoLedger").performClick()
        compose.onNodeWithContentDescription("Start dictation").performClick()
        assertEquals(listOf("open"), keys.pressed)
    }

    // ---- settings ----------------------------------------------------------------------------------------------------

    @Test
    fun `dictation settings save the mode, switches, words and snippets`() {
        val history = DictationHistory(File(app.noBackupFilesDir, "dictation_history.json"))
        show { DictationSettingsPage(vm, vm.settings.collectAsState().value, history) {} }

        compose.onNodeWithText("Hold to talk").performClick()
        pumpUntil("the mode to be saved") { settings().dictationMode == "push_to_talk" }
        compose.onNodeWithContentDescription("Clean up the text").performClick()
        pumpUntil("clean-up to be off") { !settings().dictationCleanup }
        compose.onNodeWithContentDescription("Start and stop sounds").performClick()
        pumpUntil("sounds to be off") { !settings().dictationSounds }

        compose.onNodeWithText("When I say").performTextInput(" asap ")
        compose.onNodeWithText("Write").performTextInput("as soon as possible")
        compose.onNodeWithText("Add replacement").performClick()
        pumpUntil("the replacement to be saved") { settings().dictationReplacements == listOf(r("asap", "as soon as possible")) }
        compose.onNodeWithText("asap → as soon as possible").assertExists()

        compose.onNodeWithText("Phrase").performTextInput("insert signature")
        compose.onNodeWithText("Add snippet").assertIsNotEnabled()
        compose.onNodeWithText("Text to insert").performTextInput("Best regards")
        compose.onNodeWithText("Add snippet").performClick()
        pumpUntil("the snippet to be saved") { settings().dictationSnippets == listOf(Snippet("insert signature", "Best regards")) }

        compose.onNodeWithContentDescription("Remove asap").performClick()
        pumpUntil("the replacement to be removed") { settings().dictationReplacements.isEmpty() }
    }

    @Test
    fun `recent dictations can be copied and cleared`() {
        val history = DictationHistory(File(app.noBackupFilesDir, "dictation_history.json"))
        history.add("2026-10-25T09:00:00Z", "Call Farah.")
        history.add("2026-10-25T10:00:00Z", "Send the invoice.")
        show { DictationSettingsPage(vm, vm.settings.collectAsState().value, history) {} }

        compose.onNodeWithText("Send the invoice.").assertExists()
        compose.onNodeWithText("Clear history").assertExists()
        val clipboard = app.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        compose.onAllCopyButtons().first().performClick()
        assertEquals("Send the invoice.", clipboard.primaryClip!!.getItemAt(0).text.toString())
        compose.onNodeWithText("Copied").assertExists()
        compose.onNodeWithText("Clear history").performClick()
        compose.onNodeWithText("Nothing dictated yet.").assertExists()
        assertTrue(history.all().isEmpty())
    }

    private fun androidx.compose.ui.test.junit4.ComposeContentTestRule.onAllCopyButtons() =
        onAllNodes(androidx.compose.ui.test.hasText("Copy")).fetchSemanticsNodes().indices.map {
            onAllNodes(androidx.compose.ui.test.hasText("Copy"))[it]
        }
}
