package com.echoledger.mobile.ui.v2

import android.content.Intent
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.account.AccountActions
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountExportDto
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.account.LocalAccountActions
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.DataActions
import com.echoledger.mobile.ui.v2.settings.DevicesPage
import com.echoledger.mobile.ui.v2.settings.YourDataPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Your data and Devices on the v2 components against classic's Account and Devices pages (docs/v2/audit/parity.csv
 * PH-SET-083 to 088): the same controls; Export my data (made on the server, Download the export, Try again); Sign out
 * everywhere (asks); Delete my account (the password, or the authenticator code, or the web page; the date while
 * pending); every signed-in device with when it was seen, Rename, Sign out (asks); the recent activity; in Urdu too.
 */
class AccountDataParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var which = "data"
    private var language = EchoLanguage.English
    private var account = AccountDto(name = "Ayesha Siddiqui", email = "ayesha@bilaltraders.pk", hasPassword = true)

    /** What each export call answers in turn; null is a check that failed (offline, the server busy). */
    private var exports = mutableListOf<AccountExportDto?>()
    private val calls = mutableListOf<String>()
    private val client by lazy { ApiClient(server.baseUrl, emptyList(), token = "7|eld_test") }

    private fun sample(name: String) = File("../../docs/api-samples/$name.json").readText()

    private val data = DataActions(
        account = { account },
        startExport = {
            calls += "export"
            exports.removeFirstOrNull()
        },
        exportStatus = { id ->
            calls += "status $id"
            exports.removeFirstOrNull()
        },
        signOutEverywhere = { calls += "everywhere" },
        // Through the account's own calls to the server, so a refused proof comes back as the server says it.
        deleteAccount = { password, code ->
            calls += "delete ${password ?: "-"} ${code ?: "-"}"
            AccountActions { client }.deleteAccount(password, code)
        }
    )

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = if (which == "data") "account" else "devices")
        } else {
            val client = ApiClient(server.baseUrl, emptyList(), token = "7|eld_test")
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(
                    LocalEchoToasts provides rememberEchoToastState(),
                    LocalAccountActions provides AccountActions { client }
                ) {
                    if (which == "data") {
                        YourDataPage(data, server.baseUrl, highlight = null, onBack = {})
                    } else {
                        DevicesPage(highlight = null, onBack = {})
                    }
                }
            }
        }
    }

    private fun press(name: String) {
        val node = compose.onAllNodes((hasContentDescription(name) or hasText(name)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun inDialog(words: String) {
        compose.onAllNodes(hasText(words) and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun shown(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun serve() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/account", FakeServer.json(200, sample("account")))
        server.on("GET", "/api/security", FakeServer.json(200, sample("security")))
        server.on("GET", "/api/account/security", FakeServer.json(200, sample("account_security")))
    }

    private fun controls(ready: String, todayReady: String = ready): Pair<Set<String>, Set<String>> {
        serve()
        page = 0
        showBoth()
        pumpUntil("today's page") { shown(ready) && shown(todayReady) }
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        page = 1
        pumpUntil("the v2 page") { shown(ready) }
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        return today to v2
    }

    private val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")

    @Test
    fun yourDataHasEveryControlOfTodaysAccountPage() {
        val (today, v2) = controls("Export my data", todayReady = "Sign-in & security")
        val same = listOf("button: Back", "button: Export my data", "button: Sign out everywhere…", "button: Delete my account…")
        // Today's Account page has no search; it opens Sign-in & security, the v2 page Your data opens from.
        val why = mapOf("button: Sign-in & security" to "v2's Your data opens from Sign-in & security, which Back leads to")
        assertEquals(
            emptyList<String>(),
            ParityKit.problems(
                today.associateWith {
                    0
                },
                v2.associateWith { 0 },
                same.associateWith { it },
                why
            )
        )
    }

    @Test
    fun theExportIsMadeLookedAtAgainEvenAfterAFailedCheckAndDownloaded() {
        // Making, then a check that fails, then ready: the failed check is tried again 5 s later.
        exports = mutableListOf(
            AccountExportDto("e1", "making"),
            null,
            AccountExportDto("e1", "ready", url = "https://echoledger.test/export/e1")
        )
        showBoth()
        press("Export my data")
        pumpUntil("making") { shown("Making the export") }
        // Looked at again after five seconds (that check fails), and again: ready.
        compose.mainClock.advanceTimeBy(6_000)
        pumpUntil("checked once") { calls == listOf("export", "status e1") }
        assertTrue(shown("Making the export"))
        compose.mainClock.advanceTimeBy(6_000)
        pumpUntil("ready") { shown("Download the export") }
        assertEquals(listOf("export", "status e1", "status e1"), calls)
        press("Download the export")
        val open = shadowOf(app).nextStartedActivity
        assertEquals(Intent.ACTION_VIEW, open.action)
        assertEquals("https://echoledger.test/export/e1", open.dataString)
    }

    @Test
    fun aFailedExportOffersTryAgain() {
        exports = mutableListOf(AccountExportDto("e2", "failed"))
        showBoth()
        press("Export my data")
        pumpUntil("failed") { shown("The export could not be made.") }
        press("Try again")
        pumpUntil("again") { shown("Export my data") }
    }

    @Test
    fun signOutEverywhereAsksFirst() {
        showBoth()
        press("Sign out everywhere…")
        assertTrue(shown("Every phone, computer and console session of this account is signed out, this phone too."))
        inDialog("Cancel")
        assertTrue(calls.isEmpty())
        press("Sign out everywhere…")
        inDialog("Sign out")
        pumpUntil("signed out") { calls == listOf("everywhere") }
    }

    private fun deleteWith(label: String, secret: String) {
        compose.onAllNodes(hasSetTextAction() and hasText(label, substring = true)).onFirst().performTextInput(secret)
        compose.waitForIdle()
        compose.onAllNodes(hasText(deleteWord) and hasClickAction()).onLast().performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private var deleteWord = "Delete"

    @Test
    fun deletingAsksForThePasswordOrTheCodeAndShowsTheDate() {
        server.on("DELETE", "/api/account", FakeServer.json(202, """{"deletes_at":"2026-11-29T10:00:00Z"}"""))
        showBoth()
        press("Delete my account…")
        pumpUntil("the sheet") { shown("Your recordings, transcripts, screens, files and projects are deleted after 7 days.") }
        deleteWith("Your password", "hunter22")
        pumpUntil("asked") { calls == listOf("delete hunter22 -") }
        pumpUntil("sent") { server.requestsTo("DELETE", "/api/account").isNotEmpty() }
        assertTrue(server.requestsTo("DELETE", "/api/account").single().bodyText().contains("\"password\":\"hunter22\""))
        pumpUntil("the date") { shown("This account will be deleted on 29 Nov 2026.") }
    }

    @Test
    fun aWrongPasswordIsSaidInTheSheetWhichStaysOpen() {
        server.on("DELETE", "/api/account", FakeServer.json(422, """{"message":"x","code":"wrong_password"}"""))
        showBoth()
        press("Delete my account…")
        pumpUntil("the sheet") { shown("Your password") }
        deleteWith("Your password", "nope")
        pumpUntil("said") { shown("That password isn't right.") }
        assertTrue("the sheet stays", shown("Your recordings, transcripts, screens, files and projects are deleted after 7 days."))
        assertTrue("no date", !shown("This account will be deleted on"))
        // Typing again clears it.
        compose.onAllNodes(hasSetTextAction() and hasText("Your password", substring = true)).onFirst().performTextInput("x")
        compose.waitForIdle()
        assertTrue(!shown("That password isn't right."))
    }

    @Test
    fun aWrongCodeIsSaidInTheSheetInUrdu() {
        server.on("DELETE", "/api/account", FakeServer.json(422, """{"message":"x","code":"invalid_otp"}"""))
        account = account.copy(twoFactor = true)
        language = EchoLanguage.Urdu
        deleteWord = "حذف کریں"
        showBoth()
        press("میرا اکاؤنٹ حذف کریں…")
        pumpUntil("the code") { shown("آپ کی authenticator ایپ کا کوڈ") }
        deleteWith("آپ کی authenticator ایپ کا کوڈ", "123456")
        pumpUntil("asked") { calls == listOf("delete - 123456") }
        val said = V2Testing.resources(EchoLanguage.Urdu).getString(com.echoledger.mobile.R.string.v2_acct_err_invalid_otp)
        pumpUntil("said in Urdu") { shown(said) }
    }

    @Test
    fun withTwoStepItAsksForTheCodeAndOffersTheWebPage() {
        account = account.copy(twoFactor = true)
        showBoth()
        press("Delete my account…")
        pumpUntil("the code") { shown("Code from your authenticator app") }
        press("Delete on the web page")
        val open = shadowOf(app).nextStartedActivity
        assertEquals("${server.baseUrl.trimEnd('/')}/account/delete", open.dataString)
    }

    @Test
    fun devicesHasEveryControlOfTodaysDevicesPage() {
        which = "devices"
        val (today, v2) = controls("Work laptop")
        val where = mapOf(
            "button: Back" to "button: Back",
            "button: Rename" to "button: Rename Sample phone",
            "button: Sign out Galaxy S25 Ultra" to "button: Sign out Galaxy S25 Ultra",
            "button: Sign out Work laptop" to "button: Sign out Work laptop"
        )
        val v2Only = mapOf(
            "button: Rename Galaxy S25 Ultra" to "today's Rename buttons share one name; v2 names the device",
            "button: Rename Work laptop" to "today's Rename buttons share one name; v2 names the device"
        )
        assertEquals(emptyList<String>(), ParityKit.problems(today.associateWith { 0 }, v2.associateWith { 0 }, where, reasons, v2Only))
    }

    @Test
    fun devicesShowWhenEachWasSeenRenameSignOutAndTheRecentActivity() {
        which = "devices"
        serve()
        server.on("PATCH", "/api/account/devices/01m7zddc7v12m6cg01k9mbxa2h", FakeServer.empty(204))
        server.on("DELETE", "/api/account/devices/01m7zddc7v12m6cg01k9mbxa2h", FakeServer.empty(204))
        showBoth()
        pumpUntil("the devices") { shown("Work laptop") }
        assertTrue(
            "this phone",
            compose.onAllNodesWithText("This phone", substring = true, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()
        )
        // The recent activity, with who did it.
        assertTrue("activity", shown("Recent activity"))
        assertTrue("an event", shown("Passkey added: Sample phone"))
        // Rename.
        press("Rename Galaxy S25 Ultra")
        val field = compose.onAllNodes(hasSetTextAction()).onLast()
        field.performTextInput(" 2")
        compose.waitForIdle()
        compose.onAllNodes(hasText("Save") and hasClickAction()).onLast().performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("renamed") { server.requestsTo("PATCH", "/api/account/devices/01m7zddc7v12m6cg01k9mbxa2h").isNotEmpty() }
        // Sign out another device asks first; this phone has none (it signs out from Settings).
        assertTrue(compose.onAllNodes(hasContentDescription("Sign out Sample phone")).fetchSemanticsNodes().isEmpty())
        press("Sign out Galaxy S25 Ultra")
        inDialog("Cancel")
        assertTrue(server.requestsTo("DELETE", "/api/account/devices/01m7zddc7v12m6cg01k9mbxa2h").isEmpty())
        press("Sign out Galaxy S25 Ultra")
        inDialog("Sign out")
        pumpUntil("signed out") { server.requestsTo("DELETE", "/api/account/devices/01m7zddc7v12m6cg01k9mbxa2h").isNotEmpty() }
    }

    @Test
    fun inUrduThePagesSpeakUrdu() {
        language = EchoLanguage.Urdu
        showBoth()
        pumpUntil("Urdu") {
            listOf("آپ کا ڈیٹا", "میرا ڈیٹا ایکسپورٹ کریں", "ہر جگہ سے سائن آؤٹ کریں…", "میرا اکاؤنٹ حذف کریں…").all(::shown)
        }
    }
}
