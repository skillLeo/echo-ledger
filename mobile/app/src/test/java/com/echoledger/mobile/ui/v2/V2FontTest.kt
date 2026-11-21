package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.font.FontFamily
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.design.ArabicNaskh
import com.echoledger.mobile.ui.design.Inter
import com.echoledger.mobile.ui.design.UrduNastaliq
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * v2's letters are the app's own, as on the classic screens (docs/PLAN_V2_PARITY.md section 6): Inter for Latin, Noto
 * Nastaliq Urdu for Urdu, Noto Naskh Arabic for Arabic, whatever font the phone uses (a Samsung with a handwritten
 * system font looked handwritten in v2). "Use the phone's font" is off by default and is the only way to the phone's.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class V2FontTest {
    @get:Rule
    val compose = createComposeRule()

    private var phoneFont by mutableStateOf(AppSettings().phoneFont)
    private var language by mutableStateOf(EchoLanguage.English)

    private fun family(words: String): FontFamily? {
        val results = mutableListOf<TextLayoutResult>()
        compose.onNodeWithText(words).fetchSemanticsNode().config[SemanticsActions.GetTextLayoutResult].action?.invoke(results)
        return results.single().layoutInput.style.fontFamily
    }

    private fun show() = compose.setContent {
        EchoV2Theme(darkTheme = false, language = language, phoneFont = phoneFont) {
            androidx.compose.foundation.layout.Column {
                Text("Ayesha Siddiqui", style = EchoV2.type.body)
                Text("12:05", style = EchoV2.type.mono)
                Text("Payment in 45 days", style = EchoV2.type.content("Payment in 45 days", EchoV2.type.body))
                Text("کل کی میٹنگ", style = EchoV2.type.content("کل کی میٹنگ", EchoV2.type.body))
                Text("السلام عليكم", style = EchoV2.type.content("السلام عليكم", EchoV2.type.body))
            }
        }
    }

    @Test
    fun v2TextUsesTheBundledFacesWhileUseThePhonesFontIsOff() {
        assertFalse("Use the phone's font is off by default", AppSettings().phoneFont)
        show()
        assertEquals(Inter, family("Ayesha Siddiqui"))
        assertEquals(Inter, family("12:05"))
        assertEquals(Inter, family("Payment in 45 days"))
        assertEquals(UrduNastaliq, family("کل کی میٹنگ"))
        assertEquals(ArabicNaskh, family("السلام عليكم"))
        // On the Urdu screens: Urdu in Nastaliq, English content and clocks still in Inter.
        language = EchoLanguage.Urdu
        compose.waitForIdle()
        assertEquals(UrduNastaliq, family("Ayesha Siddiqui"))
        assertEquals(Inter, family("Payment in 45 days"))
        assertEquals(Inter, family("12:05"))
        assertEquals(UrduNastaliq, family("کل کی میٹنگ"))
    }

    @Test
    fun usingThePhonesFontChangesOnlyTheLatinFace() {
        phoneFont = true
        show()
        assertEquals(FontFamily.Default, family("Ayesha Siddiqui"))
        assertEquals(FontFamily.Default, family("12:05"))
        assertEquals(UrduNastaliq, family("کل کی میٹنگ"))
        language = EchoLanguage.Urdu
        compose.waitForIdle()
        assertEquals(UrduNastaliq, family("Ayesha Siddiqui"))
        assertEquals(FontFamily.Default, family("Payment in 45 days"))
    }
}
