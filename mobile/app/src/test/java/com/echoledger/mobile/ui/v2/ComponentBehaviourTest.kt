package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.layout.Column
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.compose.ui.test.swipeRight
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.ui.v2.components.EchoActionItem
import com.echoledger.mobile.ui.v2.components.EchoBottomBar
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoDestination
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoFilter
import com.echoledger.mobile.ui.v2.components.EchoFilterBar
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSelect
import com.echoledger.mobile.ui.v2.components.EchoSourceLogo
import com.echoledger.mobile.ui.v2.components.EchoSplitButton
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.EchoToastResult
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.theme.EchoV2Type
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** What the v2 components do: Undo, dismissing, choosing, toggling, and the touch and label rules. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi", application = Application::class)
class ComponentBehaviourTest {
    @get:Rule
    val compose = createComposeRule()

    private fun show(language: EchoLanguage = EchoLanguage.English, content: @androidx.compose.runtime.Composable () -> Unit) =
        compose.setContent { EchoV2Theme(darkTheme = false, language = language, content = content) }

    @Test
    fun undoRunsWhenTappedInTime() {
        var undone = 0
        var result: EchoToastResult? = null
        show {
            val toasts = rememberEchoToastState()
            LaunchedEffect(Unit) { result = toasts.showUndo("Deleted 3 recordings", "Undo") { undone++ } }
            EchoToastHost(toasts)
        }
        compose.onNodeWithText("Deleted 3 recordings").assertExists()
        compose.onNodeWithText("Undo").performClick()
        compose.waitForIdle()
        assertEquals(1, undone)
        assertEquals(EchoToastResult.Action, result)
        compose.onNodeWithText("Deleted 3 recordings").assertDoesNotExist()
    }

    @Test
    fun undoDoesNotRunWhenTheToastTimesOut() {
        var undone = 0
        var result: EchoToastResult? = null
        compose.mainClock.autoAdvance = false
        show {
            val toasts = rememberEchoToastState()
            LaunchedEffect(Unit) { result = toasts.showUndo("Moved to Shaheen Pharma", "Undo") { undone++ } }
            EchoToastHost(toasts)
        }
        compose.mainClock.advanceTimeBy(1_000)
        compose.onNodeWithText("Moved to Shaheen Pharma").assertExists()
        compose.mainClock.advanceTimeBy(6_500)
        compose.onNodeWithText("Moved to Shaheen Pharma").assertDoesNotExist()
        assertEquals(0, undone)
        assertEquals(EchoToastResult.Dismissed, result)
    }

    /** v2.1 FIX-03: a message is a Material 3 snackbar a swipe left or right closes, as its time running out does. */
    @Test
    fun aSwipeLeftOrRightClosesTheSnackbarAndRunsNoUndo() {
        var undone = 0
        val results = mutableListOf<EchoToastResult>()
        var next by mutableStateOf(0)
        show {
            val toasts = rememberEchoToastState()
            LaunchedEffect(next) { if (next > 0) results += toasts.showUndo("Deleted $next", "Undo") { undone++ } }
            EchoToastHost(toasts)
        }
        // From the middle of the message across the screen's width (measured once it shows), left then right.
        var wide = 0f
        val swipes = listOf<androidx.compose.ui.test.TouchInjectionScope.() -> Unit>(
            { swipeLeft(startX = centerX, endX = centerX - wide) },
            { swipeRight(startX = centerX, endX = centerX + wide) }
        )
        swipes.forEachIndexed { i, swipe ->
            next = i + 1
            compose.onNodeWithText("Deleted ${i + 1}").assertExists()
            wide = compose.onRoot().fetchSemanticsNode().size.width.toFloat()
            compose.onNodeWithText("Deleted ${i + 1}").performTouchInput(swipe)
            compose.waitForIdle()
            compose.onNodeWithText("Deleted ${i + 1}").assertDoesNotExist()
            assertEquals(i + 1, results.size)
        }
        assertEquals(listOf(EchoToastResult.Dismissed, EchoToastResult.Dismissed), results)
        assertEquals("a swipe is not Undo", 0, undone)
    }

