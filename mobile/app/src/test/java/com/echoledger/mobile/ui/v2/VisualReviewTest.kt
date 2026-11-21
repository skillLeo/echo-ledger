package com.echoledger.mobile.ui.v2

import android.app.Application
import android.content.res.Configuration
import androidx.compose.foundation.layout.Column
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isRoot
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.core.LibraryViewChoice
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.privilege.AppCallCapture
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.ui.v2.activity.ActivityWords
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.home.ChecklistItem
import com.echoledger.mobile.ui.v2.home.LibraryTop
import com.echoledger.mobile.ui.v2.home.NeedsAction
import com.echoledger.mobile.ui.v2.home.NeedsBanner
import com.echoledger.mobile.ui.v2.home.setupShowsCapture
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.LibraryState
import com.echoledger.mobile.ui.v2.library.LibraryTools
import com.echoledger.mobile.ui.v2.library.OrganiseChoices
import com.echoledger.mobile.ui.v2.library.RowGroup
import com.echoledger.mobile.ui.v2.library.SelectionBar
import com.echoledger.mobile.ui.v2.library.SortGroupSheet
import com.echoledger.mobile.ui.v2.library.V2LibraryContent
import com.echoledger.mobile.ui.v2.shell.PersonAvatar
import com.echoledger.mobile.ui.v2.shell.RecordSheetActions
import com.echoledger.mobile.ui.v2.shell.RecordSheetState
import com.echoledger.mobile.ui.v2.shell.V2RecordSheetBody
import com.echoledger.mobile.ui.v2.shell.initialsOf
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.ZonedDateTime
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The U1–U4 visual review of the phone's v2 Library, navigation and Activity (points 1–11), held on the
 * narrowest phone (360 dp) at 100 % and 130 % text, in English and in Urdu (right to left), with real text measurement.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-420dpi", application = Application::class)
class VisualReviewTest {
    @get:Rule
    val compose = createComposeRule()

    private var scale by mutableFloatStateOf(1f)
    private var language by mutableStateOf(EchoLanguage.English)

