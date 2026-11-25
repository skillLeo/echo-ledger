package com.echoledger.mobile.ui.design

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.toSize
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Radius
import com.echoledger.mobile.ui.Space
import com.echoledger.mobile.ui.TextCuts
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * P-114: [InlineBanner] lays out its parts by hand so that on a small phone with large text its action goes under the
 * text instead of breaking a word. It has 27 callers, so at a normal size every part must stay exactly where the v1.1
 * Row put it: the Row is kept here, word for word, and both are compared, left to right and right to left.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w393dp-h852dp-hdpi")
class InlineBannerLayoutTest : ScreenTest() {
    private val banners = listOf(
        Triple("Made with a backup service. Re-transcribe for the best quality?", BannerKind.Warning, "Re-transcribe"),
        Triple("Transcription failed. Groq did not accept your key. Check it in Settings.", BannerKind.Error, "Check your key"),
        Triple("You're offline. Changes are saved once the connection is back.", BannerKind.Info, null),
        Triple("Saved.", BannerKind.Info, "Undo")
    )

    @Test
    fun `at a normal size every part of a banner is where the v1_1 row put it`() = assertSameAsV11()

    @Test
    @Config(qualifiers = "w360dp-h800dp-hdpi")
    fun `on a 360 dp phone at a normal size too`() = assertSameAsV11()

    @Test
    @Config(qualifiers = "w360dp-h800dp-hdpi", fontScale = 1.3f)
    fun `on a small phone with large text the action goes under the text, in line with it`() = assertStacked(LayoutDirection.Ltr)

    @Test
    @Config(qualifiers = "w360dp-h800dp-hdpi", fontScale = 1.3f)
    fun `and right to left it goes under the text at the right edge`() = assertStacked(LayoutDirection.Rtl)

    /** Every banner of [banners] beside its v1.1 Row, left to right and right to left: each part in the same place. */
    private fun assertSameAsV11() {
        show {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                LayoutDirection.entries.forEach { direction ->
                    CompositionLocalProvider(LocalLayoutDirection provides direction) {
                        banners.forEachIndexed { i, (text, kind, action) ->
                            Box(Modifier.testTag("new $direction $i")) {
                                InlineBanner(text, Modifier.padding(horizontal = 16.dp), kind = kind, action = action, onDismiss = {})
                            }
                            Box(Modifier.testTag("v1.1 $direction $i")) {
                                V11Banner(text, Modifier.padding(horizontal = 16.dp), kind = kind, action = action)
                            }
                        }
                    }
                }
            }
        }

        LayoutDirection.entries.forEach { direction ->
            banners.forEachIndexed { i, (text, _, action) ->
                fun parts(tag: String): List<String> {
                    val box = bounds(hasTestTag(tag))
                    val inside = listOfNotNull(
                        "text" to hasText(text),
                        action?.let { "action" to hasText(it) },
                        "×" to hasContentDescription("Dismiss")
                    )
                    return listOf("banner ${box.width} x ${box.height}") + inside.map { (name, part) ->
                        "$name at ${bounds(part and hasAnyAncestor(hasTestTag(tag))).translate(-box.left, -box.top)}"
                    }
                }
                assertEquals("$direction, \"$text\"", parts("v1.1 $direction $i"), parts("new $direction $i"))
            }
        }
    }

    /** P-114: the action under the text, its label in line with the text, 48 dp targets, × read last, no word broken. */
    private fun assertStacked(direction: LayoutDirection) {
        val text = "Made with a backup service. Re-transcribe for the best quality?"
        show {
            CompositionLocalProvider(LocalLayoutDirection provides direction) {
                InlineBanner(
                    text,
                    Modifier.padding(horizontal = 16.dp),
                    kind = BannerKind.Warning,
                    action = "Re-transcribe",
                    onDismiss = {}
                )
            }
        }

        val words = bounds(hasText(text))
        val label = bounds(hasText("Re-transcribe"))
        val button = compose.onNode(hasText("Re-transcribe") and hasClickAction()).fetchSemanticsNode()
        val closer = compose.onNode(hasContentDescription("Dismiss") and hasClickAction()).fetchSemanticsNode()
        val target = with(compose.density) { 48.dp.toPx() }
        assertTrue("$direction: the action is under the text: $words, $label", label.top >= words.bottom)
        val inLine = if (direction == LayoutDirection.Ltr) label.left - words.left else words.right - label.right
        assertEquals("$direction: the action's label starts where the text does", 0f, inLine, 1f)
        listOf(button, closer).forEach { node ->
            assertTrue("$direction: a 48 dp target: ${node.size}", node.size.width >= target - 1 && node.size.height >= target - 1)
        }
        val index = { node: SemanticsNode -> node.config.getOrNull(SemanticsProperties.TraversalIndex) ?: 0f }
        assertTrue("$direction: × is read after the action", index(closer) > index(button))
        assertEquals(emptyList<String>(), TextCuts.find(compose))
    }

    /** Where a part is, whole: boundsInRoot is clipped to the screen, and some of the banners are below it. */
    private fun bounds(matcher: SemanticsMatcher): Rect {
        val node = compose.onNode(matcher, useUnmergedTree = true).fetchSemanticsNode()
        return Rect(node.positionInRoot, node.size.toSize())
    }

    /** InlineBanner's Row as it was on v1.1 (2.0.x), without its closing state. */
    @Composable
    private fun V11Banner(text: String, modifier: Modifier, kind: BannerKind, action: String?) {
        val c = LocalEchoColors.current
        val (bg, fg, icon) = when (kind) {
            BannerKind.Info -> Triple(c.accentSoft, c.accentSoftText, Icons.Rounded.Info)
            BannerKind.Warning -> Triple(c.warningFill.copy(alpha = if (c.isDark) 0.14f else 0.18f), c.warning, Icons.Rounded.WarningAmber)
            BannerKind.Error -> Triple(c.dangerSoft, c.danger, Icons.Rounded.ErrorOutline)
        }
        val onClick: (() -> Unit)? = if (action != null) ({}) else null
        SwipeAway(onGone = {}, modifier = modifier) {
            Row(
                Modifier.fillMaxWidth().clip(
                    RoundedCornerShape(Radius.md)
                ).background(bg)
                    .then(onClick?.let { Modifier.clickable(onClick = it) } ?: Modifier)
                    .padding(start = Space.s3, top = Space.s1, bottom = Space.s1),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Icon(icon, contentDescription = null, tint = fg, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(Space.s2))
                Text(
                    text,
                    style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.Normal),
                    color = c.text,
                    modifier = Modifier.weight(1f).padding(vertical = Space.s2)
                )
                if (action != null) EchoButton(action, {}, kind = ButtonKind.Text)
                BarIcon(Icons.Rounded.Close, "Dismiss", {})
            }
        }
    }
}