    @Test
    fun twoQuickMessagesNeverShowAtOnce() {
        show {
            val toasts = rememberEchoToastState()
            LaunchedEffect(Unit) { toasts.show("Moved to Shaheen Pharma") }
            LaunchedEffect(Unit) { toasts.showUndo("Deleted 3 recordings", "Undo") {} }
            EchoToastHost(toasts)
        }
        val onScreen = listOf("Moved to Shaheen Pharma", "Deleted 3 recordings")
            .filter { compose.onAllNodesWithText(it).fetchSemanticsNodes().isNotEmpty() }
        assertEquals("one message at a time: $onScreen", 1, onScreen.size)
    }

    @Test
    fun bottomSheetDismissesFromItsCloseButton() {
        var open by mutableStateOf(true)
        var dismissed = 0
        show {
            if (open) {
                EchoBottomSheet(onDismiss = {
                    dismissed++
                    open = false
                }, title = "Share") { Text("Link to the transcript") }
            }
        }
        compose.onNodeWithText("Link to the transcript").assertExists()
        compose.onNodeWithContentDescription("Close").performClick()
        compose.waitForIdle()
        assertEquals(1, dismissed)
        compose.onNodeWithText("Link to the transcript").assertDoesNotExist()
    }

    @Test
    fun dialogConfirmsAndDismisses() {
        var confirmed = 0
        var dismissed = 0
        show {
            EchoDialog("Delete this recording?", "It stays in Trash for 30 days.", "Delete", {
                confirmed++
            }, "Cancel", { dismissed++ }, destructive = true)
        }
        compose.onNodeWithText("Cancel").performClick()
        compose.onNodeWithText("Delete").performClick()
        assertEquals(1, confirmed)
        assertEquals(1, dismissed)
    }

    @Test
    fun theWholeSelectFieldOpensItsChoices() {
        var chosen by mutableStateOf("Urdu")
        show { EchoSelect("Transcript language", listOf("Urdu", "English", "Punjabi"), chosen, { chosen = it }, { it }) }
        compose.onNodeWithText("Punjabi").assertDoesNotExist()
        // The label, not the arrow.
        compose.onNodeWithText("Transcript language", useUnmergedTree = true).performClick()
        compose.onNodeWithText("Punjabi").performClick()
        compose.waitForIdle()
        assertEquals("Punjabi", chosen)
        compose.onNodeWithText("English").assertDoesNotExist()
    }

