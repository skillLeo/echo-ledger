package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Rect
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.ComposeContentTestRule
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeUp
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import org.junit.Assert.assertTrue

/**
 * A screen at each text size of [scales] (Android 14's scaling), light and dark, English and Urdu. Each run checks that
 * every control can still be reached with a finger (48 dp) at the largest size. With a `build/bugbash` folder present
 * it also draws each variant, the screen's end at the largest size, and a contact sheet per theme there (never into the
 * repo).
 */
class BugBash(private val compose: ComposeContentTestRule, private val scales: List<Float>) {
    private val out = File("build/bugbash")

    var dark by mutableStateOf(false)
        private set
    var language by mutableStateOf(EchoLanguage.English)
        private set
    private var scale by mutableStateOf(1f)

    /** [each] runs after every change of variant, before the check and the picture (to open a tab, wait for data). */
    fun run(name: String, each: () -> Unit = {}, screen: @Composable (urdu: Boolean) -> Unit) {
        compose.setContent {
            val base = LocalContext.current
            val config = LocalConfiguration.current
            val scaled = remember(scale) {
                base.createConfigurationContext(Configuration(config).apply { fontScale = scale })
            }
            CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
                EchoV2Theme(darkTheme = dark, language = language) {
                    CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                        screen(language == EchoLanguage.Urdu)
                    }
                }
            }
        }
        val shoot = out.isDirectory
        val largest = scales.max()
        val small = mutableListOf<String>()
        val sheets = mutableMapOf<Boolean, MutableList<Bitmap>>()
        for (d in listOf(false, true)) {
            for (l in EchoLanguage.entries) {
                for (s in scales) {
                    dark = d
                    language = l
                    scale = s
                    compose.waitForIdle()
                    each()
                    compose.waitForIdle()
                    val variant = "${(s * 100).toInt()}-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
                    if (s == largest && !d) small += tooSmall(variant)
                    if (shoot) {
                        val bitmap = compose.onRoot().captureToImage().asAndroidBitmap()
                        save(bitmap, File(out, "$name/$variant.png"))
                        sheets.getOrPut(d) { mutableListOf() } += bitmap
                        if (s == largest && !d) {
                            repeat(4) { compose.onRoot().performTouchInput { swipeUp() } }
                            compose.waitForIdle()
                            save(compose.onRoot().captureToImage().asAndroidBitmap(), File(out, "$name/$variant-end.png"))
                        }
                    }
                }
            }
        }
        sheets.forEach { (d, list) -> save(sheet(list), File(out, "$name-sheet-${if (d) "dark" else "light"}.png")) }
        assertTrue("$name: controls under 48 dp at ${(largest * 100).toInt()} %:\n" + small.joinToString("\n"), small.isEmpty())
    }

    /** Controls a finger can't hit: clickable nodes under 48 dp high or wide. */
    private fun tooSmall(variant: String): List<String> {
        val minPx = 48 * compose.density.density - 1
        val found = mutableListOf<String>()
        fun visit(node: SemanticsNode) {
            if (node.config.getOrNull(SemanticsActions.OnClick) != null) {
                // Its own size, not what the window shows of it (a row half off screen is still a whole row), or the
                // larger area a small control answers to (a 32 dp chip takes touches over 48 dp).
                val b = node.size
                val touch = node.touchBoundsInRoot
                val high = b.height >= minPx || touch.height >= minPx
                val wide = b.width >= minPx || touch.width >= minPx
                if (b.width > 0 && (!high || !wide)) {
                    val words = node.config.getOrNull(SemanticsProperties.Text)?.joinToString() ?: node.config
                        .getOrNull(SemanticsProperties.ContentDescription)?.joinToString() ?: "?"
                    found +=
                        "$variant: \"$words\" ${(b.width / compose.density.density).toInt()}x${(b.height / compose.density.density).toInt()} dp"
                }
            }
            node.children.forEach(::visit)
        }
        visit(compose.onRoot(useUnmergedTree = false).fetchSemanticsNode())
        return found
    }

    private fun save(bitmap: Bitmap, file: File) {
        file.parentFile!!.mkdirs()
        file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }

    /** The shots side by side, one row per language (each text size a column), at a third of their size. */
    private fun sheet(shots: List<Bitmap>): Bitmap {
        val w = shots.first().width / 3
        val h = shots.first().height / 3
        val cols = scales.size
        val rows = (shots.size + cols - 1) / cols
        val sheet = Bitmap.createBitmap(w * cols, h * rows, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(sheet)
        shots.forEachIndexed { i, b ->
            val x = (i % cols) * w
            val y = (i / cols) * h
            canvas.drawBitmap(b, null, Rect(x, y, x + w, y + h), null)
        }
        return sheet
    }
}