    private fun show(content: @Composable () -> Unit) = compose.setContent {
        val base = LocalContext.current
        val config = LocalConfiguration.current
        val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
        CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
            EchoV2Theme(darkTheme = false, language = language) { content() }
        }
    }

    /** Every variant the review asks for: 100 % and 130 % text, English and Urdu. */
    private fun everyVariant(check: (String) -> Unit) {
        for (l in EchoLanguage.entries) {
            for (s in listOf(1f, 1.3f)) {
                language = l
                scale = s
                compose.waitForIdle()
                check("${l.name} at ${(s * 100).toInt()} %")
            }
        }
    }

    private fun SemanticsNodeInteraction.layout(): TextLayoutResult {
        val results = mutableListOf<TextLayoutResult>()
        fetchSemanticsNode().config[SemanticsActions.GetTextLayoutResult].action?.invoke(results)
        return results.single()
    }

    private fun bounds(n: SemanticsNodeInteraction): Rect = n.fetchSemanticsNode().boundsInRoot
    private fun rootWidth(): Float = compose.onAllNodes(isRoot()).onFirst().fetchSemanticsNode().boundsInRoot.width

    private fun words(en: String, ur: String) = if (language == EchoLanguage.Urdu) ur else en

    private fun item(id: String, pinned: Boolean = false, keep: Boolean = false): LibraryItem =
        ApiJson.decodeFromString<LibraryItem>(ServerFixtures.libraryItem(id, title = "Call $id")).copy(pinned = pinned, keep = keep)

    // Point 5: Pin / Unpin and Keep forever / Allow auto clean-up, only what applies.

    @Test
    fun noneSelectedPinnedOrKeptOffersPinAndKeepForeverOnly() {
        assertEquals(
            OrganiseChoices(pin = true, unpin = false, keep = true, unkeep = false, important = true, unimportant = false),
            OrganiseChoices.of(listOf(item("a"), item("b")))
        )
    }

    @Test
    fun allSelectedPinnedAndKeptOffersUnpinAndAllowAutoCleanUpOnly() {
        assertEquals(
            OrganiseChoices(pin = false, unpin = true, keep = false, unkeep = true, important = true, unimportant = false),
            OrganiseChoices.of(listOf(item("a", pinned = true, keep = true), item("b", pinned = true, keep = true)))
        )
    }

    @Test
    fun aMixedSelectionOffersBoth() {
        // None of them is important: Mark important only.
        assertEquals(
            OrganiseChoices.ALL.copy(unimportant = false),
            OrganiseChoices.of(listOf(item("a", pinned = true), item("b", keep = true)))
        )
        // Important mixed: both.
        assertEquals(
            OrganiseChoices.ALL,
            OrganiseChoices.of(listOf(item("a", pinned = true).copy(important = true), item("b", keep = true)))
        )
        // All important: Unmark important only.
        assertEquals(
            false,
            OrganiseChoices.of(listOf(item("a").copy(important = true), item("b").copy(important = true))).important
        )
        // Pinned all, kept some: Unpin only, and both keep actions.
        assertEquals(
            OrganiseChoices(pin = false, unpin = true, keep = true, unkeep = true, important = true, unimportant = false),
            OrganiseChoices.of(listOf(item("a", pinned = true, keep = true), item("b", pinned = true)))
        )
    }

    // Points 3 and 5: the selection bar and its ⋮.

    @Composable
    private fun bar(count: Int, choices: OrganiseChoices = OrganiseChoices.ALL, onSelectAll: (Boolean) -> Unit = {}) = SelectionBar(
        count = count,
        allSelected = false,
        oneTitle = if (count == 1) "Bilal Traders" else null,
        folders = emptyList(),
        labels = emptyList(),
        keepHours = 48,
        onClose = {},
        onSelectAll = onSelectAll,
        onAction = {},
        choices = choices
    )

    @Test
    fun theSelectionBarKeepsItsTitleOnOneLineWithMoveLabelDeleteAndMore() {
        var count by mutableStateOf(1)
        show { bar(count) }
        everyVariant { at ->
            for (n in listOf(1, 200)) {
                count = n
                compose.waitForIdle()
                val title = compose.onNodeWithText(words("$n selected", "$n منتخب"), useUnmergedTree = true)
                assertEquals("$at, $n selected", 1, title.layout().lineCount)
                val names = listOf(words("Move to project", "پروجیکٹ میں منتقل کریں"), words("Label", "لیبل"), words("Delete", "حذف کریں"))
                val width = rootWidth()
                for (name in names + words("More", "مزید")) {
                    val icon = compose.onNode(hasContentDescription(name) and hasClickAction())
                    icon.assertIsDisplayed()
                    val b = bounds(icon)
                    assertTrue("$at: $name on screen ($b in $width)", b.left >= 0f && b.right <= width + 0.5f)
                }
                // Share and Select all moved into ⋮.
                compose.onNode(hasContentDescription(words("Share or export", "شیئر یا ایکسپورٹ"))).assertDoesNotExist()
                compose.onNode(hasContentDescription(words("Select all", "سب منتخب کریں"))).assertDoesNotExist()
            }
        }
    }

    @Test
    fun everythingThatLeftTheBarIsInTheMenuWithExportAsASubMenu() {
        var selectAll: Boolean? = null
        show { bar(1, OrganiseChoices(pin = true, unpin = false, keep = false, unkeep = true)) { selectAll = it } }
        compose.onNodeWithContentDescription("More").performClick()
        for (shown in listOf("Select all", "Share", "Pin", "Allow auto clean-up", "Rename", "Export", "Delete")) {
            compose.onNode(hasText(shown) and hasClickAction()).assertExists()
        }
        // Only what applies: not pinned, so no Unpin; all kept, so no Keep forever.
        compose.onNodeWithText("Unpin").assertDoesNotExist()
        compose.onNodeWithText("Keep forever").assertDoesNotExist()
        // The formats are one Export ▸ away, not inline.
        compose.onNodeWithText("Word (.docx)").assertDoesNotExist()
        compose.onNode(hasText("Export") and hasClickAction()).performClick()
        for (format in listOf("Word (.docx)", "Text (.txt)", "Markdown (.md)", "Subtitles (.srt)", "Web subtitles (.vtt)", "JSON")) {
            compose.onNodeWithText(format).assertIsDisplayed()
        }
        compose.onNodeWithText("Pin").assertDoesNotExist()
        // Back to the menu.
        compose.onNodeWithContentDescription("Back, Export").performClick()
        compose.onNodeWithText("Pin").assertExists()
        compose.onNode(hasText("Select all") and hasClickAction()).performClick()
        assertEquals(true, selectAll)
        // Share opens the formats as a sheet, as the bar's icon did.
        compose.onNodeWithContentDescription("More").performClick()
        compose.onNode(hasText("Share") and hasClickAction()).performClick()
        compose.onNodeWithText("Export as").assertExists()
    }

    // Point 4: a selected row is tinted.

    @Test
    fun aSelectedRowIsTintedWithAccentSoft() {
        val items = listOf(item("01JA"), item("01JB"))
        var picked by mutableStateOf(setOf("01JA"))
        show {
            val text = v2Text
            val now = ZonedDateTime.parse("2026-11-21T10:00:00Z")
            val rows = items.map { LibraryModel.row(it, now, text, Locale.ENGLISH) }
            V2LibraryContent(
                state = LibraryState.Ready(rows, emptyList(), offline = false, anyAtAll = true),
                query = "",
                onQuery = {},
                views = emptySet(),
                onToggleView = {},
                initials = "AS",
                onOpen = {},
                onDelete = {},
                onRetryUpload = {},
                onRetry = {},
                onTrash = {},
                onAccount = {},
                tools = LibraryTools(
                    groups = listOf(RowGroup("all", "", rows)),
                    folded = emptySet(),
                    onFold = {},
                    selected = picked,
                    onToggle = {},
                    onSelectGroup = {},
                    selectionBar = { bar(picked.size) },
                    filterRow = {},
                    filtered = false,
                    onClearFilters = {},
                    onSortGroup = {},
                    compact = false,
                    refreshing = false,
                    onSync = {},
                    onSelect = {},
                    onPin = {},
                    matches = emptyList(),
                    onOpenAt = {}
                )
            )
        }
        val soft = EchoTokens.Light.accentSoft.toArgb()
        val canvas = EchoTokens.Light.bgCanvas.toArgb()
        everyVariant { at ->
            fun edge(title: String): Int {
                val row = compose.onAllNodes(hasText(title, substring = true) and hasClickAction()).onFirst()
                val image = row.captureToImage().asAndroidBitmap()
                // Inside the row's side padding, which is on both sides in either direction.
                return image.getPixel(2, image.height / 2)
            }
            assertEquals("$at: the selected row", soft, edge("Call 01JA"))
            assertEquals("$at: the other row", canvas, edge("Call 01JB"))
        }
    }

    // Point 6: Sort and group fits on one screen.

    @Test
    fun sortAndGroupShowsGroupSortAndDensityWithoutScrolling() {
        show { SortGroupSheet(LibraryViewChoice.DEFAULT, {}, {}) }
        for (l in EchoLanguage.entries) {
            language = l
            scale = 1f
            compose.waitForIdle()
            for (last in listOf(words("None", "کوئی نہیں"), words("Comfortable", "کشادہ"), words("Compact", "گھنی"))) {
                compose.onAllNodesWithText(last).onFirst().assertIsDisplayed()
            }
        }
    }

    // Point 2: no "?" avatar.

    @Test
    fun noNameShowsThePersonIconAndANameItsInitials() {
        assertEquals("", initialsOf(null))
        assertEquals("", initialsOf("  "))
        assertEquals("AS", initialsOf("Asad Shah"))
        show {
            Column {
                PersonAvatar("")
                PersonAvatar("AS")
            }
        }
        compose.onNodeWithText("?").assertDoesNotExist()
        compose.onNodeWithText("AS").assertExists()
    }

    // Point 7: one "not ready" signal.

    @Test
    fun theCallsOffBannerStepsAsideWhileTheSetupCardShowsCallRecording() {
        val checklist = listOf(ChecklistItem.Capture to false, ChecklistItem.FirstRecording to true)
        assertTrue(setupShowsCapture(checklist, setupHidden = false))
        assertFalse(setupShowsCapture(checklist, setupHidden = true))
        assertFalse(setupShowsCapture(listOf(ChecklistItem.Capture to true), setupHidden = false))
        val notReady = com.echoledger.mobile.core.ReadyChip.NOT_READY
        assertEquals(null, LibraryTop.banner(notReady, emptyList(), setupShowsCapture = true))
        assertEquals(NeedsBanner.Kind.CallsOff, LibraryTop.banner(notReady, emptyList(), setupShowsCapture = false)?.kind)
        // Anything else that needs the person still shows.
        val uploads = NeedsAction.FailedUploads(listOf("a"))
        assertEquals(
            NeedsBanner(NeedsBanner.Kind.FailedUploads, 1, 0),
            LibraryTop.banner(notReady, listOf(uploads), setupShowsCapture = true)
        )
    }

    // Point 8: Auto-record calls starts in the text column, after its icon.

    @Test
    fun autoRecordCallsLinesUpWithTheOtherRowsOfTheRecordSheet() {
        val state = RecordSheetState(
            AppCallCapture.BOTH_SIDES,
            armed = false,
            canArm = true,
            liveAssist = false,
            liveTranslate = false,
            translateLocked = null,
            game = false,
            watch = false
        )
        val actions = RecordSheetActions({}, {}, {}, {}, {}, {}, {}, {})
        show { Column { V2RecordSheetBody(state, actions, {}) } }
        everyVariant { at ->
            val calls = bounds(compose.onNodeWithText(words("Auto-record calls", "کالز خود بخود ریکارڈ کریں"), useUnmergedTree = true))
            val voice = bounds(compose.onNodeWithText(words("Voice & files", "آواز اور فائلیں"), useUnmergedTree = true))
            if (language == EchoLanguage.Urdu) {
                assertEquals("$at: right edges", voice.right, calls.right, 1f)
            } else {
                assertEquals("$at: left edges", voice.left, calls.left, 1f)
            }
        }
    }

    // Points 9 and 10: Activity's icons and its titles in Urdu.

    private fun activity(kind: String, title: String) =
        ActivityItemDto("a", kind, title, "Bilal Traders", null, false, "2026-11-21T07:00:00Z")

    @Test
    fun activityUsesClassicsIconsAndUrduTitles() {
        assertEquals(EchoIcons.Check, ActivityWords.icon(activity("transcript_ready", "Transcript ready")))
        assertEquals(EchoIcons.Devices, ActivityWords.icon(activity("device_paired", "Pixel 9 signed in")))
        assertEquals(EchoIcons.Error, ActivityWords.icon(activity("upload_failed", "Upload failed — retry")))
        assertEquals(EchoIcons.Missed, ActivityWords.icon(activity("missed_recording", "Missed recording")))
        val app = androidx.test.core.app.ApplicationProvider.getApplicationContext<Application>()
        val ur = app.createConfigurationContext(
            Configuration(app.resources.configuration).apply {
                setLocale(Locale.forLanguageTag("ur-PK"))
            }
        )
        val urdu = Locale.forLanguageTag("ur-PK")
        assertEquals("اپ لوڈ ناکام", ActivityWords.title(ur.resources, activity("upload_failed", "Upload failed — retry"), urdu))
        assertEquals("ٹرانسکرپٹ تیار", ActivityWords.title(ur.resources, activity("transcript_ready", "Transcript ready"), urdu))
        assertEquals("نئی ڈیوائس سائن ان ہوئی", ActivityWords.title(ur.resources, activity("device_paired", "Pixel 9 signed in"), urdu))
        // English keeps the server's own words; an unknown kind keeps them in every language.
        assertEquals(
            "Pixel 9 signed in",
            ActivityWords.title(app.resources, activity("device_paired", "Pixel 9 signed in"), Locale.ENGLISH)
        )
        assertEquals("Plan approved", ActivityWords.title(ur.resources, activity("upgrade_approved", "Plan approved"), urdu))
    }

    // Point 11: classic's row date.

    @Test
    fun aRowDateIsWrittenAsClassicWritesIt() {
        val now = ZonedDateTime.parse("2026-11-21T10:00:00Z")
        val app = androidx.test.core.app.ApplicationProvider.getApplicationContext<Application>()
        val text = com.echoledger.mobile.ui.v2.shell.V2Text.from(app.resources)
        val october = item("a")
        assertEquals("Oct 20", LibraryModel.time(october, now, text, Locale.ENGLISH))
        assertEquals(
            com.echoledger.mobile.ui.design.rowTime(october.startedAt, now.toLocalDate(), now.zone, Locale.ENGLISH),
            LibraryModel.time(october, now, text, Locale.ENGLISH)
        )
        val lastYear = october.copy(startedAt = "2025-10-20T10:00:00+00:00")
        assertEquals("Oct 20 2025", LibraryModel.time(lastYear, now, text, Locale.ENGLISH))
    }

    // Settings home: the values classic's list shows on its rows, from the same settings, in the screen's language.

    @Test
    fun settingsRowsShowClassicsValues() {
        val app = androidx.test.core.app.ApplicationProvider.getApplicationContext<Application>()
        val en = app.resources
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        val base = com.echoledger.mobile.data.AppSettings()
        fun v(page: String, s: com.echoledger.mobile.data.AppSettings = base, res: android.content.res.Resources = en) =
            com.echoledger.mobile.ui.v2.settings.pageValue(res, page, s)
        assertEquals("Automatic", v("mic"))
        assertEquals("Raw microphone", v("mic", base.copy(micSource = "mic")))
        assertEquals("Tap to start", v("voice"))
        assertEquals("Hold to talk", v("voice", base.copy(dictationMode = "hold")))
        assertEquals("Not added", v("key"))
        val withKey = base.copy(plan = base.plan.copy(transcriptionKey = com.echoledger.mobile.data.TranscriptionKeyDto(set = true)))
        assertEquals("Added", v("key", withKey))
        assertEquals("Not set up", v("games"))
        assertEquals("Not set up", v("videos"))
        val two = base.copy(
            liveTranslate = base.liveTranslate.copy(
                game = base.liveTranslate.game.copy(games = listOf("a", "b")),
                watch = base.liveTranslate.watch.copy(apps = listOf("c"))
            )
        )
        assertEquals("2 games", v("games", two))
        assertEquals("1 app", v("videos", two))
        assertEquals("Starter", v("plan", base.copy(plan = base.plan.copy(name = "Starter"))))
        // The same words in Urdu.
        assertEquals("خودکار", v("mic", res = ur))
        assertEquals("شروع کرنے کے لیے ٹیپ کریں", v("voice", res = ur))
        // Rows classic shows no value for show none here either.
        for (page in listOf("calls", "words", "privacy", "storage", "account", "devices", "help")) assertEquals(page, null, v(page))
    }

    @Test
    fun theSettingsHomeShowsMicrophoneAndVoiceValues() {
        show {
            com.echoledger.mobile.ui.v2.settings.V2SettingsRoot(
                settings = com.echoledger.mobile.data.AppSettings(),
                profile = com.echoledger.mobile.ui.v2.shell.ProfileState.Loading,
                signedIn = false,
                onUpgrade = {},
                onRetryProfile = {},
                onOpen = { _, _ -> },
                onSignOut = {},
                onBack = {}
            )
        }
        compose.onNode(hasText("Microphone & audio", substring = true) and hasText("Automatic", substring = true)).assertExists()
        compose.onNode(hasText("Voice notes & dictation", substring = true) and hasText("Tap to start", substring = true)).assertExists()
    }

    // The Record sheet scrolls, so its last rows are reachable on a narrow phone at large text.

    @Test
    fun theRecordSheetsLastRowIsReachableAt200PercentOnANarrowPhone() {
        val went = mutableListOf<String>()
        show {
            com.echoledger.mobile.ui.v2.shell.V2RecordSheet(
                RecordSheetState(
                    AppCallCapture.BOTH_SIDES,
                    armed = false,
                    canArm = true,
                    liveAssist = true,
                    liveTranslate = true,
                    translateLocked = null,
                    game = false,
                    watch = false
                ),
                RecordSheetActions({}, {}, {}, {}, {}, {}, {}, {}, onWatchTranslate = { went += "watch" }),
                onDismiss = {}
            )
        }
        for (l in EchoLanguage.entries) {
            language = l
            scale = 2f
            compose.waitForIdle()
            val watch = compose.onNodeWithText(words("Watch translate", "ویڈیو کا ترجمہ"))
            watch.performScrollTo()
            watch.assertIsDisplayed()
        }
        compose.onNodeWithText("ویڈیو کا ترجمہ").performClick()
        assertEquals(listOf("watch"), went)
    }
}