    @Test
    fun aLoadingButtonIsDisabledAndSaysWhatItIsDoing() {
        var taps = 0
        var loading by mutableStateOf(true)
        show { EchoButton("Upload", { taps++ }, loading = loading, loadingText = "Uploading") }
        compose.onNodeWithText("Upload").assertDoesNotExist()
        compose.onNodeWithText("Uploading").assertIsNotEnabled().performClick()
        assertEquals(0, taps)
        compose.onNode(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Loading")).assertExists()
        loading = false
        compose.onNodeWithText("Upload").assertIsEnabled().performClick()
        assertEquals(1, taps)
    }

    @Test
    fun switchesChipsAndRadiosChangeFromTheWholeRow() {
        var on by mutableStateOf(false)
        var filters by mutableStateOf(emptySet<String>())
        var radio by mutableStateOf(0)
        show {
            Column {
                EchoSwitch(on, { on = it }, "Auto-transcribe after calls")
                EchoFilterBar(listOf(EchoFilter("today", "Today")), filters, { k -> filters = filters + k })
                EchoRadio(radio == 1, { radio = 1 }, "Show the after-call card")
            }
        }
        compose.onNodeWithText("Auto-transcribe after calls").assertIsOff().performClick().assertIsOn()
        compose.onNodeWithText("Today").performClick()
        assertEquals(setOf("today"), filters)
        compose.onNodeWithText("Show the after-call card").performClick().assertIsSelected()
    }

    @Test
    fun searchClearButtonEmptiesTheQuery() {
        var query by mutableStateOf("basmati")
        show { EchoSearchField(query, { query = it }, "Search recordings") }
        compose.onNodeWithContentDescription("Clear search").performClick()
        assertEquals("", query)
        compose.onNodeWithContentDescription("Clear search").assertDoesNotExist()
    }

    private var galleryName by mutableStateOf(ComponentGalleries.all.keys.first())
    private var urduGallery by mutableStateOf(false)

    @Test
    fun everyTappableThingIsAtLeast48dpAndHasAName() {
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = if (urduGallery) EchoLanguage.Urdu else EchoLanguage.English) {
                ComponentGalleries.all.getValue(galleryName)(urduGallery)
            }
        }
        val min = EchoTokens.Layout.controlTouchMin.value
        val problems = mutableListOf<String>()
        for (urdu in listOf(false, true)) {
            for (name in ComponentGalleries.all.keys) {
                urduGallery = urdu
                galleryName = name
                compose.waitForIdle()
                compose.onAllNodes(SemanticsMatcher.keyIsDefined(SemanticsActions.OnClick)).fetchSemanticsNodes().forEach { node ->
                    val c = node.config
                    val label = c.getOrNull(SemanticsProperties.ContentDescription)?.joinToString()
                        ?: c.getOrNull(SemanticsProperties.Text)?.joinToString()
                        ?: c.getOrNull(SemanticsProperties.EditableText)?.text
                    val size = node.touchBoundsInRoot
                    val w = size.width / node.layoutInfo.density.density
                    val h = size.height / node.layoutInfo.density.density
                    val where = "$name${if (urdu) " (ur)" else ""}: ${label ?: "unnamed"}"
                    if (label.isNullOrBlank()) problems += "$where has no name"
                    if (w + 0.5f < min || h + 0.5f < min) problems += "$where is ${w}x$h dp"
                }
            }
        }
        assertTrue(problems.joinToString("\n"), problems.isEmpty())
    }

    @Test
    fun urduIsRightToLeftAndBodyTextIs16sp() {
        var direction: LayoutDirection? = null
        var body = 0f
        show(EchoLanguage.Urdu) {
            direction = LocalLayoutDirection.current
            body = MaterialTheme.typography.bodyLarge.fontSize.value
        }
        compose.waitForIdle()
        assertEquals(LayoutDirection.Rtl, direction)
        assertEquals(16.sp.value, body)
    }

    @Test
    fun coloursAreTheTokensWithNoWallpaperColour() {
        for ((colors, tokens) in listOf(
            EchoV2Colors.Light to EchoTokens.Light.accentDefault,
            EchoV2Colors.Dark to EchoTokens.Dark.accentDefault
        )) {
            assertEquals(tokens, colors.accent)
            assertEquals(colors.accent, colors.toMaterial().primary)
            assertEquals(colors.danger, colors.toMaterial().error)
        }
        assertEquals(EchoTokens.Light.recordDefault, EchoV2Colors.Light.record)
        assertEquals(EchoTokens.Dark.dangerDefault, EchoV2Colors.Dark.danger)
        // EchoV2Theme has no dynamic-colour switch at all.
        assertFalse(EchoV2Colors::class.java.methods.any { it.name.contains("dynamic", ignoreCase = true) })
    }

    @Test
    fun aSkeletonIsReadAsLoadingOnce() {
        show { com.echoledger.mobile.ui.v2.components.EchoSkeletonList(3) }
        compose.onAllNodesWithContentDescription("Loading").assertCountEquals(1)
    }

    @Test
    fun clocksStayLatinAndLeftToRight() {
        assertEquals("12:08", formatClock(728_000))
        assertEquals("1:06:40", formatClock(4_000_000))
        assertFalse(formatClock(0).any { it in '؀'..'ۿ' })
    }

    @Test
    fun urduRolesTakeTheirTypeTokensOnUrduAndEnglishScreens() {
        val urdu = EchoV2Type.of(EchoLanguage.Urdu, phoneFont = false)
        assertEquals(EchoTokens.Type.urduTitleL.lineHeight, urdu.titleL.lineHeight)
        assertEquals(EchoTokens.Type.urduTitleM.lineHeight, urdu.titleM.lineHeight)
        assertEquals(EchoTokens.Type.urduTitleS.lineHeight, urdu.titleS.lineHeight)
        assertEquals(EchoTokens.Type.urduBody.lineHeight, urdu.body.lineHeight)
        // Urdu is never under 14 sp: captions, meta and labels take urdu.caption (14/28).
        for (style in listOf(urdu.caption, urdu.captionStrong, urdu.meta, urdu.label)) {
            assertEquals(EchoTokens.Type.urduCaption.fontSize, style.fontSize)
            assertEquals(EchoTokens.Type.urduCaption.lineHeight, style.lineHeight)
        }
        // Urdu words on the English screens take the same tokens for their role.
        val english = EchoV2Type.of(EchoLanguage.English, phoneFont = false)
        val words = "کل کی میٹنگ"
        assertEquals(EchoTokens.Type.urduCaption.fontSize, english.content(words, english.caption).fontSize)
        assertEquals(EchoTokens.Type.urduTitleM.lineHeight, english.content(words, english.titleM).lineHeight)
        assertEquals(EchoTokens.Type.urduBody.lineHeight, english.content(words, english.body).lineHeight)
    }

    @Test
    fun bottomBarLabelsGrowTo1Point3TimesAtMost() {
        val destinations = listOf(
            EchoDestination("library", "Library", EchoIcons.Library),
            EchoDestination("projects", "Projects", EchoIcons.Projects)
        )
        show {
            val d = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(d.density, fontScale = 2f)) {
                EchoBottomBar(destinations, selectedKey = "library", onSelect = {})
            }
        }
        val node = compose.onNodeWithText("Library", useUnmergedTree = true).fetchSemanticsNode()
        val layouts = mutableListOf<TextLayoutResult>()
        node.config[SemanticsActions.GetTextLayoutResult].action?.invoke(layouts)
        val drawn = layouts.first().layoutInput.style.fontSize.value
        assertEquals(EchoTokens.Type.caption.fontSize.value * 1.3f / 2f, drawn, 0.01f)
    }

    @Test
    fun atTwiceTheTextSizeATitleHasItsOwnLineAboveTheActions() {
        show {
            val d = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(d.density, fontScale = 2f)) {
                EchoTopBar("Activity") { Text("Mark all as read") }
            }
        }
        val title = compose.onNodeWithText("Activity", useUnmergedTree = true).fetchSemanticsNode().boundsInRoot
        val action = compose.onNodeWithText("Mark all as read", useUnmergedTree = true).fetchSemanticsNode().boundsInRoot
        assertTrue("the action is under the title", action.top >= title.bottom)
        val layouts = mutableListOf<TextLayoutResult>()
        compose.onNodeWithText("Activity", useUnmergedTree = true).fetchSemanticsNode()
            .config[SemanticsActions.GetTextLayoutResult].action?.invoke(layouts)
        assertFalse("the title is not cut", layouts.first().hasVisualOverflow)
    }

    /** Seen in UX-14's shots: at the largest text a banner's words were one per line beside Fix and ×. */
    @Test
    fun atTwiceTheTextSizeABannersActionIsUnderItsWords() {
        show {
            val d = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(d.density, fontScale = 2f)) {
                com.echoledger.mobile.ui.v2.components.EchoBanner(
                    "Calls won't record: call capture isn't set up yet",
                    com.echoledger.mobile.ui.v2.components.EchoBannerKind.Warning,
                    actionLabel = "Fix",
                    onClose = {},
                    closeLabel = "Close"
                )
            }
        }
        val words = compose.onNodeWithText("Calls won't record", substring = true, useUnmergedTree = true).fetchSemanticsNode().boundsInRoot
        val fix = compose.onNodeWithText("Fix", useUnmergedTree = true).fetchSemanticsNode().boundsInRoot
        val banner = compose.onRoot().fetchSemanticsNode().boundsInRoot
        assertTrue("Fix is under the words", fix.top >= words.bottom)
        assertTrue("the words take most of the width", words.width >= banner.width * 0.6f)
    }

    @Test
    fun wallpaperColoursKeepTheBrandAccentOnTheRedesignedScreens() {
        var accent: androidx.compose.ui.graphics.Color? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false, wallpaperColours = true) { accent = EchoV2.colors.accent }
        }
        compose.waitForIdle()
        assertEquals(EchoTokens.Light.accentDefault, accent)
    }

    @Test
    fun arabicWordsTakeTheArabicTypeTokensForTheirRole() {
        val english = EchoV2Type.of(EchoLanguage.English, phoneFont = false)
        val words = "اجتماع الغد"
        assertEquals(EchoTokens.Type.arabicBody.lineHeight, english.content(words, english.body).lineHeight)
        assertEquals(EchoTokens.Type.arabicCaption.fontSize, english.content(words, english.caption).fontSize)
        assertEquals(EchoTokens.Type.arabicTitleM.lineHeight, english.content(words, english.titleM).lineHeight)
    }

    @Test
    fun aSourceMarkIsNamedWhenLabelledAndShowsItsDeviceBadge() {
        show {
            Column {
                EchoSourceLogo(SourceApp.VOICE_NOTE, label = "Voice note")
                EchoSourceLogo(SourceApp.ZOOM, device = SourceDevice.DESKTOP, label = "Zoom on a computer")
            }
        }
        compose.onNodeWithContentDescription("Voice note").assertExists()
        compose.onNodeWithContentDescription("Zoom on a computer").assertExists()
    }

    @Test
    fun aSplitButtonsMainPartActsAtOnceAndItsChevronOpensTheMenu() {
        var copied = 0
        show {
            EchoSplitButton(
                text = "Copy",
                onClick = { copied++ },
                menuLabel = "More copy options",
                mainLabel = "Copy text"
            ) { close -> EchoActionItem(EchoIcons.Export, "Copy with timestamps", { close() }) }
        }
        compose.onNodeWithContentDescription("Copy text").performClick()
        assertEquals(1, copied)
        compose.onAllNodesWithText("Copy with timestamps").assertCountEquals(0)
        compose.onNodeWithContentDescription("More copy options").performClick()
        compose.onNodeWithText("Copy with timestamps").assertExists()
        assertEquals(1, copied)
    }

    @Test
    fun aSplitButtonsMainPartIsReadByItsOwnNameAndItsChevronSaysWhetherTheMenuIsOpen() {
        show {
            EchoSplitButton(
                text = "Copy",
                onClick = {},
                menuLabel = "More copy options",
                mainLabel = "Copy text"
            ) { close -> EchoActionItem(EchoIcons.Export, "Copy with timestamps", { close() }) }
        }
        compose.onNode(hasContentDescription("Copy text") and hasClickAction()).assertExists()
        val main = compose.onNodeWithContentDescription("Copy text").fetchSemanticsNode()
        assertEquals(listOf("Copy text"), main.config[SemanticsProperties.ContentDescription])
        // The word on it is not read again after its name.
        assertEquals(false, main.config.contains(SemanticsProperties.Text))
        val chevron = compose.onNodeWithContentDescription("More copy options")
        chevron.assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Collapsed"))
        chevron.performClick()
        chevron.assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Expanded"))
    }
}
