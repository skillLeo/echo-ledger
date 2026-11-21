package com.echoledger.mobile.ui.v2

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Rect
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.ComposeContentTestRule
import androidx.compose.ui.test.onRoot
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import java.io.File

/**
 * Side-by-side pictures for the parity work (docs/PLAN_V2_PARITY.md section 8): the classic screen on the left, the v2
 * one on the right, light and dark, English and Urdu (classic has no Urdu; it shows the same English). Written to
 * docs/v2/parity/phone/ when recording (`-Proborazzi.test.record=true`), otherwise only drawn.
 */
class ParityShots(private val compose: ComposeContentTestRule) {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/parity/phone")
    private val record = System.getProperty("roborazzi.test.record") == "true"

    var dark by mutableStateOf(false)
        private set
    var language by mutableStateOf(EchoLanguage.English)
        private set
    private var classicSide by mutableStateOf(true)

    /**
     * Draws [classic] and [v2] in every variant, or in [only] those ("light-en", "dark-ur" …); [each] runs after each
     * change (to open a dialog, wait for data). Returns the variants drawn ("light-en" …).
     */
    fun shoot(
        name: String,
        classic: @Composable (dark: Boolean) -> Unit,
        v2: @Composable (dark: Boolean, language: EchoLanguage) -> Unit,
        each: () -> Unit = {},
        only: Set<String>? = null
    ): List<String> {
        compose.setContent {
            if (classicSide) {
                EchoLedgerTheme(themeMode = if (dark) "dark" else "light") {
                    // The page's own background, as the activity draws it.
                    Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) { classic(dark) }
                }
            } else {
                v2(dark, language)
            }
        }
        val drawn = mutableListOf<String>()
        val variants = listOf(false, true).flatMap { d -> EchoLanguage.entries.map { it to d } }
        for ((l, d) in variants) {
            val variant = "${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
            if (only != null && variant !in only) continue
            language = l
            dark = d
            classicSide = true
            compose.waitForIdle()
            each()
            val left = compose.onRoot().captureToImage().asAndroidBitmap()
            classicSide = false
            compose.waitForIdle()
            each()
            val right = compose.onRoot().captureToImage().asAndroidBitmap()
            if (record) save(pair(left, right), File(out, "$name-$variant.png"))
            drawn += variant
        }
        return drawn
    }

    /** The two side by side at half size, with a thin gap. */
    private fun pair(left: Bitmap, right: Bitmap): Bitmap {
        val w = left.width / 2
        val h = left.height / 2
        val gap = 8
        val out = Bitmap.createBitmap(w * 2 + gap, h, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(out)
        canvas.drawColor(Color.GRAY)
        canvas.drawBitmap(left, null, Rect(0, 0, w, h), null)
        canvas.drawBitmap(right, null, Rect(w + gap, 0, w * 2 + gap, h), null)
        return out
    }

    private fun save(bitmap: Bitmap, file: File) {
        file.parentFile!!.mkdirs()
        file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }
}
